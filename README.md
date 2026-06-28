# Living Software — Formal Proof Repository

> *"Rules are data. Data is rules. One substrate."*

Formal Lean 4 proof of the **Living Software / Invariant Layer** framework, plus an executable self-evolving cellular automaton demonstrating the unified carrier in action.

Source lineage: [`Cypoe/isa-physics/crypto/QuineProof.lean`](https://github.com/Cypoe/isa-physics/blob/main/crypto/QuineProof.lean)

---

## Repository layout

```
LivingSoftware/
├── LivingSoftware/
│   ├── TermAlgebra.lean       ← Term algebra + symbolic hash security
│   ├── ClosureAlgebra.lean    ← meta-gap, KIRAS basis, terminal quotient
│   └── LivingSoftware.lean    ← root import
├── Demo/
│   └── LivingAutomaton.lean   ← self-evolving CA (lake run demo)
├── docs/
│   └── PROOF_NOTES.md
├── lakefile.toml
├── lean-toolchain             ← leanprover/lean4:v4.14.0
└── README.md
```

---

## Theorem map

### § 1 — Banality of fixed-rule bases

| Theorem | Statement |
|---|---|
| `meta_gap_blocks_self_application` | A `FixedRuleSystem` with `HasMetaGap` has no term that is simultaneously a rule and a data value. |
| `wolfram_has_meta_gap` | Any `List`-of-rules system satisfies the meta-gap. |
| `meta_gap_prevents_self_applicable` | The meta-gap makes self-applicability impossible. |

### § 2 — Closure under self-application

`SelfApplicable` requires rules to live inside the carrier. Proved impossible under the meta-gap.

### § 3 — Minimum basis (KIRAS)

| Theorem | Statement |
|---|---|
| `kiras_generators_distinct` | K, I, R, A, S are pairwise distinct as Terms. |
| `K_is_necessary` | Removing K loses the projection/constant-discard role. |
| `I_is_necessary` | I provides identity/persistence distinct from K. |

### § 4 — Terminal quotient object

| Theorem | Statement |
|---|---|
| `operEq_refl/symm/trans` | `OperEq` is an equivalence relation. |
| `quotient_universal_property` | Every admissible encoding factors **uniquely** through `Q = Term/OperEq`. |

`Q` is the terminal object in the category of `OperEq`-respecting endomorphisms. Concrete systems (SKI, λ-calculus, TRS, bytecode) are admissible encodings; the invariant layer is their unique common quotient.

### Imported from QuineProof.lean

`LivingSoftware.TermAlgebra` re-exports all theorems from `crypto/QuineProof.lean`:

- `uniqueness_of_normal_forms` — confluence → unique normal forms
- `forward_secrecy` — Dolev-Yao symbolic hash one-wayness
- `chain_forward_secrecy` — per-anchor secrecy over the full chain
- `mask_distributes_over_merge` / `homomorphic_merge` — structural homomorphism

---

## Demo: Living Automaton

```bash
lake run demo
```

A 1-D toroidal grid where every cell stores **both** a state bit **and** a local rule token (Wolfram elementary rule index 0–7) as the same `Term` pair. Each generation:

1. **State evolves** using the cell's local rule token applied to the 3-cell neighbourhood.
2. **Rule token evolves** using a meta-rule applied to the same neighbourhood.

Both are first-class values in one carrier — no external interpreter layer. This is closure under self-application, made executable.

---

## Building

```bash
# Install elan if needed:
curl https://raw.githubusercontent.com/leanprover/elan/master/elan-init.sh -sSf | sh

lake build    # build all
lake check    # type-check only
lake run demo # run the automaton
```

Requires Lean `v4.14.0` (see `lean-toolchain`).

---

## License

MIT — Fabian / Cypoe, 2026.
