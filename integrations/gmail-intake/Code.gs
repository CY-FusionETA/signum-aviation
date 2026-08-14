/**
 * Skyledger — Module 1: Gmail supplier-invoice intake → WazzOCR (Google Apps Script)
 * ---------------------------------------------------------------------------
 * Runs on a time trigger, finds supplier-invoice emails, and sends each PDF/
 * image straight to WazzOCR over WhatsApp from a regular Wazzup channel. WazzOCR
 * OCRs it, creates the draft Xero bill, and replies in WhatsApp.
 *
 * THE FLOW (per attachment):
 *   1. Upload the attachment to Unidash's file-drop (<DROP_URL>) → short-lived
 *      public URL (Wazzup can only send files by URL, not by upload).
 *   2. Your Wazzup channel (CHANNEL_ID) sends the file URL to WazzOCR
 *      (WAZZOCR_WHATSAPP). WazzOCR sees an inbound invoice from your number
 *      (which must be in WazzOCR's Allowed phone numbers), OCRs it, creates the
 *      draft Xero bill, and replies on the same line.
 *   Unidash then matches/tags/approves the bill → client invoice.
 *
 * This is a regular WhatsApp channel (not WhatsApp Business API), so there is NO
 * 24-hour service window — the file is sent directly, with no opener message.
 *
 * INBOX LOG (Unidash): each send is reported to <INBOX_URL> (who/when/what +
 * sent|failed + the drop URL), plus a heartbeat every run. WazzOCR's WhatsApp
 * reply is caught by Unidash's <WEBHOOK_URL> (register it ONCE with setWebhook)
 * so the bill-created / error result shows in the Inbox tab. The drop URL is what
 * lets Unidash re-send a file on its own: when WazzOCR answers "already exists in
 * Xero", Unidash deletes that leftover draft bill and sends the SAME file back
 * for processing, with no second email from you.
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
 * `resetProcessed`.) Also run `setWebhook` once so processor replies reach the Inbox.
 *
 * PREREQUISITES:
 *   - Wazzup: a connected WhatsApp channel; put its channelId in CHANNEL_ID and
 *     its account key in CHANNEL_API_KEY.
 *   - Unidash config.php: set drop.key to a long random string and put the SAME
 *     value in DROP_KEY below. Its 'wazzup' block must name this SAME channel and
 *     WazzOCR number, or Unidash's own re-sends go out on a line WazzOCR ignores.
 *   - WazzOCR: connected to receive invoices on WAZZOCR_WHATSAPP, with your
 *     channel's number in its Allowed phone numbers, and exactly ONE Xero org
 *     connected so it doesn't have to ask which org to bill.
 *
 * SETUP (Gmail side):
 *   1. Gmail filter → apply label CONFIG.SOURCE_LABEL to supplier-invoice emails.
 *   2. script.google.com → new project bound to that Gmail account → paste this.
 *   3. Fill CONFIG. Run `setup` once (grant perms, create labels), then
 *      `seedProcessed` once, then `installTrigger` once (polls every 1 min).
 *      Use `run` to test by hand.
 */

var CONFIG = {
  // Wazzup — a regular WhatsApp channel sends each invoice straight to WazzOCR.
  CHANNEL_ID:       'd899e4ea-b074-423d-8a47-1254cbcc566b',   // your WhatsApp channel's Wazzup channelId
  CHANNEL_API_KEY:  'a721bdc56ea842eeb5ff89c9f9989fa0',       // that channel's Wazzup account key
  WAZZOCR_WHATSAPP: '60386817304',                            // WazzOCR's WhatsApp intake number

  // Unidash file-drop — hosts each attachment at a short-lived public URL that
  // Wazzup fetches. DROP_KEY must match drop.key in Unidash's config.php.
  DROP_URL: 'https://signum-aviation.fusioneta.com.my/drop',
  DROP_KEY: '471f2456249f2560c883cf561fec0091439af2ee8d919e5a',

  // Unidash Inbox (execution log). INBOX_URL receives one POST per attachment
  // sent (plus a heartbeat each run); WEBHOOK_URL is what we register with Wazzup
  // (run setWebhook once) so WazzOCR's WhatsApp replies land in the Inbox. Both
  // authenticate with DROP_KEY. WEBHOOK_API_KEY is the account key of the channel
  // that talks to WazzOCR (it receives the replies) — the same CHANNEL_API_KEY.
  INBOX_URL:       'https://signum-aviation.fusioneta.com.my/inbox/log',
  WEBHOOK_URL:     'https://signum-aviation.fusioneta.com.my/wazzup/webhook',
  WEBHOOK_API_KEY: 'a721bdc56ea842eeb5ff89c9f9989fa0',          // = CHANNEL_API_KEY

  // Gmail label your filter puts invoices under (create the filter first).
  SOURCE_LABEL:    'supplier-invoices',
  PROCESSED_LABEL: 'skyledger-processed',
  ERROR_LABEL:     'skyledger-error',

  ALLOWED_EXT: ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff'],
  MIN_BYTES:   8 * 1024,      // skip tiny inline images (signatures/logos)
  MAX_THREADS_PER_RUN: 20,
  SCAN_DAYS:   3,             // only scan threads active in the last N days (Gmail quota saver)
};

/**
 * Drop URL of the attachment currently being sent, reported to the Inbox with it.
 * Unidash keeps it on the row so it can send that same file to WazzOCR again by
 * itself — how a duplicate bill is cleared and recreated without you re-emailing.
 */
var LAST_DROP_URL = '';

