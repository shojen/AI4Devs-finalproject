<?php

// Story 0106 (T-7, T-10, T-29, FD-12) -- invoicing an order paid before invoicing existed ("legacy":
// no invoice and a stored status Paid, Refunded or PartiallyRefunded): only its whole uninvoiced
// amount may be invoiced and the invoice is born collected (carried over), so its debt stays 0.00.

use App\Actions\Invoices\CollectInvoice;
use App\Actions\Invoices\IssueInvoice;
use App\Enums\OrderPaymentType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    Invoicing::defaultStoreLanguage('en');
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
    Invoicing::settings();
    $this->actor = Invoicing::manager();
});

/**
 * A legacy paid 1000.00 order, with or without the legacy payment row.
 */
function legacyPaidOrder(bool $withPaymentRow, array $overrides = []): Order
{
    $order = Invoicing::thousandOrder(['payment_status' => PaymentStatus::Paid, 'collected_amount' => '1000.00'] + $overrides);

    if ($withPaymentRow) {
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method_id' => $order->payment_method_id,
            'type' => OrderPaymentType::Card,
            'paid_at' => '2026-05-01 09:00:00',
        ]);
    }

    return $order;
}

// T-7
test('a legacy order invoiced whole gets an invoice born collected from its payment row, with a debt of 0.00 and an unchanged status (T-7)', function () {
    $order = legacyPaidOrder(true);
    $payment = OrderPayment::query()->where('order_id', $order->id)->sole();

    $invoice = app(IssueInvoice::class)($order);

    $fresh = $order->fresh();

    expect($invoice->collection_carried_over)->toBeTrue()
        ->and($invoice->collected_at->equalTo($payment->paid_at))->toBeTrue()
        ->and($invoice->collection_payment_method_id)->toBe($payment->payment_method_id)
        ->and($invoice->collection_type)->toBe('card')
        ->and($invoice->collected_by)->toBeNull()
        ->and($fresh->debt())->toBe('0.00')
        ->and($fresh->collected_amount)->toBe('1000.00')
        ->and($fresh->payment_status)->toBe(PaymentStatus::Paid);
});

test('without a payment row the invoice is collected now with the order method and no type (T-7)', function () {
    $order = legacyPaidOrder(false);

    $invoice = app(IssueInvoice::class)($order);

    expect($invoice->collection_carried_over)->toBeTrue()
        ->and($invoice->collected_at->equalTo(now()))->toBeTrue()
        ->and($invoice->collection_payment_method_id)->toBe($order->payment_method_id)
        ->and($invoice->collection_type)->toBeNull()
        ->and($invoice->collected_by)->toBeNull()
        ->and($order->fresh()->debt())->toBe('0.00');
});

// T-10
test('a legacy pending order gets its first invoice and is derived from then on (T-10)', function () {
    $order = Invoicing::thousandOrder();

    $invoice = app(IssueInvoice::class)($order, '400.00');

    expect($invoice->collected_at)->toBeNull()
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and($order->fresh()->debt())->toBe('1000.00');

    Collecting::actor('invoices.collect');
    app(CollectInvoice::class)($invoice, Collecting::method(), OrderPaymentType::Transfer);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($order->fresh()->debt())->toBe('600.00');
});

test('a legacy partially refunded order with a born-collected whole invoice stays partially refunded (T-10)', function () {
    $order = Invoicing::thousandOrder(['payment_status' => PaymentStatus::PartiallyRefunded, 'refunded_amount' => '100.00', 'collected_amount' => '900.00']);
    Collecting::addLine($order, 1);

    $invoice = app(IssueInvoice::class)($order);

    $fresh = $order->fresh();

    expect($invoice->total)->toBe('900.00')
        ->and($invoice->collection_carried_over)->toBeTrue()
        ->and($fresh->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($fresh->debt())->toBe('0.00');
});

// T-29
test('a legacy order may be invoiced only for its whole amount: a partial invoice is refused on amount and writes nothing (T-29)', function (string $amount) {
    $order = legacyPaidOrder(true);
    $before = DB::table('invoices')->count();

    $errors = Collecting::errors(fn () => app(IssueInvoice::class)($order, $amount));

    expect(array_keys($errors))->toBe(['amount'])
        ->and(DB::table('invoices')->count())->toBe($before)
        ->and(Invoicing::invoicesOf($order))->toHaveCount(0);
})->with(['200.00', '999.99', '0.01']);

test('a legacy order whole invoice succeeds, stated explicitly as the whole amount too (T-29)', function () {
    $order = legacyPaidOrder(true);

    $invoice = app(IssueInvoice::class)($order, '1000.00');

    expect($invoice->total)->toBe('1000.00')->and($invoice->collection_carried_over)->toBeTrue();
});

test('a non-legacy order may be invoiced in part: a pending order, and a paid order that already has an invoice (T-29)', function () {
    $pending = Invoicing::thousandOrder();
    $partial = app(IssueInvoice::class)($pending, '200.00');

    $paidWithInvoice = Invoicing::thousandOrder(['payment_status' => PaymentStatus::Paid]);
    Collecting::invoice($paidWithInvoice, '600.00');
    $second = app(IssueInvoice::class)($paidWithInvoice, '200.00');

    expect($partial->total)->toBe('200.00')
        ->and($partial->collected_at)->toBeNull()
        ->and($second->total)->toBe('200.00')
        ->and($second->collected_at)->toBeNull();
});
