/**
 * Skyledger — Module 1: Gmail supplier-invoice intake → WazzOCR External API
 * ---------------------------------------------------------------------------
 * Runs on a time trigger, finds supplier-invoice emails, and sends each PDF
 * straight to the WazzOCR External API. WazzOCR extracts the bill with AI and
 * creates the draft Xero bill, and returns the outcome IN THE SAME CALL — so
 * there's no WhatsApp, no Wazzup, no file-drop, and no reply webhook: this script
 * gets "created / duplicate / error" back immediately and logs it to Unidash.
 *
 * THE FLOW (per attachment):
 *   1. Read the PDF bytes, Base64-encode them.
 *   2. POST to <WAZZOCR_API_BASE>/api/ext/process-pdf with the X-Api-Key header.
 *   3. WazzOCR OCRs it, matches the Xero org, creates the draft bill, and returns
 *      { status, message, bills:[{ xero:{ invoiceId, invoiceNumber } }] }.
 *   4. Report the outcome to Unidash's Inbox (<INBOX_URL>).
 *   Unidash then matches/tags/approves the bill → client invoice.
 *
 * PDF ONLY: the External API accepts application/pdf. Image invoices (png/jpg)
 * are not sent — supplier invoices are normally PDF. (If you need images, they
 * have to be converted to PDF first.)
 *
 * DEDUPE IS PER ATTACHMENT (not per thread). An attachment that WazzOCR has
 * returned a verdict for (HTTP 200) is recorded in Script Properties by
 * (message id + size + name) and never sent again. A transport failure (network /
 * 401 / 500) is NOT recorded, so it's retried on the next run.
 *
 * ONE-TIME after pasting: run `setup` once (grant perms, create labels), then
 * `seedProcessed` once (marks existing invoices done without sending), then
 * `installTrigger` once (polls every minute). Use `run` to test by hand.
 *
 * PREREQUISITES:
 *   - WazzOCR: an API key — WazzOCR Admin → the account → Connections tab →
 *     External API card → Generate API key. Put it in WAZZOCR_API_KEY, and your
 *     WazzOCR domain in WAZZOCR_API_BASE. The invoice's "Billed To" must name your
 *     company exactly as it appears in Xero so WazzOCR picks the right org.
 *   - Unidash config.php: drop.key set; put the SAME value in INBOX_KEY below.
 *   - Gmail filter that applies CONFIG.SOURCE_LABEL to supplier-invoice emails.
 */

var CONFIG = {
  // WazzOCR External API — where the PDF is sent and the bill is created.
  WAZZOCR_API_BASE: 'https://YOUR-WAZZOCR-DOMAIN',              // e.g. https://wazzocr.fusioneta.com.my (no trailing slash)
  WAZZOCR_API_KEY:  'YOUR_WAZZOCR_API_KEY',                    // Admin → Connections → External API → Generate

  // Unidash Inbox (execution log). One POST per attachment with the outcome, plus
  // a heartbeat each run. INBOX_KEY must equal drop.key in Unidash's config.php.
  INBOX_URL: 'https://signum-aviation.fusioneta.com.my/inbox/log',
  INBOX_KEY: '471f2456249f2560c883cf561fec0091439af2ee8d919e5a',

  // Gmail label your filter puts invoices under (create the filter first).
  SOURCE_LABEL:    'supplier-invoices',
  PROCESSED_LABEL: 'skyledger-processed',
  ERROR_LABEL:     'skyledger-error',

  ALLOWED_EXT: ['pdf'],       // the External API is PDF-only
  MIN_BYTES:   8 * 1024,      // skip tiny inline PDFs (rare, but keeps noise out)
  MAX_THREADS_PER_RUN: 20,
  SCAN_DAYS:   3,             // only scan threads active in the last N days (Gmail quota saver)

  // Shown on the Inbox row when a duplicate is auto-cleared and the bill remade.
  // Keep this identical to Unidash's DuplicateBill::CLEARED_MESSAGE.
  DUP_NOTE:    'Duplicate invoice detected, auto deleted old copy.',
};

