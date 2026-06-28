# Layer Contracts

Each layer is a verified boundary. CI runs `layers/NN_name/verify.php` for
every layer in order (00 → 03). A failure at any layer blocks the commit from
being adopted.

## 00 — Kernel

**What it is:** The three-table quine. `entities`, `schemas`, `carriers`,
`applications`, `runtime_state`. No application logic, no views, no triggers.

**Invariants checked by verify.php:**
- All five tables exist with required columns
- No views or triggers in the schema
- `PRAGMA foreign_keys` is ON
- `entities` has all required columns

**Never changes** unless the algebra itself evolves (rare, major version bump).

## 01 — Protocol

**What it is:** Six pure functions over the kernel tables.
`proto_select`, `proto_filter`, `proto_join`, `proto_transform`,
`proto_project`, `proto_render`.

**Invariants checked by verify.php:**
- All six functions execute correctly on test data
- No writes, no network, no filesystem I/O

**Stable.** New operations are additive; existing operations never change
signature.

## 02 — Surface

**What it is:** The HTTP router (`public/index.php`) and the cron loop
(`public/cron.php`). Translates HTTP ↔ record algebra.

**Invariants checked by verify.php:**
- `index.php` passes PHP lint
- Defines `json_out`, `state_get`, `state_set`, `bearer_ok`
- Handles `/health` and `/runtime/notify` routes

**Changes when:** new routes are added, deploy methods change.

## 03 — Capabilities

**What it is:** Deterministic transforms. Each is a `.json` descriptor +
`.php` implementation exporting `{id}_invoke(array, SQLite3): array`.

**Invariants checked by verify.php:**
- Every `.json` descriptor has a matching `.php`
- Every `.php` passes lint
- Every `.php` defines the correct `_invoke` function

**Changes when:** new capabilities are added (additive). Existing capability
contracts are append-only.

## 04 — Ontology (instance-local)

**What it is:** User-defined records, templates, materialized views.
Philosophically: the instance’s theory of mind.

**No verify.php.** Not spawnable. Lives only in `runtime_state` and `entities`
of the running instance. Agents and users operate here exclusively.

**Changes constantly** — this is normal operation.
