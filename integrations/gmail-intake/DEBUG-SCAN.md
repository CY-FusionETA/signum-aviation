# Temporary: `debugScan` — why is an invoice being skipped?

Throwaway diagnostic. Paste at the **bottom** of the Gmail-intake Apps Script
project (the one holding `Code.gs`), save, then run `debugScan` and read the
execution log. Delete the file — and this function — when the question is answered.

It re-runs the same Gmail search `run()` uses, then prints every attachment it
finds with the three reasons `run()` can skip one silently.

```javascript
function debugScan() {
  var props = PropertiesService.getScriptProperties();
  var q = 'label:' + CONFIG.SOURCE_LABEL + ' -label:' + CONFIG.PROCESSED_LABEL +
          ' has:attachment newer_than:' + CONFIG.SCAN_DAYS + 'd';
  GmailApp.search(q, 0, 20).forEach(function (t) {
    t.getMessages().forEach(function (m) {
      Logger.log('MSG %s | %s | %s', m.getDate(), m.getFrom(), m.getSubject());
      var atts = m.getAttachments();
      if (!atts.length) Logger.log('  (no attachments on this message)');
      atts.forEach(function (a) {
        var key = attKey_(m, a);
        Logger.log('  ATT "%s" %s bytes | forwardable=%s | done=%s | waitUntil=%s',
          a.getName(), a.getSize(), isForwardable_(a),
          !!props.getProperty(key), props.getProperty(key + ':after') || '-');
      });
    });
  });
}
```

## Reading the output

| Line says | Meaning | Fix |
|---|---|---|
| `forwardable=false` | Not a `.pdf` by filename, or under 8 KB (`CONFIG.ALLOWED_EXT` / `MIN_BYTES`) | Re-send as a real PDF attachment |
| `done=true` | Already recorded as processed in **this project's** Script Properties | Run `resetProcessed`, then `run` |
| `waitUntil=<number>` | Held by the blocked-duplicate backoff (Xero could not clear the old bill yet) | Wait for that time, or run `resetProcessed` |
| no `MSG` line at all | The search found nothing — this project is signed in to the wrong Google account | Open the project from the invoice mailbox account |

Script Properties are **per project**, so `done=` / `waitUntil=` only reflect the
project you run this in — not the old copy of the script.
