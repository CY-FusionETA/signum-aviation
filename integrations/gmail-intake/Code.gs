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
 * 401 / 500) is NOT recorded, so it's retried on the next run. An email with no
 * PDF to send is reported to the Inbox once, keyed by message id alone.
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

/**
 * Bumped whenever this file changes in a way that matters. It rides along on the
 * heartbeat so Unidash's Inbox tab can say whether the code pasted into Apps
 * Script is the current one — this file is NOT deployed by a repo pull, and
 * "did the paste happen?" is otherwise unanswerable from the outside.
 * Keep it identical to InboxLog::EXPECTED_SCRIPT_VERSION.
 */
var SCRIPT_VERSION = '2026-08-20';

var CONFIG = {
  // WazzOCR External API — where the PDF is sent and the bill is created.
  WAZZOCR_API_BASE: 'https://wazzocr.fusioneta.com.my',              // e.g. https://wazzocr.fusioneta.com.my (no trailing slash)
  WAZZOCR_API_KEY:  'a7fc1e2878393ff801302aac0792a91f109a293a0fc72eed',                    // Admin → Connections → External API → Generate

  // Unidash Inbox (execution log). One POST per attachment with the outcome, plus
  // a heartbeat each run. INBOX_KEY must equal drop.key in Unidash's config.php.
  INBOX_URL: 'https://signum-aviation.fusioneta.com.my/inbox/log',
  INBOX_KEY: '471f2456249f2560c883cf561fec0091439af2ee8d919e5a',

  // Gmail label your filter puts invoices under (create the filter first).
  SOURCE_LABEL:    'supplier-invoices',
  PROCESSED_LABEL: 'skyledger-processed',
  ERROR_LABEL:     'skyledger-error',

  ALLOWED_EXT: ['pdf'],       // the External API is PDF-only
  MAX_THREADS_PER_RUN: 20,
  SCAN_DAYS:   3,             // only scan threads active in the last N days (Gmail quota saver)

  // Shown on the Inbox row when a duplicate is auto-cleared and the bill remade.
  // Keep this identical to Unidash's DuplicateBill::CLEARED_MESSAGE.
  DUP_NOTE:    'Duplicate invoice detected, auto deleted old copy.',
  // Shown when the old copy could not be deleted YET (Xero rate limit / outage).
  // The attachment is deliberately left unprocessed so the next run retries it.
  DUP_RETRY:   'Duplicate detected — could not clear the old bill yet, will retry.',
  // How long to leave a blocked duplicate alone before sending it again. Every
  // send is a full OCR + AI extraction in WazzOCR, so retrying a bill that cannot
  // be cleared yet costs real money for a result we already know. Unidash says how
  // long Xero is unavailable for (retry_after); this is the floor/ceiling on it.
  RETRY_MIN_MINUTES: 15,
  RETRY_MAX_MINUTES: 360,
};

