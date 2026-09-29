# Testing

How this team writes, reviews, and runs tests in this Laravel 13 + Livewire 4 app. The short version: **coverage is not synonymous with quality**. A test earns its place by verifying real behavior — inputs, outputs, side effects, state changes — not by executing lines of code. See [philosophy.md](philosophy.md) for the full argument and the anti-patterns to avoid.

This set is split into small files on purpose, so you only load what your current task needs:

## QA — how to think about what to test

Framework-agnostic, applies to any new feature before a single line of test code is written.

- [Risk-based testing](qa/risk-based-testing.md) — the question checklist ("what can fail here?") for designing test cases.
- [Coverage review checklist](qa/coverage-review-checklist.md) — what a reviewer runs through before approving a PR's tests.
- [What not to test](qa/what-not-to-test.md) — what's reasonable to skip, and why.

## Backend — how to write it in Pest 4

- [Backend index](backend/README.md) — which file to open depending on what you're about to write.

## Frontend / Browser — how to write it in Pest 4

For QA engineers writing browser-level tests, and for turning user stories into Gherkin scenarios and then into Pest browser tests.

- [Frontend / browser testing guide](frontend/README.md) — tooling decision (Pest 4 browser testing, not a separate Playwright/BDD runner), the user-story → Gherkin → Pest workflow and reference prompt, setup status, Gherkin guidelines + domain glossary, browser-specific quality checklist, frontend coverage policy, and worked scenario/test examples. Since task 0018 [playwright-setup.md](frontend/playwright-setup/waiting-rules.md#waiting-one-call-is-banned-in-this-repo-and-one-is-bounded) also owns this repo's **waiting rules** (`->waitForEvent('networkidle')` banned outright; a bounded `->wait(n)` with a stated reason as the one accepted mitigation) and the selector ⚠️ for an admin list screen whose row controls are icon-only. Since story 0020 it owns three more environment findings that decide what a browser test can even be written to do, all source-verified rather than inferred: **`->wait(n)` is not a polling primitive, and a longer one can fail *because* it is longer** (it is routed through a retry loop that only re-tries on a thrown expectation, and `Playwright::$timeout` is 5000 ms, so `->wait(5)` throws against its own budget); **a real file upload is unreachable through `visit()` in this environment**, both because `attach()` is refused for any file input over a non-collocated Playwright connection and because the plugin's own HTTP driver never parses a multipart body into `UploadedFile`s — so an upload's *completion* is Feature-test territory permanently, while everything up to the XHR starting is still coverable; and **a page mounting one component twice duplicates every `data-test` hook**, tripping Playwright strict mode on single-element assertions while `assertSee()` silently tolerates it. Since story 0021 it also owns [a process-hygiene rule distinct from the `->waitForEvent('networkidle')` incident above](frontend/playwright-setup/waiting-rules.md#orphaned-playwright-processes-re-accumulate-on-every-browser-test-run-in-this-environment): orphaned `playwright run-server` processes re-accumulate on **every** browser-test run in this environment, not only after a hung call — run `pkill -9 -f "playwright run-server"` after any session, and check for it before trusting a flake-rate or timing measurement that looks worse than a previous run with no code change to explain it. Since story 0022 it also owns [a DOM-occlusion diagnostic distinct from a genuine timing flake](frontend/playwright-setup/waiting-rules.md#a-hung-click-with-no-error-anywhere-check-for-occlusion-with-documentelementfrompoint-before-suspecting-a-timing-flake): a real click hanging on Playwright's own actionability timeout, with no PHP error, no console error and no failed request, can mean a sibling element is silently covering the target rather than a broken handler — `document.elementFromPoint()` at the target's real screen coordinates is the fast way to tell the two apart, and it is a diagnostic `Livewire::test()` can never run. Read all of it before writing a browser test against `tests/Browser/`'s eight files.

## CI — how to run and enforce it

- [Commands](ci/commands.md) — full suite, single file/test, coverage report, thresholds, parallel runs. Command table at the end. Since story 0021 its **Database prerequisite** section distinguishes CI/host-native worktrees (always literally `testing`) from a Sail-based worktree (may not be), reconciling it with [worktree-databases.md](worktree-databases.md)'s own host-native correction rather than leaving the two pages reading as contradictory — and its memory-limit note gains a sibling for **`php artisan test`** itself: an unscoped run fatals at PHP CLI's default 128M on this host-native setup, with the exact `vendor/bin/pest`-direct workaround (an `artisan test` subprocess does not inherit its parent's `-d` flag).
- [Pipeline integration](ci/pipeline-integration.md) — a documented proposal for CI coverage enforcement (the real pipeline doesn't enforce coverage today — see that file for the current state). Since story 0019 it also records the first `setup-php` input added for **correctness** rather than tooling, `extensions: imagick`, and why `->skip(fn () => ! extension_loaded('imagick'))` was rejected in its place.

## Local environment — per-worktree isolated testing databases

- [Worktree databases](worktree-databases.md) — why `.env.testing` (gitignored, one per checkout) is required, why every `git worktree` needs its **own** testing database name (`testing1`, `testing2`, …) rather than sharing one, and the setup/cleanup steps for opening and removing a worktree. Read this before running `artisan migrate:fresh` (with or without `--env=testing`) from any worktree.

## Related, not duplicated here

- [`.claude/skills/pest-testing/SKILL.md`](../../.claude/skills/pest-testing/SKILL.md) — Pest 4 syntax reference (`test()`/`it()`/`expect()`, `make:test`, browser/smoke/architecture testing). This doc set assumes you know that or will look it up there; it focuses on judgment (what/why to test), not syntax (how to call `expect()`).
- [conventions/base-standards.md](../conventions/base-standards.md) — the quality-gate order (test → Pint → Larastan) every change goes through.
- [security/image-upload-processing.md](../security/image-upload-processing.md) — not a testing doc, but the one security page that is mostly **test-design** guidance: why asserting a generated file's *extension* proves nothing and its byte signature must be checked instead (`RIFF`…`WEBP`, the ISO-BMFF `avif` brand), why a read-only-directory fixture makes the **first** write fail and leaves a partial-write cleanup branch vacuously asserted, and why a fixture named `.jpg` can report `image/jpeg` from 2 KB of random bytes. Read it before writing a test against anything that decodes a user-supplied file.

_Last updated: 2026-08-31 — Story 0022 (Shared searchable, server-side-filtered multi-select component): one widened pointer, no structural change. **Frontend / Browser** now names story 0022's own DOM-occlusion diagnostic (a hung Playwright click with no error anywhere, found via `document.elementFromPoint()` rather than a timing fix) and corrects the file count to eight, three of them still flat — the prior "four to six" count (below) had itself missed `RolesIndexTest.php` (present since task 0011), a stale under-count corrected in the same pass in [conventions/base-standards.md](../conventions/directory-structure.md#directory-structure). **Verified as unchanged rather than assumed:** the QA section, the backend section, the CI/Commands and pipeline-integration entries, and the security pointer — this story adds no new command, threshold or database strategy, and its Phase 4 findings are new instances of already-documented rules (see [security/livewire-authorization.md](../security/livewire-authorization/locked-properties.md#every-server-derived-property-is-locked-not-just-the-ids)), not a new authorization layer._

_Earlier revision notes: [testing--README.md](../history/testing--README.md)._
