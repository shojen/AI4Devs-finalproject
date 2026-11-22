<?php

// Story 0106 (T-11, T-22b) -- the model surface of collection (latestCollectedInvoice, the legacy
// payment relation) and the shared "every line fully refunded" rule used by RecordRefund and by the
// derived payment status.

use App\Actions\Orders\EveryLineFullyRefunded;
use App\Actions\Orders\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// T-11
test('latestCollectedInvoice returns the most recent collection by collected_at (T-11)', function () {
    $order = Collecting::order('1000.00');
    Collecting::invoice($order, '300.00', true, ['collected_at' => '2026-03-01 10:00:00']);
    $latest = Collecting::invoice($order, '300.00', true, ['collected_at' => '2026-05-01 10:00:00']);
    Collecting::invoice($order, '300.00', true, ['collected_at' => '2026-04-01 10:00:00']);
    Collecting::invoice($order, '100.00', false);

    expect(Collecting::latestCollected($order->refresh())?->id)->toBe($latest->id);
});

test('latestCollectedInvoice is null when nothing is collected (T-11)', function () {
    $order = Collecting::order('1000.00');
    Collecting::invoice($order, '300.00');

    expect(Collecting::latestCollected($order))->toBeNull();
});

test('Order::payment still reads a legacy order_payments row and collection never writes it (T-11)', function () {
    $order = Order::factory()->paid()->create(['subtotal' => '100.00', 'total' => '100.00']);
    $payment = OrderPayment::query()->where('order_id', $order->id)->sole();
    $before = Collecting::row('order_payments', $payment->id);

    Collecting::invoice($order, '100.00', true);
    Collecting::sync($order);

    expect($order->fresh()->payment?->id)->toBe($payment->id)
        ->and(Collecting::row('order_payments', $payment->id))->toBe($before);
});

// T-22b: [lines as [quantity, units to refund now], expected "every line fully refunded"]
test('the shared helper answers like RecordRefund for every line state (T-22b)', function (array $lines, bool $expected) {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.refund');
    $this->actingAs($editor);
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending]);
    $items = [];

    foreach ($lines as [$quantity]) {
        $items[] = OrderItem::factory()->for($order)->create(['quantity' => $quantity, 'unit_price' => '10.00', 'line_total' => bcmul('10.00', (string) $quantity, 2)]);
    }

    $refund = [];

    foreach ($lines as $index => [, $units]) {
        if ($units > 0) {
            $refund[$items[$index]->id] = $units;
        }
    }

    app(RecordRefund::class)($order, $refund);

    $fresh = $order->fresh();
    $helper = app(EveryLineFullyRefunded::class)($fresh->items()->get());

    expect($helper)->toBe($expected)
        ->and($fresh->payment_status === PaymentStatus::Refunded)->toBe($expected);
})->with([
    'single line fully refunded' => [[[2, 2]], true],
    'single line partly refunded' => [[[3, 1]], false],
    'two lines, both fully refunded' => [[[1, 1], [2, 2]], true],
    'two lines, one fully refunded' => [[[1, 1], [2, 0]], false],
    'two lines, both partly refunded' => [[[2, 1], [4, 3]], false],
]);

test('the shared helper reports a line with nothing refunded as not fully refunded, and an empty set as vacuously true like RecordRefund (T-22b)', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    $helper = app(EveryLineFullyRefunded::class);

    expect($helper($order->items()->get()))->toBeFalse()
        ->and($item->refunded_quantity)->toBe(0)
        ->and($helper($order->items()->whereKey('none')->get()))->toBeTrue();
});
