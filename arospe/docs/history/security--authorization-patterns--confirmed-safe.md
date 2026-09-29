# Revision history — `docs/security/authorization-patterns/confirmed-safe.md`

> Moved here **unchanged** from the end of [`confirmed-safe.md`](../security/authorization-patterns/confirmed-safe.md) in the 2026-09-24 docs optimization pass (a doc keeps one `_Last updated_` line; the accumulated `_Previously:` chain lives here). Read it only to trace when or why that document changed.

_Previously: 2026-08-22 — Task 0013, Phase 6 docs sync (sidebar module gating — UI): **closed** the registry section's F1/F2 status banner, which still read "open finding … as of story 0013's Phase 4 audit" while both remediations had already shipped in the same story (Phase 5 code-review finding). Both ✅ blocks are now the **real shipped tests** from [`tests/Feature/Navigation/SidebarModuleGatingTest.php`](../../tests/Feature/Navigation/SidebarModuleGatingTest.php), quoted verbatim, in place of the recommendation snippets they replaced. Recorded why the shipped allow-list test asserts `toHaveKey('permissions')` alone rather than the fuller `toHaveKeys([...])` shape originally recommended — **it is a correct narrowing, not a residual**: `permissions` is the only registry key whose absence is silent, because it is the only one read through `empty()`; the four keys read by direct array access raise an `E_WARNING` that `HandleExceptions::handleError()` rethrows as an `ErrorException` (a loud 500), and a missing `group` fails **closed** through `Collection::groupBy()`'s `data_get()` → `''` bucket. Both facts verified against framework source rather than assumed. The reusable pattern this registry establishes for later epics is now documented in [architecture/authorization.md](../architecture/authorization/how-to-gate.md#the-second-half-of-a-module-gate-the-sidebar-registry), which this page points at rather than duplicating._

_Previously: 2026-08-21 — Task 0013, Phase 4 audit (sidebar module gating — UI): added two sections for this repo's first **declarative permission registry** (`config/modules.php`), whose whole design is that every later epic appends entries to it. **A registry that means "ungated" by absence fails open, silently** is an open finding written as a ❌/✅ pair — `empty($item['permissions'])` cannot distinguish "declared ungated" from "the author forgot the key", verified by execution across three silent fail-open shapes and two fail-closed ones, plus the recommendation that a registry entry's permissions be pinned to its route's real `can:` middleware by a test rather than by a comment. **Confirmed safe: a sidebar built on `Gate::any()`** records the six things a later epic should not re-derive — why `Gate::any()` traverses the identical mechanism as `can:` middleware, why the two `Gate::before` callbacks compose in either order, why `hasAnyPermission()` would have been the exact inverse of the requirement, why an unseeded ability and a guest both deny rather than throw, that the rendered markup is escaped in every position including the `data-test` array keys, and that `flux:sidebar.group` renders its slot twice when `expandable` and `icon` are combined._

