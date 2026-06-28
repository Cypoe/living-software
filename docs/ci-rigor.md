# CI Rigor

The tie-in becomes rigorous when the AI layer is reduced to a proposal source and the CI layer is the sole admission authority.

## Principle

- Models may suggest.
- The repository decides.
- The running instance adopts only repository states that satisfy deterministic checks.

## Required CI layers

### 1. Syntax and static checks
- PHP lint for `public/*.php`
- JSON validation for capability descriptors
- SQL parse/smoke checks for schema migration fragments

### 2. Structural admissibility
- Every capability `schema_in` and `schema_out` must resolve.
- Every declared composition must type-check.
- No orphan references.
- No duplicate capability ids.

### 3. Behavioral tests
- A tiny executable kernel test suite boots SQLite from `kernel/schema.sql`.
- Golden tests assert that record creation, lookup, capability invocation, and candidate rejection behave as expected.
- Merge tests assert that equivalent edits collapse and conflicting edits emit conflict/candidate records.

### 4. Meta-tests
- Tests for the test harness itself: ensure failure cases really fail.
- Mutation testing lite: intentionally break a rule in a fixture and ensure CI catches it.

## No-knowledge CI

The strongest setup is one where CI has no semantic dependence on LLM outputs.
It only knows contracts, schemas, fixtures, and invariants.
That means:

- if an agent adds a capability, CI only checks contracts and observed behavior;
- if an agent adds a schema, CI only checks closure and test fixtures;
- if an agent proposes prose/doc changes, CI may accept trivially.

## Runtime adoption

The deployed instance should:

1. periodically fetch GitHub state;
2. compare pinned commit vs latest admissible commit;
3. self-update only to commits that passed CI;
4. record the adopted commit in its own state.
