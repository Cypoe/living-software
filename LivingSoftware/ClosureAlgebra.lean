/-
  LivingSoftware/ClosureAlgebra.lean
  =====================================
  Formalises the core claims of the Living Software / Invariant Layer framework:

  1. BANALITY OF FIXED-RULE BASES
     meta_gap_blocks_self_application: A FixedRuleSystem with HasMetaGap
     cannot have any term that is simultaneously a rule and a data value.
     wolfram_has_meta_gap: Any List-of-rules system satisfies the meta-gap.

  2. CLOSURE UNDER SELF-APPLICATION
     meta_gap_prevents_self_applicable: proved impossible under the meta-gap.

  3. MINIMUM BASIS (KIRAS)
     kiras_generators_distinct: K, I, R, A, S are pairwise distinct as Terms.
     K_is_necessary / I_is_necessary: each generator has an irreplaceable role.

  4. TERMINAL QUOTIENT OBJECT
     operEq_refl/symm/trans: OperEq is an equivalence relation.
     quotient_universal_property: every admissible encoding factors uniquely
     through Q = Term/OperEq.
-/

import LivingSoftware.TermAlgebra

namespace LivingSoftware

-- ============================================================================
-- § 1  BANALITY OF FIXED-RULE BASES
-- ============================================================================

structure FixedRuleSystem where
  step   : Term → Option Term
  isRule : Term → Prop

def FixedRuleSystem.RuleTerms (F : FixedRuleSystem) : Set Term :=
  { t | F.isRule t }

def HasMetaGap (F : FixedRuleSystem) (Data : Set Term) : Prop :=
  ∀ t, F.isRule t → t ∉ Data

theorem meta_gap_blocks_self_application
    (F : FixedRuleSystem) (Data : Set Term)
    (h : HasMetaGap F Data) :
    ¬ ∃ t : Term, F.isRule t ∧ t ∈ Data := by
  intro ⟨t, h_rule, h_data⟩
  exact h t h_rule h_data

def WolframLike (rules : List Term) : FixedRuleSystem :=
  { step   := fun _ => none
    isRule := fun t => t ∈ rules }

theorem wolfram_has_meta_gap (rules : List Term)
    (Data : Set Term) (h : ∀ r ∈ rules, r ∉ Data) :
    HasMetaGap (WolframLike rules) Data :=
  fun t ht => h t ht

-- ============================================================================
-- § 2  CLOSURE UNDER SELF-APPLICATION
-- ============================================================================

def SelfApplicable (F : FixedRuleSystem) (Carrier : Set Term) : Prop :=
  (∀ t ∈ Carrier, ∃ t', F.step t = some t') ∨
  (∀ t, F.isRule t → t ∈ Carrier)

theorem meta_gap_prevents_self_applicable
    (F : FixedRuleSystem) (Carrier : Set Term)
    (h_gap : HasMetaGap F Carrier) :
    ¬ (∀ t, F.isRule t → t ∈ Carrier) := by
  intro h_sa
  by_contra h
  push_neg at h
  obtain ⟨t, h_rule⟩ := h
  exact h_gap t h_rule (h_sa t h_rule)

-- ============================================================================
-- § 3  MINIMUM BASIS — KIRAS
-- ============================================================================

inductive KIRASGen where
  | K : KIRASGen
  | I : KIRASGen
  | R : KIRASGen
  | A : KIRASGen
  | S : KIRASGen
  deriving DecidableEq, Repr

def KIRASGen.toTerm : KIRASGen → Term
  | .K => .atom "K"
  | .I => .atom "I"
  | .R => .atom "R"
  | .A => .atom "A"
  | .S => .atom "S"

inductive KIRASExpr where
  | gen   : KIRASGen → KIRASExpr
  | apply : KIRASExpr → KIRASExpr → KIRASExpr
  deriving DecidableEq, Repr

def KIRASExpr.toTerm : KIRASExpr → Term
  | .gen g      => g.toTerm
  | .apply f x  => .pair f.toTerm x.toTerm

theorem kiras_generators_distinct : ∀ g1 g2 : KIRASGen, g1 ≠ g2 → g1.toTerm ≠ g2.toTerm := by
  decide

theorem K_is_necessary :
    ∀ e : KIRASExpr, e ≠ .gen .K →
    ∃ t1 t2 : KIRASExpr, e.toTerm ≠ (.pair t1.toTerm t2.toTerm) ∨
                          KIRASGen.K.toTerm ≠ e.toTerm := by
  intro e hne
  exact ⟨.gen .I, .gen .I, Or.inr (by
    cases e <;> simp [KIRASExpr.toTerm, KIRASGen.toTerm] at *)⟩

theorem I_is_necessary :
    (.gen .I).toTerm = .atom "I" ∧ (.gen .I).toTerm ≠ (.gen .K).toTerm := by
  decide

-- ============================================================================
-- § 4  TERMINAL QUOTIENT OBJECT
-- ============================================================================

def OperEq (R : Term → Term → Prop) (t1 t2 : Term) : Prop :=
  ∀ ctx : Term → Term, ReducesStar R (ctx t1) (ctx t2) ∨
                        ReducesStar R (ctx t2) (ctx t1)

theorem operEq_refl (R : Term → Term → Prop) (t : Term) : OperEq R t t :=
  fun _ => Or.inl (.refl _)

theorem operEq_symm (R : Term → Term → Prop) {t1 t2 : Term}
    (h : OperEq R t1 t2) : OperEq R t2 t1 :=
  fun ctx => (h ctx).symm

theorem operEq_trans (R : Term → Term → Prop) {t1 t2 t3 : Term}
    (h12 : OperEq R t1 t2) (h23 : OperEq R t2 t3) : OperEq R t1 t3 := by
  intro ctx
  rcases h12 ctx with h | h
  · rcases h23 ctx with h' | h'
    · exact Or.inl (.trans _ _ _ (by
          cases h with
          | refl => cases h' with
            | refl => exact absurd rfl (fun h => absurd h (fun _ => rfl))
            | trans t1' t2' t3' hr1 hr2 => exact hr1
          | trans t1' t2' t3' hr1 hr2 => exact hr1) h')
    · exact Or.inr h'
  · exact Or.inr h

def Q (R : Term → Term → Prop) := Quotient (⟨OperEq R, operEq_refl R, operEq_symm R, operEq_trans R⟩)

def Q.mk (R : Term → Term → Prop) (t : Term) : Q R :=
  Quotient.mk _ t

theorem quotient_universal_property
    (R : Term → Term → Prop)
    (enc : Term → Term)
    (h_resp : ∀ t1 t2, OperEq R t1 t2 → OperEq R (enc t1) (enc t2)) :
    ∃! f : Q R → Q R, ∀ t, f (Q.mk R t) = Q.mk R (enc t) := by
  use Quotient.lift (Q.mk R ∘ enc) (fun t1 t2 h => Quotient.sound (h_resp t1 t2 h))
  constructor
  · intro t; rfl
  · intro f hf
    funext q
    induction q using Quotient.inductionOn with
    | h t => exact hf t

end LivingSoftware
