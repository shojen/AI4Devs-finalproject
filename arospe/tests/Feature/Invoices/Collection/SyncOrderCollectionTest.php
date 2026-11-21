<?php

// Story 0106 (T-15, T-17, T-18, T-30) -- SyncOrderCollection (idempotent, transaction-bound,
// recomputed from rows), its wiring into the three line-item actions through AfterOrderTotalsChanged,
// and the rule that order_payments is never written by collection.

use App\Actions\Invoices\AfterOrderTotalsChanged;
use App\Actions\Invoices\ApplyInvoiceCollection;
use App\Actions\Invoices\CollectInvoice;
use App\Actions\Invoices\ResolveOrderPaymentStatus;
use App\Actions\Invoices\SyncOrderCollection;
use App\Actions\Invoices\UndoInvoiceCollection;
use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\RemoveOrderItem;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// T-15
test('syncing three times gives the same result and writes nothing after the first (T-15)', function () {
    $order = Collecting::order('1000.00');
    Collecting::invoice($order, '600.00', true);
    Collecting::invoice($order, '400.00');

    $first = Collecting::sync($order);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(update|insert|delete)\b.*\b(orders|invoices)\b/is', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $second = Collecting::sync($order);
    $third = Collecting::sync($order);

    expect($first->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($first->collected_amount)->toBe('600.00')
        ->and($second->only(['payment_status', 'collected_amount']))->toEqual($first->only(['payment_status', 'collected_amount']))
        ->and($third->only(['payment_status', 'collected_amount']))->toEqual($first->only(['payment_status', 'collected_amount']))
        ->and($writes)->toBe([]);
});

test('syncing outside a transaction is refused with a LogicException (T-15)', function () {
    $order = Collecting::order('1000.00');
    Collecting::invoice($order, '600.00', true);

    Numbering::withoutTransaction(function () use ($order): void {
        expect(fn () => app(SyncOrderCollection::class)($order))->toThrow(LogicException::class);
    });
});

test('a stale order instance is re-derived from the rows, never from its own attributes (T-15)', function () {
    $order = Collecting::order('1000.00');
    $invoice = Collecting::invoice($order, '600.00');
    $stale = Order::query()->findOrFail($order->id);

    DB::table('invoices')->where('id', $invoice->id)->update(['collected_at' => now(), 'collection_type' => 'card']);

    DB::transaction(function () use ($stale): void {
        app(SyncOrderCollection::class)($stale);
    });

    $fresh = $order->fresh();

    expect($stale->collected_amount)->toBe('0.00')
        ->and($fresh->collected_amount)->toBe('600.00')
        ->and($fresh->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});

test('a cancelled order without invoices keeps its stored collected amount (T-15)', function () {
    $order = Collecting::order('1000.00', [
        'status' => OrderStatus::Cancelled,
        'payment_status' => PaymentStatus::Paid,
        'collected_amount' => '400.00',
    ]);

    $synced = Collecting::sync($order);

    expect($synced->collected_amount)->toBe('400.00')
        ->and($synced->payment_status)->toBe(PaymentStatus::Paid);
});

// T-17
test('a legacy paid order edited before any invoice keeps its status; collected follows billable and the debt stays 0.00 (T-17)', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->paid()->withItems(1)->create(['tax_rate' => null]);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, $item->quantity + 2);

    $fresh = $order->fresh();

    expect($fresh->payment_status)->toBe(PaymentStatus::Paid)
        ->and($fresh->collected_amount)->toBe($fresh->billableAmount())
        ->and($fresh->debt())->toBe('0.00');
});

test('a legacy pending order edited before any invoice stays pending with collected 0.00 (T-17)', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->withItems(1)->create(['tax_rate' => null]);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, $item->quantity + 2);

    $fresh = $order->fresh();

    expect($fresh->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and($fresh->collected_amount)->toBe('0.00')
        ->and($fresh->debt())->toBe($fresh->billableAmount());
});

