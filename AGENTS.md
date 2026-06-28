# Living Software — Agent Contract

This repository is the canonical genome for the Living Software kernel.

## Core rule

Agents may propose changes, but the running system only adopts changes that pass deterministic admissibility checks.

## Permitted agent outputs

- New capability descriptors in `capabilities/*.json`
- Capability implementations in `capabilities/*.(php|sql|md|prompt)`
- Schema migrations appended to `kernel/schema.sql`
- Tests in `tests/`
- Documentation updates in `docs/`

## Required constraints

1. Protocol before implementation: every new implementation must declare its input/output schema.
2. No silent mutation: all changes arrive as Git diffs, PRs, or candidate records.
3. CI is the gatekeeper: if tests, schema checks, or composition checks fail, the change is inadmissible.
4. Candidate knowledge is not trusted by default: LLM-proposed code/specs must be validated by executable tests.

## Capability proposal format

Each capability should define:

- `id`
- `schema_in`
- `schema_out`
- `impl_type` (`deterministic`, `stochastic`, `hybrid`)
- `contract`
- `impl_ref`

## CI principle

The CI should verify closure of composition without relying on model knowledge. Tests must be executable, local, and repeatable.