/** Entry point for the time trigger. */
function run() {
  ensureLabels_();
  var processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL);
  var errored   = GmailApp.getUserLabelByName(CONFIG.ERROR_LABEL);
  var props     = PropertiesService.getScriptProperties();

  var query   = 'label:' + CONFIG.SOURCE_LABEL + ' -label:' + CONFIG.PROCESSED_LABEL +
                ' has:attachment newer_than:' + CONFIG.SCAN_DAYS + 'd';
  var threads = GmailApp.search(query, 0, CONFIG.MAX_THREADS_PER_RUN);
  if (threads.length) Logger.log('Scanning %s new thread(s)', threads.length);

  pingHeartbeat_();   // tell the Inbox the poller ran, even on idle minutes

  threads.forEach(function (thread) {
    var anyProcessed = false, anyFailed = false;
    thread.getMessages().forEach(function (msg) {
      msg.getAttachments().forEach(function (att) {
        if (!isForwardable_(att)) return;
        var key = attKey_(msg, att);
        if (props.getProperty(key)) return;            // this exact attachment already done
        try {
          var r = sendToWazzOCR_(att);                 // { code, json, raw }
          // Duplicate: WazzOCR made nothing because that invoice number is already
          // on a Xero bill. Ask Unidash (the only side with the Xero connection) to
          // delete the leftover DRAFT, then re-send the same PDF so the bill is
          // recreated fresh — logged as one row carrying the "auto deleted" note.
          var bill0 = (r.json.bills && r.json.bills[0]) || {};
          if (r.code === 200 && String(bill0.status || '').toLowerCase() === 'duplicate') {
            var number  = dupNumber_(r);
            var cleared = number ? clearDuplicate_(number)
                                 : { cleared: false, message: 'No invoice number in the duplicate reply.' };
            if (cleared.cleared) {
              var r2 = sendToWazzOCR_(att);            // fresh bill under the now-free number
              logInbox_(msg, att, r2, CONFIG.DUP_NOTE);
              r = r2;                                  // done-ness follows the re-send
            } else {                                   // nothing live to delete (e.g. a voided copy) → show as-is
              logInbox_(msg, att, r);
            }
          } else {
            logInbox_(msg, att, r);
          }
          if (r.code === 200) {                        // WazzOCR returned a verdict → done, never resend
            props.setProperty(key, String(Date.now()));
            anyProcessed = true;
          } else {                                     // transport failure → retried next run
            anyFailed = true;
          }
        } catch (err) {
          anyFailed = true;                            // not recorded → retried next run
          Logger.log('Send failed for "%s": %s', att.getName(), err);
          logInbox_(msg, att, { code: 0, json: {}, raw: String(err) });
        }
      });
    });
    if (anyFailed) {
      thread.addLabel(errored);
    } else if (anyProcessed) {
      thread.addLabel(processed);
      thread.removeLabel(errored);
    }
  });
}

/** Stable key for one attachment: message id + size + name. */
function attKey_(msg, att) {
  return 'att_' + msg.getId() + '_' + att.getSize() + '_' + (att.getName() || '');
}

/** Should this attachment be sent? PDF only, above the tiny-file floor. */
function isForwardable_(att) {
  if (att.getSize() < CONFIG.MIN_BYTES) return false;
  var name = (att.getName() || '').toLowerCase();
  var ext  = name.indexOf('.') >= 0 ? name.split('.').pop() : '';
  return CONFIG.ALLOWED_EXT.indexOf(ext) >= 0;
}

/**
 * Send one PDF to the WazzOCR External API and return its verdict.
 * @return {{code:number, json:Object, raw:string}}
 */
