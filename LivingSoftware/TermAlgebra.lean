/-
  LivingSoftware/TermAlgebra.lean
  ================================
  Imported directly from QuineProof.lean (Cypoe/isa-physics).
  Contains the core term algebra, rewrite confluence, symbolic hash
  security (Dolev–Yao model), and structural homomorphism.

  Source: https://github.com/Cypoe/isa-physics/blob/main/crypto/QuineProof.lean
-/

-- ============================================================================
-- 1. TERM REPRESENTATION
-- ============================================================================

inductive Term where
  | atom : String → Term
  | pair : Term → Term → Term
  | op   : String → Term → Term
  | hash : Term → Term
  deriving DecidableEq, Repr

-- ============================================================================
-- 2. REWRITE SYSTEM & CONFLUENCE
-- ============================================================================

inductive ReducesStar (R : Term → Term → Prop) : Term → Term → Prop where
  | refl  (t : Term) : ReducesStar R t t
  | trans (t1 t2 t3 : Term) : R t1 t2 → ReducesStar R t2 t3 → ReducesStar R t1 t3

def IsNormalForm (R : Term → Term → Prop) (t : Term) : Prop :=
  ∀ t', ¬ R t t'

theorem normal_form_reduces_star_eq {R : Term → Term → Prop} {t t' : Term}
    (hnf : IsNormalForm R t) (hr : ReducesStar R t t') : t = t' := by
  cases hr with
  | refl => rfl
  | trans _ t2 _ hr1 _ => exact absurd hr1 (hnf t2)

/-- Confluence implies unique normal forms. -/
theorem uniqueness_of_normal_forms {R : Term → Term → Prop}
    (h_confl : ∀ {t t1 t2 : Term},
        ReducesStar R t t1 → ReducesStar R t t2 →
        ∃ t3, ReducesStar R t1 t3 ∧ ReducesStar R t2 t3)
    {t t1 t2 : Term}
    (h1 : ReducesStar R t t1) (h2 : ReducesStar R t t2)
    (hnf1 : IsNormalForm R t1) (hnf2 : IsNormalForm R t2) : t1 = t2 := by
  obtain ⟨t3, ht1, ht2⟩ := h_confl h1 h2
  rw [normal_form_reduces_star_eq hnf1 ht1, normal_form_reduces_star_eq hnf2 ht2]

-- ============================================================================
-- 3. CRYPTOGRAPHIC ANCHOR CHAIN  (Dolev–Yao symbolic model)
-- ============================================================================

inductive Derivable (S : List Term) : Term → Prop where
  | member          : ∀ t, t ∈ S → Derivable S t
  | pair_intro      : ∀ t1 t2, Derivable S t1 → Derivable S t2 → Derivable S (Term.pair t1 t2)
  | pair_elim_left  : ∀ t1 t2, Derivable S (Term.pair t1 t2) → Derivable S t1
  | pair_elim_right : ∀ t1 t2, Derivable S (Term.pair t1 t2) → Derivable S t2
  | op_intro        : ∀ name t, Derivable S t → Derivable S (Term.op name t)
  | op_elim         : ∀ name t, Derivable S (Term.op name t) → Derivable S t
  | hash_intro      : ∀ t, Derivable S t → Derivable S (Term.hash t)

def termSize : Term → Nat
  | .atom _     => 1
  | .pair t1 t2 => termSize t1 + termSize t2 + 1
  | .op _ t     => termSize t + 1
  | .hash t     => termSize t + 1

def NoHash (nf : Term) : Term → Prop
  | .atom _     => True
  | .pair t1 t2 => NoHash nf t1 ∧ NoHash nf t2
  | .op _ t    => NoHash nf t
  | .hash t    => t ≠ nf ∧ NoHash nf t

theorem size_pos (t : Term) : termSize t > 0 := by
  induction t <;> simp [termSize]

theorem size_contains_hash {nf t : Term} (h : ¬ NoHash nf t) : termSize t ≥ termSize (Term.hash nf) := by
  induction t with
  | atom _ => simp [NoHash] at h
  | pair t1 t2 ih1 ih2 =>
    simp [NoHash] at h
    by_cases h1 : NoHash nf t1
    · have h2 : ¬ NoHash nf t2 := h h1
      have ih := ih2 h2; simp [termSize] at *; omega
    · have ih := ih1 h1; simp [termSize] at *; omega
  | op _ t ih =>
    simp [NoHash] at h
    have ih' := ih h; simp [termSize] at *; omega
  | hash t ih =>
    simp [NoHash] at h
    by_cases heq : t = nf
    · subst heq; exact Nat.le_refl _
    · simp [heq] at h; have ih' := ih h; simp [termSize] at *; omega

theorem nohash_nf (nf : Term) : NoHash nf nf := by
  by_cases h : NoHash nf nf
  · exact h
  · have h_size := size_contains_hash h
    simp [termSize] at h_size
    exact absurd h_size (Nat.not_succ_le_self _)

def subst (nf d : Term) : Term → Term
  | .atom s     => .atom s
  | .pair t1 t2 => .pair (subst nf d t1) (subst nf d t2)
  | .op name t  => .op name (subst nf d t)
  | .hash t     => if t = nf then d else .hash (subst nf d t)

theorem subst_noop {nf d t : Term} (h : NoHash nf t) : subst nf d t = t := by
  induction t with
  | atom _ => rfl
  | pair t1 t2 ih1 ih2 =>
    simp [NoHash] at h; exact congrArg₂ _ (ih1 h.1) (ih2 h.2)
  | op _ t ih =>
    simp [NoHash] at h; simp [subst, ih h]
  | hash t ih =>
    simp [NoHash] at h; simp [subst, h.1, ih h.2]

theorem mem_map_of_mem (f : α → β) {a : α} {l : List α} (h : a ∈ l) : f a ∈ l.map f := by
  induction l with
  | nil => cases h
  | cons x xs ih =>
    cases h with
    | head => exact List.Mem.head _
    | tail _ hxs => exact List.Mem.tail _ (ih hxs)

theorem derivable_mono (S1 S2 : List Term) (h_sub : ∀ x ∈ S1, x ∈ S2)
    {t : Term} (h : Derivable S1 t) : Derivable S2 t := by
  induction h with
  | member t h_mem => exact Derivable.member _ (h_sub t h_mem)
  | pair_intro t1 t2 _ _ ih1 ih2 => exact Derivable.pair_intro _ _ ih1 ih2
  | pair_elim_left t1 t2 _ ih => exact Derivable.pair_elim_left _ _ ih
  | pair_elim_right t1 t2 _ ih => exact Derivable.pair_elim_right _ _ ih
  | op_intro name t _ ih => exact Derivable.op_intro _ _ ih
  | op_elim name t _ ih => exact Derivable.op_elim _ _ ih
  | hash_intro t _ ih => exact Derivable.hash_intro _ ih

theorem derivable_subst_map (S : List Term) (nf d : Term)
    (hd : Derivable (S.map (subst nf d)) d)
    {t : Term} (h : Derivable S t) : Derivable (S.map (subst nf d)) (subst nf d t) := by
  induction h with
  | member t h_mem => exact Derivable.member _ (mem_map_of_mem _ h_mem)
  | pair_intro t1 t2 _ _ ih1 ih2 => simp [subst]; exact Derivable.pair_intro _ _ ih1 ih2
  | pair_elim_left t1 t2 _ ih => exact Derivable.pair_elim_left _ _ ih
  | pair_elim_right t1 t2 _ ih => exact Derivable.pair_elim_right _ _ ih
  | op_intro name t _ ih => simp [subst]; exact Derivable.op_intro _ _ ih
  | op_elim name t _ ih => exact Derivable.op_elim _ _ ih
  | hash_intro t _ ih =>
    simp [subst]; split
    · exact hd
    · exact Derivable.hash_intro _ ih

theorem derivable_cut (S1 S2 : List Term) (h_sub : ∀ x ∈ S1, Derivable S2 x)
    {t : Term} (h : Derivable S1 t) : Derivable S2 t := by
  induction h with
  | member t h_mem => exact h_sub t h_mem
  | pair_intro t1 t2 _ _ ih1 ih2 => exact Derivable.pair_intro _ _ ih1 ih2
  | pair_elim_left t1 t2 _ ih => exact Derivable.pair_elim_left _ _ ih
  | pair_elim_right t1 t2 _ ih => exact Derivable.pair_elim_right _ _ ih
  | op_intro name t _ ih => exact Derivable.op_intro _ _ ih
  | op_elim name t _ ih => exact Derivable.op_elim _ _ ih
  | hash_intro t _ ih => exact Derivable.hash_intro _ ih

theorem map_subst_noop {nf d : Term} {l : List Term} (h : ∀ x ∈ l, NoHash nf x) :
    l.map (subst nf d) = l := by
  induction l with
  | nil => rfl
  | cons x xs ih =>
    simp [List.map]
    exact ⟨subst_noop (h x (List.mem_cons_self x xs)),
           ih (fun y hy => h y (List.mem_cons_of_mem x hy))⟩

inductive BuiltFromHash (nf : Term) : Term → Prop where
  | base        : BuiltFromHash nf (.hash nf)
  | pair_intro  : BuiltFromHash nf t1 → BuiltFromHash nf t2 → BuiltFromHash nf (.pair t1 t2)
  | op_intro    : BuiltFromHash nf t → BuiltFromHash nf (.op o t)
  | hash_intro  : BuiltFromHash nf t → BuiltFromHash nf (.hash t)

theorem built_from_hash_size {nf t : Term} (h : BuiltFromHash nf t) : termSize t > termSize nf := by
  induction h with
  | base => simp [termSize]
  | pair_intro _ _ ih1 ih2 => simp [termSize]; omega
  | op_intro _ ih => simp [termSize]; omega
  | hash_intro _ ih => simp [termSize]; omega

theorem derivable_single_hash_imp {nf : Term} {S : List Term} {t : Term}
    (hS : S = [.hash nf]) (h : Derivable S t) : BuiltFromHash nf t := by
  induction h generalizing hS with
  | member t h_mem =>
    subst hS; cases h_mem with
    | head => exact BuiltFromHash.base
    | tail _ hxs => cases hxs
  | pair_intro t1 t2 _ _ ih1 ih2 => exact BuiltFromHash.pair_intro (ih1 hS) (ih2 hS)
  | pair_elim_left _ _ _ ih =>
    cases ih hS with | pair_intro h1 _ => exact h1
  | pair_elim_right _ _ _ ih =>
    cases ih hS with | pair_intro _ h2 => exact h2
  | op_intro name t _ ih => exact BuiltFromHash.op_intro (ih hS)
  | op_elim name t _ ih =>
    cases ih hS with | op_intro h1 => exact h1
  | hash_intro t _ ih => exact BuiltFromHash.hash_intro (ih hS)

theorem derivable_single_hash_nf {nf : Term} (h : Derivable [.hash nf] nf) : False :=
  absurd (built_from_hash_size (derivable_single_hash_imp rfl h)) (by simp [termSize]; omega)

theorem built_from_hash_not_nohash {nf t : Term} (h : BuiltFromHash nf t) : ¬ NoHash nf t := by
  induction h with
  | base => simp [NoHash]
  | pair_intro _ _ _ ih2 => simp [NoHash]; intro _; exact ih2
  | op_intro _ ih => simp [NoHash]; exact ih
  | hash_intro _ ih => simp [NoHash]; intro _; exact ih

theorem non_deducibility_of_preimages_symbolic (S : List Term) (nf : Term)
    (h_nohash : ∀ x ∈ S, NoHash nf x) :
    ¬ Derivable S nf → ¬ Derivable (.hash nf :: S) nf := by
  intro h_not h_der
  cases S with
  | nil => exact derivable_single_hash_nf h_der
  | cons d S_tail =>
    have hd : Derivable (d :: S_tail) d := Derivable.member _ List.mem_cons_self
    have hd_mapped : Derivable ((.hash nf :: d :: S_tail).map (subst nf d)) d := by
      simp [List.map, subst]; exact Derivable.member _ List.mem_cons_self
    have h_sub := derivable_subst_map (.hash nf :: d :: S_tail) nf d hd_mapped h_der
    have h_sub' : Derivable (d :: (d :: S_tail).map (subst nf d)) nf := by
      simp [List.map, subst, subst_noop (nohash_nf nf)] at h_sub; exact h_sub
    rw [map_subst_noop h_nohash] at h_sub'
    exact h_not (derivable_cut _ _ (fun x h_mem => by
      cases h_mem with
      | head => exact hd
      | tail _ hxs => exact Derivable.member _ hxs) h_sub')

theorem hash_noninterference_symbolic (S : List Term) (nf t' : Term)
    (_ : nf ≠ t') (h_nohash_S : ∀ x ∈ S, NoHash nf x) (h_nohash_t' : NoHash nf t') :
    ¬ Derivable S t' → ¬ Derivable (.hash nf :: S) t' := by
  intro h_not h_der
  cases S with
  | nil =>
    exact absurd h_nohash_t' (built_from_hash_not_nohash (derivable_single_hash_imp rfl h_der))
  | cons d S_tail =>
    have hd : Derivable (d :: S_tail) d := Derivable.member _ List.mem_cons_self
    have hd_mapped : Derivable ((.hash nf :: d :: S_tail).map (subst nf d)) d := by
      simp [List.map, subst]; exact Derivable.member _ List.mem_cons_self
    have h_sub := derivable_subst_map (.hash nf :: d :: S_tail) nf d hd_mapped h_der
    have h_sub' : Derivable (d :: (d :: S_tail).map (subst nf d)) t' := by
      simp [List.map, subst, subst_noop h_nohash_t'] at h_sub; exact h_sub
    rw [map_subst_noop h_nohash_S] at h_sub'
    exact h_not (derivable_cut _ _ (fun x h_mem => by
      cases h_mem with
      | head => exact hd
      | tail _ hxs => exact Derivable.member _ hxs) h_sub')

theorem hash_preserves_non_derivability (S : List Term) (nf t' : Term)
    (h_nohash_S : ∀ x ∈ S, NoHash nf x) (h_nohash_t' : NoHash nf t') :
    ¬ Derivable S t' → ¬ Derivable (.hash nf :: S) t' := by
  intro h_not
  by_cases h : nf = t'
  · subst h; exact non_deducibility_of_preimages_symbolic S nf h_nohash_S h_not
  · exact hash_noninterference_symbolic S nf t' h h_nohash_S h_nohash_t' h_not

def SymbolicallySecret (S : List Term) (nf : Term) : Prop := ¬ Derivable S nf

theorem forward_secrecy (S : List Term) (nf : Term)
    (h_nohash_S : ∀ x ∈ S, NoHash nf x) (h_not_derivable : ¬ Derivable S nf) :
    ¬ Derivable (.hash nf :: S) nf :=
  non_deducibility_of_preimages_symbolic S nf h_nohash_S h_not_derivable

structure AnchorChain where
  S   : List Term
  nfs : List Term

def exposeAnchors (c : AnchorChain) : List Term :=
  c.nfs.map (.hash ·) ++ c.S

def ChainIndependent (nfs : List Term) (S : List Term) : Prop :=
  List.Nodup nfs ∧
  (∀ x ∈ nfs, ∀ y ∈ S, NoHash x y) ∧
  (∀ x ∈ nfs, ∀ y ∈ nfs, x ≠ y → NoHash x y)

theorem derivable_under_hashes (S nfs : List Term) (t : Term)
    (h_not        : ¬ Derivable S t)
    (h_nohash     : ∀ x ∈ nfs, NoHash x t)
    (h_nohash_S   : ∀ x ∈ nfs, ∀ y ∈ S, NoHash x y)
    (h_nohash_self : ∀ x ∈ nfs, ∀ y ∈ nfs, x ≠ y → NoHash x y)
    (h_nodup      : List.Nodup nfs) :
    ¬ Derivable (nfs.map (.hash ·) ++ S) t := by
  induction nfs with
  | nil => exact h_not
  | cons x xs ih =>
    have h_nodup_xs : List.Nodup xs := (List.nodup_cons.mp h_nodup).2
    have h_not_mem_xs : x ∉ xs := (List.nodup_cons.mp h_nodup).1
    have ih' := ih
      (fun y hy => h_nohash y (List.mem_cons_of_mem x hy))
      (fun y hy z hz => h_nohash_S y (List.mem_cons_of_mem x hy) z hz)
      (fun y hy z hz h_ne => h_nohash_self y (List.mem_cons_of_mem x hy) z (List.mem_cons_of_mem x hz) h_ne)
      h_nodup_xs
    have h_nohash_S' : ∀ y ∈ (xs.map (.hash ·) ++ S), NoHash x y := by
      intro y hy
      rw [List.mem_append] at hy
      cases hy with
      | inl h_xs =>
        obtain ⟨z, hz_mem, hz_eq⟩ := List.mem_map.mp h_xs
        subst hz_eq; simp [NoHash]
        have h_ne : x ≠ z := fun hc => absurd (hc ▸ hz_mem) h_not_mem_xs
        exact ⟨h_ne.symm, h_nohash_self x List.mem_cons_self z (List.mem_cons_of_mem x hz_mem) h_ne⟩
      | inr h_S => exact h_nohash_S x List.mem_cons_self y h_S
    exact hash_preserves_non_derivability _ x t h_nohash_S' (h_nohash x List.mem_cons_self) ih'

theorem chain_forward_secrecy (c : AnchorChain) (h_ind : ChainIndependent c.nfs c.S)
    (h_opaque : ∀ nf ∈ c.nfs, SymbolicallySecret c.S nf) :
    ∀ nf ∈ c.nfs, SymbolicallySecret (exposeAnchors c) nf := by
  intro nf hmem
  exact derivable_under_hashes c.S c.nfs nf (h_opaque nf hmem)
    (fun x hx => by
      by_cases heq : x = nf
      · subst heq; exact nohash_nf x
      · exact h_ind.2.2 x hx nf hmem heq)
    h_ind.2.1 h_ind.2.2 h_ind.1

def mask : Term → Term
  | .atom _     => .atom "masked"
  | .pair t1 t2 => .pair (mask t1) (mask t2)
  | .op name t  => .op name (mask t)
  | .hash t     => .hash (mask t)

def restore (a : String) : Term → Term
  | .atom _     => .atom s!"restored_with_{a}"
  | .pair t1 t2 => .pair (restore a t1) (restore a t2)
  | .op name t  => .op name (restore a t)
  | .hash t     => .hash (restore a t)

def merge (t1 t2 : Term) : Term := .pair t1 t2

theorem mask_distributes_over_merge (t1 t2 : Term) :
    mask (merge t1 t2) = merge (mask t1) (mask t2) := rfl

theorem homomorphic_merge (t1 t2 : Term) (a : String) :
    restore a (merge (mask t1) (mask t2)) =
    merge (restore a (mask t1)) (restore a (mask t2)) := rfl
