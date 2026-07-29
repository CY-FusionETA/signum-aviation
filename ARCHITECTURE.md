# Skyledger — Architecture

Plain **PHP 8 + SQLite**, no framework, no build step (Starship design). One deployable app
today: **`module4-leon-po/`** (LEON → Xero PO). Other modules will be added as sibling
folders under this repo.

## Request flow (Module 4)

```
Browser ──HTTP──► nginx (root = module4-leon-po/public)
                     │  try_files → index.php  (front controller)
                     ▼
      public/index.php ── routes ─┬─ /login /logout           → auth (session + password)
                                  ├─ /settings                → Settings (Xero creds, currency)
                                  ├─ /xero/connect|callback   → XeroOAuth (OAuth2, org connect)
                                  ├─ /import                  → LeonProcessor::import()
                                  │        └─ LeonParser (CSV via fgetcsv | PDF via pdftotext)
                                  │        └─ TripRepo::upsert()  → leon_trips (master list)
                                  └─ /create-pos              → LeonProcessor::createPosForIds()
                                           └─ XeroClientFactory → XeroApiClient | XeroStubClient
                                                    └─ POST /api.xro/2.0/PurchaseOrders (DRAFT)
                                           └─ TripRepo::markSynced()

SQLite (storage/skyledger.sqlite): oauth_tokens · app_settings · leon_trips
```

The master list (`leon_trips`) is the shared reference: Modules 2/3 will match an OCR'd
supplier invoice to a trip in it, then reuse `LeonProcessor::createPosForIds()` to raise the
PO before booking the bill.

## File map (`module4-leon-po/`)

### Entry points
| File | Role |
|---|---|
| `public/index.php` | Front controller — auth, routing, all UI (import, master list, create POs, settings). |
| `public/router.php` | Dev-only router for `php -S` (nginx uses `try_files` instead). |
| `public/.htaccess` | Apache fallback routing (nginx uses the vhost). |
| `cli/process.php` | Headless runner: import a LEON file + create draft POs for all trips. |
| `db/migrate.php` | Create the SQLite DB + tables (safe to re-run). |

### Core (`src/`)
| File | Role |
|---|---|
| `bootstrap.php` | Loads config, `App\` autoloader, timezone, error logging, `cfg()`/`e()` helpers. |
| `Db.php` | PDO/SQLite singleton (WAL) + `q/one/all/insert` helpers. |
| `Settings.php` | `app_settings` key/value overlay on top of config.php (DB value wins). |

### Xero seam (`src/Service/Xero/`) — ported from Starship
| File | Role |
|---|---|
| `XeroOAuth.php` | OAuth2 auth-code + refresh; token storage; **org reconnection** (a tenant switch clears every trip's `xero_po_id` so they re-create in the new org). |
| `XeroClientInterface.php` | The seam: `createPurchaseOrder(trip)`. |
| `XeroApiClient.php` | Live client — resolves the client→Xero ContactID, builds the `PurchaseOrders` payload, POSTs it as DRAFT. `buildOrderPayload()` is pure (shared with the stub). |
| `XeroStubClient.php` | Dry-run client used when Xero isn't connected — returns the exact payload it *would* send. |
| `XeroClientFactory.php` | Returns the live client when enabled + configured + connected, else the stub. |

### LEON pipeline (`src/Service/Leon/`)
| File | Role |
|---|---|
| `LeonParser.php` | Header-driven parse of the Flight Count export. CSV via `fgetcsv`; PDF via `pdftotext -layout` sliced at the header labels' character offsets. Handles Inc/Ltd column orders, preamble + `∑` row, blank clients, alpha/slash trip numbers, dd-mm-yyyy dates. |
| `LeonProcessor.php` | Orchestration: `import()` builds the master list; `createPosForIds()` creates one DRAFT PO per selected trip (idempotent per tenant); `process()` = both, for the CLI. |

### Persistence (`src/Repo/`)
| File | Role |
|---|---|
| `TripRepo.php` | `leon_trips` CRUD: `upsert` (keyed by trip_number+entity), `markSynced/markError`, `all`, `hasPoInTenant`. |

### Data + config
| File | Role |
|---|---|
| `db/schema.sql` | `oauth_tokens`, `app_settings`, `leon_trips`. |
| `config/config.sample.php` | Template → copy to `config/config.php` (git-ignored). |
| `storage/` | SQLite DB + uploads (git-ignored; above the web root, never HTTP-reachable). |

### Deploy + tests
| File | Role |
|---|---|
| `deploy/nginx.conf.example` | nginx + PHP-FPM vhost (root = `public/`). |
| `deploy/setup.sh` | One-shot DigitalOcean provisioning (PHP, poppler, config, migrate, nginx). |
| `deploy/DEPLOY.md` | Step-by-step DigitalOcean guide + Xero connect. |
| `tests/run_tests.php` | 38 assertions (CSV+PDF parse, PO payload, import, dry-run, tenant switch, idempotency). |
| `tests/fixtures/` | LEON CSV + PDF fixtures (both entities) + PDF generator. |

## Security model
- `public/` is the only web-exposed directory; `config/`, `src/`, `db/`, `storage/` sit
  above it and are unreachable over HTTP.
- The UI is gated by a single admin password (`app.admin_password_hash`); OAuth and
  PO creation are never public.
- Xero tokens live in SQLite (`oauth_tokens`), not in the repo. `config/config.php` and
  `storage/` are git-ignored.