/** Entry point for the time trigger. */
function run() {
  ensureLabels_();
  var processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL);
  var errored   = GmailApp.getUserLabelByName(CONFIG.ERROR_LABEL);
  var props     = PropertiesService.getScriptProperties();

  // Only NEW invoice threads: exclude ones already tagged processed and bound to
  // the last SCAN_DAYS. Once a thread succeeds it gets the processed label and is
  // never re-read again, so idle runs return nothing and barely touch Gmail's
  // daily quota — the read cost tracks how many invoices actually arrive, not how
  // often we poll. (Re-testing the same invoice → send it as a NEW email.)
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
        if (props.getProperty(key)) return;            // this exact attachment already sent
        try {
          LAST_DROP_URL = '';                          // never report the previous file's URL
          sendToWazzOCR_(att, msg);
          props.setProperty(key, String(Date.now())); // record only on success
          anyProcessed = true;
          logInbox_(msg, att, 'sent', '');
        } catch (err) {
          anyFailed = true;                            // not recorded → retried next run
          Logger.log('Send failed for "%s": %s', att.getName(), err);
          logInbox_(msg, att, 'failed', String(err));
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
 * Deliver one attachment to WazzOCR:
 *   1. host the file on Unidash's file-drop (Wazzup can only send files by URL),
 *   2. your WhatsApp channel sends the file straight to WazzOCR.
 * WazzOCR then sees a real inbound invoice from your (allowed) number.
 * Throws on failure so the attachment is retried next run.
 */
function sendToWazzOCR_(att, msg) {
  var url = dropFile_(att);   // short-lived public URL Wazzup can fetch
  LAST_DROP_URL = url;        // reported to the Inbox so Unidash can re-send this file

  wazzupSend_('send (channel ' + CONFIG.CHANNEL_ID + ')', CONFIG.CHANNEL_API_KEY, {
    channelId:  CONFIG.CHANNEL_ID,
    chatType:   'whatsapp',
    chatId:     CONFIG.WAZZOCR_WHATSAPP,
    contentUri: url,
  });

  Logger.log('Sent "%s" to WazzOCR %s (msg %s)', att.getName(), CONFIG.WAZZOCR_WHATSAPP, msg.getId());
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
 * Log one attachment send to Unidash's Inbox (best-effort — a logging failure
 * must never break intake, so this swallows its own errors).
 *   status: 'sent' (handed to WazzOCR) or 'failed' (send threw).
 *   drop_url: which hosted copy it was, so Unidash can send it again itself.
 */
function logInbox_(msg, att, status, error) {
  if (!CONFIG.INBOX_URL) return;
  try {
    UrlFetchApp.fetch(CONFIG.INBOX_URL, {
      method: 'post',
      muteHttpExceptions: true,
      payload: {
        key:        CONFIG.DROP_KEY,
        event_at:   new Date().toISOString(),
        sender:     msg.getFrom(),
        subject:    msg.getSubject(),
        attachment: att.getName(),
        size:       String(att.getSize()),
        status:     status,
        error:      error ? String(error).slice(0, 500) : '',
        drop_url:   LAST_DROP_URL || '',
      },
    });
  } catch (e) {
    Logger.log('Inbox log failed: %s', e);
  }
}

/** Tell the Inbox the poller ran (liveness), even on minutes with no invoice. */
function pingHeartbeat_() {
  if (!CONFIG.INBOX_URL) return;
  try {
    UrlFetchApp.fetch(CONFIG.INBOX_URL, {
      method: 'post', muteHttpExceptions: true,
      payload: { key: CONFIG.DROP_KEY, heartbeat: '1' },
    });
  } catch (e) { /* best-effort */ }
}

/**
 * Run ONCE to point Wazzup's webhook at Unidash so WazzOCR's WhatsApp replies
 * (bill created / error) flow into the Inbox. Uses WEBHOOK_API_KEY — the account
 * of the channel that receives those replies. API-type integrations set their
 * webhook here rather than in the Wazzup UI. Safe to re-run.
 */
function setWebhook() {
  var res = UrlFetchApp.fetch('https://api.wazzup24.com/v3/webhooks', {
    method: 'patch',
    contentType: 'application/json',
    muteHttpExceptions: true,
    headers: { Authorization: 'Bearer ' + (CONFIG.WEBHOOK_API_KEY || CONFIG.CHANNEL_API_KEY) },
    payload: JSON.stringify({
      webhooksUri: CONFIG.WEBHOOK_URL + '?key=' + encodeURIComponent(CONFIG.DROP_KEY),
      subscriptions: { messagesAndStatuses: true, contactsAndDealsCreation: false, channelsUpdates: false, templateStatus: false },
    }),
  });
  Logger.log('setWebhook → HTTP %s %s', res.getResponseCode(), res.getContentText().slice(0, 300));
}

/**
 * Run ONCE after pasting/upgrading: record every attachment already in your
 * invoice mailbox as "done" WITHOUT sending, so the switch to per-attachment
 * dedupe doesn't re-forward your history. New emails after this flow normally.
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
    thread.addLabel(processed);   // exclude it from future scans (quota saver)
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

/** Poll every minute. everyMinutes only accepts 1, 5, 10, 15 or 30, so 1 is the
 *  fastest available. Idle runs now skip already-processed threads (~6 Gmail
 *  reads each), so the read quota is no longer the bottleneck; the binding limit
 *  is the 90-min/day trigger runtime, which short idle runs stay under. Run once. */
function installTrigger() {
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'run') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('run').timeBased().everyMinutes(1).create();
  Logger.log('Trigger installed: run() every 1 minute.');
}
