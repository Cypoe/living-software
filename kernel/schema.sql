-- Living Software kernel schema v2
-- WAL + foreign keys are set by the boot sequence, not here.
-- This file must be idempotent (all CREATE ... IF NOT EXISTS).

-- ─── Core triad ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS entities (
  id            TEXT PRIMARY KEY,
  type          TEXT NOT NULL,
  owner         TEXT,
  label         TEXT,
  body_ref      TEXT,          -- carrier id of primary body
  schema_ref    TEXT,          -- schemas.id this entity conforms to
  status        TEXT NOT NULL DEFAULT 'active', -- active | archived | deleted
  visibility    TEXT NOT NULL DEFAULT 'private', -- private | public | shared
  metadata_json TEXT NOT NULL DEFAULT '{}',
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_entities_type   ON entities(type);
CREATE INDEX IF NOT EXISTS idx_entities_owner  ON entities(owner);
CREATE INDEX IF NOT EXISTS idx_entities_status ON entities(status);

CREATE TABLE IF NOT EXISTS schemas (
  id              TEXT PRIMARY KEY,
  name            TEXT NOT NULL UNIQUE,
  version         TEXT NOT NULL DEFAULT '1',
  definition_json TEXT NOT NULL DEFAULT '{}',
  created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE IF NOT EXISTS carriers (
  id            TEXT PRIMARY KEY,
  entity_id     TEXT NOT NULL,
  carrier_type  TEXT NOT NULL, -- file | db_row | url | blob | php | html | json | did | oidc_token
  locator       TEXT,          -- path, URL, DID string, or NULL for inline
  content       TEXT,          -- inline body (for small carriers)
  content_hash  TEXT,
  mime_type     TEXT,
  byte_size     INTEGER,
  metadata_json TEXT NOT NULL DEFAULT '{}',
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  FOREIGN KEY(entity_id) REFERENCES entities(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_carriers_entity    ON carriers(entity_id);
CREATE INDEX IF NOT EXISTS idx_carriers_type      ON carriers(carrier_type);

-- ─── Capabilities ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS capabilities (
  id            TEXT PRIMARY KEY,
  label         TEXT NOT NULL,
  schema_in     TEXT NOT NULL,
  schema_out    TEXT NOT NULL,
  impl_type     TEXT NOT NULL DEFAULT 'php', -- php | wasm | llm | remote
  impl_ref      TEXT NOT NULL,               -- capabilities/{id}.php or URL
  contract_json TEXT NOT NULL DEFAULT '{}',
  is_enabled    INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

-- Application log: every capability invocation is a record
CREATE TABLE IF NOT EXISTS applications (
  id            TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(8)))),
  capability_id TEXT NOT NULL,
  input_ref     TEXT,   -- entity id of input record
  output_ref    TEXT,   -- entity id of output record
  status        TEXT NOT NULL DEFAULT 'pending', -- pending | ok | error
  result_json   TEXT NOT NULL DEFAULT '{}',
  ancestry_ref  TEXT,   -- parent application id
  duration_ms   REAL,
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  FOREIGN KEY(capability_id) REFERENCES capabilities(id)
);

-- ─── Transform chains ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS transform_chains (
  id            TEXT PRIMARY KEY,
  label         TEXT NOT NULL,
  steps_json    TEXT NOT NULL DEFAULT '[]', -- [{"op":"select","args":{...}}, ...]
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

-- ─── Materialized views ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS materialized_views (
  id              TEXT PRIMARY KEY,
  label           TEXT NOT NULL,
  source_chain_id TEXT,         -- transform_chains.id
  body_type       TEXT NOT NULL DEFAULT 'json', -- json | html | text
  body            TEXT,         -- rendered output
  invalidated     INTEGER NOT NULL DEFAULT 1,   -- 1 = needs recompute
  last_computed   TEXT,
  entity_refs     TEXT NOT NULL DEFAULT '[]',   -- JSON array of entity ids used
  created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

-- ─── Identity / DID store ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS identities (
  id            TEXT PRIMARY KEY,   -- internal UUID
  did           TEXT UNIQUE,        -- did:key:z... or did:web:...
  did_method    TEXT NOT NULL DEFAULT 'key', -- key | web | peer
  did_doc_json  TEXT NOT NULL DEFAULT '{}',
  owner_ref     TEXT,               -- entities.id of owning user entity
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  rotated_at    TEXT
);

-- ─── Runtime state ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS runtime_state (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

-- ─── Full-text search (FTS5 over entity label + metadata) ──────────────────

CREATE VIRTUAL TABLE IF NOT EXISTS entities_fts USING fts5(
  id UNINDEXED,
  label,
  type UNINDEXED,
  metadata_json,
  content='entities',
  content_rowid='rowid'
);

CREATE TRIGGER IF NOT EXISTS entities_fts_insert AFTER INSERT ON entities BEGIN
  INSERT INTO entities_fts(rowid, id, label, type, metadata_json)
  VALUES (new.rowid, new.id, new.label, new.type, new.metadata_json);
END;

CREATE TRIGGER IF NOT EXISTS entities_fts_update AFTER UPDATE ON entities BEGIN
  INSERT INTO entities_fts(entities_fts, rowid, id, label, type, metadata_json)
  VALUES ('delete', old.rowid, old.id, old.label, old.type, old.metadata_json);
  INSERT INTO entities_fts(rowid, id, label, type, metadata_json)
  VALUES (new.rowid, new.id, new.label, new.type, new.metadata_json);
END;

CREATE TRIGGER IF NOT EXISTS entities_fts_delete AFTER DELETE ON entities BEGIN
  INSERT INTO entities_fts(entities_fts, rowid, id, label, type, metadata_json)
  VALUES ('delete', old.rowid, old.id, old.label, old.type, old.metadata_json);
END;

-- ─── Seed runtime defaults (idempotent) ────────────────────────────────────

INSERT OR IGNORE INTO runtime_state(key, value) VALUES
  ('schema_version',  '2'),
  ('adopted_commit',  ''),
  ('setup_complete',  '0'),
  ('owner_token',     ''),
  ('last_heartbeat',  ''),
  ('last_deploy',     ''),
  ('deploy_method',   'git');
