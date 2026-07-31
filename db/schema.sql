-- Signum LEON → Xero PO module (Module 4). SQLite schema.
-- Mirrors Starship's oauth_tokens + app_settings; adds a leon_trips table.

-- Xero OAuth tokens. One connected tenant at a time (Starship pattern):
-- the access token lives ~30 min and is refreshed transparently; the rotated
-- refresh token is persisted. Reconnecting to a different org replaces the row.
CREATE TABLE IF NOT EXISTS oauth_tokens (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  provider       TEXT NOT NULL DEFAULT 'xero',
  tenant_id      TEXT,
  tenant_name    TEXT,
  access_token   TEXT NOT NULL,
  refresh_token  TEXT NOT NULL,
  expires_at     TEXT NOT NULL,
  scope          TEXT,
  updated_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (provider, tenant_id)
);

-- Key/value settings overlay (Xero creds, currency map, admin password hash).
CREATE TABLE IF NOT EXISTS app_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- One row per LEON trip. Keyed by (tenant_id, trip_number) so the SAME trip in
-- two different Xero orgs is tracked independently, and a tenant switch (handled
-- in XeroOAuth::store) clears xero_po_id so the trip re-creates in the new org.
CREATE TABLE IF NOT EXISTS leon_trips (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id      TEXT,               -- Xero tenant this PO belongs to (NULL until pushed)
  entity         TEXT,               -- 'inc' | 'ltd' (from the source file), informational
  trip_number    TEXT NOT NULL,
  client_name    TEXT,
  aircraft       TEXT,
  route          TEXT,
  start_date     TEXT,               -- ISO yyyy-mm-dd
  end_date       TEXT,               -- ISO yyyy-mm-dd
  flights_count  INTEGER,
  currency       TEXT,               -- resolved currency code, or NULL = org base
  xero_po_id     TEXT,               -- set once the draft PO is created
  xero_po_number TEXT,
  xero_synced_at TEXT,
  xero_last_error TEXT,
  source_file    TEXT,
  created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (trip_number, entity)
);

CREATE INDEX IF NOT EXISTS idx_leon_trips_tenant ON leon_trips (tenant_id);

-- Module 3: draft supplier bills pulled from Xero, matched to a trip in the
-- master list, and (on confirm) tagged with the trip number. Keyed by
-- (tenant, xero invoice) so the same bill in two orgs is tracked separately.
CREATE TABLE IF NOT EXISTS xero_bills (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id           TEXT,
  xero_invoice_id     TEXT NOT NULL,
  invoice_number      TEXT,
  supplier            TEXT,
  bill_date           TEXT,               -- ISO yyyy-mm-dd
  reference           TEXT,
  total               REAL,               -- amount in the bill's own currency
  currency            TEXT,               -- the bill's currency code
  currency_rate       REAL,               -- Xero rate: base units per 1 bill-currency unit
  base_currency       TEXT,               -- org base currency (e.g. MYR)
  base_total          REAL,               -- total converted into base currency
  description         TEXT,               -- concatenated line descriptions
  ex_airport          TEXT,               -- extracted ICAO
  ex_date             TEXT,               -- extracted service date (ISO)
  ex_tail             TEXT,               -- extracted aircraft tail
  match_status        TEXT,               -- matched | ambiguous | review | tagged
  matched_trip_id     INTEGER,
  matched_trip_number TEXT,
  matched_client      TEXT,
  tagged_at           TEXT,
  xero_last_error     TEXT,
  created_at          TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at          TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id, xero_invoice_id)
);
CREATE INDEX IF NOT EXISTS idx_xero_bills_status ON xero_bills (match_status);

-- Module 5: client sales invoices raised from a trip's tagged bills.
-- One row per trip invoiced (keyed by tenant + trip).
CREATE TABLE IF NOT EXISTS trip_invoices (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id           TEXT,
  trip_id             INTEGER,
  trip_number         TEXT,
  client              TEXT,
  currency            TEXT,
  subtotal            REAL,
  admin               REAL,
  support             REAL,
  total               REAL,
  xero_invoice_id     TEXT,
  xero_invoice_number TEXT,
  invoiced_at         TEXT,
  xero_last_error     TEXT,
  created_at          TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id, trip_id)
);
