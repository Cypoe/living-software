# Proof Notes — Living Software

## The Meta-Gap Argument

The sharpest counter to fixed-rule systems is categorical, not computational.
Define the meta-gap as R ∩ Data = ∅.  Any system that enumerates rules
externally satisfies this by construction — Wolfram's hypergraph update rules
are a finite list, not elements of the evolving graph.

Lean proof: `meta_gap_blocks_self_application` in `ClosureAlgebra.lean`.

## Why KIRAS and not SKI

SKI is Turing-complete but collapses all four structural roles into one
constructor family.  The KIRAS generators are minimal in the sense that each
is associated with a distinct invariant role:

- **K** — constant / projection: K x y = x (information discard)
- **I** — identity / persistence: I x = x (I² = I; projector property)
- **R** — resolution: asymmetry, ordering, conflict resolution
- **A** — directed application / adjacency
- **S** — structural closure: the e-graph merge / unification operation

Removing any one leaves a system that cannot express one of these roles
without a meta-level workaround — reinstating the meta-gap.

## The Terminal Quotient

Q = Term / OperEq is terminal in the following sense.  Consider the category
**Enc** whose objects are pairs (T, enc) where T is a carrier and enc : T → Term
respects operational equality, and whose morphisms are commuting triangles.
Q together with the canonical projection is the terminal object: every (T, enc)
admits a unique morphism to Q.

Lean proof: `quotient_universal_property` in `ClosureAlgebra.lean`.

## Self-Evolution vs. Universal Computation

Universal computation (Turing completeness, λ-universality) does not imply
self-evolution in our sense.  A universal Turing machine can simulate any TM
but its own tape-transition table is immutable.  Self-evolution requires the
transition table to be a cell on the tape — rules as data.

The `LivingAutomaton` demo makes this concrete: the rule token is a `Fin 8`
stored in the same `Cell` structure as the state bit, and both are updated by
the same `Grid.step` function.  There is no separate interpreter process.
