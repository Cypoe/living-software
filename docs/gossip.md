# Gossip Merge Protocol

## Overview

Two instances of Living Software that share a common ancestor commit can exchange record deltas via the gossip_merge capability. Neither instance is authoritative over the other. The merge is deterministic, idempotent, and conflict-preserving.

## Merge rules

1. **Non-conflicting delta**: entity present in peer but not in local → insert.
2. **Identical content**: same id, same content hash → no-op (sparse/lazy: equivalent edits collapse).
3. **Diverged content**: same id, different content → emit `conflict_record` into entity graph. Never silent overwrite.

## Conflict records

A `conflict_record` entity is itself a first-class record. It can be:
- resolved by a CI rule (if a resolution_strategy record exists for this entity type)
- resolved by an agent (via a PR that adds or updates the entity)
- resolved by a human (via the GitHub Issue template)

## Sparse ancestry

Operationally equivalent edit paths collapse: if two instances both arrived at the same content hash for an entity via different intermediate edits, the merge sees them as identical and no-ops. Only genuinely novel states produce new records.

## Endpoint

`POST /capability/gossip_merge/invoke`

Payload:
```json
{
  "peer_id": "instance-b",
  "ancestor_sha": "<common commit sha>",
  "entities": [...],
  "schemas": [...]
}
```

Response:
```json
{
  "ok": true,
  "inserted": 3,
  "skipped": 12,
  "conflicts": []
}
```
