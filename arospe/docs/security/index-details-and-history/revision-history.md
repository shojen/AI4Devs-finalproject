# Security Knowledge Base — Index details and history — Revision history

> Part of [Security Knowledge Base — Index details and history](../index-details-and-history.md). **Read this part when:** you need to trace when or why a security page was added or widened. The other parts are listed in the [hub](../index-details-and-history.md#parts).

## Revision history

_Last updated: 2026-09-14 — Story 0045 (Orders — core CRUD backend), closing the story: reconciled
[related-id-pair-resolution.md](../related-id-pair-resolution.md)'s **F-1** from ❌ OPEN to ✅ closed, and
corrected its ✅ section to describe the shape `App\Actions\Orders\CreateOrder` actually ships (a
bulk-fetch-then-compare check) rather than the simpler per-item relation query it had shown, once it
became clear the same audit's **F-2** finding (bulk-query, not per-item) rules that simpler shape out for
this caller — both close the identical hole; only the mechanism differs. Per
[errors-log-archive.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s
audit-authored-page rule, the original ❌/OPEN framing is corrected in place rather than silently
rewritten — see the page itself for what it said before.

_Previously: 2026-09-11 — Story 0045 (Orders — core CRUD backend), **Phase 4**: added
[related-id-pair-resolution.md](../related-id-pair-resolution.md), the sixteenth page and the first about
a **pair** of caller-supplied ids rather than a single untrusted row. Written as a ❌/✅ pair with the ❌
marked **OPEN**, per
[errors-log-archive.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s
audit-authored-page rule, so Phase 5's fix has a slot to land in rather than requiring the framing to be
rewritten. The same audit's other findings produced no new durable rule and live in the audit response
rather than here — an unbounded `items` array and an unbounded `quantity` are
[array-validation-bounds.md](../array-validation-bounds.md)'s existing rule and an ordinary missing
ceiling; the float money arithmetic and the `Rule::exists()`/`findOrFail()` soft-delete divergence are
per-review notes. Confirmed rather than assumed, with no finding: the `#[Fillable]` omission lists on
both models, the authorize-before-anything ordering in `CreateOrder` (zero domain queries precede the
refusal), the retry-safe transaction shape (every row built inside the closure via `forceCreate()`,
totals computed before it opens, no `attempts:`), and the absence of any raw SQL._

_Previously: 2026-09-10 — Story 0036 (Shipping rate rules — backend), **Phase 4 re-audit, round 2**:
[livewire-error-bag-persistence.md](../livewire-error-bag-persistence.md)'s own ❌ (Failure mode 2,
`Zones::confirmDelete()` missing a reset) closed within the same audit pass that raised it, per
[errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s
rule for audit-authored pages — corrected in place rather than left open once the fix landed.

_Previously: 2026-09-04 — Story 0029 (Product variants — core backend), **Phase 4 re-audit**: no new
page. Closed both of [derived-column-invariants.md](../derived-column-invariants.md)'s original ❌ sections
(re-verified by enumerating the three writers of `product_variants.sku` and by reading the two variant
actions' statement order, not by trusting the fix) and added two sections to it — one ❌ **OPEN**
finding the remediation itself introduced (`attempts: 3` over a closure that mutates an Eloquent model
created outside it — a silent lost update on any retried attempt, with the coupled fact that `attempts`
on a nested transaction is inert and the shipped caller nests both actions), and one confirmed-safe
record that `causedByConcurrencyError()` matches an exception's **message**, not its class. Both are
summarised in this page's own entry above._

_Previously: 2026-09-04 — Story 0029 (Product variants — core backend), **Phase 4**: added
[derived-column-invariants.md](../derived-column-invariants.md), the fifteenth page and the first about a
stored derived column. The audit's other confirmations produced no new rule and live in the audit
response rather than here — V-10's database read-back (verified by execution: an all-uppercase
attribute-value id creates the variant with the **stored** lowercase id in the pivot and the correct
derived SKU), the `#[Fillable]` exclusion of `sku`/`combination_hash`, the authorization-before-
validation ordering in all three variant actions (verified: an unauthorized actor submitting a
5,000-element payload issues **zero** domain queries before the refusal), and D-16.1's two-pass bound
(verified: 5,000 submitted ids → **zero** `Rule::exists()` queries), which is
[array-validation-bounds.md](../array-validation-bounds.md)'s rule applied correctly at a fourth call
site rather than a new one._

_Previously: 2026-09-03 — Story 0027 (Products — list screen and product editor UI), **Phase 4 (three rounds)**: no new page. Updated the [array-validation-bounds.md](../array-validation-bounds.md) entry above: story 0027 discharged only half of story 0026's hand-off — `regionIds` got the two-pass shape exactly as specified, but `galleryMediaIds` was wired one-pass (2,000 submitted ids → 2,000 queries despite `max:20`), which its own re-audit closed and generalised into a second rule (**two-pass validation bounds the save; a cap at the mutation point bounds the component**, since `#[Locked]` binds the property write channel, never a public method like `addGalleryImages()` that appends to it with no cap).

_Previously: 2026-09-03 — Story 0028 (Product variant attribute types & values — backend), **Phase 4**: no new page. Updated the [array-validation-bounds.md](../array-validation-bounds.md) entry above: story 0028 is that page's first real, shipped call site — `App\Livewire\Products\AttributeTypes\Index::save()` reproduced the identical O(n²) hazard against `distinct:ignore_case` and closed it directly with a three-pass sequential `validate()` structure, unlike story 0026's two call sites, both still unreachable and closed only by a written Definition-of-Done hand-off. Two other Phase 4 findings from this story (an unhandled `TypeError` from an unvalidated row shape; silent data loss from a duplicate submitted owned-id) are mechanical fixes with their own real regression tests, recorded on [database/schema.md](../../database/schema-products/attribute-types-and-values.md#product_attribute_values) rather than given a new security page._

_Previously: 2026-09-03 — Story 0026, **Phase 4 second re-audit**: no new page. Updated the
[array-validation-bounds.md](../array-validation-bounds.md) entry above for finding **R-1's**
resolution — the ❌ it marked "open" is now recorded as a **decision** rather than left standing,
since the hazard is a property of a `validate()` call site this story structurally does not contain,
so the resolution is the corrected docblock plus a written hand-off (DoD item 5) and not a code fix.
The same pass found the identical false claim about `list` in the same docblock and measured it
(30 associative ids → 30 queries), which is now that page's own section. This is the
audit-authored-page rule below working as intended: the ❌/✅ pair left a slot, and the slot was
filled inside the same story rather than a story later._

_Previously: 2026-09-03 — Story 0026 (Product ↔ Sales Region assignment and tax resolution
backend), **Phase 4 re-audit**: added [array-validation-bounds.md](../array-validation-bounds.md), the
**thirteenth** page and the first about the cost of a validation rule rather than about what it
permits. It exists because re-auditing story 0026's first-round F-3 fix as new code — per this
project's own standing rule — found that the fix does not close what its docblock claims: the
`['array', 'list', 'max:254']` bound rejects an oversized or associative array cleanly (verified: a
`ValidationException`, never a crash) and 254 is genuinely the catalog's hard ceiling (249 +
`SPAIN_TERRITORIES`' 5, with no create path), but it does not prevent the per-element
`Rule::exists()` queries from running first. Written as a ❌/✅ pair with the ❌ marked **open**, per
[errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s
audit-authored-page rule. No other page on this index changed — story 0026's other four fixes
(F-1's non-disclosure docblock, F-4's direct pivot query, F-5's deterministic tiebreak, F-6's scan
ceiling) were each re-verified closed by execution and produced no new durable rule, so they live in
the audit response rather than here._

_Previously: 2026-09-02 — Story 0024a (Product description — HTML sanitization on write): added
[html-sanitization.md](../html-sanitization.md), the **twelfth** page and the first about
untrusted-HTML-storage rather than authorization or file decoding. Written as ❌/✅ pairs describing
the shipped, closed state from the outset (both Phase 4 findings — F-1's `block`-vs-`drop`
distinction, F-2's idempotence-to-convergence correction — were already closed by the time this page
was written), per [errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s
rule. No other page on this index changed — this story's other findings (F-3/F-4, both scheme/host
restrictions left at an informational, accepted default) are recorded on the new page itself rather
than duplicated here._

_Previously: 2026-08-27 — Story 0019 (Media Library upload and conversions — backend), **Phase 4 re-audit**: added [image-upload-processing.md](../image-upload-processing.md), the eleventh page, from the verification of findings F-1 (decompression bomb via unbounded Imagick decode), F-2 (the action not validating its own input, and trusting `putFile()`'s inferred extension), F-3 (unchecked `Storage::put()` return) and F-5 (Livewire's temporary-upload endpoint carrying no `mimes` restriction and a looser size ceiling). Every number on that page was measured against the shipped code in this worktree rather than carried over from the first audit's notes, and the reproduction fixtures were removed afterwards. Written as ❌/✅ pairs describing the **shipped** state from the outset, per [errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s rule for an audit-authored page — the failure mode that page's own footer records as having recurred with a one-day fuse._

_Previously: 2026-08-26 — Task 0017 (Sales Region tax configuration — backend), **Phase 6 docs sync**: no new page and no new rule — [model-instance-trust.md](../model-instance-trust.md) was re-verified against `HEAD` rather than rewritten (every ❌/✅ code block still matches the shipped actions, the `whereKeyNot()` → `$rows->reject(...)` supersession is recorded on the page and confirmed in the code, and nothing in this app reads `SetSalesRegionActive`'s return value, exactly as the R-2 section says). What this pass corrected is **this index entry**, which stopped at Phase 4 round 2 while the page itself gained two Phase 5 code-review corrections the next day: the status blockquote's "neither finding was dashboard-reachable" claim, which is true only of F-2, and the lock-ordering bullet's over-claim, where the residual window inside `SetSalesRegionActive`'s promotion path is closed by the **outer** transaction's `attempts: 3` rather than by ordering. Both are now summarised above. Recorded as a distinct data point rather than folded away: this is the audit-authored-page failure mode recurring with a **one-day** fuse instead of a one-story one, caught by the review that immediately followed — which is the prescribed fix working, not a new lesson (see [errors-log.md](../../errors-log.md))._

_Previously: 2026-08-26 — Task 0017, Phase 4 re-audit round 2 and
same-day fix: [model-instance-trust.md](../model-instance-trust.md) gained a third section, applying this
project's own rule that a security fix needs re-auditing as new code, not merely confirmed to close the
finding it answers. The round-1 fix's own lock-ordering justification turned out to protect a scenario that
cannot occur, while round 1 had introduced a real, execution-confirmed deadlock elsewhere — collapsed to one
real ordered lock query rather than an asserted one (R-1). A promotion branch could return an instance lying
about `is_default` after a nested action's separate write cleared it, fixed with a `refresh()` (R-2). A note
recorded (not fixed — no rule reads it yet) on the `Gate` target still being the caller's instance (R-3). An
authorization-ordering fix applied without touching an already-reviewed-and-accepted two-transaction shape
(R-4 — the re-audit's broader atomicity suggestion was declined in writing, per this project's own rule that
a re-raised instruction contradicting an existing decision is withdrawn rather than acted on). Three
test-hygiene fixes (R-5).

_Previously: 2026-08-25 — Task 0017, Phase 4 audit and same-day fix: added
[model-instance-trust.md](../model-instance-trust.md), the **tenth** page (this said "eleventh" until the Phase 6 pass counted the directory: `ls docs/security/*.md` returns ten files besides this index). The audit's two findings
share one root cause and one remedy, which is what earns them a page rather than a per-review note: a
caller-supplied Eloquent instance is untrusted on **both** sides — its attributes are not a safe input to a
guard, and its dirty set is not a safe payload for a write. Written as ❌/✅ pairs from the start per the
[audit-authored-page rule](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)
and updated to **closed** once the fix landed the same day, so neither block was left describing a tree that
no longer existed. This is also the first page here about a **domain invariant** rather than an authorization
rule — the authorization coverage of this story (three actions, five component methods, the policy, the
`can:`-gated route and the refusal logging) was audited and found complete, with no finding. Per-review
findings, including the severity list and the verdict on the TOCTOU item Phase 2 deferred here, live in the
audit response, not on this page._

_Previously: 2026-08-24 — Task 0015a, Phase 5 code review finding F-3: [step-up-authentication.md](../step-up-authentication.md)
was authored during the *first* Phase 4 audit (Phase 3's shipped code, role/status/delete only) and
never revisited once the human-approved widening (F1/F2/F3/F4, decisions D6/D7/D8) and its own re-audit
landed — the exact staleness
[errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)
already names. This entry is rewritten around that page's now-closed ⚠️ items rather than describing
them as open._

_Previously: 2026-08-24 — Added [step-up-authentication.md](../step-up-authentication.md) from the
Phase 4 audit of task 0015a (step-up authentication for privileged Users actions) — the first code in
this repo to act on the `password.confirm` row of
[livewire-authorization.md](../livewire-authorization.md)'s `PersistentMiddleware` table. The audit
raised no blocking implementation finding: the guard's ordering, scope, fail-closed behaviour, 423
render and single-predicate UI hint were each verified against the shipped code and vendor source.
Its findings are about the control's **scope and dependencies** — recorded as that page's ⚠️ list,
since each needs a human decision rather than a patch._

_Previously: 2026-08-22 — Task 0013 (module/sidebar access gating — UI), Phase 6 docs sync: the registry section's index entry above is rewritten now that **both findings are closed** — its ✅ blocks are the shipped guard tests rather than recommendations, and it records why the shipped allow-list assertion is deliberately narrower than the one originally recommended. Added the pointer to [architecture/authorization.md](../../architecture/authorization/how-to-gate.md#the-second-half-of-a-module-gate-the-sidebar-registry), which now owns the registry's reusable shape so this page can stay limited to the security rules. No new page and no new rule in this pass._

_Previously: 2026-08-21 — Task 0013 (module/sidebar access gating — UI), Phase 4 audit: no new page —
`authorization-patterns.md` gained two sections for this repo's first **declarative permission registry**,
`config/modules.php`. The registry's whole design is that every later epic appends entries to it, which
makes the shape of its default the durable question rather than the two entries it holds today: "ungated"
is currently expressed as an **absent or empty** `permissions` key read through `empty()`, so three
distinct developer mistakes — an omitted key, a misspelled key, a `null` value — all resolve to "visible
to everyone" with no warning, no exception and no log, while the mistakes that *look* riskier all fail
closed. Written as a ❌/✅ pair with the finding marked **open**, per the audit-authored-page rule, so
Phase 5's fix has a slot rather than needing the framing rewritten. The companion **confirmed-safe**
section records the `Gate::any()` mechanics a later epic should not re-derive, all verified by execution
against the real component and vendor source rather than from the package's docs. Per-review findings (the
severity list, including the registry-vs-route drift guard and two low-severity hygiene notes) live in the
audit response, not here._

_Previously: 2026-08-21 — Task 0012 (module/sidebar access gating — backend), Phase 4 audit: no new
page — `authorization-patterns.md` gained one **confirmed-safe** rule, "A `can:`-gated route's 403 names
no permission — and `APP_DEBUG` is not what makes that true". This story ships zero production code, so
it produced no bypass to write up; what it did produce is a guarantee that every later epic's module
gate inherits, holding for a mechanism different from the one assumed, with two named conditions that
would reopen it. Verified by rendering the real exception handler at both debug settings rather than by
reading the test's assertions. The audit's other findings are per-review (a missing post-commit
permission-cache flush in `App\Livewire\Roles\Index`, which is a **violation of an existing rule on this
same page** rather than a new one, plus three test-quality corrections) and live in the audit response,
not here._

_Previously: 2026-08-21 — Task 0011 (Roles & permissions management — UI), Phase 4 audit (verdict
PASS, no blocking findings): still no new page — `authorization-patterns.md` gained one durable rule,
"A control omitted from the DOM is safe only for the one value whose guard preserves an omission", and
its **"Two guards on one payload"** section was narrowed in the same pass. That section closed with
"the second action never has to preserve anything, because nothing is ever invisibly absent" — true
while the paired Blade view was still unbuilt, and no longer true now that it ships one deliberate
omission, with `EnforceAdministratorPermissionGrant`'s preserve branch live rather than dormant.
Corrected rather than only appended to, per [errors-log.md](../../errors-log.md)'s "a security page
documented the vulnerable code as current" lesson. Every claim in the new section was verified by
rendering the real component for both actor tiers (41 vs. 42 checkboxes) and by executing a broad
administrator's omitting save, not by reading the markup._

_Previously: 2026-08-20 — Task 0010 (Roles & permissions management — backend), Phase 6 docs sync:
still no new page — `authorization-patterns.md` gained a second rule from this story, "An identity
derived from a mutable column must be locked once code exists that can mutate it" (Phase 4 round-1
finding **F1**, High). Round 1's other seven findings each already had a home on that page or were
screen-specific hardening with no generalizable lesson; F1's did not, and it is the one most likely to
recur — every future module screen widens the set of columns application code can write, and some of
those columns are read as identities elsewhere. The index entry above was widened accordingly._

_Previously: 2026-08-20 — Task 0010, Phase 4 re-audit
(round 2, verdict PASS): added no new page — it expanded `authorization-patterns.md` with one durable
rule, "Two guards on one payload must agree on what an omission means", the pipeline-level companion to
task 0009's two grant rules. It is the first section on that page recording a **hazard rather than a
bypass**: the roles screen's two transformer actions deliberately treat an omitted-but-already-granted
permission in opposite ways, and the property that makes the combination safe (`permissionOptions()`
returning the unfiltered catalog) lives in neither guard. The index entry above was widened accordingly.
Round 1's eight findings were each re-verified closed by execution, not by reading._

_Previously: 2026-08-20 — Task 0009 (Administrator-level permission grant): its three Phase 4 rounds
again added no new page — they expanded `authorization-patterns.md` with two durable rules (a full-set
sync behind a partially-visible form must preserve what the actor cannot see; a check over a submitted
list must accept every shape the write accepts and derive the "before" state itself) and **closed** that
page's policy-layer partial-hydration residual, now that `RolePolicy` and the `Gate::before` deferral
both read `Role::isSuperAdminRoleRow()`. The index entry above was widened accordingly._

_Previously: 2026-08-20 — Task 0016 (Sales Region catalog schema + seeder): added no new page — the
audit expanded `seeder-safety.md` with four durable rules for required-catalog seeders and withdrew that
page's now-wrong `--class=RolePermissionSeeder` production runbook line. The index entry above was
widened accordingly._

_Previously: 2026-08-19 — Task 0008a (centralize Administrator-level role identification): its three
Phase 4 rounds added no new page — they expanded `authorization-patterns.md` with two durable rules
(a rule that must bind a Super Admin actor is a direct throw, never a `Gate` check; authorization that
consults a relation reloads it above the first check that reads it) and corrected two of that page's
now-stale passages. The index entry above was widened accordingly._

_Previously: 2026-08-17 — Third Phase 4 pass on task 0008: expanded the `authorization-patterns.md`
entry for the partially-hydrated-identity rule (finding R1) and for role-name acquisition now being
closed by the `creating`/`updating` guards rather than the unique index (finding F3)._

_Previously: 2026-08-17 — Phase 4 re-audit of task 0007: expanded the `login-status-enforcement.md`
entry for the `getPrevious()`-not-`getOriginal()` rule (which replaced that page's own disproven
first recommendation) and the nullable passkey-callback rule._

_Previously: 2026-08-17 — Added `login-status-enforcement.md` from the Phase 4 audit of task 0007
(non-active status blocks sign-in)._

_Previously: 2026-08-16 — Added `blade-livewire-output-encoding.md` from the Phase 4 audit of task
0006 (Users list + create/edit modal UI)._

_Previously: 2026-08-16 — Added `ci-workflow-hardening.md` from the Phase 4 audit of task 0006b
(browser-test infrastructure setup)._

_Previously: 2026-08-14 — Added `soft-delete-patterns.md` from the Phase 4 audit of task 0005
(soft-delete users + administrator-level protection guard)._