/** Entry point for the time trigger. */
function run() {
  ensureLabels_();
  var processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL);
  var errored   = GmailApp.getUserLabelByName(CONFIG.ERROR_LABEL);
  var props     = PropertiesService.getScriptProperties();

  // No 'has:attachment' filter: an email with nothing to send still belongs in the
  // Inbox, saying why, rather than disappearing without trace.
  var query   = 'label:' + CONFIG.SOURCE_LABEL + ' -label:' + CONFIG.PROCESSED_LABEL +
                ' newer_than:' + CONFIG.SCAN_DAYS + 'd';
  var threads = GmailApp.search(query, 0, CONFIG.MAX_THREADS_PER_RUN);
  if (threads.length) Logger.log('Scanning %s new thread(s)', threads.length);

  pingHeartbeat_();   // tell the Inbox the poller ran, even on idle minutes

  var aiPrompt = fetchAiPrompt_();   // operator's AI prompt add-on, sent with every upload

  threads.forEach(function (thread) {
    var anyProcessed = false, anyFailed = false;
    thread.getMessages().forEach(function (msg) {
      var sendable = msg.getAttachments().filter(isForwardable_);

      // Nothing here we can process — a supplier who sent a photo, or just wrote a
      // note. Record it once so the email is visible in the Inbox with the reason,
      // and do NOT label the thread: a later reply carrying a real PDF must still
      // be picked up.
      if (!sendable.length) {
        var mkey = msgKey_(msg);
        if (!props.getProperty(mkey)) {
          logSkipped_(msg);
          props.setProperty(mkey, String(Date.now()));
        }
        return;
      }

      sendable.forEach(function (att) {
        var key = attKey_(msg, att);
        if (props.getProperty(key)) return;            // this exact attachment already done
        // Blocked on something that will not clear for a while (Xero rate limit)?
        // Say nothing and spend nothing until then — sending it again would run
        // another OCR + AI extraction only to be told "duplicate" a second time.
        var waitKey = key + ':after';
        var notBefore = Number(props.getProperty(waitKey) || 0);
        if (notBefore && Date.now() < notBefore) { anyFailed = true; return; }
        if (notBefore) props.deleteProperty(waitKey);  // the wait is over
        try {
          var r = sendToWazzOCR_(att, aiPrompt);       // { code, json, raw }
          // Duplicate: WazzOCR made nothing because that invoice number is already
          // on a Xero bill. Ask Unidash (the only side with the Xero connection) to
          // delete the leftover DRAFT, then re-send the same PDF so the bill is
          // recreated fresh — logged as one row carrying the "auto deleted" note.
          var bill0 = (r.json.bills && r.json.bills[0]) || {};
          if (r.code === 200 && String(bill0.status || '').toLowerCase() === 'duplicate') {
            var number  = dupNumber_(r);
            var cleared = number ? clearDuplicate_(number)
                                 : { cleared: false, retryable: false, message: 'No invoice number in the duplicate reply.' };
            if (cleared.cleared) {
              var r2 = sendToWazzOCR_(att, aiPrompt);  // fresh bill under the now-free number
              logInbox_(msg, att, r2, CONFIG.DUP_NOTE);
              r = r2;                                  // done-ness follows the re-send
            } else if (cleared.retryable) {
              // Xero said "not now" (rate limit / outage), so the old bill is still
              // there and nothing was recreated. Leave this attachment UNRECORDED so
              // the next run sends it again — marking it done here loses the invoice
              // for good, since the thread would get the processed label.
              logInbox_(msg, att, r, CONFIG.DUP_RETRY + (cleared.message ? ' ' + cleared.message : ''));
              // Come back when Xero says it will be free, clamped so we neither
              // hammer it nor forget about it.
              var mins = Math.min(CONFIG.RETRY_MAX_MINUTES,
                         Math.max(CONFIG.RETRY_MIN_MINUTES, Math.ceil(Number(cleared.retry_after || 0) / 60)));
              props.setProperty(waitKey, String(Date.now() + mins * 60 * 1000));
              Logger.log('Duplicate %s blocked — not retrying for %s min', number, mins);
              anyFailed = true;
              return;
            } else {                                   // nothing live to delete (e.g. a voided copy) → show as-is
              logInbox_(msg, att, r);
            }
          } else {
            logInbox_(msg, att, r);
          }
          if (r.code === 200) {                        // WazzOCR returned a verdict → done, never resend
            props.deleteProperty(waitKey);
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

/** Stable key for a whole email, for what is recorded per message not per file. */
function msgKey_(msg) {
  return 'msg_' + msg.getId();
}

/** Should this attachment be sent? PDF only — no size floor, any size goes. */
function isForwardable_(att) {
  if (att.getSize() <= 0) return false;      // an empty part has nothing to OCR
  var name = (att.getName() || '').toLowerCase();
  var ext  = name.indexOf('.') >= 0 ? name.split('.').pop() : '';
  return CONFIG.ALLOWED_EXT.indexOf(ext) >= 0;
}

/**
 * Send one PDF to the WazzOCR External API and return its verdict.
 * @return {{code:number, json:Object, raw:string}}
 */
function sendToWazzOCR_(att, aiPrompt) {
  var body = {
    fileBase64: Utilities.base64Encode(att.getBytes()),
    mimeType:   'application/pdf',
    fileName:   att.getName(),
  };
  if (aiPrompt) body.aiPrompt = aiPrompt;   // Unidash AI prompt add-on, per-upload
  var res = UrlFetchApp.fetch(CONFIG.WAZZOCR_API_BASE + '/api/ext/process-pdf', {
    method: 'post',
    contentType: 'application/json',
    muteHttpExceptions: true,
    headers: { 'X-Api-Key': CONFIG.WAZZOCR_API_KEY },
    payload: JSON.stringify(body),
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
  var reached = r.code === 200;                        // did WazzOCR return a verdict?
  var bill    = (r.json.bills && r.json.bills[0]) || {};
  var xero    = bill.xero || {};
  postInbox_({
    event_at:    new Date().toISOString(),
    sender:      msg.getFrom(),
    subject:     msg.getSubject(),
    attachment:  att.getName(),
    size:        String(att.getSize()),
    status:      reached ? 'sent' : 'failed',           // delivery to WazzOCR
    error:       reached ? '' : ('HTTP ' + r.code + ': ' + String(r.json.error || r.json.message || r.raw).slice(0, 300)),
    // The synchronous WazzOCR result — Unidash shows this as Success / Failed.
    result:      reached ? String(r.json.status || '') : '',   // created|duplicate|pending|empty|partial|error
    message:     String(r.json.message || r.json.error || ''),
    bill_id:     String(xero.invoiceId || ''),
    bill_number: String(xero.invoiceNumber || ''),
    dup_note:    String(dupNote || ''),                 // '' unless this is the post-duplicate remake
  });
}

/**
 * Report an email that carried nothing we could send, so it still shows up in the
 * Inbox with the reason instead of being dropped in silence.
 *
 * Counts REAL attachments only — inline images are signature logos and tracking
 * pixels, and including them would tell the operator that a plain text email
 * "attached a PNG".
 */
function logSkipped_(msg) {
  var names = msg.getAttachments({ includeInlineImages: false }).map(function (a) {
    return a.getName() || '(unnamed file)';
  });
  postInbox_({
    event_at:   new Date().toISOString(),
    sender:     msg.getFrom(),
    subject:    msg.getSubject(),
    attachment: names.join(', '),
    size:       '0',
    status:     'skipped',
    error:      names.length
      ? 'Nothing sent — the processor reads PDF only, and this email attached ' + names.join(', ') + '.'
      : 'Nothing sent — this email has no attachment.',
  });
}

/** POST one row to the Unidash Inbox. Never throws: logging must not stop a send. */
function postInbox_(payload) {
  if (!CONFIG.INBOX_URL) return;
  payload.key = CONFIG.INBOX_KEY;
  try {
    UrlFetchApp.fetch(CONFIG.INBOX_URL, { method: 'post', muteHttpExceptions: true, payload: payload });
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
    var code = res.getResponseCode();
    // Unidash says whether it is worth trying again; a non-200 from Unidash itself
    // (down, bad key) is transient too, so default to retrying when it said nothing.
    var retryable = (typeof json.retryable === 'boolean') ? json.retryable : (code !== 200);
    Logger.log('clearDuplicate "%s" → HTTP %s cleared=%s retryable=%s', number, code, !!json.cleared, retryable);
    return { code: code, cleared: !!json.cleared, retryable: retryable,
             retry_after: Number(json.retry_after || 0), message: String(json.message || '') };
  } catch (e) {
    Logger.log('clearDuplicate failed for "%s": %s', number, e);
    return { code: 0, cleared: false, retryable: true, message: String(e) };
  }
}

/**
 * The operator's AI prompt add-on, managed in Unidash (Settings → AI prompt add-on).
 * Fetched once per run and sent to WazzOCR with every upload as the per-request
 * aiPrompt field. Best-effort: a failure just means no extra prompt this run.
 * @return {string}
 */
function fetchAiPrompt_() {
  if (!CONFIG.INBOX_URL) return '';
  var url = CONFIG.INBOX_URL.replace(/\/inbox\/log$/, '/inbox/ai-prompt')
          + '?key=' + encodeURIComponent(CONFIG.INBOX_KEY);
  try {
    var res = UrlFetchApp.fetch(url, { method: 'get', muteHttpExceptions: true });
    if (res.getResponseCode() !== 200) return '';
    var json = JSON.parse(res.getContentText()) || {};
    return String(json.prompt || '');
  } catch (e) {
    Logger.log('AI prompt fetch failed: %s', e);
    return '';
  }
}

/** Tell the Inbox the poller ran (liveness), even on minutes with no invoice. */
function pingHeartbeat_() {
  if (!CONFIG.INBOX_URL) return;
  try {
    UrlFetchApp.fetch(CONFIG.INBOX_URL, {
      method: 'post', muteHttpExceptions: true,
      payload: { key: CONFIG.INBOX_KEY, heartbeat: '1', version: SCRIPT_VERSION },
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
