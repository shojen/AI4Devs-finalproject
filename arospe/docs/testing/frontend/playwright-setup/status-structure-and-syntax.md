# Browser Test Setup — Status, folder structure and syntax

> Part of [Browser Test Setup](../playwright-setup.md). **Read this part when:** you set up or locate browser tests: install status, the `tests/Browser/` layout, and real Pest browser syntax. The other parts are listed in the [hub](../playwright-setup.md#table-of-contents).

## Current status: installed

The browser-testing plugin is now a real dependency of this project. The two install steps below are **done** (verified against `composer.json`, `composer.lock`, and `package.json`):

1. ✅ **Pest browser plugin added** — `pestphp/pest-plugin-browser` (`^4.3`, resolved to `v4.3.1`) is in `composer.json`'s `require-dev` and installed under `vendor/pestphp/pest-plugin-browser/`. It pulled in a set of `amphp/*` transitive dependencies; those are internal to the plugin and not something you interact with directly.

   ```bash
   composer require pestphp/pest-plugin-browser --dev
   ```

2. ✅ **Playwright installed as a dev dependency** — `playwright` (`^1.61.1`) is in `package.json`'s `devDependencies`.

   ```bash
   npm install playwright@latest --save-dev
   ```

3. ✅ **Browser binaries downloaded** — the confirmed command is `npx playwright install` (no TODO — this is the real command that was run). It downloads the Chromium, Firefox, and WebKit binaries into `~/.cache/ms-playwright/`, which is a **machine-local cache, not committed to the repo**. Anyone setting up a fresh machine or a CI runner must run it once before browser tests can execute:

   ```bash
   npx playwright install
   ```

4. ✅ **The `tests/Browser/` suite is wired up** (task 0006b) — the suite is declared in `phpunit.xml`, gets `RefreshDatabase` through `tests/Pest.php`, ignores its own screenshots, and holds a first real test. Details in [Folder structure](#folder-structure) below; CI's side of it in [CI integration](selectors-tagging-and-ci.md#ci-integration).

### Known caveat: missing system libraries on this host

During `npx playwright install`, host validation warned that several system libraries are missing on this machine — `libgtk-4`, various GStreamer libraries, `libflite`, `libmanette`, `libsecret`, and others (mostly WebKit/Firefox media/UI dependencies). This is **not a broken install**:

- **Chromium** — the default browser Pest drives — does not appear to need these libraries, so the default browser-test path works.
- **Firefox / WebKit** runs may be unreliable on this host until those libraries are present. Installing them is done with `sudo npx playwright install --with-deps`, which installs OS-level system packages. That is a **system change requiring separate approval** and was **not run** as part of this setup — it is out of scope here. Treat Firefox/WebKit runs as unverified on this machine until it is.

> This caveat is about **this developer host only**, and task 0006b did not change it. CI is a different machine and a different answer: the workflow step added by that task runs `npx --no playwright install --with-deps chromium`, so the GitHub-hosted runner installs its own OS-level packages on a fresh, disposable VM each run — no approval question there, because nothing persists. It installs them for **Chromium only**, so it is not evidence that Firefox/WebKit work anywhere.

## Folder structure

Browser tests live in `tests/Browser/`, a sibling of the existing suites. This mirrors how the repo already splits `tests/Unit/` and `tests/Feature/` (see the table in [../philosophy.md](../../philosophy.md#unit-vs-integration-vs-feature-in-this-codebase)):

```
tests/
  Unit/        Pure logic, no DB (no RefreshDatabase)
  Feature/     Full request/Livewire lifecycle, real DB (RefreshDatabase applied via tests/Pest.php)
  Browser/     Real-browser end-to-end tests (Pest browser plugin), RefreshDatabase applied too;
               tests/Browser/Auth/LoginSmokeTest.php (the pipeline canary),
               tests/Browser/UsersIndexTest.php (the Users screen, task 0006),
               tests/Browser/RolesIndexTest.php (the Roles screen, task 0011),
               tests/Browser/SalesRegionsIndexTest.php (the Sales Regions screen, task 0018),
               tests/Browser/Media/GalleryTest.php (the media gallery modal, story 0020),
               tests/Browser/Components/WysiwygEditorTest.php +
               tests/Browser/Components/WysiwygEditorOutputHtmlTest.php (the WYSIWYG editor, story 0021) and
               tests/Browser/Components/SearchableMultiSelectTest.php (this component, story 0022) and
               tests/Browser/Orders/ (six files, one per concern: list, tax display, line items,
               status transitions, cancellation, refund visibility; story 0055 — mirrored folder from
               the start; shared fixtures in tests/Support/Orders/OrdersUi.php) and
               tests/Browser/BlogTags/IndexTest.php (the blog tag screen, story 0060 — mirrored
               folder, ratified at that story's Phase 2) and
               tests/Browser/BlogCategories/IndexTest.php (the blog category screen, story 0062 —
               the same mirrored convention) and
               tests/Browser/BlogPosts/IndexTest.php + tests/Browser/BlogPosts/EditorJourneyTest.php
               (the blog post list and editor, story 0063 — the same mirrored convention; the journey
               wraps its select/`wire:model` flow in `retry(3, ...)`, and uses a non-zero seconds part
               in its `datetime-local` value because Chromium drops `:00`)
  Browser/Fixtures/  Real, checked-in binary fixtures a browser test needs as bytes on disk
                     (sample-upload.jpg) — never generated at runtime
  ```

> ⚠️ **Corrected 2026-08-31 (story 0022) — this file's own inventory omitted `RolesIndexTest.php` from every count below since it was first mentioned, understating the flat total by one at every step.** `tests/Browser/RolesIndexTest.php` has existed flat since task 0011 (2026-08-21) and was simply never added to this section's file list, its ✅ callout, or its closing paragraph — an under-count that survived four subsequent stories' own passes over this page. The numbers below are corrected in place, not merely appended to.
>
> ✅ **The mirrored-subfolder rule has now held three times running, and the flat files are the minority.** This page has said since task 0006 that the flat `UsersIndexTest.php` is debt and *"the next browser test goes in its mirrored subfolder"*; both task 0011 (`RolesIndexTest.php`) and task 0018 (`SalesRegionsIndexTest.php`) shipped flat anyway, which is why the lesson was re-aimed at Phase 2 — **a story file that names a test path is making a convention decision, so the path belongs in the review, not in the implementation.** Story 0020 is where that first landed (`tests/Browser/Media/GalleryTest.php`, named explicitly in its task file), story 0021 repeated it for both its files, and story 0022 repeats it a third time: `tests/Browser/Components/SearchableMultiSelectTest.php` sits under the mirrored `Components/` subfolder, matching `App\Livewire\Components\SearchableMultiSelect`'s own `tests/Feature/Components/` counterpart, with no departure to record. Three of eight files (`UsersIndexTest.php`, `RolesIndexTest.php`, `SalesRegionsIndexTest.php`) are still flat; that remains debt, and moving them is nobody's story yet — but the flat form is now clearly the minority a new test author would infer from counting files.

The suite is wired up (task 0006b). All four pieces are real and verifiable right now:

- **`tests/Browser/` exists**, holding `Auth/LoginSmokeTest.php` (plus `UsersIndexTest.php` since task 0006, `RolesIndexTest.php` since task 0011, `SalesRegionsIndexTest.php` since task 0018, `Media/GalleryTest.php` since story 0020, `Components/WysiwygEditorTest.php` + `Components/WysiwygEditorOutputHtmlTest.php` since story 0021, and `Components/SearchableMultiSelectTest.php` since story 0022) — a deliberately assertion-light canary that visits `/login`, asserts its user-visible text renders, and calls `assertNoJavaScriptErrors()`. Its job is proving the pipeline runs end to end, **not** covering sign-in behavior (that belongs to `tests/Feature/Auth/AuthenticationTest.php` and to whichever story owns sign-in browser coverage). Don't grow product assertions into it.
- **`phpunit.xml` declares a third `Browser` testsuite** alongside `Unit` and `Feature`:

  ```xml
  <!-- phpunit.xml -->
  <testsuite name="Browser">
      <directory>tests/Browser</directory>
  </testsuite>
  ```

  So `php artisan test` discovers browser tests automatically, and `php artisan test --testsuite=Browser` runs only them.

- **`RefreshDatabase` applies to `Browser` too** — decided **yes**, and wired through the single existing binding in `tests/Pest.php` rather than a second `pest()->extend(...)` block:

  ```php
  // tests/Pest.php
  pest()->extend(TestCase::class)
      ->use(RefreshDatabase::class)
      ->in('Feature', 'Browser');
  ```

  It is correct here for a specific, verified reason: Pest's browser plugin dispatches the page's requests through the **same in-process Laravel kernel** (`vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php` resolves `HttpKernel` and calls `$kernel->handle(...)`), not a separate server process — so the test's open transaction is visible to the page under test, exactly as it is for `Feature`. That is what makes `actingAs()` and model factories usable from a browser test at all.

- **`.gitignore` ignores `/tests/Browser/Screenshots`** — the repo-root-anchored path matching `Pest\Browser\Support\Screenshot::dir()`, which hardcodes `rootPath.'/tests/Browser/Screenshots'`. Confirmed against that source and with `git check-ignore -v`, not guessed from the directory name. Worth knowing when you write a browser test: Pest auto-captures a screenshot on **any** failed browser assertion, not only when you call `->screenshot()` explicitly.

Mirror the app structure inside `tests/Browser/` (e.g. `tests/Browser/Auth/`, `tests/Browser/Settings/`) exactly as `tests/Feature/` already does — `Auth/LoginSmokeTest.php` establishes that. **Three of eight files depart from it.** Task 0006 shipped `tests/Browser/UsersIndexTest.php` **flat**, where the mirror would put it at `tests/Browser/Users/IndexTest.php` (its component-level counterpart *is* at `tests/Feature/Users/IndexRenderingTest.php`), and this file recorded it as "the real current state, not a second convention — put the next browser test in its mirrored subfolder". The next two browser tests both shipped flat too, for the identical reason (a path written into a story file before anyone opened this page): task 0011's `tests/Browser/RolesIndexTest.php` and task 0018's `tests/Browser/SalesRegionsIndexTest.php`. **That is where the drift stopped.** Story 0020's `tests/Browser/Media/GalleryTest.php`, story 0021's `tests/Browser/Components/WysiwygEditorTest.php` / `Components/WysiwygEditorOutputHtmlTest.php`, and story 0022's `tests/Browser/Components/SearchableMultiSelectTest.php` all shipped in their mirrored subfolders, each because the story's own task file named the path explicitly and cited this section as the reason — so the mirrored form is now a clear majority (five of eight) rather than the minority it was when this paragraph last said the opposite. **The mirrored subfolder is still, and has always been, the convention** (`tests/Browser/SalesRegions/IndexTest.php` is where a second Sales Regions browser file belongs, `tests/Browser/Roles/IndexTest.php` for a second Roles one), and the three flat files are debt, not precedent — but a reader counting files today would no longer mistake flat for the pattern. The practical lesson holds regardless of the count: **a story file that names a test path is making a convention decision, so the path belongs in the Phase 2 review** — the three stories that got this right did so for exactly that reason. Note the artisan-first workflow used everywhere else needs one manual step here: `php artisan make:test --pest LoginBrowserTest` still places the file under `tests/Feature/`, so move it into `tests/Browser/` after generating it.

## Real syntax

All examples below use only syntax shown in [`.claude/skills/pest-testing/SKILL.md`](../../../../.claude/skills/pest-testing/SKILL.md) — don't invent methods it doesn't demonstrate.

A browser test visits a URL, asserts on user-visible content, drives the page, and always checks for JavaScript errors:

```php
// Shape from .claude/skills/pest-testing/SKILL.md — adapt route/labels to this app
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in');

    $page->assertSee('Sign In')
        ->assertNoJavaScriptErrors()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!');

    Notification::assertSent(ResetPassword::class);
});
```

The building blocks available:

| Call | Purpose |
| --- | --- |
| `visit('/login')` | Open a page in a real browser; returns a page object to chain on. |
| `->assertSee('Log in')` | Assert user-visible text is present (prefer this over selectors). |
| `->assertNoJavaScriptErrors()` | Fail if the page threw any JS error — a cheap, high-value check; include it in every browser test. |
| `->click('Log in')` | Click by visible text/label. |
| `->fill('email', 'ada@example.com')` | Type into a field by its name/label. |
| `->assertNoConsoleLogs()` | Assert no stray console output (used in smoke testing). |

**Laravel helpers work inside browser tests** — reuse them instead of driving the UI to set up state:

- `$this->actingAs(User::factory()->create())` to start authenticated.
- Model factories, including custom states such as `User::factory()->withTwoFactor()` (used in `tests/Feature/Auth/TwoFactorChallengeTest.php`).
- `Notification::fake()` / `Notification::assertSent(...)` for password-reset and recovery-code mail.
- `RefreshDatabase` for a clean DB per test.

**Smoke testing** multiple pages for JS errors in one shot — a fast first line of defense across this app's real routes:

```php
// Real routes from docs/api/routes.md
$pages = visit(['/', '/login', '/register']);

$pages->assertNoJavaScriptErrors()->assertNoConsoleLogs();
```

Authenticated routes (`/dashboard`, `/settings/profile`) need `actingAs` first, and `/settings/security` additionally needs a confirmed password in the session (`password.confirm` middleware) — see [../../architecture/authentication.md](../../../architecture/authentication.md) and [../../api/routes.md](../../../api/routes.md).
