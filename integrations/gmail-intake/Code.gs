/**
 * Skyledger — Module 1: Gmail supplier-invoice intake → WazzOCR (Google Apps Script)
 * ---------------------------------------------------------------------------
 * Runs on a time trigger, finds supplier-invoice emails, and POSTs each PDF/
 * image attachment straight to WazzOCR's pipeline over HTTPS. No phone, no
 * WhatsApp send — WazzOCR OCRs the document and creates the draft Xero bill in
 * the routed account's connected organisation.
 *
 * DEDUPE IS PER ATTACHMENT (not per thread). Each attachment that's been
 * forwarded is recorded in Script Properties by (message id + size + name), so:
 *   - re-sending the same invoice as a NEW email (even a reply in the same
 *     thread) is a new message → it gets forwarded again, and
 *   - the same attachment is never sent twice.
 * This is what lets you re-test the same invoice: just send it as a fresh email.
 *
 * ONE-TIME after pasting/upgrading this script: run `seedProcessed` once. It
 * records every invoice already in your mailbox as "done" WITHOUT sending, so
 * upgrading doesn't re-forward your whole history. New emails from then on flow
 * through normally. (To wipe the record and re-forward everything, run
 * `resetProcessed`.)
 *
 * HOW THE ROUTING WORKS (no phone needed):
 *   WazzOCR's /api/whatsapp/process-file picks the account from two body fields:
 *     channelId  — the shared trial channel id (WazzOCR → Connections)
 *     chatId     — a phone number listed under that account's
 *                  "Allowed phone numbers" (Connections → Allowed phone numbers)
 *   The phone is only a routing KEY in the payload; nothing is sent to WhatsApp.
 *
 * PREREQUISITES in WazzOCR (account "Signum Aviation"):
 *   1. Connections → connect the Signum Xero org (connect exactly ONE org so the
 *      AI doesn't have to ask which org to bill — that avoids a "pending/picker".)
 *   2. Connections → Allowed phone numbers → add a number (e.g. a placeholder like
 *      60000000018) and use that same number as ROUTING_PHONE below.
 *   3. Note the trial channel id → CHANNEL_ID below.
 *
 * SETUP (Gmail side):
 *   1. Gmail filter → apply label CONFIG.SOURCE_LABEL to supplier-invoice emails.
 *   2. script.google.com → new project bound to that Gmail account → paste this.
 *   3. Fill CONFIG. Run `setup` once (grant perms, create labels), then
 *      `seedProcessed` once, then `installTrigger` once (polls every 1 min).
 *      Use `run` to test by hand.
 */

var CONFIG = {
  // WazzOCR full pipeline (OCR + create draft Xero bill). HTTPS only.
  WAZZOCR_URL:   'https://wazzocr.fusioneta.com.my/api/whatsapp/process-file',
  // Routing (from WazzOCR → Connections for the Signum account):
  CHANNEL_ID:    'REPLACE_WITH_TRIAL_CHANNEL_ID',
  ROUTING_PHONE: 'REPLACE_WITH_AN_ALLOWED_PHONE',   // e.g. 60000000018 — must be in Allowed phone numbers

  // Gmail label your filter puts invoices under (create the filter first).
  SOURCE_LABEL:    'supplier-invoices',
  PROCESSED_LABEL: 'skyledger-processed',
  ERROR_LABEL:     'skyledger-error',

  ALLOWED_EXT: ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff'],
  MIN_BYTES:   8 * 1024,      // skip tiny inline images (signatures/logos)
  MAX_THREADS_PER_RUN: 20,
};

