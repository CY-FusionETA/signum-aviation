# Module 1 — Gmail supplier-invoice intake

A Google Apps Script that captures supplier-invoice emails and forwards each
PDF/image attachment to a webhook over HTTPS — **no phone, no WhatsApp**. Email
is its own front door into the OCR → bill pipeline; Wazzup/WhatsApp stays a
separate (optional) door.

```
Gmail (filter → label)  →  Apps Script (this)  →  POST attachment  →  WazzOCR / Skyledger relay
                                                                        → OCR/structure (Module 2)
                                                                        → match LEON master list + create Xero bill (Module 3)
```

## Why not push it through Wazzup/WhatsApp?
Wazzup is the *WhatsApp transport* (inbound message → webhook); WazzOCR is the
*OCR brain*. The brain doesn't need WhatsApp. Sending email attachments back out
through the Wazzup API just to trigger the WhatsApp webhook means hosting the
file publicly, paying per message, and faking an inbound — the wrong tool. Email
attachments should go straight to the processor over HTTP, which is what this does.

## Setup
1. **Gmail filter** — route supplier invoices to a label (default `supplier-invoices`):
   Gmail → Settings → Filters → create a filter (e.g. `has:attachment` from your
   handlers, or forwarded to a dedicated address) → "Apply label: supplier-invoices".
2. **Apps Script** — go to script.google.com, new project bound to that Gmail
   account, paste `Code.gs`, and fill in `CONFIG`:
   - `INGEST_URL` — your WazzOCR POC intake URL (or a Skyledger relay endpoint).
   - `INGEST_TOKEN` — a long random string the endpoint checks (`X-Ingest-Token`).
3. Run **`setup`** once (grant permissions; creates the labels).
4. Run **`installTrigger`** once (polls every 5 minutes).
5. Test with **`run`** by hand and watch the execution log.

## The webhook contract (what the endpoint receives)
`multipart/form-data`, header `X-Ingest-Token: <token>`:

| field | value |
|---|---|
| `file` | the attachment (binary) |
| `source` | `gmail` |
| `message_id` | Gmail message id — use for idempotency downstream |
| `from` / `subject` / `email_date` | email metadata |
| `filename` | attachment filename |

Return any `2xx` for success. On non-2xx the script tags the thread
`skyledger-error` and retries next run; on success it tags `skyledger-processed`
so it's never sent twice.

## Notes
- Only forwards `pdf/png/jpg/jpeg/webp/tif` over 8 KB (skips signature/logo images).
- Dedup is thread-label based (fine for the usual one-invoice-per-thread); the
  `message_id` is forwarded so the downstream can also dedup exactly.
