/**
 * Skyledger — Module 1: Gmail supplier-invoice intake → WazzOCR (Google Apps Script)
 * ---------------------------------------------------------------------------
 * Runs on a time trigger, finds supplier-invoice emails, and delivers each PDF/
 * image to WazzOCR over WhatsApp via a WABA relay. WazzOCR OCRs it, creates the
 * draft Xero bill, and replies in WhatsApp on the WABA line.
 *
 * THE FLOW (per attachment):
 *   1. Upload the attachment to Unidash's file-drop (<DROP_URL>) → short-lived
 *      public URL (Wazzup can only send files by URL, not by upload).
 *   2. Ensure WABA's FREE service window is open: if it's been more than
 *      WINDOW_HOURS since the last opener, WazzOCR's line (WAZZOCR_CHANNEL_ID)
 *      sends one opener text to your WABA number (WABA_NUMBER). If the window is
 *      still open, no opener is sent. This runs ONLY when relaying a real
 *      invoice — nothing is ever sent while the system is idle.
 *   3. Your WABA line (WABA_CHANNEL_ID) sends the file URL to WazzOCR
 *      (WAZZOCR_WHATSAPP). WazzOCR sees an inbound invoice from the WABA number
 *      (which must be in WazzOCR's Allowed phone numbers), OCRs it, creates the
 *      draft Xero bill, and replies on the WABA line.
 *   Unidash then matches/tags/approves the bill → client invoice.
 *
 * DEDUPE IS PER ATTACHMENT (not per thread). Each attachment that's been sent is
 * recorded in Script Properties by (message id + size + name), so:
 *   - re-sending the same invoice as a NEW email (even a reply in the same
 *     thread) is a new message → it gets sent again, and
 *   - the same attachment is never sent twice.
 *
 * ONE-TIME after pasting/upgrading this script: run `seedProcessed` once. It
 * records every invoice already in your mailbox as "done" WITHOUT sending, so
 * upgrading doesn't re-send your whole history. (To wipe and re-send, run
 * `resetProcessed`.)
 *
 * PREREQUISITES:
 *   - Wazzup: a connected WhatsApp channel; put the API key in WAZZUP_API_KEY.
 *   - Unidash config.php: set drop.key to a long random string and put the SAME
 *     value in DROP_KEY below.
 *   - WazzOCR: connected to receive invoices on WAZZOCR_WHATSAPP, with exactly
 *     ONE Xero org connected so it doesn't have to ask which org to bill.
 *
 * SETUP (Gmail side):
 *   1. Gmail filter → apply label CONFIG.SOURCE_LABEL to supplier-invoice emails.
 *   2. script.google.com → new project bound to that Gmail account → paste this.
 *   3. Fill CONFIG. Run `setup` once (grant perms, create labels), then
 *      `seedProcessed` once, then `installTrigger` once (polls every 1 min).
 *      Use `run` to test by hand.
 */

