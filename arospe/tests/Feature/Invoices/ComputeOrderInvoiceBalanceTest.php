<?php

// Story 0101b -- the order invoicing balance (shared definitions, FD-6, FD-8, FD-9). Assumed
// `components` / `invoicedComponents` keys: base, tax, shipping (the story types them as
// array<string, string> without naming the keys).

use App\Actions\Invoices\ComputeOrderInvoiceBalance;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Arr;
use Tests\Support\Invoices\Invoicing;

/**
 * @param  array<string, string>  $totals
 */
function balanceStandardInvoice(Order $order, array $totals, ?DateTimeInterface $voidedAt = null): Invoice
{
    return Invoice::factory()->standard()->forOrder($order)->create($totals + ['voided_at' => $voidedAt]);
}

test('an order with nothing invoiced owes its whole billable amount, split into base, tax and shipping (FD-8)', function () {
    $balance = app(ComputeOrderInvoiceBalance::class)(Invoicing::thousandOrder());

    // Story 0106 added netCollected, debt and isLegacy; their own tests live in Collection/.
    expect(Arr::except($balance, ['netCollected', 'debt', 'isLegacy']))->toBe([
        'billable' => '1000.00',
        'netInvoiced' => '0.00',
        'uninvoiced' => '1000.00',
        'liveCount' => 0,
        'components' => ['base' => '800.00', 'tax' => '168.00', 'shipping' => '32.00'],
        'invoicedComponents' => ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00'],
    ]);
});

test('the refunded merchandise comes off the base only (FD-8)', function () {
    $order = Invoicing::thousandOrder(['refunded_amount' => '100.00']);

    $balance = app(ComputeOrderInvoiceBalance::class)($order);

    expect($balance['billable'])->toBe('900.00')
        ->and($balance['components'])->toBe(['base' => '700.00', 'tax' => '168.00', 'shipping' => '32.00']);
});

test('a cancelled order has nothing billable even when a refund left a positive amount (shared definitions)', function () {
    $order = Invoicing::thousandOrder(['status' => 'cancelled', 'refunded_amount' => '100.00']);

    $balance = app(ComputeOrderInvoiceBalance::class)($order);

    expect($balance['billable'])->toBe('0.00')->and($balance['uninvoiced'])->toBe('0.00');
});

test('net invoiced is the signed sum of every invoice and live invoices are the unvoided standard ones', function () {
    $order = Invoicing::thousandOrder();
    $voided = balanceStandardInvoice($order, ['subtotal' => '160.00', 'tax_amount' => '33.60', 'shipping_amount' => '6.40', 'total' => '200.00'], now());
    Invoice::factory()->corrective($voided)->forOrder($order)->create([
        'subtotal' => '-160.00', 'tax_amount' => '-33.60', 'shipping_amount' => '-6.40', 'total' => '-200.00',
    ]);
    balanceStandardInvoice($order, ['subtotal' => '320.00', 'tax_amount' => '67.20', 'shipping_amount' => '12.80', 'total' => '400.00']);

    $balance = app(ComputeOrderInvoiceBalance::class)($order);

    expect($balance['netInvoiced'])->toBe('400.00')
        ->and($balance['uninvoiced'])->toBe('600.00')
        ->and($balance['liveCount'])->toBe(1)
        ->and($balance['invoicedComponents'])->toBe(['base' => '320.00', 'tax' => '67.20', 'shipping' => '12.80']);
});

test('uninvoiced is never negative when a refund pushed billable below what is invoiced (FD-9)', function () {
    $order = Invoicing::thousandOrder(['refunded_amount' => '300.00']);
    balanceStandardInvoice($order, ['subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00']);

    $balance = app(ComputeOrderInvoiceBalance::class)($order);

    expect($balance['billable'])->toBe('700.00')
        ->and($balance['netInvoiced'])->toBe('1000.00')
        ->and($balance['uninvoiced'])->toBe('0.00');
});

test('invoices of another order do not count', function () {
    $order = Invoicing::thousandOrder();
    balanceStandardInvoice(Invoicing::thousandOrder(), ['subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00']);

    $balance = app(ComputeOrderInvoiceBalance::class)($order);

    expect($balance['netInvoiced'])->toBe('0.00')->and($balance['liveCount'])->toBe(0);
});
