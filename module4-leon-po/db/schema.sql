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
