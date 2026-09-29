<?php

use App\Enums\PaymentMethodCode;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

// Story 0038, Phase 3 (TDD "red" step): App\Enums\PaymentMethodCode does not exist yet.
//
// No RefreshDatabase -- label() resolves through the translator (__()), which needs the app
// container, bound per-file here rather than directory-wide (docs/testing/backend/unit-tests.md),
// matching tests/Unit/Enums/ProductStatusTest.php's existing precedent.

uses(TestCase::class);

test('the only backing value is bank_transfer', function () {
    expect(array_column(PaymentMethodCode::cases(), 'value'))->toBe(['bank_transfer']);
});

test('from throws ValueError for an unrecognized code', function () {
    expect(fn () => PaymentMethodCode::from('paypal'))->toThrow(ValueError::class);
});

// Locale-switched (never asserted against a literal 'en' string) so the assertion cannot pass
// against a hardcoded return -- the same N-2 finding ProductStatusTest.php's own label test
// already guards against.
test('label resolves through the translator rather than returning a literal', function () {
    $originalLocale = App::getLocale();

    App::setLocale('es');

    try {
        expect(PaymentMethodCode::BankTransfer->label())->toBe(trans('payment-methods.names.bank_transfer'));
    } finally {
        App::setLocale($originalLocale);
    }
});