var CONFIG = {
  // Wazzup — the invoice reaches WazzOCR via a two-hop relay that keeps it inside
  // the WABA free service window (no business-initiated charge):
  //   1) if the window has lapsed, WazzOCR's line messages your WABA number to
  //      re-open it (only when a real invoice is being sent — never on idle)
  //   2) your WABA line sends the attachment to WazzOCR (free, inside that window)
  WAZZUP_API_KEY:     'ef3eddf51f7d431ab2927e0d46a2dbf3',        // account owning the channels below
  WAZZOCR_CHANNEL_ID: '61e245cf-3c1c-4586-a89b-3c1f75de659a',    // WazzOCR's line — sends the opener
  WAZZOCR_WHATSAPP:   '60102300975',                            // WazzOCR's WhatsApp intake number
  WABA_CHANNEL_ID:    'e8b53235-fc0f-414d-b427-411aa982ad3d',   // your WABA line's Wazzup channelId
  WABA_NUMBER:        '60386817302',                            // your WABA WhatsApp number
  WABA_API_KEY:       '687372d465f646cda5a46c604b9c4bb3',       // WABA line's own Wazzup account key
  OPENER_TEXT:        'Signum Aviation Attachment from email',
  WINDOW_HOURS:       20,                                       // assume the WABA free window stays open this long after an opener
  OPENER_WAIT_MS:     15000,                                    // pause after an opener so it lands before the file (cold-start only)

  // Unidash file-drop — hosts each attachment at a short-lived public URL that
  // Wazzup fetches. DROP_KEY must match drop.key in Unidash's config.php.
  DROP_URL: 'https://signum-aviation.fusioneta.com.my/drop',
  DROP_KEY: '471f2456249f2560c883cf561fec0091439af2ee8d919e5a',

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
        if (props.getProperty(key)) return;            // this exact attachment already sent
        try {
          sendViaWazzup_(att, msg);
          props.setProperty(key, String(Date.now())); // record only on success
          anyProcessed = true;
        } catch (err) {
          anyFailed = true;                            // not recorded → retried next run
          Logger.log('Send failed for "%s": %s', att.getName(), err);
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

/**
 * Deliver one attachment to WazzOCR via the WABA relay:
 *   1. host the file on Unidash's file-drop (Wazzup can only send files by URL),
 *   2. make sure the WABA free service window is open (send an opener only if it
 *      has lapsed — see ensureWindowOpen_), then
 *   3. the WABA line sends the file to WazzOCR (free, inside that window).
 * WazzOCR then sees a real inbound invoice from the (allowed) WABA number.
 * Throws on failure so the attachment is retried next run.
 */
function sendViaWazzup_(att, msg) {
  var url = dropFile_(att);   // short-lived public URL Wazzup can fetch

  ensureWindowOpen_();        // opens the WABA window only if it may have closed

  // WABA line → WazzOCR, carrying the attachment (free service message).
  wazzupSend_('WABA hop (channel ' + CONFIG.WABA_CHANNEL_ID + ')', CONFIG.WABA_API_KEY || CONFIG.WAZZUP_API_KEY, {
    channelId:  CONFIG.WABA_CHANNEL_ID,
    chatType:   'whatsapp',
    chatId:     CONFIG.WAZZOCR_WHATSAPP,
    contentUri: url,
  });

  Logger.log('Relayed "%s" to WazzOCR via WABA %s (msg %s)', att.getName(), CONFIG.WABA_NUMBER, msg.getId());
}

/**
 * Ensure the WABA free service window is open before we send a file. Only sends
 * an opener if it's been more than WINDOW_HOURS since the last one (tracked in
 * Script Properties). Called ONLY when there's an attachment to send — never on
 * idle — so no opener ever goes out unless a real invoice is being relayed.
 */
function ensureWindowOpen_() {
  var props = PropertiesService.getScriptProperties();
  var last  = Number(props.getProperty('waba_window_at') || 0);
  if (last && (Date.now() - last) < CONFIG.WINDOW_HOURS * 3600 * 1000) return;   // still open

  // WazzOCR's line → WABA number opens the window (WABA sees a customer message).
  wazzupSend_('opener hop (channel ' + CONFIG.WAZZOCR_CHANNEL_ID + ')', CONFIG.WAZZUP_API_KEY, {
    channelId: CONFIG.WAZZOCR_CHANNEL_ID,
    chatType:  'whatsapp',
    chatId:    CONFIG.WABA_NUMBER,
    text:      CONFIG.OPENER_TEXT,
  });
  Utilities.sleep(CONFIG.OPENER_WAIT_MS);   // let the opener land before the file goes out
  props.setProperty('waba_window_at', String(Date.now()));
}

/** POST one Wazzup /v3/message. Throws on non-2xx, tagged with which hop it was. */
function wazzupSend_(label, apiKey, payload) {
  var res = UrlFetchApp.fetch('https://api.wazzup24.com/v3/message', {
    method: 'post',
    contentType: 'application/json',
    muteHttpExceptions: true,
    headers: { Authorization: 'Bearer ' + apiKey },
    payload: JSON.stringify(payload),
  });
  var code = res.getResponseCode(), body = res.getContentText();
  if (code < 200 || code >= 300) throw new Error(label + ' — Wazzup HTTP ' + code + ' — ' + body.slice(0, 300));
  return body;
}

/** Upload an attachment to Unidash's file-drop; returns its public URL. */
function dropFile_(att) {
  var res = UrlFetchApp.fetch(CONFIG.DROP_URL, {
    method: 'post',
    muteHttpExceptions: true,
    payload: { key: CONFIG.DROP_KEY, file: att.copyBlob() },   // multipart
  });
  var code = res.getResponseCode(), body = res.getContentText();
  if (code < 200 || code >= 300) throw new Error('File-drop HTTP ' + code + ' — ' + body.slice(0, 200));
  var json = JSON.parse(body);
  if (!json.url) throw new Error('File-drop returned no url: ' + body.slice(0, 200));
  return json.url;
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