// T-18
/**
 * Replace AfterOrderTotalsChanged with a double that records the transaction level of each call.
 *
 * @param  list<int>  $levels
 */
function expectOneTotalsHook(array &$levels): void
{
    test()->mock(AfterOrderTotalsChanged::class, function ($mock) use (&$levels): void {
        $mock->shouldReceive('__invoke')->once()->andReturnUsing(function (Order $order) use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
    });
}

test('AfterOrderTotalsChanged runs exactly once per line-item action, inside the caller transaction (T-18)', function (string $action) {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->withItems(2)->create(['tax_rate' => null]);
    $baseline = DB::transactionLevel();
    $levels = [];
    expectOneTotalsHook($levels);

    match ($action) {
        'add' => app(AddOrderItem::class)($order, Product::factory()->create(['price' => '10.00'])->id, null, 1),
        'remove' => app(RemoveOrderItem::class)($order, $order->items()->first()->id),
        'update' => app(UpdateOrderItemQuantity::class)($order, $order->items()->first()->id, 4),
    };

    expect($levels)->toHaveCount(1)
        ->and($levels[0])->toBeGreaterThan($baseline);
})->with(['add', 'remove', 'update']);

test('the totals hook syncs the collection of an invoiced order after a line edit (T-18)', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->withItems(1)->create(['tax_rate' => null]);
    $order->refresh();
    Collecting::invoice($order, $order->total, true);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, $item->quantity + 2);

    $fresh = $order->fresh();

    expect($fresh->collected_amount)->toBe($order->total)
        ->and($fresh->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and(bccomp($fresh->debt(), '0.00', 2))->toBe(1);
});

test('AfterOrderTotalsChanged is a collaborator that only syncs the collection in this story (T-18)', function () {
    $order = Collecting::order('1000.00');
    Collecting::invoice($order, '600.00', true);

    DB::transaction(function () use ($order): void {
        app(AfterOrderTotalsChanged::class)(Order::query()->lockForUpdate()->findOrFail($order->id));
    });

    expect($order->fresh()->collected_amount)->toBe('600.00');
});

// T-30
test('collect, undo and sync never write order_payments, even for a legacy order that owns a payment row (T-30)', function () {
    Collecting::actor();
    $order = Order::factory()->paid()->create(['subtotal' => '1000.00', 'total' => '1000.00']);
    $payment = OrderPayment::query()->where('order_id', $order->id)->sole();
    $before = Collecting::row('order_payments', $payment->id);
    $invoice = Collecting::invoice($order, '1000.00');
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (preg_match('/^\s*(insert|update|delete)\b.*\border_payments\b/is', $query->sql) === 1) {
            $statements[] = $query->sql;
        }
    });

    app(CollectInvoice::class)($invoice, Collecting::method(), OrderPaymentType::Card);
    app(UndoInvoiceCollection::class)($invoice->fresh());
    Collecting::sync($order);

    expect($statements)->toBe([])
        ->and(Collecting::row('order_payments', $payment->id))->toBe($before)
        ->and(OrderPayment::query()->count())->toBe(1);
});

arch('the collection actions never use the OrderPayment model (T-30)')
    ->expect([
        'App\Actions\Invoices\CollectInvoice',
        'App\Actions\Invoices\UndoInvoiceCollection',
        'App\Actions\Invoices\ApplyInvoiceCollection',
        'App\Actions\Invoices\SyncOrderCollection',
        'App\Actions\Invoices\ResolveOrderPaymentStatus',
        'App\Actions\Invoices\AfterOrderTotalsChanged',
    ])
    ->not->toUse(OrderPayment::class);

test('the six collection classes exist (T-30)', function (string $class) {
    expect(class_exists($class))->toBeTrue();
})->with([
    CollectInvoice::class,
    UndoInvoiceCollection::class,
    ApplyInvoiceCollection::class,
    SyncOrderCollection::class,
    AfterOrderTotalsChanged::class,
    ResolveOrderPaymentStatus::class,
]);