function sendToWazzOCR_(att) {
  var res = UrlFetchApp.fetch(CONFIG.WAZZOCR_API_BASE + '/api/ext/process-pdf', {
    method: 'post',
    contentType: 'application/json',
    muteHttpExceptions: true,
    headers: { 'X-Api-Key': CONFIG.WAZZOCR_API_KEY },
    payload: JSON.stringify({
      fileBase64: Utilities.base64Encode(att.getBytes()),
      mimeType:   'application/pdf',
      fileName:   att.getName(),
    }),
  });
  var code = res.getResponseCode();
  var raw  = res.getContentText();
  var json = {};
  try { json = JSON.parse(raw) || {}; } catch (e) { json = {}; }
  Logger.log('WazzOCR "%s" → HTTP %s %s', att.getName(), code, json.status || '');
  return { code: code, json: json, raw: raw };
}

/**
 * Report one attachment's outcome to Unidash's Inbox (best-effort — a logging
 * failure must never break intake, so it swallows its own errors).
 *   r = { code, json, raw } from sendToWazzOCR_.
 *   dupNote (optional) — set to CONFIG.DUP_NOTE when this row is the fresh bill made
 *   after a duplicate was auto-cleared, so the Inbox shows "auto deleted old copy".
 */
function logInbox_(msg, att, r, dupNote) {
  if (!CONFIG.INBOX_URL) return;
  var reached = r.code === 200;                        // did WazzOCR return a verdict?
  var bill    = (r.json.bills && r.json.bills[0]) || {};
  var xero    = bill.xero || {};
  try {
    UrlFetchApp.fetch(CONFIG.INBOX_URL, {
      method: 'post',
      muteHttpExceptions: true,
      payload: {
        key:         CONFIG.INBOX_KEY,
        event_at:    new Date().toISOString(),
        sender:      msg.getFrom(),
        subject:     msg.getSubject(),
        attachment:  att.getName(),
        size:        String(att.getSize()),
        status:      reached ? 'sent' : 'failed',       // delivery to WazzOCR
        error:       reached ? '' : ('HTTP ' + r.code + ': ' + String(r.json.error || r.json.message || r.raw).slice(0, 300)),
        // The synchronous WazzOCR result — Unidash shows this as Success / Failed.
        result:      reached ? String(r.json.status || '') : '',   // created|duplicate|pending|empty|partial|error
        message:     String(r.json.message || r.json.error || ''),
        bill_id:     String(xero.invoiceId || ''),
        bill_number: String(xero.invoiceNumber || ''),
        dup_note:    String(dupNote || ''),             // '' unless this is the post-duplicate remake
      },
    });
  } catch (e) {
    Logger.log('Inbox log failed: %s', e);
  }
}

/**
 * The Xero invoice number a "duplicate" reply is about, so Unidash can find and
 * delete the leftover bill. WazzOCR puts it on bills[0].bill.invoiceNo; fall back
 * to the number named in the message ("existing bill for X"). '' if none found.
 */
function dupNumber_(r) {
  var b   = (r.json.bills && r.json.bills[0]) || {};
  var bl  = b.bill || {}, xe = b.xero || {};
  var n = bl.invoiceNo || bl.invoiceNumber || xe.invoiceNumber || '';
  if (!n && r.json.message) {
    var m = String(r.json.message).match(/(?:existing bill for|bill)\s+([A-Za-z0-9][A-Za-z0-9._\/\-]{2,})/i);
    if (m) n = m[1];
  }
  return String(n || '').trim();
}

/**
 * Ask Unidash to delete the leftover DRAFT bill under this invoice number so the
 * PDF can be sent again and created fresh. Returns { code, cleared, message };
 * cleared is true only when a live draft was actually deleted.
 */
