<?php

// Story 0104 -- SendOrderInvoices: an order's invoices in one mail, or "Issue and send" when the
// order has no live invoice (FD-5, FD-7, FD-8, FD-13, FD-16).

use App\Actions\Invoices\SendOrderInvoices;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceLine;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Notifications\InvoiceSent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    Delivering::prepare();
    $this->actor = Delivering::sender('invoices.send', 'invoices.create');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function zeroCorrective(Invoice $of, array $overrides = []): Invoice
{
    return Delivering::corrective($of, $overrides + ['subtotal' => '0.00', 'shipping_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '0.00']);
}

// T-7
test('an order with live invoices and unsent correctives goes out in one mail, each row original or copy on its own (T-7)', function () {
    $order = Invoicing::thousandOrder();
    $alreadySent = Delivering::invoice($order, ['total' => '300.00']);
    Delivering::deliver($alreadySent, 'sent');
    $fresh = Delivering::invoice($order, ['total' => '300.00']);
    $unsentCorrective = zeroCorrective($fresh);
    $sentCorrective = zeroCorrective($fresh);
    Delivering::deliver($sentCorrective, 'sent');
    $voided = Delivering::invoice($order, ['total' => '100.00', 'voided_at' => now()]);

    $deliveries = app(SendOrderInvoices::class)($order);

    $byInvoice = collect($deliveries)->keyBy('invoice_id');

    expect($deliveries)->toBeArray()->and(array_is_list($deliveries))->toBeTrue()
        ->and($deliveries)->toHaveCount(3)
        ->and($byInvoice->keys()->all())->toEqualCanonicalizing([$alreadySent->id, $fresh->id, $unsentCorrective->id])
        ->and($byInvoice[$alreadySent->id]->is_copy)->toBeTrue()
        ->and($byInvoice[$fresh->id]->is_copy)->toBeFalse()
        ->and($byInvoice[$unsentCorrective->id]->is_copy)->toBeFalse()
        ->and(InvoiceDelivery::query()->whereIn('invoice_id', [$voided->id, $sentCorrective->id])->where('status', 'queued')->count())->toBe(0);

    Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
    Notification::assertSentOnDemand(InvoiceSent::class, function (InvoiceSent $notification, array $channels, $notifiable) use ($deliveries, $order): bool {
        $ids = Delivering::idsOf($notification);
        sort($ids);
        $expected = collect($deliveries)->pluck('id')->sort()->values()->all();

        return $ids === $expected && $notifiable->routes['mail'] === $order->customer->email;
    });
});

// T-8
test('an order with no invoice is issued for the whole uninvoiced amount, itemised, then sent as one original (T-8)', function () {
    $order = Invoicing::thousandOrder();

    $deliveries = app(SendOrderInvoices::class)($order);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->invoice->id)->toBe($invoice->id)
        ->and($deliveries[0]->invoice->number)->toBe($invoice->number)
        ->and($deliveries[0]->is_copy)->toBeFalse()
        ->and($deliveries[0]->sent_by)->toBe($this->actor->id)
        ->and($invoice->type)->toBe(InvoiceType::Standard)
        ->and($invoice->total)->toBe('1000.00')
        ->and($invoice->issued_by)->toBe($this->actor->id)
        ->and(InvoiceLine::query()->where('invoice_id', $invoice->id)->count())->toBeGreaterThan(0);
    Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
});

// T-16
test('a soft-deleted customer refuses the order send with no row, no mail and no invoice issued (T-16)', function () {
    $order = Invoicing::thousandOrder();
    $order->customer->delete();
    $before = Numbering::snapshot();

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe(__('invoices.errors.customer_deleted'))
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
    Notification::assertNothingSent();
});

test('a soft-deleted customer refuses the order send also when the order already has invoices (T-16)', function () {
    $order = Invoicing::thousandOrder();
    Delivering::invoice($order);
    $order->customer->delete();

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

// T-17
test('an unusable customer address refuses the order send before any row or invoice, and a valid unusual one is sent (T-17)', function (string $address, bool $usable) {
    $order = Invoicing::thousandOrder();
    $order->customer->forceFill(['email' => $address])->save();
    $before = Numbering::snapshot();

    if ($usable) {
        expect(app(SendOrderInvoices::class)($order)[0]->recipient_email)->toBe($address);

        return;
    }

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe(__('invoices.errors.customer_email_unusable'))
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
    Notification::assertNothingSent();
})->with([
    'empty' => ['', false],
    'whitespace' => ['   ', false],
    'no at sign' => ['customer.example.test', false],
    'no top-level domain' => ['a@b', false],
    'longer than an address may be' => ['a@'.str_repeat('b', 250).'.co', false],
    'plus addressing' => ['a+b@x.co', true],
]);

// T-19
test('a failure after the rows are inserted rolls the order send back: no row and nothing sent (T-19)', function () {
    $order = Invoicing::thousandOrder();
    Delivering::invoice($order);
    Delivering::invoice($order);
    $armed = true;

    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'insert into `invoice_deliveries`')) {
            throw new RuntimeException('forced failure after the insert');
        }
    });

    expect(fn () => app(SendOrderInvoices::class)($order))->toThrow(Exception::class);

    $armed = false;

    expect(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

// T-20
test('an out-of-date order refuses the order send, with or without invoices, and succeeds once cleared (T-20)', function (bool $hasInvoice) {
    $order = Invoicing::thousandOrder(['invoice_out_of_date_at' => now()]);

    if ($hasInvoice) {
        Delivering::invoice($order);
    }

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe(__('invoices.errors.out_of_date'))
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $order->forceFill(['invoice_out_of_date_at' => null])->save();

    expect(app(SendOrderInvoices::class)($order))->toHaveCount(1);
})->with([
    'with an invoice' => [true],
    'without an invoice' => [false],
]);

// T-21
test('an order whose invoices are all voided and still has an uninvoiced amount is issued and sent, with unsent correctives attached (T-21)', function () {
    $order = Invoicing::thousandOrder();
    $voided = Delivering::invoice($order, ['total' => '400.00', 'subtotal' => '330.00', 'tax_amount' => '70.00', 'shipping_amount' => '0.00', 'voided_at' => now()]);
    $corrective = zeroCorrective($voided);

    $deliveries = app(SendOrderInvoices::class)($order);

    $issued = Invoice::query()->where('order_id', $order->id)->whereNotIn('id', [$voided->id, $corrective->id])->sole();

    expect($deliveries)->toHaveCount(2)
        ->and(collect($deliveries)->pluck('invoice_id')->all())->toEqualCanonicalizing([$issued->id, $corrective->id])
        ->and($issued->total)->toBe('600.00')
        ->and(collect($deliveries)->every(fn (InvoiceDelivery $delivery): bool => $delivery->is_copy === false))->toBeTrue();
    Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
});

test('an order whose invoices are all voided with nothing left to invoice and only sent correctives is refused as nothing to send (T-21)', function () {
    $order = Invoicing::thousandOrder();
    $voided = Delivering::invoice($order, ['total' => '1000.00', 'voided_at' => now()]);
    Delivering::deliver(zeroCorrective($voided), 'sent');
    $before = Numbering::snapshot();

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->not->toStartWith('invoices.errors.')
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(InvoiceDelivery::query()->count())->toBe(1)
        ->and(Numbering::snapshot())->toBe($before);
    Notification::assertNothingSent();
});

// T-22
test('eleven attachments are refused before any row is written, ten are sent (T-22)', function () {
    expect(config('invoicing.delivery.max_attachments'))->toBe(10);

    $order = Invoicing::thousandOrder();
    $invoices = collect(range(1, 10))->map(fn (): Invoice => Delivering::invoice($order));

    expect(app(SendOrderInvoices::class)($order))->toHaveCount(10);

    InvoiceDelivery::query()->delete();
    Delivering::invoice($order);

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->not->toStartWith('invoices.errors.')
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and($invoices)->toHaveCount(10);
    Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
});

// T-24 (order-level target)
test('an order-level refusal logs a warning with target_type order and the order id, never an e-mail address (T-24)', function () {
    $order = Invoicing::thousandOrder();
    Delivering::invoice($order);
    $actor = Delivering::sender('invoices.view');
    Log::spy();

    expect(fn () => app(SendOrderInvoices::class)($order))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'send'
            && ($context['target_type'] ?? null) === 'order'
            && ($context['target_id'] ?? null) === $order->id
            && ! str_contains((string) json_encode($context), '@'))
        ->once();
});

// T-25
test('with no invoice, issue and send needs both permissions: send alone and create alone are refused, both succeed (T-25)', function (array $permissions, bool $allowed) {
    $order = Invoicing::thousandOrder();
    $actor = Delivering::sender(...$permissions);
    Log::spy();
    $before = Numbering::snapshot();

    if ($allowed) {
        expect(app(SendOrderInvoices::class)($order))->toHaveCount(1)
            ->and(Invoice::query()->count())->toBe(1);

        return;
    }

    expect(fn () => app(SendOrderInvoices::class)($order))->toThrow(AuthorizationException::class)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
    Notification::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused' && ($context['actor_id'] ?? null) === $actor->id)
        ->once();
})->with([
    'send only' => [['invoices.send'], false],
    'create only' => [['invoices.create'], false],
    'both' => [['invoices.send', 'invoices.create'], true],
]);

test('send alone may still send an order that already has a live invoice (T-25)', function () {
    $order = Invoicing::thousandOrder();
    Delivering::invoice($order);
    Delivering::sender('invoices.send');

    expect(app(SendOrderInvoices::class)($order))->toHaveCount(1);
});

// T-26
test('an order with no invoice that cannot be issued is refused with the matching message, no invoice and no number consumed (T-26)', function (Closure $arrange, ?string $messageKey) {
    $order = $arrange();
    $before = Numbering::snapshot();

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe($messageKey === null ? $errors['invoice'][0] : __($messageKey))
        ->and($errors['invoice'][0])->not->toStartWith('invoices.errors.')
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
    Notification::assertNothingSent();
})->with([
    'cancelled' => [fn (): Order => Invoicing::thousandOrder(['status' => 'cancelled']), 'invoices.errors.cancelled'],
    'nothing left to invoice' => [fn (): Order => Invoicing::thousandOrder(['refunded_amount' => '1000.00']), null],
    'out of date' => [fn (): Order => Invoicing::thousandOrder(['invoice_out_of_date_at' => now()]), 'invoices.errors.out_of_date'],
    'issuer data incomplete' => [function (): Order {
        InvoiceSetting::query()->update(['issuer_tax_id' => null]);

        return Invoicing::thousandOrder();
    }, 'invoices.errors.issuer_incomplete'],
    'customer deleted' => [function (): Order {
        $order = Invoicing::thousandOrder();
        $order->customer->delete();

        return $order;
    }, 'invoices.errors.customer_deleted'],
]);

// T-27
test('when issuing commits but recording the delivery throws, the invoice stays issued and unsent, the error is keyed delivery, and a later send is an original (T-27)', function () {
    $order = Invoicing::thousandOrder();
    $armed = true;

    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'insert into `invoice_deliveries`')) {
            throw new RuntimeException('delivery recording failed');
        }
    });

    $errors = Delivering::refusal(fn () => app(SendOrderInvoices::class)($order));

    $armed = false;

    expect(array_keys($errors))->toBe(['delivery'])
        ->and($errors['delivery'][0])->toBe(__('invoices.errors.issued_but_not_queued'))
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $deliveries = app(SendOrderInvoices::class)($order);

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->is_copy)->toBeFalse()
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);
});

// T-27b
test('the order send returns a list of deliveries each with its invoice, and a refused state is keyed invoice (T-27b)', function () {
    $order = Invoicing::thousandOrder();
    $first = Delivering::invoice($order);
    $second = Delivering::invoice($order);

    $deliveries = app(SendOrderInvoices::class)($order);

    expect(array_is_list($deliveries))->toBeTrue()
        ->and(collect($deliveries)->every(fn ($delivery): bool => $delivery instanceof InvoiceDelivery))->toBeTrue()
        ->and(collect($deliveries)->map(fn (InvoiceDelivery $delivery): string => $delivery->invoice->id)->all())->toEqualCanonicalizing([$first->id, $second->id]);

    $stale = Invoicing::thousandOrder(['invoice_out_of_date_at' => now()]);

    expect(array_keys(Delivering::refusal(fn () => app(SendOrderInvoices::class)($stale))))->toBe(['invoice']);
});
