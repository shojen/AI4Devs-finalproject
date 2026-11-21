<?php

// Story 0105 T-1..T-7 -- the automatic invoice on order creation, driven through the real CreateOrder.

use App\Actions\Invoices\IssueAutomaticInvoice;
use App\Enums\InvoiceDeliveryStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\Order;
use App\Notifications\AutomaticInvoiceFailed;
use App\Notifications\InvoiceSent;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Invoices\Automating;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

test('with the flag off by default an order creates no invoice, no delivery and touches neither table (T-1)', function () {
    Automating::prepare(on: false);
    $payload = Automating::payload();
    Automating::orderCreator();
    $touched = [];

    DB::listen(function ($query) use (&$touched): void {
        if (preg_match('/`(invoices|invoice_deliveries)`/', $query->sql) === 1) {
            $touched[] = $query->sql;
        }
    });

    Automating::createOrder($payload);

    $queries = $touched;

    expect($queries)->toBe([])
        ->and(Order::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0);
});

test('on a fresh database the flag is off and nothing is invoiced (T-1)', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    Notification::fake();

    Automating::createOrder();

    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0);
});

test('with the flag on exactly one itemised standard invoice for the whole amount is issued by the system (T-2)', function () {
    Automating::prepare();

    $order = Automating::createOrder(Automating::payload('25.00', 4));

    $invoice = Invoice::query()->sole();

    expect($invoice->order_id)->toBe($order->id)
        ->and($invoice->type)->toBe(InvoiceType::Standard)
        ->and($invoice->total)->toBe('100.00')
        ->and($invoice->number)->not->toBe('')
        ->and($invoice->issued_by)->toBeNull()
        ->and($invoice->lines)->toHaveCount($order->items()->count())
        ->and($invoice->lines->first()->quantity)->toBe(4);
});

test('the invoice is queued once to the customer as the original, with no sender (T-3)', function () {
    Automating::prepare();

    $order = Automating::createOrder();

    $delivery = InvoiceDelivery::query()->sole();
    $invoice = Invoice::query()->sole();
    $sent = Delivering::sentNotifications();

    expect($delivery->invoice_id)->toBe($invoice->id)
        ->and($delivery->status)->toBe(InvoiceDeliveryStatus::Queued)
        ->and($delivery->is_copy)->toBeFalse()
        ->and($delivery->sent_by)->toBeNull()
        ->and($delivery->recipient_email)->toBe((string) $order->customer()->value('email'))
        ->and($sent)->toHaveCount(1)
        ->and(Delivering::idsOf($sent[0]))->toBe([$delivery->id]);
});

test('an administrator without any invoice permission creating the order still gets the invoice issued with a null author (T-4)', function () {
    Automating::prepare();
    $admin = Automating::orderCreator();

    Automating::createOrder();

    expect($admin->getAllPermissions()->pluck('name')->all())->toBe(['orders.create'])
        ->and(Invoice::query()->sole()->issued_by)->toBeNull()
        ->and(InvoiceDelivery::query()->sole()->sent_by)->toBeNull();
});

test('an order with a billable amount of 0.00 gets no invoice, no delivery, no notification and consumes no number (T-5)', function (string $price) {
    Automating::prepare();
    $before = Numbering::snapshot();

    $order = Automating::createOrder(Automating::payload($price));

    expect($order->total)->toBe('0.00')
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);

    Notification::assertSentTimes(InvoiceSent::class, 0);
    Notification::assertSentTimes(AutomaticInvoiceFailed::class, 0);
})->with(['a free product' => ['0.00']]);

test('an order whose billable amount is zero through the action directly is skipped without failure (T-5)', function () {
    Automating::prepare(fakeNotifications: false);
    $order = Invoicing::order([['Free sample', 'FREE-1', 1, '0.00']], null);

    app(IssueAutomaticInvoice::class)($order->id);

    expect(Invoice::query()->count())->toBe(0)
        ->and(Automating::failures())->toHaveCount(0);
});

test('an order with a null tax rate prints rate null and tax 0.00, and an order with tax and shipping already set prints them (T-6)', function () {
    Automating::prepare();

    Automating::createOrder();
    $plain = Invoice::query()->sole();

    expect($plain->tax_rate)->toBeNull()
        ->and($plain->tax_amount)->toBe('0.00');

    $taxed = Invoicing::thousandOrder();
    app(IssueAutomaticInvoice::class)($taxed->id);
    $invoice = Invoice::query()->where('order_id', $taxed->id)->sole();

    expect($invoice->tax_rate)->toBe('21.000')
        ->and($invoice->tax_amount)->toBe('168.00')
        ->and($invoice->shipping_amount)->toBe('32.00')
        ->and($invoice->total)->toBe('1000.00');
});

test('on the sync queue the delivery ends sent and the PDF carries no copy mark (T-7)', function () {
    Automating::prepare(fakeNotifications: false);

    Automating::createOrder();

    $delivery = InvoiceDelivery::query()->sole();

    expect($delivery->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($delivery->is_copy)->toBeFalse();
});
