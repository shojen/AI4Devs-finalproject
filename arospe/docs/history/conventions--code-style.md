# Revision history — `docs/conventions/code-style.md`

> Moved here **unchanged** from the end of [`code-style.md`](../conventions/code-style.md) in the 2026-09-24 docs optimization pass (a doc keeps one `_Last updated_` line; the accumulated `_Previously:` chain lives here). Read it only to trace when or why that document changed.

_Previously: 2026-08-31 — Story 0022 (Shared searchable, server-side-filtered multi-select component). Extended the `app()` carve-out's list of confirmed instances with a **third shape**, not a rewritten rule: `App\Livewire\Components\SearchableMultiSelect::hasSearchedEnough()`, a plain public method (neither `#[Computed]` nor a Livewire lifecycle hook) invoked directly from its Blade view as `$this->hasSearchedEnough()` with a fixed, zero-argument call site — the rule's existing wording already covered it, since the Blade template rather than this class is what fixes the parameter list. `updatedSearch()` in the same component reuses the already-established lifecycle-hook shape (`app(NormalizeForSearch::class)`, matching `Gallery::updatedPendingUploads()`) rather than adding a fourth. **Verified as unchanged rather than assumed:** every other section on this page (explicit types, braces, validation traits, PHPDoc array shapes, per-method action injection) — this story adds no new action, no new validation trait, and its one `array<int, array{...}>` PHPDoc shape on `MultiSelectOptionsResolver::search()`/`resolveSelected()` is the existing array-shape convention applied, not extended._

_Previously: 2026-08-29 — Story 0020 (Shared media gallery modal — frontend), Phase 6: extended the
`app()` carve-out with its **second** shape and, more usefully, restated the rule behind it. It had read
as "a `#[Computed]` method may use `app()`", which is a description of the one instance that existed;
story 0020's `Gallery::updatedPendingUploads()` is a Livewire lifecycle hook — invoked through
`wrap($component)->__call($name, $params)` with fixed parameters rather than a container `call()`, so a
type-hint there is never resolved — and it fails the same test for the same reason. The rule is now
stated once: **a method whose parameter list is fixed by something other than this class may use
`app()`, and nothing else may.** Note the hook delegates to `upload()`, which keeps its ordinary
method-injected signature because it is also called directly (and container-resolved) by every Feature
test — the hook is a caller, not a replacement for the signature. Nothing else on this page changed: the
story's new action (`App\Actions\Media\UpdateMediaDetails`) constructor-injects
`LogRefusedPrivilegedAttempt` for the reason the documented exception already gives — its `__invoke()`
parameter list is a public contract — and its `array<int, array{...}>` payload shapes and explicit
return types are the existing type/PHPDoc rules applied, not new ones._

_Previously: 2026-08-26 — Task 0017 (Sales Region tax configuration — backend), Phase 6: extended the
constructor-injection exception with **the clearest case it has** — one action constructor-injecting
another (`SetSalesRegionActive` ← `SetDefaultSalesRegion`), with the ❌ pair against `app()`, which this
story's own Phase 1 draft proposed before Phase 2 corrected it. The distinction is not stylistic: `app()`
earns its single exception because a zero-parameter `#[Computed]` method *cannot* accept an injected
dependency, whereas an action's constructor always can — and a constructor dependency is swappable in a
test where an `app()` call in a method body is not. Also refreshed the two counts in the 0015b paragraph
above it, which had become under-counts (`LogRefusedPrivilegedAttempt` is now constructor-injected into
**eight** actions and method-injected into **three** components); the 0015b sentence itself is left as the
historical statement it is. Nothing else on this page changed: no new type, brace, validation-trait or
PHPDoc convention, and the story's own `@return array<int, ValidationRule|array<mixed>|string>` trait
docblocks and `array<int, array{...}>` shapes on `$regions` / `replacementCandidates()` are the existing
rules applied, not new ones._

_Previously: 2026-08-24 — Task 0015b (log refused privileged attempts), Phase 6: extended the
constructor-injection exception with the constraint that falls out of it — **an action must be resolved
from the container, never `new`-ed, including in tests**. This story gave three actions their *first*
constructor dependency, and every `new RequestEmailChange` call site broke at once (ten in
`tests/Feature/Settings/EmailChangeTest.php`, all rewritten to `app(RequestEmailChange::class)` with no
assertion changed), which is the proof that a zero-argument constructor is not a contract. The story's
own seven injection sites are the existing rule and its exception applied unchanged — five actions
constructor-inject `LogRefusedPrivilegedAttempt`, both Livewire components method-inject it — not a
third case, so the rule above is unmodified. Nothing else on this page changed: no new type, brace,
validation-trait or PHPDoc convention._

_Previously: 2026-08-24 — Task 0015a (step-up authentication for privileged Users actions), Phase 6:
added the constructor-injection exception, with the real ✅/❌ pair from
`App\Actions\Auth\EnsureRecentPasswordConfirmation`'s three call sites (two constructor-injected, one
method-injected, one `app()`-resolved out of necessity) — found during Phase 6 review rather than named
by the change→doc mapping, since this page's "Inject single-purpose actions per-method" rule reads as
contradicted by two of the three until the reason is stated._

_Previously: 2026-07-12 — Initial scaffold of the documentation set by the docs-maintainer skill._
