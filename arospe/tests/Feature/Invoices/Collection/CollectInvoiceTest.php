<?php

// Story 0106 (T-2, T-3, T-4, T-12b, T-19, T-28) -- collecting and undoing the collection of one invoice,
// through the real actions and database. Only the clock is arranged and the log is spied.

use App\Actions\Invoices\CollectInvoice;
use App\Actions\Invoices\UndoInvoiceCollection;
use App\Actions\Orders\TransitionOrderStatus;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
    $this->actor = Collecting::actor();
    $this->method = Collecting::method();
    $this->order = Collecting::order('1000.00');
    $this->first = Collecting::invoice($this->order, '600.00');
    $this->second = Collecting::invoice($this->order, '400.00');
});

/**
 * Does the logged call mention every given identifier?
 *
 * @param  list<mixed>  $arguments
 * @param  list<string>  $needles
 */
function collectionLogMentions(array $arguments, array $needles): bool
{
    $encoded = (string) json_encode($arguments);

    foreach ($needles as $needle) {
        if (! str_contains($encoded, $needle)) {
            return false;
        }
    }

    return true;
}

// T-2
test('collecting 600.00 of 600.00 + 400.00 sets the five columns, the collected amount, partially paid and logs once at info (T-2)', function () {
    Log::spy();

    app(CollectInvoice::class)($this->first, $this->method, OrderPaymentType::Card);

    $invoice = $this->first->fresh();
    $order = $this->order->fresh();

    expect($invoice->collected_at->equalTo(now()))->toBeTrue()
        ->and($invoice->collected_by)->toBe($this->actor->id)
        ->and($invoice->collection_payment_method_id)->toBe($this->method->id)
        ->and($invoice->collection_type)->toBe('card')
        ->and($invoice->collection_carried_over)->toBeFalse()
        ->and($order->collected_amount)->toBe('600.00')
        ->and($order->debt())->toBe('400.00')
        ->and($order->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($this->second->fresh()->collected_at)->toBeNull();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (mixed ...$arguments): bool => collectionLogMentions($arguments, [$this->actor->id, $this->first->id, $this->order->id]))
        ->once();
});

// T-3
test('collecting the 400.00 too makes the order paid with a debt of 0.00 and leaves the fulfilment status alone (T-3)', function () {
    app(CollectInvoice::class)($this->first, $this->method, OrderPaymentType::Transfer);
    app(CollectInvoice::class)($this->second, $this->method, OrderPaymentType::Transfer);

    $order = $this->order->fresh();

    expect($order->collected_amount)->toBe('1000.00')
        ->and($order->debt())->toBe('0.00')
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->status)->toBe(OrderStatus::Pending);
});

test('collecting never changes the order status even from Processing (T-3)', function () {
    $order = Collecting::order('1000.00', ['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
    $only = Collecting::invoice($order, '1000.00');

    app(CollectInvoice::class)($only, $this->method, OrderPaymentType::Paypal);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

// T-4
test('undoing a collection clears the five columns, recomputes the amount, restores the status and logs once at warning (T-4)', function () {
    app(CollectInvoice::class)($this->first, $this->method, OrderPaymentType::Card);
    Log::spy();

    app(UndoInvoiceCollection::class)($this->first->fresh());

    $invoice = $this->first->fresh();
    $order = $this->order->fresh();

    expect($invoice->collected_at)->toBeNull()
        ->and($invoice->collected_by)->toBeNull()
        ->and($invoice->collection_payment_method_id)->toBeNull()
        ->and($invoice->collection_type)->toBeNull()
        ->and($invoice->collection_carried_over)->toBeFalse()
        ->and($order->collected_amount)->toBe('0.00')
        ->and($order->debt())->toBe('1000.00')
        ->and($order->payment_status)->toBe(PaymentStatus::PendingPayment);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (mixed ...$arguments): bool => collectionLogMentions($arguments, [$this->actor->id, $this->first->id, $this->order->id]))
        ->once();
});

test('collecting then undoing then collecting again counts the invoice once (T-4)', function () {
    app(CollectInvoice::class)($this->first, $this->method, OrderPaymentType::Card);
    app(UndoInvoiceCollection::class)($this->first->fresh());
    app(CollectInvoice::class)($this->first->fresh(), $this->method, OrderPaymentType::Card);

    expect($this->order->fresh()->collected_amount)->toBe('600.00');
});

// T-12b
test('collect returns the refreshed invoice with its order loaded, so the debt and status read without a query (T-12b)', function () {
    $result = app(CollectInvoice::class)($this->first, $this->method, OrderPaymentType::Card);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect($result)->toBeInstanceOf(Invoice::class)
        ->and($result->is($this->first))->toBeTrue()
        ->and($result->collected_at)->not->toBeNull()
        ->and($result->relationLoaded('order'))->toBeTrue()
        ->and($result->order->debt())->toBe('400.00')
        ->and($result->order->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($queries)->toBe([]);
});

test('every state refusal of collect is a validation error keyed invoice (T-12b)', function () {
    $collected = Collecting::invoice($this->order, '1.00', true);

    $errors = Collecting::errors(fn () => app(CollectInvoice::class)($collected, $this->method, OrderPaymentType::Card));

    expect(array_keys($errors))->toBe(['invoice']);
});

// T-19
test('a Processing order that becomes partially paid stays Processing: Shipped is refused, Pending allowed, Shipped allowed once paid again (T-19)', function () {
    $order = Collecting::order('1000.00', ['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
    $a = Collecting::invoice($order, '600.00', true);
    $b = Collecting::invoice($order, '400.00', true);
    Collecting::sync($order);
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit', 'invoices.collect');
    $this->actingAs($editor);

    app(UndoInvoiceCollection::class)($b->fresh());

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and(fn () => app(TransitionOrderStatus::class)($order->fresh(), OrderStatus::Shipped))->toThrow(ValidationException::class);

    app(CollectInvoice::class)($b->fresh(), $this->method, OrderPaymentType::Card);

    app(TransitionOrderStatus::class)($order->fresh(), OrderStatus::Shipped);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and($a->fresh()->isCollected())->toBeTrue();
});

test('a partially paid Processing order may move back to Pending once confirmed (T-19)', function () {
    $order = Collecting::order('1000.00', ['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
    Collecting::invoice($order, '600.00', true);
    Collecting::invoice($order, '400.00', false);
    Collecting::sync($order);
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);

    app(TransitionOrderStatus::class)($order->fresh(), OrderStatus::Pending, true);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});

// T-28
test('extra request keys are ignored: the actor and the server clock decide and the whole invoice is collected (T-28)', function () {
    $someoneElse = User::factory()->create();
    request()->merge([
        'collected_by' => $someoneElse->id,
        'collected_at' => '2001-01-01 00:00:00',
        'amount' => '1.00',
        'collected_amount' => '999.00',
    ]);

    app(CollectInvoice::class)($this->first, $this->method, OrderPaymentType::Card);

    $invoice = $this->first->fresh();

    expect($invoice->collected_by)->toBe($this->actor->id)
        ->and($invoice->collected_at->equalTo(now()))->toBeTrue()
        ->and($this->order->fresh()->collected_amount)->toBe('600.00');
});

test('the collect action takes no amount: its signature is the invoice, the method and the type only (T-28)', function () {
    $parameters = (new ReflectionMethod(CollectInvoice::class, '__invoke'))->getParameters();

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))
        ->not->toContain('amount')
        ->and($parameters)->toHaveCount(3);
});
