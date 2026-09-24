# Errors Log Archive

Archived entries from [errors-log.md](errors-log.md) — moved here **byte-for-byte, unedited**, once that file grew past a manageable size. This is not a second, independent log: the entry format, the rule that history is never rewritten in place (only appended to via a dated "Correction —"/"Narrowed —" blockquote), and every other convention are defined once, in [errors-log.md](errors-log.md#entry-format) — read that file's intro first if you haven't.

These are the **oldest** entries (newest-first, like the main log), covering 2026-07-21 through 2026-08-26. Newer entries live in [errors-log.md](errors-log.md).

## Browse by topic

An agent working on a specific domain can jump straight to the 1-3 relevant entries below instead of reading the whole log top to bottom — see [contracts.md](contracts/token-and-doc-rules.md#token-efficient-reading-and-dispatch-rule)'s Token-Efficient Reading and Dispatch Rule. Covers all 50 entries across this file and [errors-log.md](errors-log.md); an entry touching more than one domain is listed under each. `(main log)` marks an entry that lives in [errors-log.md](errors-log.md).

**Livewire/Blade/Flux rendering & compilation quirks**
- [A `null` Livewire property bound to a native `<select>` silently dropped the user's own pick](errors-log/archive-2026-07-21-to-2026-08-17.md#a-null-livewire-property-bound-to-a-native-select-silently-dropped-the-users-own-pick--2026-08-16) — 2026-08-16
- [`disabled:cursor-not-allowed` on a Flux button was never the cursor the user saw](errors-log/archive-2026-07-21-to-2026-08-17.md#disabledcursor-not-allowed-on-a-flux-button-was-never-the-cursor-the-user-saw--2026-08-16) — 2026-08-16
- [A conditionally-bound `tooltip` prop rendered an empty tooltip on every enabled row](errors-log/archive-2026-07-21-to-2026-08-17.md#a-conditionally-bound-fluxbutton-tooltip-prop-rendered-an-empty-tooltip-on-every-enabled-row--2026-08-16) — 2026-08-16
- [Absolutely-positioned dropdown occludes a sibling via DOM order, not CSS](errors-log/2026-08-28-to-2026-08-31.md#an-absolutely-positioned-element-with-no-explicit-top-takes-its-static-position-from-dom-order-and-can-silently-occlude-a-sibling--2026-08-31) — 2026-08-31 (main log)
- [A Livewire directive modifier's duration can't be interpolated into an attribute NAME](errors-log/2026-08-28-to-2026-08-31.md#a-livewire-directive-modifiers-duration-cannot-be-interpolated-as-part-of-a-component-tags-attribute-name--2026-08-31) — 2026-08-31 (main log)
- [A bare `@disabled(...)` inside a `<flux:button>` tag corrupts the whole compiled view](errors-log/2026-08-28-to-2026-08-31.md#a-bare-disableddisabled-inside-a-fluxbutton-tags-attribute-list-corrupts-the-whole-compiled-view--2026-08-31) — 2026-08-31 (main log)
- [Two `@directive(...)` calls in one component-tag attribute string silently fail to compile](errors-log/archive-2026-08-23-to-2026-08-26.md#two-directive-calls-in-one-blade-component-tags-attribute-string-silently-fail-to-compile--2026-08-26) — 2026-08-26

**Testing/QA process & infrastructure**
- [A Pest `arch()` rule over an array of namespaces shipped green while proving nothing](errors-log/archive-2026-08-17-to-2026-08-21.md#a-pest-arch-rule-over-an-array-of-namespaces-shipped-green-while-proving-nothing--2026-08-18) — 2026-08-18
- [Two agents dispatched in parallel both wrote to the same Blade view](errors-log/archive-2026-07-21-to-2026-08-17.md#two-agents-dispatched-in-parallel-both-wrote-to-the-same-blade-view--2026-08-16) — 2026-08-16
- [A test asserted against a fixture address the local `.env` also pointed `SUPER_ADMIN_EMAIL` at](errors-log/archive-2026-07-21-to-2026-08-17.md#a-test-asserted-against-a-fixture-address-that-the-local-env-also-pointed-super_admin_email-at--2026-08-12) — 2026-08-12
- [A verification record listing two of three quality gates is a record of two gates](errors-log/archive-2026-08-23-to-2026-08-26.md#a-verification-record-that-lists-two-of-three-quality-gates-is-a-record-of-two-gates--2026-08-26) — 2026-08-26
- [A count-based HTML assertion counted a wrapper element it never meant to include](errors-log/archive-2026-08-17-to-2026-08-21.md#a-count-based-assertion-over-rendered-html-counted-a-wrapper-element-it-never-meant-to-include--2026-08-21) — 2026-08-21
- [Both per-change quality gates are scoped by default, and both silently passed](errors-log/archive-2026-08-17-to-2026-08-21.md#both-of-this-projects-per-change-quality-gates-are-scoped-by-default-and-both-silently-passed--2026-08-20) — 2026-08-20
- [A planned test asserted a refusal by `verified`, a middleware that refuses nobody in this app](errors-log/archive-2026-08-17-to-2026-08-21.md#a-planned-test-asserted-a-refusal-by-verified-a-middleware-that-refuses-nobody-in-this-app--2026-08-20) — 2026-08-20

**Environment & infrastructure gotchas** (stale build artifacts, container/db config, resource limits)
- [A stale `public/hot` file made all 19 browser tests time out, misread as real UI bugs](errors-log/2026-08-28-to-2026-08-31.md#a-stale-publichot-file-from-an-old-npm-run-dev-session-made-all-19-browser-tests-time-out-misread-as-real-ui-bugs--2026-08-28) — 2026-08-28 (main log)
- [An Imagick resource limit was requested in the wrong unit, masked by `policy.xml` clamping it](errors-log/2026-08-28-to-2026-08-31.md#an-imagick-resource-limit-was-requested-in-the-wrong-unit-masked-by-the-hosts-own-policyxml-silently-clamping-it--2026-08-28) — 2026-08-28 (main log)
- [A "runs as non-root" claim was re-verified using the wrong `sail` invocation](errors-log/2026-08-28-to-2026-08-31.md#a-test-suites-own-runs-as-non-root-claim-was-re-verified-using-the-wrong-sail-invocation--2026-08-28) — 2026-08-28 (main log)
- [The full test suite degrades under repeated heavy runs in one session, unrelated to any code change](errors-log/2026-08-28-to-2026-08-31.md#the-full-test-suite-run-repeatedly-and-heavily-in-one-session-degrades-in-ways-unrelated-to-any-code-change--2026-08-28) — 2026-08-28 (main log)
- [A missing `.env.testing` let a self-healing `migrate:fresh` wipe the shared dev database](errors-log/archive-2026-08-23-to-2026-08-26.md#a-missing-envtesting-file-let-a-self-healing-migratefresh-wipe-the-shared-dev-database--2026-08-26) — 2026-08-26

**Database/transactions/migrations**
- [A redundant `users_uuid_unique` index survived the UUID primary-key conversion](errors-log/archive-2026-07-21-to-2026-08-17.md#a-redundant-users_uuid_unique-index-survived-the-uuid-primary-key-conversion--2026-08-12) — 2026-08-12
- [`DB::transaction($fn, attempts: N)` retried a closure that mutated a model created outside it](errors-log/2026-09-01-to-2026-09-07.md#dbtransactionfn-attempts-n-retried-a-closure-that-mutated-a-model-created-outside-it-producing-a-silent-lost-update-reported-as-success--2026-09-04) — 2026-09-04 (main log)
- [Wrapping existing code in `DB::transaction()` moved a cache flush nobody had written](errors-log/archive-2026-08-17-to-2026-08-21.md#wrapping-existing-code-in-a-dbtransaction-moved-a-cache-flush-nobody-had-written--2026-08-21) — 2026-08-21

**Authorization/security**
- [Two of three security-audit rounds found the flaw in the previous round's fix](errors-log/archive-2026-08-17-to-2026-08-21.md#two-of-the-three-security-audit-rounds-found-the-flaw-in-the-previous-rounds-fix--2026-08-19) — 2026-08-19
- [A listener read the pre-save value with `getOriginal()`, already overwritten by `save()`](errors-log/archive-2026-08-17-to-2026-08-21.md#a-listener-read-the-pre-save-value-with-getoriginal-which-save-had-already-overwritten--2026-08-17) — 2026-08-17
- [Freeing a deleted user's email left their password-reset token live](errors-log/archive-2026-07-21-to-2026-08-17.md#freeing-a-deleted-users-email-left-their-password-reset-token-live--2026-08-14) — 2026-08-14
- [A row carrying the configured email was treated as proof of mailbox ownership](errors-log/archive-2026-07-21-to-2026-08-17.md#a-row-carrying-the-configured-email-was-treated-as-proof-of-mailbox-ownership--2026-08-09) — 2026-08-09
- [A scope exclusion named screens while the story edited a class those screens share](errors-log/archive-2026-08-23-to-2026-08-26.md#a-scope-exclusion-named-screens-while-the-story-edited-a-class-those-screens-share--2026-08-24) — 2026-08-24
- [Wrapping existing code in `DB::transaction()` moved a permission-cache flush nobody had written](errors-log/archive-2026-08-17-to-2026-08-21.md#wrapping-existing-code-in-a-dbtransaction-moved-a-cache-flush-nobody-had-written--2026-08-21) — 2026-08-21
- [A guard took the state it was guarding as a parameter, reopening its own hole one level up](errors-log/archive-2026-08-17-to-2026-08-21.md#a-guard-took-the-state-it-was-guarding-as-a-parameter-reopening-its-own-hole-one-level-up--2026-08-20) — 2026-08-20
- [A security page documented the vulnerable code as current, because it was written before its own fix](errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20) — 2026-08-20

**Actions/API contract design** (parameter defaults, ambiguity)
- [An action's own parameter default reintroduced the omission ambiguity its stricter collaborator was built to close](errors-log/2026-09-01-to-2026-09-07.md#an-actions-own-parameter-default-reintroduced-the-omission-ambiguity-its-stricter-collaborator-was-built-to-close--2026-09-01) — 2026-09-01 (main log)
- [A guard took the state it was guarding as a parameter, reopening its own hole one level up](errors-log/archive-2026-08-17-to-2026-08-21.md#a-guard-took-the-state-it-was-guarding-as-a-parameter-reopening-its-own-hole-one-level-up--2026-08-20) — 2026-08-20

**Docs/process/review quality**
- [A task file's relative links broke silently when it moved to `in-progress/`/`done/`](errors-log/archive-2026-08-17-to-2026-08-21.md#a-task-files-relative-links-broke-silently-when-it-moved-to-in-progressdone--2026-08-17) — 2026-08-17
- [A doc's "this app has no X yet" claim outlived the X by two tasks](errors-log/archive-2026-07-21-to-2026-08-17.md#a-docs-this-app-has-no-x-yet-claim-outlived-the-x-by-two-tasks--2026-08-13) — 2026-08-13
- [Gherkin scenarios written with a generic "I" and bundled multi-action steps](errors-log/archive-2026-07-21-to-2026-08-17.md#gherkin-scenarios-written-with-a-generic-i-and-bundled-multi-action-steps--2026-07-21) — 2026-07-21
- [One docs pass reported two gaps that were not there, both marked "verified"](errors-log/2026-08-28-to-2026-08-31.md#one-docs-pass-reported-two-gaps-that-were-not-there-both-marked-verified--2026-08-29) — 2026-08-29 (main log)
- [A reviewer's "correction" replaced an accurate technical explanation with a wrong one, unverified](errors-log/archive-2026-08-23-to-2026-08-26.md#a-reviewers-correction-replaced-an-accurate-technical-explanation-with-a-wrong-one-unverified--2026-08-24) — 2026-08-24
- [A deferred story's findings were claims about a tree that no longer existed](errors-log/archive-2026-08-23-to-2026-08-26.md#a-deferred-storys-findings-were-claims-about-a-tree-that-no-longer-existed-and-one-of-them-would-have-reopened-a-bug-in-this-log--2026-08-23) — 2026-08-23
- [A security page documented the vulnerable code as current, because it was written before its own fix](errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20) — 2026-08-20

## Archived entries

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Archived entries 2026-08-23 to 2026-08-26](errors-log/archive-2026-08-23-to-2026-08-26.md) | the topic index points at an archived entry dated 2026-08-23 through 2026-08-26. | Two `@directive(...)` calls in one Blade component tag's attr...; A verification record that lists two of three quality gates i...; A missing `.env.testing` file let a self-healing `migrate:fre...; A scope exclusion named screens while the story edited a clas...; A reviewer's "correction" replaced an accurate technical expl...; A deferred story's findings were claims about a tree that no... |
| [Archived entries 2026-08-17 to 2026-08-21](errors-log/archive-2026-08-17-to-2026-08-21.md) | the topic index points at an archived entry dated 2026-08-17 through 2026-08-21. | Wrapping existing code in a `DB::transaction()` moved a cache...; A count-based assertion over rendered HTML counted a wrapper...; Both of this project's per-change quality gates are scoped by...; A planned test asserted a refusal by `verified`, a middleware...; A guard took the state it was guarding as a parameter, reopen...; A security page documented the vulnerable code as current, be...; Two of the three security-audit rounds found the flaw in the...; A Pest `arch()` rule over an array of namespaces shipped gree...; A listener read the pre-save value with `getOriginal()`, whic...; A task file's relative links broke silently when it moved to... |
| [Archived entries 2026-07-21 to 2026-08-17](errors-log/archive-2026-07-21-to-2026-08-17.md) | the topic index points at an archived entry dated 2026-07-21 through 2026-08-17. | A `null` Livewire property bound to a native `<select>` silen...; `disabled:cursor-not-allowed` on a Flux button was never the...; A conditionally-bound `flux:button` `tooltip` prop rendered a...; Two agents dispatched in parallel both wrote to the same Blad...; Freeing a deleted user's email left their password-reset toke...; A doc's "this app has no X yet" claim outlived the X by two t...; A redundant `users_uuid_unique` index survived the UUID prima...; A test asserted against a fixture address that the local `.en...; A row carrying the configured email was treated as proof of m...; Gherkin scenarios written with a generic "I" and bundled mult... |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
