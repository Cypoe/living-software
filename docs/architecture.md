# Living Software — Architecture

## The One Primitive: Records

Every concept in the system — a task, a deployed page, a CI rule, a backup
schedule, a user’s theory of mind — is a row in the `entities` table. The
table has five fields: `id`, `type`, `owner`, `body_ref`, `metadata_json`.
That is the entire data model.

The `schemas` table describes the shape of entity types. The `carriers` table
describes where an entity’s body lives (file, blob, URL, DB row). These three
tables form the **kernel quine**: the system can represent its own schema using
itself.

## Five Layers

```
04_ontology      ─ user records, templates, views          [instance-local, never spawned]
03_capabilities  ─ deterministic transforms (webhook_out, gossip_merge, …)
02_surface       ─ HTTP router + protocol adapters (index.php, cron.php)
01_protocol      ─ record algebra: select/filter/join/transform/project/render
00_kernel        ─ three-table quine + runtime_state + applications log
```

Rules:
- A layer calls only downward.
- No layer mutates a lower layer’s schema at runtime.
- Each layer has a `layer.json` contract and a `verify.php` checked by CI.
- Spawn copies layers 00–03. Layer 04 is always instance-local.

## Spawn

`php spawn/spawn.php --target=/path --name=my-instance`

Creates a new instance directory with:
- Identical kernel schema (layer 00)
- Identical capabilities (layer 03)
- Identical HTTP surface (layer 02)
- Blank ontology (layer 04 — zero entity records)
- Fresh `kernel.db` with one `instance_identity` record
- Unfilled `.env` (operator must configure)

The spawned instance inherits the **protocol algebra** but starts its own
**ontology**. It can diverge from the parent via gossip_merge, accumulate its
own records, and eventually fork its own capabilities.

## Self-Deploy Loop

```
cron.php (every minute)
  └─ GET github.com/repos/.../commits/main
       └─ if newer than adopted_commit AND all CI checks passed:
            └─ deploy (git reset / FTP / rsync)
                 └─ write adopted_commit → runtime_state
```

The GitHub Actions workflows (`deploy_ftp.yml`, `deploy_sftp.yml`) are the
push-based alternative: they trigger after CI passes and call
`POST /runtime/notify` to update `adopted_commit` immediately.

## Agent Loop

```
instance encounters unknown schema
  └─ inserts candidate_record into entities
       └─ cron tick → webhook_out → GitHub Issue (label: Living Software + candidate)
            └─ agent reads issue → drafts PR (new schema row or capability)
                 └─ CI admissibility check → layers verified
                      └─ merge → adopted_commit advances → instance adopts
```

## Gossip / Multi-Instance

Two instances with a common ancestor commit can exchange record deltas via
`POST /capability/gossip_merge/invoke`. Merge rules:

1. Entity absent locally → insert.
2. Same id, same content hash → no-op (idempotent).
3. Same id, different content → emit `conflict_record`. Never silent overwrite.

Conflict records are first-class entities: they can be resolved by CI rules,
agents, or humans via GitHub Issues.

## Protocol Algebra

Six pure operations (layer 01), all read-only, all return PHP arrays:

| Operation | Signature |
|---|---|
| `proto_select` | `(db, type, limit) → records[]` |
| `proto_filter` | `(records[], key, value) → records[]` |
| `proto_join` | `(a[], b[], keyA, keyB) → records[]` |
| `proto_transform` | `(records[], fn) → records[]` |
| `proto_project` | `(records[], fields[]) → records[]` |
| `proto_render` | `(template, record) → string` |

Capabilities compose these operations plus a write (via the `applications`
log). The surface layer (HTTP router) calls capabilities. Ontology records
reference capabilities by id.
