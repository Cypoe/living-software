# ADR, Plan, Session & Spike Pipeline

Every non-trivial decision, workblock, working session, and experiment in
Living Software is a **record**. This document defines the canonical types,
their state machines, and the CI verification gates that enforce them.

The pipeline exists so the repo is always a readable log of *why* the system
is the way it is — not just *what* it is.

---

## Record Types

| Type | Purpose | Immutable after? |
|---|---|---|
| **ADR** | Architecture Decision Record — a binding choice about the invariant layers or protocol algebra | `accepted` |
| **Plan** | A scoped workblock with clear inputs, outputs, and acceptance criteria | `done` |
| **Session** | A working session log — what was explored, what was learned, what changed | always (append-only) |
| **Spike** | A time-boxed investigation with a defined question and verdict | `closed` |

---

## ADR

### State machine

```
proposed → under-review → accepted
                       ↘ superseded-by: ADR-NNN
```

### File location

```
docs/adr/
  ADR-0001-kernel-three-table-quine.md
  ADR-0002-layer-contract-verification.md
  ADR-NNNN-{slug}.md
```

### Template

```markdown
# ADR-NNNN: {Title}

**Status:** proposed | under-review | accepted | superseded-by ADR-XXXX  
**Date:** YYYY-MM-DD  
**Layers affected:** 00_kernel | 01_protocol | 02_surface | 03_capabilities  

## Context

What situation or constraint forces a decision here?

## Decision

Exactly what was decided. One clear declarative sentence, then elaboration.

## Consequences

- What becomes easier
- What becomes harder or constrained
- What is explicitly out of scope

## Alternatives considered

| Option | Why rejected |
|---|---|
| ... | ... |
```

### CI gate

`layers/01_protocol/verify.php` checks that every `docs/adr/ADR-*.md` file:
- Has a `**Status:**` field with a valid value
- Has `## Context`, `## Decision`, `## Consequences` sections
- If `accepted`: is referenced by at least one `layer.json` changelog entry

A malformed ADR blocks `admissible` promotion.

---

## Plan

### State machine

```
draft → active → done
              ↘ cancelled (reason required)
```

### File location

```
docs/plans/
  PLAN-0001-{slug}.md
```

### Template

```markdown
# PLAN-NNNN: {Title}

**Status:** draft | active | done | cancelled  
**Date:** YYYY-MM-DD  
**ADRs referenced:** ADR-NNNN, …  
**Session log:** SESSION-NNNN, …  

## Goal

One sentence: what outcome does this plan produce?

## Inputs

- What exists before this plan starts

## Acceptance criteria

- [ ] Criterion 1
- [ ] Criterion 2

## Steps

1. Step description
2. …

## Outcome

<!-- filled in when status → done -->
```

### CI gate

No hard block on Plans, but `tests/golden.php` warns if an `active` Plan
has been open for > 14 days without a linked Session update.

---

## Session

Sessions are **append-only**. Never edit a past session entry.

### File location

```
docs/sessions/
  SESSION-NNNN-YYYY-MM-DD-{slug}.md
```

### Template

```markdown
# SESSION-NNNN: {Title}

**Date:** YYYY-MM-DD  
**Plan:** PLAN-NNNN (if applicable)  
**Participants:** Fabian, [agent/tool names]

## What was attempted

## What was learned

## What changed (commits, files, records)

## Open questions / next
```

### Convention

Session files are written *during or immediately after* the session.
They do not need to be polished — they are the raw log.
An agent working in a session appends to the current session file;
it never edits past lines.

---

## Spike

### State machine

```
open → closed (verdict: adopt | reject | defer)
```

### File location

```
docs/spikes/
  SPIKE-NNNN-{slug}.md
```

### Template

```markdown
# SPIKE-NNNN: {Title}

**Status:** open | closed  
**Opened:** YYYY-MM-DD  
**Timebox:** N days  
**Question:** One sentence — what are we trying to find out?

## Method

## Findings

## Verdict

`adopt` | `reject` | `defer` — one sentence rationale.

## Follow-up

- [ ] ADR-NNNN if adopting
- [ ] PLAN-NNNN if work required
```

---

## Pipeline overview

```
  Idea / question
       │
       ▼
  SPIKE (optional, for unknowns)
       │ verdict: adopt
       ▼
  ADR  (for layer/protocol decisions)
       │ accepted
       ▼
  PLAN (for implementation workblocks)
       │ active
       ▼
  SESSION (working log, append-only)
       │ commit(s)
       ▼
  CI: golden + layer verify
       │ pass
       ▼
  label: admissible
       │ merge
       ▼
  label: adopted
       │ cron.php
       ▼
  running instance updated
```

---

## Naming and numbering

Numbers are sequential per type, zero-padded to 4 digits.
Slugs are lowercase-hyphenated, max 6 words.

```
ADR-0001-kernel-three-table-quine
PLAN-0001-initial-kernel-bootstrap
SESSION-0001-2026-06-28-living-software-init
SPIKE-0001-gossip-merge-strategy
```

---

## GitHub integration

- Each ADR/Plan/Spike maps to a GitHub Issue with the matching label
  (`adr`, `plan`, `spike`) so state is visible on the board
- Session files are committed directly — no Issue needed
- A Plan Issue is closed when status → `done`; the ADR Issue stays open
  until `accepted` and is then locked (read-only reference)
