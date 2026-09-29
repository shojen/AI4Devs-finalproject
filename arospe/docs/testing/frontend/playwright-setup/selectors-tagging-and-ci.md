# Browser Test Setup — Selectors, tagging, CI and examples

> Part of [Browser Test Setup](../playwright-setup.md). **Read this part when:** you choose selectors, tag/name/parallelize browser tests, or check how CI runs them. The other parts are listed in the [hub](../playwright-setup.md#table-of-contents).

## Selector strategy

**Prefer user-visible text and roles over brittle CSS/DOM selectors.** This app is well suited to it: its Blade/Flux views expose real labels and button text you can target directly.

- ✅ Buttons carry visible text: `{{ __('Log in') }}`, `{{ __('Remove passkey') }}`, `{{ __('Enable 2FA') }}` (see `resources/views/livewire/auth/login.blade.php` and `resources/views/livewire/settings/security.blade.php`). Target these with `->click('Log in')` / `->assertSee('Remove passkey')`.
- ✅ `flux:input` renders an accessible `:label` (e.g. `Email address`, `Password`), so `->fill('email', ...)` binds by name/label without a custom selector.
- ⚠️ `data-test` attributes already exist on a few elements (`data-test="login-button"` on the login submit, `data-test="update-password-button"` in the security view). These are the repo's existing hook style — **not** `data-testid`. Lean on visible text first; use an existing `data-test` hook only when text is genuinely ambiguous (e.g. two identically-labelled buttons on one page). Adding new `data-test` hooks is an application-code change — request it from a frontend owner rather than editing views from a docs/QA task.
- ⚠️ **On an admin list screen, "prefer visible text" inverts, and the story that builds the screen owns the hooks.** The Users, Roles and (since task 0018) Sales Regions screens render **icon-only** row controls across many rows, so no visible text identifies a row's action at all — `data-test="edit-region-{id}"` and its siblings are the *only* correct selector there, and each is present on both the enabled and the disabled branch precisely so a test selects the same control either way. Two further traps that screen's tests hit and that generalise: a **page-global substring assertion is unsafe once a second row exists** (`assertSee('0%')` matches inside `10%`, which is why that screen added a row-scoped `data-test="rate-region-{id}"` and a helper that reads only that cell), and a **disabled-state helper must match the real `disabled="disabled"` attribute**, never a bare `disabled` substring — Flux's compiled class list carries the literal `disabled:opacity-75` on the *enabled* branch too, so the naive helper reports every control as disabled and the test can never fail.

Rationale: a test that asserts "the user sees `Remove passkey`" survives markup refactors and verifies what the user actually experiences; a test keyed to `#submit-btn` or a Tailwind class breaks on cosmetic changes without any behavior changing. This is the same behavior-over-implementation principle as [../philosophy.md](../../philosophy.md#3-tests-coupled-to-implementation-instead-of-behavior).

## Test tagging, naming, and parallelization

- **Naming:** follow the existing convention — name the test after the behavior and condition, not `it('works')`. See [../qa/coverage-review-checklist.md](../../qa/coverage-review-checklist.md) and the philosophy doc's [naming anti-pattern](../../philosophy.md#5-generic-names-like-test_it_works).
- **Tagging (proposal, not yet in place):** this repo has **no** `@smoke` / `@regression` tag convention today. Pest supports grouping via `->group('smoke')` on a test, which then runs with `php artisan test --group=smoke`. Adopting a `smoke` / `regression` grouping for browser tests is a reasonable proposal — but document it as *proposed*, exactly as the CI coverage gate is marked proposed-not-enacted in [../ci/pipeline-integration.md](../../ci/pipeline-integration.md). Do not describe tags as if they already exist.
  - `TODO: team to decide whether to adopt Pest groups (e.g. smoke / regression / browser) for selective CI runs, and record the decision as an ADR in docs/decisions/.`
- **Parallelization:** Laravel's `--parallel` needs `brianium/paratest`, which is **not installed** (see [../ci/commands.md](../../ci/commands.md#run-in-parallel)). Pest's own test **sharding** (splitting tests across parallel CI jobs) is listed as a Pest 4 feature in the skill file, but is likewise not configured here. Treat both as future options gated on real suite-runtime pain, not as available today.

## CI integration

**CI runs the browser suite — on Chromium only.** `.github/workflows/tests.yml` runs `php artisan test --parallel`, pinned to a single PHP version (**`8.5`** since 2026-09-06 — see [../ci/pipeline-integration.md](../../ci/pipeline-integration.md#current-state-real-as-of-this-writing)), and since the `Browser` testsuite is declared in `phpunit.xml`, that single command now executes browser tests too. Task 0006b added the step that makes this possible, immediately after `Install Node Dependencies`:

```yaml
# .github/workflows/tests.yml
- name: Install Playwright Browser (Chromium)
  run: npx --no playwright install --with-deps chromium
```

This step was not optional politeness: without it the pipeline would **hard-fail**, not skip. Verified empirically during task 0006b by hiding the browser binaries and rerunning the canary — the plugin throws (`PlaywrightOutdatedException`) and the run exits non-zero; it has no graceful-degradation path. Declaring a `Browser` testsuite and leaving `tests.yml` alone would have turned the run red. The `--no` flag is a deliberate supply-chain guard — see [../../security/ci-workflow-hardening.md](../../../security/ci-workflow-hardening.md) for why bare `npx` was rejected. (This paragraph originally read "three green matrix legs" — stale since `tests.yml`'s PHP matrix was dropped to a single `8.5` version on 2026-09-06.)

Two things this does **not** mean — do not overstate them:

- **It is not cross-browser CI coverage.** Only Chromium is installed and only Chromium is exercised. Firefox and WebKit remain unverified everywhere, per the [known caveat](status-structure-and-syntax.md#known-caveat-missing-system-libraries-on-this-host). Whether cross-browser runs are worth their runtime is still an open backlog decision.
- **There is no browser-specific trigger policy.** Browser tests simply inherit whatever `tests.yml` already does for everything else — every push/PR to `develop`/`main`/`master`/`workos`. Nobody has decided whether a growing browser suite should instead run on a schedule or behind a label to keep the pipeline fast; that question is still open and was explicitly left out of task 0006b's scope.

Coverage gating is a separate, still-unenacted proposal — see [../ci/pipeline-integration.md](../../ci/pipeline-integration.md); adding the browser suite did not change it.

## Correct vs. incorrect examples

❌ Incorrect — brittle, keyed to implementation detail, and no JS-error check:

```php
it('logs in', function () {
    visit('/login')
        ->fill('#email-input-field', 'ada@example.com')
        ->fill('#password-input-field', 'password')
        ->click('.btn.btn-primary.w-full'); // breaks on any CSS refactor
});
```

✅ Correct — targets visible text/labels, asserts observable outcome, checks for JS errors:

```php
// Real routes/labels: routes/web.php, resources/views/livewire/auth/login.blade.php
it('signs an existing user in and lands them on the dashboard', function () {
    $user = User::factory()->create();

    visit('/login')
        ->assertNoJavaScriptErrors()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('Log in')
        ->assertSee('Dashboard');
});
```

_Last updated: 2026-09-26 — Story 0063 (blog posts list + editor): added `tests/Browser/BlogPosts/` to the folder listing in [status-structure-and-syntax.md](status-structure-and-syntax.md). Earlier, 2026-09-23 — Story 0055 (orders list + detail/editor UI). Added `tests/Browser/Orders/` to the folder listing and the piped-output hang finding (redirect a browser run to a file; the leaked `run-server` keeps the pipe open) beside the orphaned-process section. Earlier updates (2026-07-19 through 2026-09-15) covered: the suite's install/wiring and first files (0006b), the waiting rules and icon-only selector ⚠️ (0018), the `wait(n)`/upload/duplicate-hook limits (0020), the orphaned-process finding (0021), the occlusion diagnostic (0022), and the `retry(3, ..., 250)` CI-flake mitigation with its confirming instances (0024b, 2026-09-06); the `pkill` self-match caveat (0046). Folded into this single line per [contracts.md](../../../contracts/token-and-doc-rules.md#doc-growth-management-rule); see git history for the full prior chain if needed._