function clearDuplicate_(number) {
  var url = CONFIG.INBOX_URL.replace(/\/inbox\/log$/, '/inbox/clear-duplicate');
  try {
    var res = UrlFetchApp.fetch(url, {
      method: 'post',
      muteHttpExceptions: true,
      payload: { key: CONFIG.INBOX_KEY, bill_number: number },
    });
    var json = {};
    try { json = JSON.parse(res.getContentText()) || {}; } catch (e) { json = {}; }
    Logger.log('clearDuplicate "%s" → HTTP %s cleared=%s', number, res.getResponseCode(), !!json.cleared);
    return { code: res.getResponseCode(), cleared: !!json.cleared, message: String(json.message || '') };
  } catch (e) {
    Logger.log('clearDuplicate failed for "%s": %s', number, e);
    return { code: 0, cleared: false, message: String(e) };
  }
}

/** Tell the Inbox the poller ran (liveness), even on minutes with no invoice. */
function pingHeartbeat_() {
  if (!CONFIG.INBOX_URL) return;
  try {
    UrlFetchApp.fetch(CONFIG.INBOX_URL, {
      method: 'post', muteHttpExceptions: true,
      payload: { key: CONFIG.INBOX_KEY, heartbeat: '1' },
    });
  } catch (e) { /* best-effort */ }
}

/**
 * Run ONCE after pasting: record every attachment already in your invoice mailbox
 * as "done" WITHOUT sending, so switching to this script doesn't re-process your
 * history. New emails after this flow normally.
 */
function seedProcessed() {
  ensureLabels_();
  var props     = PropertiesService.getScriptProperties();
  var processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL);
  var threads   = GmailApp.search('label:' + CONFIG.SOURCE_LABEL + ' has:attachment', 0, 200);
  var n = 0;
  threads.forEach(function (thread) {
    thread.getMessages().forEach(function (msg) {
      msg.getAttachments().forEach(function (att) {
        if (!isForwardable_(att)) return;
        props.setProperty(attKey_(msg, att), 'seed');
        n++;
      });
    });
    thread.addLabel(processed);
  });
  Logger.log('Seeded %s existing PDF(s) as already-processed. Send a NEW email to test.', n);
}

/** Wipe the processed-attachment record so the next run re-sends everything it sees. */
function resetProcessed() {
  var props = PropertiesService.getScriptProperties();
  var n = 0;
  props.getKeys().forEach(function (k) {
    if (k.indexOf('att_') === 0) { props.deleteProperty(k); n++; }
  });
  Logger.log('Cleared %s processed-attachment record(s). Next run re-sends what it sees.', n);
}

/** Create the labels (run once, grants permissions). */
function setup() {
  ensureLabels_();
  Logger.log('Labels ready. Make a Gmail filter that applies "%s" to invoice emails.', CONFIG.SOURCE_LABEL);
}

function ensureLabels_() {
  [CONFIG.SOURCE_LABEL, CONFIG.PROCESSED_LABEL, CONFIG.ERROR_LABEL].forEach(function (n) {
    if (!GmailApp.getUserLabelByName(n)) GmailApp.createLabel(n);
  });
}

/** Poll every minute (fastest Apps Script allows). Run once. */
function installTrigger() {
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'run') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('run').timeBased().everyMinutes(1).create();
  Logger.log('Trigger installed: run() every 1 minute.');
}

/**
 * OPTIONAL — set/update WazzOCR's AI extraction rules for this account (chart of
 * accounts codes, per-supplier handling, etc.). Run by hand when your rules
 * change; they persist until you update them again. Send '' to clear all rules.
 */
function updatePromptRules() {
  var rules = [
    // 'Always assign handling charges to code 6-1234.',
    // 'If the supplier is "Signature Flight Support", assign to code 325.',
  ].join('\n');

  var res = UrlFetchApp.fetch(CONFIG.WAZZOCR_API_BASE + '/api/ext/prompt', {
    method: 'post',
    contentType: 'application/json',
    muteHttpExceptions: true,
    headers: { 'X-Api-Key': CONFIG.WAZZOCR_API_KEY },
    payload: JSON.stringify({ prompt: rules }),
  });
  Logger.log('updatePromptRules → HTTP %s %s', res.getResponseCode(), res.getContentText().slice(0, 300));
}
