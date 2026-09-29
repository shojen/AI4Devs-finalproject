# Base Standards — Artisan-first workflow and quality gates

> Part of [Base Standards](../base-standards.md). **Read this part when:** you finish any PHP change and must run the quality gates, or scaffold files. The other parts are listed in the [hub](../base-standards.md#parts).

## Artisan-first workflow

Per project `CLAUDE.md` (Laravel Boost guidelines): use `php artisan make:*` to scaffold new files (models, migrations, controllers, tests, etc.) instead of hand-writing boilerplate, and pass `--no-interaction` plus the correct options. Use `php artisan make:test --pest <Name>` for tests (see [pest-testing skill](../../../.claude/skills/pest-testing/SKILL.md)).

## Quality gates

Every PHP change in this repo should pass, in this order, before being considered done:

1. `php artisan test --compact --filter=<Name>` — narrowest relevant test(s) first, matching [`tests/Feature/**`](../../../tests) structure.
2. `vendor/bin/pint --dirty --format agent` — auto-fixes formatting against the `laravel` preset (`pint.json`).
3. Larastan level 7 (`phpstan.neon`) for static analysis on `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`.

### Steps 1 and 2 are the *iteration* forms. Run both unscoped before declaring the work done

Both commands above take a scope argument, and **a narrowed gate reports "pass", not "not checked"** — nothing in either one's output distinguishes "I looked and found nothing" from "I looked at almost nothing". Task 0010 shipped past both of them at once (see [errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#both-of-this-projects-per-change-quality-gates-are-scoped-by-default-and-both-silently-passed--2026-08-20)), so the completion form is now stated explicitly:

```bash
vendor/bin/pint --format agent   # NOT --dirty
php artisan test                 # NOT --filter
```

- **`--dirty` inspects only files with *uncommitted* changes**, so it becomes a complete no-op the moment the work is committed — it is inversely coupled to commit hygiene, failing hardest exactly when the workflow is being followed best. It is the right tool mid-edit and the wrong last check on a committed branch.
- **`--filter` cannot observe a change's effect on the rest of the suite.** This matters far more often than it looks: a story that registers a **model event, an observer, a global scope, or middleware** has a blast radius of the whole suite by construction, however narrowly its own feature is scoped. `App\Models\Role`'s holder-count `deleting` guard was specified as part of the roles-CRUD story and reads as scoped to it — it binds every role in every test in the repo.

Use the scoped forms freely while iterating; the unscoped runs are what counts as the record.

**`php artisan test --parallel` is an equally valid unscoped record, and the faster one** (measured on this repo's own 950-test suite: ~2.6x on this project's dev container — see [testing/ci/commands.md#run-in-parallel](../../testing/ci/commands.md#run-in-parallel)). It runs every test in every suite exactly like the plain unscoped form; `--parallel` changes how the work is distributed across processes, not what gets checked. CI runs it this way since the test-performance review that measured it. The one thing `--parallel` needs that the sequential form doesn't: `storage/framework/views` must sit on a filesystem that tolerates concurrent writes — see the ⚠️ in the linked section if you rebuild the Sail image and hit `tempnam()` errors under load.

_Last updated: 2026-09-11 — Doc growth management pass (docs/contracts/token-and-doc-rules.md#doc-growth-management-rule), not a story. Condensed the Directory Structure section's accumulated "this codebase's Nth/third/fourth/fifth shipped instance of the same pattern" counting narrative for `Products/`'s `SyncProductGallery`/`SyncProductSalesRegions`/`SyncProductAttributeValues` paragraphs into tighter prose that keeps the actual rule (a collaborator invoked only by an already-authorized action needs no gate of its own), every class name and every reachability-test reference — no fact removed. Left every heading, every ✅/❌ example and every block explicitly marked "quoted rather than silently rewritten" (the story 0025/0042 corrections) untouched, since other docs and `ai-spec/tasks/` files link into this file's headings by anchor.

_Previously: 2026-09-10 — Story 0043 (Customers — "new customer" notification, backend). Extended `Actions/Customers/` with `NotifyCustomerCreated` and `Notifications/` with `CustomerCreated`. Story 0042 (same day) added `SoftDeletes` to `App\Models\Customer`, with no `delete()` override unlike `User`'s, and `CustomerPolicy::delete()` as a fourth flat ability. No convention rule changed by either story. This file's longer prior `_Previously:` chain is folded into this single line per [contracts.md](../../contracts/token-and-doc-rules.md#doc-growth-management-rule) — no content changed or lost; see git history for the full prior chain if needed._
