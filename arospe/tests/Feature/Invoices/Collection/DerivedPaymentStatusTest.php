<?php

// Story 0106 (T-1, T-5, T-6, T-20, T-21) -- the derived payment status (truth table), the collected
// amount and the debt. Exercised through SyncOrderCollection on real rows, so the resolver's inputs
// are the stored order, its lines and its invoices exactly as production reads them.

use App\Actions\Invoices\ComputeOrderInvoiceBalance;
use App\Actions\Invoices\ResolveOrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('the pure resolver class exists', function () {
    expect(class_exists(ResolveOrderPaymentStatus::class))->toBeTrue();
});

// T-1: every scenario satisfies two rows of the table so only first-match produces the expected result.
// [scenario, expected payment status, expected collected amount (null = not asserted)]
dataset('payment status truth table', [
    'row 0: a cancelled order keeps its stored Paid although nothing is collected (also row 5)' => [
        ['status' => OrderStatus::Cancelled, 'stored' => 'paid', 'invoices' => [['1000.00', false]]], 'paid', null,
    ],
    'row 0: a cancelled order keeps its stored PartiallyPaid although fully collected (also row 3)' => [
        ['status' => OrderStatus::Cancelled, 'stored' => 'partially_paid', 'invoices' => [['1000.00', true]]], 'partially_paid', null,
    ],
    'row 0: a legacy paid order without invoices keeps Paid and collected equals billable' => [
        ['stored' => 'paid'], 'paid', '1000.00',
    ],
    'row 0: a legacy pending order without invoices stays pending, collected 0.00' => [
        ['stored' => 'pending_payment'], 'pending_payment', '0.00',
    ],
    'row 0: a legacy partially refunded order without invoices keeps its state, collected is billable' => [
        ['stored' => 'partially_refunded', 'refunded' => '300.00', 'lines' => 'partial'], 'partially_refunded', '700.00',
    ],
    'row 1: refund recorded and every line refunded -> Refunded (also rows 2 and 5)' => [
        ['stored' => 'paid', 'refunded' => '1000.00', 'lines' => 'full', 'invoices' => [['1000.00', false]]], 'refunded', '0.00',
    ],
    'row 1: fully refunded and fully collected with its corrective -> Refunded (also row 2)' => [
        ['stored' => 'paid', 'refunded' => '1000.00', 'lines' => 'full', 'invoices' => [['1000.00', true, '-1000.00']]], 'refunded', '0.00',
    ],
    'row 2: refund recorded, a line left, fully collected -> PartiallyRefunded (also row 3)' => [
        ['stored' => 'paid', 'refunded' => '300.00', 'lines' => 'partial', 'invoices' => [['1000.00', true, '-300.00']]], 'partially_refunded', '700.00',
    ],
    'row 2: a refunded order that still carries debt shows its refund state (also row 5)' => [
        ['stored' => 'paid', 'refunded' => '300.00', 'lines' => 'partial', 'invoices' => [['1000.00', false]]], 'partially_refunded', '0.00',
    ],
    'lines refunded but no refund amount is not a refund (row 3 applies)' => [
        ['stored' => 'pending_payment', 'lines' => 'full', 'invoices' => [['1000.00', true]]], 'paid', '1000.00',
    ],
    'row 3: collected in full by two invoices -> Paid (also row 4)' => [
        ['invoices' => [['600.00', true], ['400.00', true]]], 'paid', '1000.00',
    ],
    'row 3: collected above billable -> Paid (also row 4)' => [
        ['total' => '900.00', 'invoices' => [['1000.00', true]]], 'paid', '1000.00',
    ],
    'row 4: partly collected -> PartiallyPaid (also row 5 would apply to the uncollected rest)' => [
        ['invoices' => [['600.00', true], ['400.00', false]]], 'partially_paid', '600.00',
    ],
    'row 5: nothing collected -> PendingPayment even when it was stored PartiallyPaid' => [
        ['stored' => 'partially_paid', 'invoices' => [['600.00', false], ['400.00', false]]], 'pending_payment', '0.00',
    ],
    'row 5: billable 0.00 without a refund and nothing collected -> PendingPayment' => [
        ['stored' => 'paid', 'total' => '0.00', 'invoices' => [['500.00', false]]], 'pending_payment', '0.00',
    ],
]);

test('the derived payment status and collected amount follow the truth table, first matching row winning (T-1)', function (array $scenario, string $expected, ?string $collected) {
    if (isset($scenario['stored'])) {
        $scenario['stored'] = PaymentStatus::from($scenario['stored']);
    }

    $order = Collecting::scenario($scenario);

    $synced = Collecting::sync($order);

    expect($synced->payment_status)->toBe(PaymentStatus::from($expected))
        ->and($synced->status)->toBe($order->status);

    if ($collected !== null) {
        expect($synced->collected_amount)->toBe($collected);
    }
})->with('payment status truth table');

