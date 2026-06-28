# Living Software

Living Software is a self-hosting, self-evolving record-algebraic substrate.

It separates protocol from implementation, treats capabilities as first-class records, and aims to support deterministic self-improvement, gossip-based merge/reproduction, and agent-assisted evolution.

## Initial layout

- `kernel/schema.sql` — minimal kernel schema
- `public/boot.php` — HTTP bootstrap and record gateway
- `public/cron.php` — keep-alive / self-update heartbeat
- `AGENTS.md` — agent contract for proposing changes
- `docs/ci-rigor.md` — rigorous CI and admissibility strategy
