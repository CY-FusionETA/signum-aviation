/**
 * Skyledger — Module 1: Gmail supplier-invoice intake (Google Apps Script)
 * ---------------------------------------------------------------------------
 * Runs on a time trigger, finds supplier-invoice emails, and POSTs each PDF/
 * image attachment to a webhook (WazzOCR, or a Skyledger relay) over HTTPS.
 * No phone, no WhatsApp — the attachment flows straight in.
 *
 * SETUP (once):
 *  1. In Gmail, make a filter that labels supplier-invoice emails with the
 *     label in CONFIG.SOURCE_LABEL (e.g. "supplier-invoices"). Point whatever
 *     inbox invoices arrive at → that label.
 *  2. Paste this file into script.google.com (new project, bound to that
 *     Gmail account). Fill in CONFIG below.
 *  3. Run `setup` once (grant permissions; it creates the labels).
 *  4. Run `installTrigger` once to poll every few minutes.
 *  5. `run` is what the trigger calls; you can also run it by hand to test.
 *
 * The webhook receives multipart/form-data:
 *   file        the attachment (binary)
 *   source      "gmail"
 *   message_id  Gmail message id (use for idempotency downstream)
 *   from        sender
 *   subject     email subject
 *   email_date  ISO 8601
 *   filename    attachment filename
 * with header  X-Ingest-Token: <CONFIG.INGEST_TOKEN>
 */

var CONFIG = {
  // Where attachments are POSTed. Point at your WazzOCR POC intake URL,
  // or a Skyledger relay endpoint. HTTPS only.
  INGEST_URL:   'https://REPLACE_ME/ingest',
  // Shared secret sent as X-Ingest-Token so the endpoint can reject strangers.
  INGEST_TOKEN: 'REPLACE_WITH_A_LONG_RANDOM_STRING',

  // Gmail label your filter puts invoices under (create the filter first).
  SOURCE_LABEL:    'supplier-invoices',
  PROCESSED_LABEL: 'skyledger-processed',
  ERROR_LABEL:     'skyledger-error',

  // Only forward real documents.
  ALLOWED_EXT: ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff'],
  MIN_BYTES:   8 * 1024,     // skip tiny inline images (signatures/logos)
  MAX_THREADS_PER_RUN: 20,   // safety cap per run
};

/** Entry point for the time trigger. */
function run() {
  var processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL);
  var errored   = GmailApp.getUserLabelByName(CONFIG.ERROR_LABEL);
  if (!processed || !errored) { setup(); processed = GmailApp.getUserLabelByName(CONFIG.PROCESSED_LABEL); errored = GmailApp.getUserLabelByName(CONFIG.ERROR_LABEL); }

  var query = 'label:' + CONFIG.SOURCE_LABEL +
              ' -label:' + CONFIG.PROCESSED_LABEL +
              ' -label:' + CONFIG.ERROR_LABEL +
              ' has:attachment';
  var threads = GmailApp.search(query, 0, CONFIG.MAX_THREADS_PER_RUN);
  Logger.log('Found %s thread(s) to process', threads.length);

  threads.forEach(function (thread) {
    var sentAny = false, failed = false;
    thread.getMessages().forEach(function (msg) {
      msg.getAttachments().forEach(function (att) {
        if (!isForwardable_(att)) return;
        try {
          postAttachment_(att, msg);
          sentAny = true;
        } catch (err) {
          failed = true;
          Logger.log('POST failed for "%s": %s', att.getName(), err);
        }
      });
    });
    // Label the thread so it isn't reprocessed. On failure, tag it for review
    // and DON'T mark processed, so a fixed endpoint retries next run.
    if (failed)       thread.addLabel(errored);
    else if (sentAny) thread.addLabel(processed);
    else              thread.addLabel(processed); // no forwardable attachments — done
  });
}

/** Should this attachment be forwarded? */
function isForwardable_(att) {
  if (att.getSize() < CONFIG.MIN_BYTES) return false;
  var name = (att.getName() || '').toLowerCase();
  var ext  = name.indexOf('.') >= 0 ? name.split('.').pop() : '';
  return CONFIG.ALLOWED_EXT.indexOf(ext) >= 0;
}

/** POST one attachment + email metadata to the webhook. Throws on non-2xx. */
function postAttachment_(att, msg) {
  var res = UrlFetchApp.fetch(CONFIG.INGEST_URL, {
    method: 'post',
    muteHttpExceptions: true,
    headers: { 'X-Ingest-Token': CONFIG.INGEST_TOKEN },
    payload: {
      file:       att.copyBlob(),
      source:     'gmail',
      message_id: msg.getId(),
      from:       msg.getFrom(),
      subject:    msg.getSubject(),
      email_date: msg.getDate().toISOString(),
      filename:   att.getName(),
    },
  });
  var code = res.getResponseCode();
  if (code < 200 || code >= 300) {
    throw new Error('HTTP ' + code + ' — ' + res.getContentText().slice(0, 300));
  }
  Logger.log('Sent "%s" (msg %s) → HTTP %s', att.getName(), msg.getId(), code);
}

/** Create the labels (run once, grants permissions). */
function setup() {
  [CONFIG.SOURCE_LABEL, CONFIG.PROCESSED_LABEL, CONFIG.ERROR_LABEL].forEach(function (n) {
    if (!GmailApp.getUserLabelByName(n)) GmailApp.createLabel(n);
  });
  Logger.log('Labels ready. Make a Gmail filter that applies "%s" to invoice emails.', CONFIG.SOURCE_LABEL);
}

/** Poll every 5 minutes. Run once. */
function installTrigger() {
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'run') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('run').timeBased().everyMinutes(5).create();
  Logger.log('Trigger installed: run() every 5 minutes.');
}