_Previously: 2026-08-21 — Task 0012, Phase 6 docs sync: **Flush the permission cache after the transaction commits** gained the three real call sites that now hold its shape (`RolePermissionSeeder`, plus `saveRole()` / `deleteRole()` since this story's Phase 4 fix), and a ⚠️ recording why this rule was violated in the first place — the flush at issue was the **vendor's**, fired from inside `syncPermissions()` and `Role`'s `deleted` event, so task 0010's `DB::transaction()` wrapper moved it pre-commit with no flush line appearing anywhere in that diff. Generalised as: wrapping existing code in a transaction is a change to every side effect that code already performed. The [confirmed-safe 403 section](../security/authorization-patterns/confirmed-safe.md#confirmed-safe-a-can-gated-routes-403-names-no-permission--and-app_debug-is-not-what-makes-that-true) below was re-verified against the shipped `ModuleRouteAccessTest.php` in this pass — its code quotes, the `assertSee`/`assertDontSee` pairing and both named reopening conditions still match the real files, so it needed no correction (the [audit-authored-page rule](../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20) says to check, not to assume)._

_Previously: 2026-08-21 — Task 0012 (module/sidebar access gating — backend), Phase 4 audit: added
"Confirmed safe: a `can:`-gated route's 403 names no permission — and `APP_DEBUG` is not what makes that
true". The story ships no production code, so this is a **confirmed-safe** entry rather than a bypass —
but the reason the guarantee holds is not the one the story's own test comment assumed, and the
difference decides what a future story could break: an app-owned `errors/403.blade.php` or a
`Response::deny('…')` message, never the debug flag. Verified by rendering the real exception handler
against the real `users.index` route at both debug settings (byte-identical 6605-byte body), and by
reading `Exception::applicationRouteContext()`, which **does** render `can:users.view` on the debug page
a 403 never reaches._

_Previously: 2026-08-20 — Task 0010, Phase 6 docs sync: added "An identity derived from a mutable
column must be locked once code exists that can mutate it", the durable rule behind that story's Phase 4
round-1 finding **F1** (High) — the only round-1 finding whose lesson was not already covered here. It
is the counterpart to task 0008a's centralization work: centralizing an identity onto a column is safe
until a screen can write that column, and the story that ships the screen owns locking it. Includes the
"lock exactly what the identity depends on, and nothing more" constraint (the Administrator row's
permission set stays editable on purpose) and the sanctioned-bypass requirement without which adding a
`creating` guard breaks the seeder in production._

_Previously: 2026-08-20 — Task 0010's Phase 4 re-audit (round 2): added "Two guards on one payload
must agree on what an omission means". Unlike every other section on this page it documents **no live
bypass** — the roles screen's two transformers handle omission in opposite ways, which is safe only
because `permissionOptions()` renders the permission catalog unfiltered. It is written as a
forward-looking rule because the obvious next step for the sibling UI story (hiding permissions the
actor cannot grant) is exactly what would turn the divergence into the silent-revoke bug the section
above it records. Also records that the two actions' call order is **not** what keeps them from
disagreeing (verified by reversing them), and that a grant-scope rule leaves revocation unrestricted
by construction._

_Previously: 2026-08-20 — Task 0009's three Phase 4 rounds: added "A full-set sync behind a
partially-visible form must preserve what the actor cannot see" (finding F1 — `syncPermissions()`
replaces the whole set while the administrator-level toggle is rendered only to the Super Admin, so an
unprivileged actor's routine edit silently revoked a grant; the preserve-vs-deny resolution was a human
product decision, recorded as such) and "A check over a submitted list must accept every shape the
write accepts, and derive the 'before' state itself" (findings F2/N1 — id, model-instance and nested
shapes evaded a name-only membership check the sync still honoured; N2/N3/NR1 — the first fix took the
"before" snapshot as a caller-supplied array, reopening the hole one level up, closed structurally by
taking the `Role` instead). **Closed** the policy-layer residual under "A guard that reads a row's
protected identity…": `RolePolicy` and the `Gate::before` deferral both read `isSuperAdminRoleRow()`
now (finding F4), and the paragraph carries the generalisation about finishing a partial identity-helper
conversion in one pass._

_Previously: 2026-08-19 — Task 0008a's three Phase 4 rounds: added "A rule that must bind a Super
Admin actor must be a direct throw, not a `Gate` check" (findings F1/N2 — `Gate::before` grants before
any policy method runs, so a `Gate`-mediated invariant is inert for exactly the actor it must bind;
plus the mirror-image mistake of checking only the submitted value and never the target's current
state) and "Authorization that consults a relation must reload it before the first check reads it"
(finding N1 — a caller's `->with('roles')` hydration is attacker-influenced input, and the reload added
for a different reason sat below the `Gate` call that needed it). Rewrote the "not hydrated" section's
now-stale `isSuperAdminRole()` code quote for the extracted `persistedName()`, and narrowed its
policy-layer residual: the fix is now a call to `Role::isSuperAdminRoleRow()` rather than a design
task. Corrected the deferred role-shaped-predicate residual under "An ability must cover every
attribute…" — 0008a centralised that rule and deliberately kept it keyed on the name, so it is a
recorded product decision, not an open item._

_Previously, 2026-08-18 — Task 0008 Phase 6 (docs sync): noted in "Read the Super Admin role name with
a literal default" that the shipped instance of that double fallback now lives solely in
`App\Models\Role::superAdminName()`, so its snippet reads as the rule rather than a code quotation._

_Previously, 2026-08-17 — Task 0008's **third** Phase 4 pass (finding R1): added "A guard that reads a
row's protected identity must distinguish 'not hydrated' from 'hydrated but null'", the rule behind a
working rename bypass of the Super Admin immutability guard that survived the first fix, plus the
recorded policy-layer residual under partial hydration._

_Previously, 2026-08-17 — Corrected during task 0008's Phase 4 **re-audit** (finding F3): the section
previously claimed the unique index alone closed role-name acquisition of the Super Admin name: it does
not, once `config('auth.super_admin.role')` is overridable. Documents the `creating`/`updating` guards
on `App\Models\Role` that actually close it, and the sanctioned `firstOrCreateSuperAdminRole()`
exception the seeder uses._

_Previously, 2026-08-13 — Added "An ability must cover every attribute that achieves its effect"
during the Phase 4 **re-audit** of task 0004 (finding F1's fix), including the normalisation rule for
the change-detection comparison that arms such a guard and the deferred role-shaped-predicate
residual._

_2026-08-09 — Updated during the Phase 4 **re-audit** of task 0002: the `Gate::before`
snippets now show the shipped guard-scoped, `instanceof`-guarded closure; the guest-handling guidance was
corrected (a `mixed` parameter admits guests, so the body must guard, not the type hint); and the
literal-default section now documents why `config($key, $default)` alone does not cover a
present-but-`null` key._
