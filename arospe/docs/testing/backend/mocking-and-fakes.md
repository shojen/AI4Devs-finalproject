# Mocking and Fakes

For the `use function Pest\Laravel\mock;` import mechanics, see [`.claude/skills/pest-testing/SKILL.md`](../../../.claude/skills/pest-testing/SKILL.md). This file covers the judgment call: *when* faking preserves a test's value, and when it destroys it.

## The rule

Fake the boundary of the system you don't own or can't safely trigger for real in a test (network calls, email delivery, queued jobs, time). Never fake the thing the test exists to verify — see [philosophy.md](../philosophy.md#2-tests-that-mock-everything-and-verify-nothing-real).

## What to fake in this codebase, and why

| Concern | This app's real config in tests | Fake it with | When |
| --- | --- | --- | --- |
| Mail (password reset, etc.) | `MAIL_MAILER=array` (see [`phpunit.xml`](../../../phpunit.xml)) — already captured in memory, not actually sent | `Mail::fake()` / `Notification::fake()` + `Mail::assertSent(...)` | Whenever a test needs to assert *that* an email/notification was triggered, not just that the mailer config is `array`. |
| Queue | `QUEUE_CONNECTION=sync` — jobs run inline, synchronously, during the request | `Queue::fake()` + `Queue::assertPushed(...)` | Only if you need to assert a job was *dispatched* without executing its side effects (e.g. it calls a real third-party API). If the job is cheap and safe to actually run, letting `sync` execute it for real is more valuable — it proves the job itself works, not just that it was queued. |
| HTTP to third-party APIs | None exist in this codebase today | `Http::fake()` | The moment this app makes an outbound HTTP call (there are currently none — this app has no `routes/api.php` and no external API integrations per [api/routes.md](../../api/routes.md)), fake it in tests; never let a test suite depend on a real network call succeeding. |
| Wall-clock time | Real `now()` | `Carbon::setTestNow(...)`/`travel()`/`travelTo()` | Any test whose assertion depends on "now" (e.g. `two_factor_confirmed_at` freshness, token expiry) — without freezing time, the test is non-deterministic by construction (see the [DoD checklist](../README.md)). |
| PDF engine (invoice rendering, story 0102) | The real engine starts headless Chrome through Node | `Tests\Support\Invoices\FakesPdf::fake()` (wraps `Pdf::fake()`) | Every test under `tests/Feature/Invoices/Pdf`; see [the guard](#the-pdf-engine-guard-story-0102) |

## The PDF engine guard (story 0102)

`FakesPdf` ([`tests/Support/Invoices/FakesPdf.php`](../../../tests/Support/Invoices/FakesPdf.php)) makes starting Chromium by accident impossible: `tests/Pest.php` resets its flag in `beforeEach` and asserts it in `afterEach` for everything under `Feature/Invoices/Pdf`, so a test that neither calls `FakesPdf::fake()` nor `FakesPdf::optOut()` fails. `FakesPdfGuardTest` covers the guard, including a source scan. The fake's `base64()` and `generatePdfContent()` still return dummy PDF content, so an attachment test (story 0104) can use them.

Real-render coverage is the **opt-in smoke** `tests/Feature/Invoices/Pdf/InvoicePdfSmokeTest.php`, skipped unless `INVOICE_PDF_E2E=1` (needs Node, puppeteer's Chrome and the `LARAVEL_PDF_*` env keys; CI sets it, see [pipeline integration](../ci/pipeline-integration.md)). Do not cite it as proof that JavaScript or local files are blocked: its "no `PWNED-BY-SCRIPT`" and "no `root:x:0:0`" assertions are weak (the script is Blade-escaped anyway and Chrome's page streams are compressed). That proof is `RenderInvoicePdfTest` (option-level assertions on the Browsershot instance) and `SafeBrowsershotDriverTest` (file-loaded HTML, `blockLocalFileAccess`, cleanup). What the smoke does prove is a real, bounded render, and that customer text such as `view-source` no longer breaks it (the Browsershot guard entry in [errors-log.md](../../errors-log.md)).

## What NOT to mock

- **This app's own Action classes** (`App\Actions\Fortify\CreateNewUser`, `App\Livewire\Settings\Security`, etc.). Mocking the class under test — or the one collaborator that actually contains the business rule you're verifying — produces a test that passes regardless of whether the real logic is correct. See the Mockery example in [philosophy.md](../philosophy.md#2-tests-that-mock-everything-and-verify-nothing-real).
- **The database**, in `tests/Feature/`. `RefreshDatabase` gives a real, migrated schema per test — that's already fast and isolated (see [database-strategy.md](database-strategy.md)); there's no reason to fake Eloquent here.
- **Validation rules** you're trying to test. Faking `Password::default()` to always pass, in a test that's supposed to verify the password policy, defeats the test's purpose.

## Concrete example: faking Notification for password reset

```php
<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('requesting a password reset sends the reset notification', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});
```

This test would still pass even if the reset *link itself* were broken — `Notification::fake()` intercepts before rendering. If the goal is "the reset flow actually works end-to-end," pair this with a separate test that follows the real (non-faked) flow through to a successful `password.update`, per [risk-based-testing.md #5](../qa/risk-based-testing.md).

_Last updated: 2026-11-19 — Story 0102: added the PDF engine row and the `FakesPdf` guard / opt-in smoke section._
