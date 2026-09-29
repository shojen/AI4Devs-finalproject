<?php

use App\Enums\PaymentStatus;
use Tests\TestCase;

// Story 0045, Phase 3: App\Enums\PaymentStatus already exists (scaffolded ahead of the write
// path), so the enum-shape test below is expected to be GREEN already. The lang-key-parity test
// targets `lang/en/orders.php` / `lang/es/orders.php`, which do NOT exist yet -- that specific test
// is expected to fail until backend-expert creates both lang files.
//
// N-4's resolution: NEITHER enum declares label() this story -- story 0055 adds it as their first
// real consumer. See OrderStatusTest.php's own docblock; the same reasoning applies unchanged --
// including the `uses(TestCase::class)` CI fix for `lang_path()` needing the app container.
uses(TestCase::class);

test('PaymentStatus exposes exactly the cases PRD §3.2 names, with the expected backing values', function () {
    $expected = [
        'PendingPayment' => 'pending_payment',
        'Paid' => 'paid',
        'Refunded' => 'refunded',
        'PartiallyRefunded' => 'partially_refunded',
    ];

    $actual = [];
    foreach (PaymentStatus::cases() as $case) {
        $actual[$case->name] = $case->value;
    }

    expect($actual)->toEqualCanonicalizing($expected);
});

test('every PaymentStatus case has a payment_statuses leaf in both lang/en/orders.php and lang/es/orders.php, key-for-key identical', function () {
    $en = require lang_path('en/orders.php');
    $es = require lang_path('es/orders.php');

    expect($en)->toHaveKey('payment_statuses')
        ->and($es)->toHaveKey('payment_statuses');

    $expectedKeys = array_map(fn (PaymentStatus $case): string => $case->value, PaymentStatus::cases());

    expect(array_keys($en['payment_statuses']))->toEqualCanonicalizing($expectedKeys)
        ->and(array_keys($es['payment_statuses']))->toEqualCanonicalizing($expectedKeys)
        ->and(array_keys($en['payment_statuses']))->toEqualCanonicalizing(array_keys($es['payment_statuses']));

    foreach (PaymentStatus::cases() as $case) {
        expect($en['payment_statuses'][$case->value])->toBeString()->not->toBeEmpty();
        expect($es['payment_statuses'][$case->value])->toBeString()->not->toBeEmpty();
    }
});
