<?php

use App\Actions\Orders\RecordRefund;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0092a: RecordRefund must acquire its row locks in the same order as the line-item
// actions (orders -> order_items), otherwise a concurrent refund and line edit can deadlock.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Run the callback and return the `FOR UPDATE` statements it issued, as the table each one locks.
 *
 * @return list<string>
 */
function lockedTablesInOrder(Closure $callback): array
{
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        if (! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }

        if (preg_match('/from [`"]?(\w+)[`"]?/i', $query->sql, $matches) === 1) {
            $locked[] = $matches[1];
        }
    });

    $callback();

    return $locked;
}

test('a refund records the same result as before', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.refund');
    $this->actingAs($actor);

    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => Product::factory()->create(['price' => '10.00'])->id,
        'quantity' => 4,
    ]);

    $result = app(RecordRefund::class)($order, [$item->id => 2]);

    expect($result->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($order->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and((string) $order->refunded_amount)->toBe('20.00');
});

test('a refund locks the order before its lines, in the same order a line edit does', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.refund', 'orders.edit']);
    $this->actingAs($actor);

    $refundOrder = Order::factory()->paid()->create();
    $refundItem = OrderItem::factory()->for($refundOrder)->create([
        'product_id' => Product::factory()->create(['price' => '10.00'])->id,
        'quantity' => 4,
    ]);
    $editOrder = Order::factory()->create(['status' => OrderStatus::Pending]);
    $editItem = OrderItem::factory()->for($editOrder)->create([
        'product_id' => Product::factory()->create(['price' => '10.00'])->id,
        'quantity' => 1,
    ]);

    $refundLocks = lockedTablesInOrder(fn () => app(RecordRefund::class)($refundOrder, [$refundItem->id => 2]));
    $editLocks = lockedTablesInOrder(fn () => app(UpdateOrderItemQuantity::class)($editOrder, $editItem->id, 3));

    expect(array_search('orders', $refundLocks, true))->toBeInt()
        ->and(array_search('orders', $refundLocks, true))->toBeLessThan(array_search('order_items', $refundLocks, true))
        ->and(array_search('orders', $editLocks, true))->toBeInt()
        ->and(array_search('orders', $editLocks, true))->toBeLessThan(array_search('order_items', $editLocks, true));
});

test('the refund guards read the order as locked, not the callers stale instance', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.refund');
    $this->actingAs($actor);

    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => Product::factory()->create(['price' => '10.00'])->id,
        'quantity' => 4,
    ]);

    Order::query()->whereKey($order->id)->update(['payment_status' => PaymentStatus::Refunded]);

    try {
        app(RecordRefund::class)($order, [$item->id => 1]);
        $this->fail('The refund should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items')
            ->and(Refund::query()->count())->toBe(0);
    }
});
