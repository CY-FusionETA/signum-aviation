# Skyledger

Finance automation for **Signum Aviation** — turning the LEON flight-ops trip list
and supplier invoices into Xero purchase orders, bills and client invoices, across
both entities (**Signum Aviation Inc**, USD · **Signum Aviation Ltd**, UK/VAT).

Built on the **Starship** design: plain PHP 8 + SQLite, no framework, no build step,
with a reusable Xero OAuth layer that can reconnect to any organisation.

## Modules

| # | Module | Status | Notes |
|---|--------|--------|-------|
| 1 | Email → AI | planned | Gmail forwards every email to the AI processor. |
| 2 | AI email processor | planned | OCR + structure email/attachments (reuses **WazzOCR**). |
| 3 | Create Xero bills | planned | Match OCR'd invoice to a trip in the master list, create the Xero bill (reuses **Starship**). |
| 4 | **LEON → PO** | **built** | [`module4-leon-po/`](module4-leon-po/) — import LEON (CSV/PDF) into the trip **master list**, create a draft Xero PO per trip. |
| 5 | AI → client invoice | planned | Raise the client sales invoice from the PO / LEON data. |

The **trip master list** built by Module 4 is the shared reference (the "catalogue"):
Module 2/3 match an incoming supplier invoice to a trip in it, then reuse Module 4's
create-PO action to raise the PO before booking the bill.

## The manual process being automated

**AP (payments):** a supplier invoice arrives by email → match the trip in LEON by
aircraft + date + airport → read the trip number + billing info → create a Xero **bill**
against the handler, assigned to the client → log in the finance tracker → move the trip
to "Ready" once every leg is in.

**AR (invoicing):** for a "Ready" trip → pull the LEON finance brief → raise a client
**sales invoice**: recharge supplier costs (×1.02 FX buffer), trip-support fee
(CAA member 550 / non-member 650), 11% admin charge, permits/customs → move to "Invoiced".

See [`module4-leon-po/README.md`](module4-leon-po/README.md) for the built module.
