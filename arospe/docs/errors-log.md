# Errors Log

A structured log of real mistakes made in this project and the concrete rule adopted to avoid repeating them. Not a general bug tracker — only entries that produced a lasting convention belong here.

**Older entries have been archived.** Entries dated 2026-07-21 through 2026-08-26 (the oldest twenty-six) were moved, byte-for-byte and unedited, to [errors-log-archive.md](errors-log-archive.md) to keep this file under a manageable size — history is never rewritten in place, only relocated. Any correction or narrowing blockquote that was already attached to an archived entry moved with it. Check the archive before assuming a mistake from before 2026-08-27 was never recorded.

## Browse by topic

An agent working on a specific domain can jump straight to the 1-3 relevant entries below instead of reading the whole log top to bottom — see [contracts.md](contracts/token-and-doc-rules.md#token-efficient-reading-and-dispatch-rule)'s Token-Efficient Reading and Dispatch Rule. Covers all 50 entries across this file and the archive; an entry touching more than one domain is listed under each. `(archive)` marks an entry that lives in [errors-log-archive.md](errors-log-archive.md).

**Livewire/Blade/Flux rendering & compilation quirks**
- [A Livewire computed called as a method is never memoised, and no test noticed](errors-log/2026-09-10-to-2026-09-23.md#a-livewire-computed-called-as-a-method-is-never-memoised-and-no-test-noticed--2026-09-23) — 2026-09-23
- [A typed int property bound to a number input is unset by a cleared box](errors-log/2026-09-10-to-2026-09-23.md#a-typed-int-property-bound-to-a-number-input-is-unset-by-a-cleared-box--2026-09-23) — 2026-09-23
- [addError on a real public property persists across requests](errors-log/2026-09-10-to-2026-09-23.md#adderror-on-a-real-public-property-persists-across-requests--2026-09-23) — 2026-09-23
- [CSS Grid's default `align-items: stretch` lets one tall sibling cell distort a Flux `<ui-field>`'s own internal row heights in its unrelated neighbours](errors-log/2026-09-10-to-2026-09-23.md#css-grids-default-align-items-stretch-lets-one-tall-sibling-cell-distort-a-flux-ui-fields-own-internal-row-heights-in-its-unrelated-neighbours--2026-09-11) — 2026-09-11
- [A `data-test` hook on `<flux:modal>` itself is always present, open or closed — a "the modal stayed open" assertion needs the hook on conditionally-rendered content inside it](errors-log/2026-09-10-to-2026-09-23.md#a-data-test-hook-on-fluxmodal-itself-is-always-present-open-or-closed--a-the-modal-stayed-open-assertion-needs-the-hook-on-conditionally-rendered-content-inside-it--2026-09-10) — 2026-09-10
- [A SECOND `->call()` on an already-mounted `Livewire::test()` component does not re-throw `AuthorizationException` the way the first one does](errors-log/2026-09-10-to-2026-09-23.md#a-second--call-on-an-already-mounted-livewiretest-component-does-not-re-throw-authorizationexception-the-way-the-first-one-does--2026-09-10) — 2026-09-10
- [Livewire skips `ConvertEmptyStringsToNull`/`TrimStrings`, and Laravel skips non-implicit rules for a blank string — the two combine to let a raw `''` reach a `DECIMAL` column](errors-log/2026-09-10-to-2026-09-23.md#livewire-skips-convertemptystringstonulltrimstrings-and-laravel-skips-non-implicit-rules-for-a-blank-string--the-two-combine-to-let-a-raw--reach-a-decimal-column--2026-09-10) — 2026-09-10
- [A `flux:fieldset` wrapping a Flux field silently swallows its auto-rendered validation error](errors-log/2026-09-01-to-2026-09-07.md#a-fluxfieldset-wrapping-a-flux-field-silently-swallows-its-auto-rendered-validation-error--2026-09-07) — 2026-09-07
- [Absolutely-positioned dropdown occludes a sibling via DOM order, not CSS](errors-log/2026-08-28-to-2026-08-31.md#an-absolutely-positioned-element-with-no-explicit-top-takes-its-static-position-from-dom-order-and-can-silently-occlude-a-sibling--2026-08-31) — 2026-08-31
- [A Livewire directive modifier's duration can't be interpolated into an attribute NAME](errors-log/2026-08-28-to-2026-08-31.md#a-livewire-directive-modifiers-duration-cannot-be-interpolated-as-part-of-a-component-tags-attribute-name--2026-08-31) — 2026-08-31
- [A bare `@disabled(...)` inside a `<flux:button>` tag corrupts the whole compiled view](errors-log/2026-08-28-to-2026-08-31.md#a-bare-disableddisabled-inside-a-fluxbutton-tags-attribute-list-corrupts-the-whole-compiled-view--2026-08-31) — 2026-08-31
- [Two `@directive(...)` calls in one component-tag attribute string silently fail to compile](errors-log/archive-2026-08-23-to-2026-08-26.md#two-directive-calls-in-one-blade-component-tags-attribute-string-silently-fail-to-compile--2026-08-26) — 2026-08-26 (archive)
- [A `null` Livewire property bound to a native `<select>` silently dropped the user's own pick](errors-log/archive-2026-07-21-to-2026-08-17.md#a-null-livewire-property-bound-to-a-native-select-silently-dropped-the-users-own-pick--2026-08-16) — 2026-08-16 (archive)
- [`disabled:cursor-not-allowed` on a Flux button was never the cursor the user saw](errors-log/archive-2026-07-21-to-2026-08-17.md#disabledcursor-not-allowed-on-a-flux-button-was-never-the-cursor-the-user-saw--2026-08-16) — 2026-08-16 (archive)
- [A conditionally-bound `tooltip` prop rendered an empty tooltip on every enabled row](errors-log/archive-2026-07-21-to-2026-08-17.md#a-conditionally-bound-fluxbutton-tooltip-prop-rendered-an-empty-tooltip-on-every-enabled-row--2026-08-16) — 2026-08-16 (archive)

**Testing/QA process & infrastructure**
- [A `Log::spy()` `withArgs()` closure that captures into a list records duplicates](errors-log/2026-09-10-to-2026-09-23.md#a-logspy-withargs-closure-that-captures-into-a-list-records-duplicates--2026-09-24) — 2026-09-24
- [A browser test piped through tail hangs forever, and pkill can kill its own shell](errors-log/2026-09-10-to-2026-09-23.md#a-browser-test-piped-through-tail-hangs-forever-and-pkill-can-kill-its-own-shell--2026-09-23) — 2026-09-23
- [A test that restates the implementation's formula cannot catch a units error](errors-log/2026-09-10-to-2026-09-23.md#a-test-that-restates-the-implementations-formula-cannot-catch-a-units-error--2026-09-20) — 2026-09-20
- [A SECOND `->call()` on an already-mounted `Livewire::test()` component does not re-throw `AuthorizationException` the way the first one does](errors-log/2026-09-10-to-2026-09-23.md#a-second--call-on-an-already-mounted-livewiretest-component-does-not-re-throw-authorizationexception-the-way-the-first-one-does--2026-09-10) — 2026-09-10
- [Two `php artisan test` invocations against the same worktree's testing database, run concurrently, produced ~47 spurious failures across completely unrelated tests](errors-log/2026-09-10-to-2026-09-23.md#two-php-artisan-test-invocations-against-the-same-worktrees-testing-database-run-concurrently-produced-47-spurious-failures-across-completely-unrelated-tests--2026-09-10) — 2026-09-10
- [A geography-entry factory override to `name` silently left `normalized_name` stale, making a search test fail against real search code](errors-log/2026-09-01-to-2026-09-07.md#a-geographyentryfactory-override-to-name-silently-left-normalized_name-stale-making-a-search-test-fail-against-real-search-code--2026-09-07) — 2026-09-07
- [Playwright's `selectOption` fires `change` unconditionally, so no `->select()`-driven test can be a null-`<select>` desync's regression net](errors-log/2026-09-01-to-2026-09-07.md#playwrights-selectoption-fires-change-unconditionally-so-no--select-driven-browser-test-can-be-the-regression-net-for-a-null-select-desync--2026-09-06) — 2026-09-06
- [A verification record listing two of three quality gates is a record of two gates](errors-log/archive-2026-08-23-to-2026-08-26.md#a-verification-record-that-lists-two-of-three-quality-gates-is-a-record-of-two-gates--2026-08-26) — 2026-08-26 (archive)
- [A count-based HTML assertion counted a wrapper element it never meant to include](errors-log/archive-2026-08-17-to-2026-08-21.md#a-count-based-assertion-over-rendered-html-counted-a-wrapper-element-it-never-meant-to-include--2026-08-21) — 2026-08-21 (archive)
- [Both per-change quality gates are scoped by default, and both silently passed](errors-log/archive-2026-08-17-to-2026-08-21.md#both-of-this-projects-per-change-quality-gates-are-scoped-by-default-and-both-silently-passed--2026-08-20) — 2026-08-20 (archive)
- [A planned test asserted a refusal by `verified`, a middleware that refuses nobody in this app](errors-log/archive-2026-08-17-to-2026-08-21.md#a-planned-test-asserted-a-refusal-by-verified-a-middleware-that-refuses-nobody-in-this-app--2026-08-20) — 2026-08-20 (archive)
- [A Pest `arch()` rule over an array of namespaces shipped green while proving nothing](errors-log/archive-2026-08-17-to-2026-08-21.md#a-pest-arch-rule-over-an-array-of-namespaces-shipped-green-while-proving-nothing--2026-08-18) — 2026-08-18 (archive)
- [Two agents dispatched in parallel both wrote to the same Blade view](errors-log/archive-2026-07-21-to-2026-08-17.md#two-agents-dispatched-in-parallel-both-wrote-to-the-same-blade-view--2026-08-16) — 2026-08-16 (archive)
- [A test asserted against a fixture address the local `.env` also pointed `SUPER_ADMIN_EMAIL` at](errors-log/archive-2026-07-21-to-2026-08-17.md#a-test-asserted-against-a-fixture-address-that-the-local-env-also-pointed-super_admin_email-at--2026-08-12) — 2026-08-12 (archive)

**Environment & infrastructure gotchas** (stale build artifacts, container/db config, resource limits)
- [A finite Imagick `TIME` limit is captured for the whole worker, so every image conversion failed once a parallel test worker passed 60 seconds of uptime](errors-log/2026-09-10-to-2026-09-23.md#a-finite-imagick-time-limit-is-captured-for-the-whole-worker-so-every-image-conversion-failed-once-a-parallel-test-worker-passed-60-seconds-of-uptime--2026-09-24) — 2026-09-24
- [A `.env.testing` missing most of `.env`'s content produced a wide spray of unrelated-looking Feature test failures](errors-log/2026-09-01-to-2026-09-07.md#a-envtesting-missing-most-of-envs-content-produced-a-wide-spray-of-unrelated-looking-feature-test-failures--2026-09-06) — 2026-09-06
- [GitHub Actions' Ubuntu runner ships `libheif` with no AVIF encoder, so Imagick silently wrote a 0-byte `.avif`](errors-log/2026-09-01-to-2026-09-07.md#github-actions-ubuntu-runner-ships-libheif-with-no-avif-encoder-so-imagick-silently-wrote-a-0-byte-avif--2026-09-06) — 2026-09-06
- [A stale `public/hot` file made all 19 browser tests time out, misread as real UI bugs](errors-log/2026-08-28-to-2026-08-31.md#a-stale-publichot-file-from-an-old-npm-run-dev-session-made-all-19-browser-tests-time-out-misread-as-real-ui-bugs--2026-08-28) — 2026-08-28
- [An Imagick resource limit was requested in the wrong unit, masked by `policy.xml` clamping it](errors-log/2026-08-28-to-2026-08-31.md#an-imagick-resource-limit-was-requested-in-the-wrong-unit-masked-by-the-hosts-own-policyxml-silently-clamping-it--2026-08-28) — 2026-08-28
- [A "runs as non-root" claim was re-verified using the wrong `sail` invocation](errors-log/2026-08-28-to-2026-08-31.md#a-test-suites-own-runs-as-non-root-claim-was-re-verified-using-the-wrong-sail-invocation--2026-08-28) — 2026-08-28
- [The full test suite degrades under repeated heavy runs in one session, unrelated to any code change](errors-log/2026-08-28-to-2026-08-31.md#the-full-test-suite-run-repeatedly-and-heavily-in-one-session-degrades-in-ways-unrelated-to-any-code-change--2026-08-28) — 2026-08-28
- [A missing `.env.testing` let a self-healing `migrate:fresh` wipe the shared dev database](errors-log/archive-2026-08-23-to-2026-08-26.md#a-missing-envtesting-file-let-a-self-healing-migratefresh-wipe-the-shared-dev-database--2026-08-26) — 2026-08-26 (archive)

**Database/transactions/migrations**
- [`DB::transaction($fn, attempts: N)` retried a closure that mutated a model created outside it](errors-log/2026-09-01-to-2026-09-07.md#dbtransactionfn-attempts-n-retried-a-closure-that-mutated-a-model-created-outside-it-producing-a-silent-lost-update-reported-as-success--2026-09-04) — 2026-09-04
- [Wrapping existing code in `DB::transaction()` moved a cache flush nobody had written](errors-log/archive-2026-08-17-to-2026-08-21.md#wrapping-existing-code-in-a-dbtransaction-moved-a-cache-flush-nobody-had-written--2026-08-21) — 2026-08-21 (archive)
- [A redundant `users_uuid_unique` index survived the UUID primary-key conversion](errors-log/archive-2026-07-21-to-2026-08-17.md#a-redundant-users_uuid_unique-index-survived-the-uuid-primary-key-conversion--2026-08-12) — 2026-08-12 (archive)

**Authorization/security**
- [A scope exclusion named screens while the story edited a class those screens share](errors-log/archive-2026-08-23-to-2026-08-26.md#a-scope-exclusion-named-screens-while-the-story-edited-a-class-those-screens-share--2026-08-24) — 2026-08-24 (archive)
- [Wrapping existing code in `DB::transaction()` moved a permission-cache flush nobody had written](errors-log/archive-2026-08-17-to-2026-08-21.md#wrapping-existing-code-in-a-dbtransaction-moved-a-cache-flush-nobody-had-written--2026-08-21) — 2026-08-21 (archive)
- [A guard took the state it was guarding as a parameter, reopening its own hole one level up](errors-log/archive-2026-08-17-to-2026-08-21.md#a-guard-took-the-state-it-was-guarding-as-a-parameter-reopening-its-own-hole-one-level-up--2026-08-20) — 2026-08-20 (archive)
- [A security page documented the vulnerable code as current, because it was written before its own fix](errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20) — 2026-08-20 (archive)
- [Two of three security-audit rounds found the flaw in the previous round's fix](errors-log/archive-2026-08-17-to-2026-08-21.md#two-of-the-three-security-audit-rounds-found-the-flaw-in-the-previous-rounds-fix--2026-08-19) — 2026-08-19 (archive)
- [A listener read the pre-save value with `getOriginal()`, already overwritten by `save()`](errors-log/archive-2026-08-17-to-2026-08-21.md#a-listener-read-the-pre-save-value-with-getoriginal-which-save-had-already-overwritten--2026-08-17) — 2026-08-17 (archive)
- [Freeing a deleted user's email left their password-reset token live](errors-log/archive-2026-07-21-to-2026-08-17.md#freeing-a-deleted-users-email-left-their-password-reset-token-live--2026-08-14) — 2026-08-14 (archive)
- [A row carrying the configured email was treated as proof of mailbox ownership](errors-log/archive-2026-07-21-to-2026-08-17.md#a-row-carrying-the-configured-email-was-treated-as-proof-of-mailbox-ownership--2026-08-09) — 2026-08-09 (archive)

**Actions/API contract design** (parameter defaults, ambiguity)
- [An action's own parameter default reintroduced the omission ambiguity its stricter collaborator was built to close](errors-log/2026-09-01-to-2026-09-07.md#an-actions-own-parameter-default-reintroduced-the-omission-ambiguity-its-stricter-collaborator-was-built-to-close--2026-09-01) — 2026-09-01
- [A guard took the state it was guarding as a parameter, reopening its own hole one level up](errors-log/archive-2026-08-17-to-2026-08-21.md#a-guard-took-the-state-it-was-guarding-as-a-parameter-reopening-its-own-hole-one-level-up--2026-08-20) — 2026-08-20 (archive)

**Docs/process/review quality**
- [One docs pass reported two gaps that were not there, both marked "verified"](errors-log/2026-08-28-to-2026-08-31.md#one-docs-pass-reported-two-gaps-that-were-not-there-both-marked-verified--2026-08-29) — 2026-08-29
- [A reviewer's "correction" replaced an accurate technical explanation with a wrong one, unverified](errors-log/archive-2026-08-23-to-2026-08-26.md#a-reviewers-correction-replaced-an-accurate-technical-explanation-with-a-wrong-one-unverified--2026-08-24) — 2026-08-24 (archive)
- [A deferred story's findings were claims about a tree that no longer existed](errors-log/archive-2026-08-23-to-2026-08-26.md#a-deferred-storys-findings-were-claims-about-a-tree-that-no-longer-existed-and-one-of-them-would-have-reopened-a-bug-in-this-log--2026-08-23) — 2026-08-23 (archive)
- [A security page documented the vulnerable code as current, because it was written before its own fix](errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20) — 2026-08-20 (archive)
- [A task file's relative links broke silently when it moved to `in-progress/`/`done/`](errors-log/archive-2026-08-17-to-2026-08-21.md#a-task-files-relative-links-broke-silently-when-it-moved-to-in-progressdone--2026-08-17) — 2026-08-17 (archive)
- [A doc's "this app has no X yet" claim outlived the X by two tasks](errors-log/archive-2026-07-21-to-2026-08-17.md#a-docs-this-app-has-no-x-yet-claim-outlived-the-x-by-two-tasks--2026-08-13) — 2026-08-13 (archive)
- [Gherkin scenarios written with a generic "I" and bundled multi-action steps](errors-log/archive-2026-07-21-to-2026-08-17.md#gherkin-scenarios-written-with-a-generic-i-and-bundled-multi-action-steps--2026-07-21) — 2026-07-21 (archive)

## Entry format

Newest entry first, directly below this line. Every entry uses this exact structure:

```markdown
## <short problem title> — <YYYY-MM-DD>
- **Context**: what was being worked on
- **What happened**: observed symptom/error
- **Root cause**: why it happened
- **Fix applied**: what changed, with a file path or commit/PR reference
- **How to avoid it next time**: a concrete, actionable rule — link to a `conventions/` doc if one covers it
```

## Entries

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Entries 2026-09-10 to 2026-09-24](errors-log/2026-09-10-to-2026-09-23.md) | the topic index points at an entry dated 2026-09-10 through 2026-09-24. | A `Log::spy()` `withArgs()` closure that captures into a list records duplicates; A Livewire computed called as a method is never memoised, and...; A typed int property bound to a number input is unset by a cl...; addError on a real public property persists across requests; A browser test piped through tail hangs forever, and pkill ca...; A test that restates the implementation's formula cannot catc...; CSS Grid's default `align-items: stretch` lets one tall sibli...; A `data-test` hook on `<flux:modal>` itself is always present...; A SECOND `->call()` on an already-mounted `Livewire::test()`...; Two `php artisan test` invocations against the same worktree'...; Livewire skips `ConvertEmptyStringsToNull`/`TrimStrings`, and... |
| [Entries 2026-09-01 to 2026-09-07](errors-log/2026-09-01-to-2026-09-07.md) | the topic index points at an entry dated 2026-09-01 through 2026-09-07. | A `GeographyEntryFactory` override to `name` silently left `n...; A `flux:fieldset` wrapping a Flux field silently swallows its...; A `.env.testing` missing most of `.env`'s content produced a...; Playwright's `selectOption` fires `change` unconditionally, s...; GitHub Actions' Ubuntu runner ships `libheif` with no AVIF en...; `DB::transaction($fn, attempts: N)` retried a closure that mu...; An action's own parameter default reintroduced the omission a... |
| [Entries 2026-08-28 to 2026-08-31](errors-log/2026-08-28-to-2026-08-31.md) | the topic index points at an entry dated 2026-08-28 through 2026-08-31. | An absolutely-positioned element with no explicit `top` takes...; A Livewire directive modifier's duration cannot be interpolat...; A bare `@disabled(...)`/`:disabled="..."` inside a `<flux:but...; One docs pass reported two gaps that were not there, both mar...; A stale `public/hot` file from an old `npm run dev` session m...; An Imagick resource limit was requested in the wrong unit, ma...; A test suite's own "runs as non-root" claim was re-verified u...; The full test suite, run repeatedly and heavily in one sessio... |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
