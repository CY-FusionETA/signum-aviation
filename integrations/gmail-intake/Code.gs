/**
 * Skyledger — Module 1: Gmail supplier-invoice intake → WazzOCR (Google Apps Script)
 * ---------------------------------------------------------------------------
 * Runs on a time trigger, finds supplier-invoice emails, and sends each PDF/image
 * attachment to WazzOCR over WhatsApp (via Wazzup). WazzOCR OCRs the document,
 * creates the draft Xero bill, and replies in WhatsApp — so every invoice and
 * WazzOCR's response are visible on the sending WhatsApp line.
 *
 * THE FLOW (per attachment):
 *   1. Upload the attachment to Unidash's file-drop (<DROP_URL>) → get a short-
 *      lived public URL. Wazzup can only send files by URL, not by upload.
 *   2. POST to Wazzup /v3/message: send that URL to WazzOCR's WhatsApp number
 *      (WAZZOCR_WHATSAPP) from your Wazzup WhatsApp channel.
 *   3. WazzOCR receives the WhatsApp, OCRs it, creates the draft Xero bill, and
 *      replies to your line — Unidash then matches/tags/approves → client invoice.
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
  // Wazzup — sends the invoice to WazzOCR over WhatsApp. API key from Wazzup → API.
  WAZZUP_API_KEY:    'ef3eddf51f7d431ab2927e0d46a2dbf3',
  WAZZUP_CHANNEL_ID: '61e245cf-3c1c-4586-a89b-3c1f75de659a',   // blank = auto-detect from the key
  WAZZOCR_WHATSAPP:  '60102300975',       // WazzOCR's WhatsApp intake number (digits + country code)

  // Unidash file-drop — hosts each attachment at a short-lived public URL that
  // Wazzup fetches. DROP_KEY must match drop.key in Unidash's config.php.
  DROP_URL: 'https://signum-aviation.fusioneta.com.my/drop',
  DROP_KEY: 'REPLACE_WITH_DROP_KEY',

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
 * Send one attachment to WazzOCR over WhatsApp: host it on Unidash's file-drop,
 * then hand the URL to Wazzup. Throws on failure so the attachment is retried.
 */
function sendViaWazzup_(att, msg) {
  var url = dropFile_(att);   // short-lived public URL Wazzup can fetch

  var payload = {
    channelId:  getChannelId_(),
    chatType:   'whatsapp',
    chatId:     CONFIG.WAZZOCR_WHATSAPP,   // WazzOCR's WhatsApp number
    contentUri: url,
  };
  var res = UrlFetchApp.fetch('https://api.wazzup24.com/v3/message', {
    method: 'post',
    contentType: 'application/json',
    muteHttpExceptions: true,
    headers: { Authorization: 'Bearer ' + CONFIG.WAZZUP_API_KEY },
    payload: JSON.stringify(payload),
  });
  var code = res.getResponseCode(), body = res.getContentText();
  if (code < 200 || code >= 300) {
    throw new Error('Wazzup HTTP ' + code + ' — ' + body.slice(0, 300));
  }
  Logger.log('Sent "%s" to WazzOCR via WhatsApp (msg %s) → HTTP %s', att.getName(), msg.getId(), code);
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

/** The Wazzup channelId to send from — explicit, else auto-detected + cached. */
function getChannelId_() {
  if (CONFIG.WAZZUP_CHANNEL_ID) return CONFIG.WAZZUP_CHANNEL_ID;
  var props = PropertiesService.getScriptProperties();
  var cached = props.getProperty('wazzup_channel_id');
  if (cached) return cached;

  var res = UrlFetchApp.fetch('https://api.wazzup24.com/v3/channels', {
    method: 'get',
    muteHttpExceptions: true,
    headers: { Authorization: 'Bearer ' + CONFIG.WAZZUP_API_KEY },
  });
  if (res.getResponseCode() >= 300) throw new Error('Wazzup channels HTTP ' + res.getResponseCode() + ' — ' + res.getContentText().slice(0, 200));
  var chans = JSON.parse(res.getContentText()) || [];
  var pick = null;
  chans.forEach(function (c) {
    var t = String(c.transport || '').toLowerCase();
    if (!pick && (t.indexOf('whatsapp') >= 0 || t === 'wapi' || t === 'waba')) pick = c;
  });
  if (!pick || !pick.channelId) throw new Error('No WhatsApp channel found in Wazzup — set WAZZUP_CHANNEL_ID manually.');
  props.setProperty('wazzup_channel_id', pick.channelId);
  return pick.channelId;
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