/** Entry point for the time trigger. */
function run() {
  ensureLabels_();
  var processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL);
  var errored   = GmailApp.getUserLabelByName(CONFIG.ERROR_LABEL);
  var props     = PropertiesService.getScriptProperties();

  // Every source-labeled thread with attachments — deduped at the ATTACHMENT
  // level below, so processed threads are rescanned and new messages in them
  // still go through.
  var query   = 'label:' + CONFIG.SOURCE_LABEL + ' has:attachment';
  var threads = GmailApp.search(query, 0, CONFIG.MAX_THREADS_PER_RUN);
  Logger.log('Scanning %s thread(s)', threads.length);

  threads.forEach(function (thread) {
    var anyProcessed = false, anyFailed = false;
    thread.getMessages().forEach(function (msg) {
      msg.getAttachments().forEach(function (att) {
        if (!isForwardable_(att)) return;
        var key = attKey_(msg, att);
        if (props.getProperty(key)) return;            // this exact attachment already forwarded
        try {
          sendToWazzOcr_(att, msg);
          props.setProperty(key, String(Date.now())); // record only on success
          anyProcessed = true;
        } catch (err) {
          anyFailed = true;                            // not recorded → retried next run
          Logger.log('WazzOCR send failed for "%s": %s', att.getName(), err);
        }
      });
    });
    // Labels are just a visual indicator now (dedupe is the property store).
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

/** Should this attachment be forwarded? */
function isForwardable_(att) {
  if (att.getSize() < CONFIG.MIN_BYTES) return false;
  var name = (att.getName() || '').toLowerCase();
  var ext  = name.indexOf('.') >= 0 ? name.split('.').pop() : '';
  return CONFIG.ALLOWED_EXT.indexOf(ext) >= 0;
}

/** POST one attachment to WazzOCR. Throws on failure so the attachment is retried. */
function sendToWazzOcr_(att, msg) {
  var res = UrlFetchApp.fetch(CONFIG.WAZZOCR_URL, {
    method: 'post',
    muteHttpExceptions: true,
    payload: {
      file:      att.copyBlob(),          // multipart "file"
      channelId: CONFIG.CHANNEL_ID,       // routes to the account
      chatId:    CONFIG.ROUTING_PHONE,    // allowed-phone routing key (no WhatsApp sent)
      fileName:  att.getName(),
    },
  });

  var code = res.getResponseCode();
  var body = res.getContentText();
  if (code < 200 || code >= 300) {
    throw new Error('HTTP ' + code + ' — ' + body.slice(0, 300));
  }

  var json = {};
  try { json = JSON.parse(body); } catch (e) { /* non-JSON = treat as ok */ }

  if (json.error) {
    throw new Error('WazzOCR: ' + json.error + (json.ticketCode ? ' [' + json.ticketCode + ']' : ''));
  }
  if (json.status === 'ignored') {
    // Sender phone not mapped to an account on this channel — a config problem.
    throw new Error('WazzOCR ignored the doc: ROUTING_PHONE "' + CONFIG.ROUTING_PHONE +
      '" is not in the account\'s Allowed phone numbers (Connections tab).');
  }
  if (json.status === 'empty') {
    Logger.log('WazzOCR read "%s" but found no bill (not an invoice?) — leaving it as done.', att.getName());
    return; // treated as processed; nothing to bill
  }
  Logger.log('WazzOCR accepted "%s" (msg %s) → HTTP %s', att.getName(), msg.getId(), code);
}

/**
 * Run ONCE after pasting/upgrading: record every attachment already in your
 * invoice mailbox as "done" WITHOUT sending, so the switch to per-attachment
 * dedupe doesn't re-forward your history. New emails after this flow normally.
 */
function seedProcessed() {
  ensureLabels_();
  var props   = PropertiesService.getScriptProperties();
  var threads = GmailApp.search('label:' + CONFIG.SOURCE_LABEL + ' has:attachment', 0, 200);
  var n = 0;
  threads.forEach(function (thread) {
    thread.getMessages().forEach(function (msg) {
      msg.getAttachments().forEach(function (att) {
        if (!isForwardable_(att)) return;
        props.setProperty(attKey_(msg, att), 'seed');
        n++;
      });
    });
  });
  Logger.log('Seeded %s existing attachment(s) as already-processed. Send a NEW email to test.', n);
}

/** Wipe the processed-attachment record so the next run re-forwards everything it sees. */
function resetProcessed() {
  var props = PropertiesService.getScriptProperties();
  var n = 0;
  props.getKeys().forEach(function (k) {
    if (k.indexOf('att_') === 0) { props.deleteProperty(k); n++; }
  });
  Logger.log('Cleared %s processed-attachment record(s). Next run re-forwards what it sees.', n);
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

/** Poll every minute (Apps Script's fastest time trigger). Run once. */
function installTrigger() {
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'run') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('run').timeBased().everyMinutes(1).create();
  Logger.log('Trigger installed: run() every 1 minute.');
}
