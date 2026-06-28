/-
  Demo/LivingAutomaton.lean
  ==========================
  A self-evolving cellular automaton where each cell stores both
  a STATE and a LOCAL RULE TOKEN on the same Term carrier.

  Run with: lake run demo

  Design:
    Cell     = (state : Bool) × (rule : Fin 8)
    Grid     = Array Cell   (1-D toroidal)
    EvolveState  applies rule[cell] to neighbourhood
    EvolveRule   applies a meta-rule to the same neighbourhood
    OneStep      does both in one pass — unified carrier demonstration
-/
import LivingSoftware.TermAlgebra

open LivingSoftware

structure Cell where
  state : Bool
  rule  : Fin 8
  deriving Repr

def Cell.toTerm (c : Cell) : Term :=
  .pair (.atom (if c.state then "1" else "0"))
        (.atom (toString c.rule.val))

def Cell.ofTerm (t : Term) : Cell :=
  match t with
  | .pair (.atom s) (.atom r) =>
    { state := s == "1"
      rule  := ⟨r.toNat! % 8, by omega⟩ }
  | _ => { state := false, rule := ⟨0, by omega⟩ }

def applyRule (r : Fin 8) (l c ri : Bool) : Bool :=
  let idx : Fin 8 := ⟨(if l then 4 else 0) + (if c then 2 else 0) + (if ri then 1 else 0), by omega⟩
  (r.val >>> idx.val) % 2 == 1

def metaRule (r : Fin 8) (l c ri : Bool) : Fin 8 :=
  let pop := (if l then 1 else 0) + (if c then 1 else 0) + (if ri then 1 else 0)
  ⟨(r.val + pop) % 8, by omega⟩

def Grid := Array Cell

def Grid.step (g : Grid) : Grid :=
  g.mapIdx fun i c =>
    let n  := g.size
    let l  := g[(i + n - 1) % n]!.state
    let ri := g[(i + 1)     % n]!.state
    { state := applyRule c.rule l c.state ri
      rule  := metaRule  c.rule l c.state ri }

def Grid.show (g : Grid) : String :=
  g.foldl (init := "") fun acc c =>
    acc ++ (if c.state then "█" else "░")

def Grid.showRules (g : Grid) : String :=
  g.foldl (init := "") fun acc c =>
    acc ++ toString c.rule.val

def demoCellTerm : IO Unit := do
  let c : Cell := { state := true, rule := ⟨30, by omega⟩ }
  let t := c.toTerm
  IO.println s!"Cell: {repr c}"
  IO.println s!"As Term: {repr t}"
  IO.println s!"Round-trip eq: {repr (Cell.ofTerm t)}"

def runDemo (steps n : Nat) : IO Unit := do
  let seed : Grid := Array.range n |>.map fun i =>
    { state := i == n / 2, rule := ⟨30, by omega⟩ }
  IO.println "═══════════════════════════════════════════════════"
  IO.println " LIVING AUTOMATON — unified state/rule substrate"
  IO.println "═══════════════════════════════════════════════════"
  IO.println ""
  IO.println "┌── Cell as unified Term ──────────────────────────"
  demoCellTerm
  IO.println ""
  IO.println "┌── Evolution  (states │ rule tokens) ────────────"
  let mut g := seed
  for _ in List.range steps do
    IO.println s!"  {g.show}  │  {g.showRules}"
    g := g.step
  IO.println ""
  IO.println "Rule tokens mutate each generation — rules ARE data."

def main : IO Unit := runDemo 20 40
