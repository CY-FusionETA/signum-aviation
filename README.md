# Skyledger — Module 4: LEON → Xero PO

Imports a LEON **Flight Count** export (**CSV, XLSX or PDF** — one or many files at once)
into a persistent **trip master list**, lets you pick trips, and creates one **DRAFT
Purchase Order per trip** in the connected Xero organisation. That master list is the shared reference Modules 3 and 5 use
to reconcile supplier invoices and client invoices against.

Built on the **Starship** design: plain PHP 8 + SQLite, no framework, no build step. The
Xero layer (OAuth2, token storage, org reconnection, interface/stub seam) is ported from
Starship so it behaves identically — **reconnect to a new organisation any time and each
trip re-creates in that org**.

---

## What it does

1. **Import** LEON Flight Count files — **CSV, XLSX or PDF, one or many at once** — into the
   trip master list. Header-driven, so it copes with the Inc and Ltd exports having their
   columns in a different order. Skips the title/date-range preamble and the `∑` total row;
   handles blank clients and non-numeric trip numbers (`KZ2OS4`, `07-2026/76`); converts
   `dd-mm-yyyy` dates to ISO. XLSX is read with a dependency-free reader (Excel date serials
   converted automatically); PDF uses `pdftotext -layout` sliced at the header labels'
   character offsets — all three formats parse identically. With multiple files, each file's
   **entity is auto-detected from its name** (or force Inc/Ltd for the whole batch).
2. **Pick trips** in the UI (checkboxes; rows already having a PO in the connected org are
   marked and locked).
3. **Create a DRAFT Purchase Order** per selected trip:
   - **Contact** = the trip's client (looked up by exact name, created if missing).
   - **PurchaseOrderNumber** = trip number.  **Date/DeliveryDate** = trip start/end.
   - **Reference** = aircraft reg + route.  **One description-only line** with the full
     trip metadata (no amounts — this is a trip anchor; costs attach later as bills).
   - **CurrencyCode** = per-entity map (`currency.inc`/`currency.ltd`), or omitted so Xero
     uses the org base currency.
4. **Idempotent** — a trip already pushed to the *connected* org is skipped. Reconnecting
   to a different org clears those ids so the trips re-create there.

> **One trip = one PO.** A LEON trip already bundles its multiple flights/legs (the
> "Flights count" column), and a supplier invoice covers the whole trip — so the trip is
> the PO grain. Different trips are never merged (they can be different clients, invoiced
> separately).

> **Purchase Orders note.** The Xero MCP has no PO endpoint, so this module calls Xero's
> REST API directly (`POST /api.xro/2.0/PurchaseOrders`), exactly as Starship does.

---

## Requirements

PHP **8.1+** with `pdo_sqlite`, `curl`, `mbstring`, and `zip` + `xml` (for XLSX import).
`pdftotext` (poppler-utils) for PDF import. A web server for the OAuth redirect, or the
built-in PHP server for local use.

## Quick start (local)

```bash
cp config/config.sample.php config/config.php
#   edit config/config.php:
#   - app.base_url             e.g. http://localhost:8000  (no trailing slash)
#   - app.admin_password_hash   php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"

php db/migrate.php                                  # create storage/skyledger.sqlite
php -S localhost:8000 -t public public/router.php   # open http://localhost:8000
```

Sign in → **Xero settings**: paste Client ID/Secret + Redirect URI
(`<base_url>/xero/callback`, registered verbatim in your Xero app) → **Connect to Xero** →
**Import** a LEON CSV/PDF → tick trips → **Create draft POs for selected**.

### Reconnecting to a new org
**Reconnect / switch org** and log into the other organisation. Trips already pushed to the
old org have their `xero_po_id` cleared, so they re-create in the newly connected org.

## Headless / cron

```bash
php cli/process.php --file=path/to/leon.(csv|pdf) --entity=inc          # inc | ltd
php cli/process.php --file=path/to/leon.(csv|pdf) --entity=ltd --dry-run
```

With no live Xero connection the run is always a dry run (the stub client prints the exact
`PurchaseOrders` JSON that *would* be sent).

## Deploy (DigitalOcean)

See [`deploy/DEPLOY.md`](deploy/DEPLOY.md). nginx + PHP-FPM with the web root at `public/`
(config/, src/, db/, storage/ sit above it and are never HTTP-reachable). `deploy/setup.sh`
provisions an Ubuntu droplet in one shot. The app serves at the **domain root** — no path
segment. Architecture + file map: [`ARCHITECTURE.md`](ARCHITECTURE.md).

## Tests

```bash
php tests/run_tests.php     # 38 assertions: CSV + PDF parsing, PO payload, import,
                            # dry-run, tenant switch, idempotency
```

`tests/fixtures/generate_pdfs.py` regenerates the LEON-style PDF fixtures from the CSVs.

## Notes & follow-ups

- **PDF parsing** is validated against LEON-layout PDFs generated from the real data; when
  you have an actual LEON PDF export, run it through once to confirm the column offsets
  line up (they will if it's the standard Flight Count layout).
- The PO **contact is the client** so the trip↔client link is visible for Modules 3/5.
- Currencies are omitted by default so a fresh demo org isn't rejected; set
  `currency.inc`/`currency.ltd` once USD/GBP are enabled in the target org.
