# Module 1 — Gmail supplier-invoice intake → WazzOCR

A Google Apps Script that captures supplier-invoice emails and POSTs each
PDF/image attachment **straight into WazzOCR's pipeline** over HTTPS — **no
phone, no WhatsApp send**. WazzOCR OCRs the document and creates the draft Xero
bill in the routed account's connected organisation.

```
Gmail (filter → label)  →  Apps Script (this)  →  POST /api/whatsapp/process-file  →  WazzOCR
                                                                                        OCR (Gemini) + create draft Xero bill
```

## Why no phone is needed
WazzOCR's `/api/whatsapp/process-file` endpoint has **no login gate**. It decides
which account to process for from two fields in the POST body:

| field | meaning |
|---|---|
| `channelId` | the shared trial channel id (WazzOCR → Connections) |
| `chatId` | a phone number listed under that account's **Allowed phone numbers** |

The phone is only a **routing key** — nothing is sent to WhatsApp. (Wazzup/WhatsApp
is a *separate* intake door; email uses this HTTP door instead.)

## Prerequisites in WazzOCR (account "Signum Aviation")
1. **Connections → connect the Signum Xero org.** Connect **exactly one** org, so
   the AI never has to ask which org to bill (that would create a "pending/picker"
   that normally needs a WhatsApp reply).
2. **Connections → Allowed phone numbers → add a number** (a placeholder is fine,
   e.g. `60000000018`). Use that same number as `ROUTING_PHONE`.
3. Note the **trial channel id** → `CHANNEL_ID`.

## Setup (Gmail side)
1. **Gmail filter** → apply the label in `CONFIG.SOURCE_LABEL`
   (`supplier-invoices`) to supplier-invoice emails.
   The filter should match *every* invoice email, so give it no narrowing terms —
   no `has:attachment` (emails with nothing to send belong in the Inbox too) and no
   subject match (a supplier who does not write "Invoice" would be dropped in silence).
   Gmail insists on at least one condition, so use **To: `<the mailbox address>`** —
   it matches everything delivered there. (A `Doesn't have:` some-nonsense-string
   condition works as a catch-all too, but reads like a trick.)
   **Or skip filters entirely:** set `CONFIG.SOURCE_LABEL` to `''` and the whole
   inbox is scanned. That suits a mailbox nothing but invoices arrives at. The cost
   is the override: with no source label there is no way to hand one specific email
   to the poller, and no filter to switch off to stop it.
2. **script.google.com** → new project bound to that Gmail account → paste
   `Code.gs` → fill `CONFIG` (`CHANNEL_ID`, `ROUTING_PHONE`).
3. Run **`setup`** once (grant permissions, create labels).
4. Run **`installTrigger`** once (polls every 5 minutes). Use **`run`** to test.

## Behaviour
- Forwards every `.pdf` attachment, whatever its size (`CONFIG.ALLOWED_EXT`; the External API is PDF-only). No size floor.
- Scans **every** email in scope (the source label, or the whole inbox when it is blank), not just ones with an attachment. An email carrying no PDF is
  reported to the Inbox once (keyed by message id) as **Not sent**, with the reason — "no attachment", or
  the names of the files it did carry. Its thread is left unlabelled so a later reply with a real PDF is
  still picked up. Inline images (signature logos) are not counted as attachments.
- On success → tags the thread `skyledger-processed` (never sent twice).
- On failure → tags `skyledger-error` and **doesn't** mark processed, so it
  retries next run once fixed. A `status:"ignored"` response means `ROUTING_PHONE`
  isn't in the account's Allowed phone numbers.

## What this does and doesn't cover (Modules 2 & 3)
WazzOCR handles **Module 2 (OCR)** and most of **Module 3 (create the Xero bill)**
— supplier, amount, currency, COA account code, and the draft bill in Signum's Xero.

What WazzOCR does **not** do is the Signum-specific **LEON/PO cross-reference** —
matching the invoice to a trip in the Skyledger master list and assigning the cost
to the client for recharge. That's a Skyledger enrichment step (see the main repo
`ARCHITECTURE.md`) to be added on top; it isn't part of this email-intake script.
For the POC, WazzOCR creating the draft supplier bill is the end of this path.