// T-20
test('cent boundaries: 999.99 or 0.01 of 1000.00 collected is partially paid, 1000.00 is paid (T-20)', function (array $collectedFlags, string $expected, string $collected) {
    $order = Collecting::order('1000.00');
    Collecting::invoice($order, '999.99', $collectedFlags[0]);
    Collecting::invoice($order, '0.01', $collectedFlags[1]);

    $synced = Collecting::sync($order);

    expect($synced->payment_status)->toBe(PaymentStatus::from($expected))
        ->and($synced->collected_amount)->toBe($collected)
        ->and($synced->debt())->toBe(bcsub('1000.00', $collected, 2));
})->with([
    '999.99 collected' => [[true, false], 'partially_paid', '999.99'],
    '0.01 collected' => [[false, true], 'partially_paid', '0.01'],
    'both collected' => [[true, true], 'paid', '1000.00'],
]);

// T-5: [order overrides, total, invoices, expected debt]
test('the debt is billable minus net collected, never below zero, as a two-decimal string (T-5)', function (array $scenario, string $expectedDebt) {
    $order = Collecting::scenario($scenario);
    $synced = Collecting::sync($order);

    $balance = app(ComputeOrderInvoiceBalance::class)($synced);

    expect($synced->debt())->toBe($expectedDebt)
        ->and($balance['debt'])->toBe($expectedDebt);
})->with([
    'nothing collected' => [['invoices' => [['600.00', false], ['400.00', false]]], '1000.00'],
    '600 collected' => [['invoices' => [['600.00', true], ['400.00', false]]], '400.00'],
    '1000 collected' => [['invoices' => [['600.00', true], ['400.00', true]]], '0.00'],
    'collected above billable is clamped' => [['total' => '900.00', 'invoices' => [['1000.00', true]]], '0.00'],
    'refunded 100 and collected 900' => [['refunded' => '100.00', 'lines' => 'partial', 'invoices' => [['1000.00', true, '-100.00']]], '0.00'],
    'uninvoiced pending order owes everything' => [[], '1000.00'],
    'legacy paid order owes nothing' => [['stored' => PaymentStatus::Paid], '0.00'],
]);

// T-6
test('net collected is the signed sum of collected invoices; a corrective of an uncollected invoice contributes 0 (T-6)', function () {
    $order = Collecting::order('1000.00');
    $collected = Collecting::invoice($order, '600.00', true);
    Collecting::corrective($collected, '-100.00');
    $uncollected = Collecting::invoice($order, '400.00', false);
    Collecting::corrective($uncollected, '-50.00');

    $balance = app(ComputeOrderInvoiceBalance::class)($order->refresh());

    expect($balance['netCollected'])->toBe('500.00')
        ->and($balance['isLegacy'])->toBeFalse();
});

test('the balance flags a legacy order: no invoice and a paid-like stored status (T-6)', function (PaymentStatus $stored, bool $legacy) {
    $order = Collecting::order('1000.00', ['payment_status' => $stored]);

    expect(app(ComputeOrderInvoiceBalance::class)($order)['isLegacy'])->toBe($legacy);
})->with([
    'paid' => [PaymentStatus::Paid, true],
    'refunded' => [PaymentStatus::Refunded, true],
    'partially refunded' => [PaymentStatus::PartiallyRefunded, true],
    'pending' => [PaymentStatus::PendingPayment, false],
]);

test('an order with an invoice is not legacy even when stored as paid (T-6)', function () {
    $order = Collecting::order('1000.00', ['payment_status' => PaymentStatus::Paid]);
    Collecting::invoice($order, '1000.00');

    expect(app(ComputeOrderInvoiceBalance::class)($order)['isLegacy'])->toBeFalse();
});

// T-21
test('standard 600 collected, corrective -100, standard 400 uncollected: net collected 500, billable 900, debt 400, partially paid (T-21)', function () {
    $order = Collecting::order('900.00');
    $first = Collecting::invoice($order, '600.00', true);
    Collecting::corrective($first, '-100.00');
    Collecting::invoice($order, '400.00', false);

    $synced = Collecting::sync($order);
    $balance = app(ComputeOrderInvoiceBalance::class)($synced);

    expect($balance['netCollected'])->toBe('500.00')
        ->and($balance['billable'])->toBe('900.00')
        ->and($balance['debt'])->toBe('400.00')
        ->and($synced->collected_amount)->toBe('500.00')
        ->and($synced->debt())->toBe('400.00')
        ->and($synced->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});
