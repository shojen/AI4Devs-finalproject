# Revision history — `docs/security/blade-livewire-output-encoding.md`

> Moved here **unchanged** from the end of [`blade-livewire-output-encoding.md`](../security/blade-livewire-output-encoding.md) in the 2026-09-24 docs optimization pass (a doc keeps one `_Last updated_` line; the accumulated `_Previously:` chain lives here). Read it only to trace when or why that document changed.

_Previously: 2026-08-24 — Task 0015 (Users CRUD security hardening), found by grepping this tree rather than by the story's Definition of Done, which does not name this file. The `#[Locked]` bullet described `$users` as **deliberately left unlocked**, an accepted residual with the cost of locking it spelled out; finding F4 locked it and rewrote the two tests that cost referred to, so the paragraph now records the residual as **closed** while keeping the reasoning that made it acceptable for two stories. Added a note that the same bullet's "every mutating **and disclosing** method re-authorizes" clause was **aspirational** when written — the three modal openers carried no authorization until this story's finding F7 — since a reader would otherwise take it as a verified fact about the code at the time. Nothing else on this page changed: the `@js()` rule, the removed `$users`-derived pending-address anti-pattern and the `openEditModal()` quote were re-verified against the real files and are unaffected._

_Previously: 2026-08-22 — Task 0013, Phase 6 docs sync: **corrected** the "Translation calls never take user data as the key" claim, which asserted that "every `__()` in this repo passes a **literal** first argument". That was already imprecise before this story (`App\Enums\UserStatus::label()` concatenates, and task 0011's composed `roles.modules.*` labels do too) and this story adds the first **fully variable** key — `__($item['label'])` in `resources/views/components/sidebar-nav.blade.php`, read from `config/modules.php`. The rule is restated by **provenance** rather than by syntax: a key may be computed, but every term must come from code, config or a seeded catalog. The rest of this page was re-verified against the real files in the same pass and needed no change — the layout this story rewrote still contains no `{!! !!}`, and the new component interpolates nothing into a `wire:*` directive (`wire:navigate` takes no argument), so the `@js()` rule is not engaged by it._

_Previously: 2026-08-21 — Task 0012, Phase 6 link sweep: fixed this file's own table-of-contents anchor for the `{{ }}`-in-a-`wire:`-directive section, which carried three leading hyphens where the generated slug has two (the heading opens with `{{ }}`, and stripping the braces leaves exactly two spaces). Content unchanged._

_Previously: 2026-08-16 — Re-audit of task 0006: all three findings verified fixed against the real
files, so the two consequences above were rewritten from "this is what the code does" to the rule plus
the shipped fix (`@js()` on both `wire:click` arguments, `#[Locked] $editingPendingEmail` read from
`User::findOrFail()`, `#[Locked] $deletingUserName`), and `$users` staying unlocked was recorded as an
accepted residual with the condition that would reopen it._

_Previously: 2026-08-16 — Created from the Phase 4 audit of task 0006 (Users list + create/edit
modal UI)._
