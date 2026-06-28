# Layers

Each layer is a verified boundary. Lower layers are stable contracts;
higher layers depend on them but cannot mutate them.

```
04_ontology      ─ user records, templates, views, domain entities
03_capabilities  ─ deterministic transforms over records (webhook_out, gossip_merge, …)
02_surface       ─ HTTP router, protocol adapters (index.php, cron.php)
01_protocol      ─ record algebra: select/filter/join/transform/project
00_kernel        ─ three-table quine: entities, schemas, carriers + runtime_state
```

**Rules:**
- A layer may only call downward (04 → 03 → 02 → 01 → 00).
- No layer may mutate the schema of a lower layer at runtime.
- Each layer has a `layer.json` contract and a `verify.php` test.
- CI runs all `verify.php` files in order; any failure blocks adoption.
- Spawn copies layers 00–03. Layer 04 (ontology) is always instance-local.
