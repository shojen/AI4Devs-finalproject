<?php

// Story 0039 — Pest 4 browser tests for the Payment methods settings screen. The wired-up
// tests/Browser/ suite runs on Chromium in CI (task 0006b, done).
//
// Levels chosen per docs/testing/frontend/coverage-policy.md: these three tests exist because
// Livewire::test()->set() writes the `iban` property directly and can never prove the real
// wire:model binding + Save click actually work, and because App\Livewire\PaymentMethods\Index
// ::save() mutates $this->iban to its normalised form BEFORE validating -- an implementation
// that renders the outer "configured IBAN" summary from $this->iban instead of the persisted row
// would display a REJECTED attempt as though it were the configured account, and every one of
// 0038's own tests (which assert fresh()->iban, i.e. the database) would stay green regardless.
//
// SELECTOR STRATEGY: '@configure-bank-transfer' for the one row action (present on both the
// enabled and the disabled branch); '@payment-method-configured-iban' scoped via assertSeeIn(),
// never a page-wide assertSee(), for exactly the reason above -- the outer display and the
// modal's own <input> can both hold IBAN-shaped text at once. The `iban` <input> is targeted by
// its wire:model property name (fill('iban', ...)), the same convention every other screen's
// browser tests already use for a flux:input.
//
// The 4-character grouping is DISPLAY COPY (see the task file's own assertion-quality rules) --
// every assertSeeIn() against '@payment-method-configured-iban' below matches only the leading,
// unspaced 4-character prefix (e.g. 'DE89'), never the fully-formatted string, so a future change
// to the grouping/separator does not couple this suite to copy this story does not own. The
// DATABASE assertion (fresh()->iban) is what actually proves the stored value, on every test.

use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function paymentMethodsBrowserTestActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['payment-methods.view', 'payment-methods.edit']);

    return $actor;
}

// =====================================================================
// B1 -- the real form saves: fill, click Save, assert the screen AND the database.
// =====================================================================

test('a valid, space-grouped, lowercase IBAN is accepted through the real form and stored canonically', function () {
    $method = PaymentMethod::factory()->create();
    $this->actingAs(paymentMethodsBrowserTestActor());

    visit('/payment-methods')
        ->assertNoJavaScriptErrors()
        ->click('@configure-bank-transfer')
        ->assertNoJavaScriptErrors()
        ->fill('iban', 'es91 2100 0418 4502 0005 1332')
        ->click('Save')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@payment-method-configured-iban', 'ES91');

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

// =====================================================================
// B2 -- the highest-risk case: a rejection must not let the display drift from the database.
// =====================================================================

test('a checksum-invalid IBAN is rejected and the previously configured IBAN keeps showing', function () {
    $method = PaymentMethod::factory()->withIban('DE89370400440532013000')->create();
    $this->actingAs(paymentMethodsBrowserTestActor());

    visit('/payment-methods')
        ->assertNoJavaScriptErrors()
        ->click('@configure-bank-transfer')
        ->assertNoJavaScriptErrors()
        // Structurally valid (2-letter country, 2 check digits, alnum body) but the wrong mod-97
        // check digit -- fails on the checksum, not on the regex, matching
        // tests/Feature/PaymentMethods/Datasets.php's own case of the same name.
        ->fill('iban', 'ES9021000418450200051332')
        ->click('Save')
        ->assertNoJavaScriptErrors()
        ->assertSee(__('payment-methods.iban.invalid'))
        // data-test="payment-method-modal" now lives on the modal's INNER div, rendered only
        // inside @if ($showModal) -- so this genuinely proves the modal stayed open, rather
        // than being vacuously true against <flux:modal>'s own unconditional wrapper.
        ->assertPresent('@payment-method-modal')
        ->assertSeeIn('@payment-method-configured-iban', 'DE89');

    expect($method->fresh()->iban)->toBe('DE89370400440532013000');
});

// =====================================================================
// B3 -- cancelling discards the typed value.
// =====================================================================

test('cancelling the edit form closes it and leaves the configured IBAN unchanged', function () {
    $method = PaymentMethod::factory()->withIban('DE89370400440532013000')->create();
    $this->actingAs(paymentMethodsBrowserTestActor());

    visit('/payment-methods')
        ->assertNoJavaScriptErrors()
        ->click('@configure-bank-transfer')
        ->assertNoJavaScriptErrors()
        ->fill('iban', 'GB29NWBK60161331926819')
        ->click('Cancel')
        ->assertNoJavaScriptErrors()
        // "closes it" is asserted structurally, not merely inferred from the IBAN staying
        // unchanged -- data-test="payment-method-modal" only renders inside @if ($showModal).
        ->assertMissing('@payment-method-modal')
        ->assertSeeIn('@payment-method-configured-iban', 'DE89');

    expect($method->fresh()->iban)->toBe('DE89370400440532013000');
});
