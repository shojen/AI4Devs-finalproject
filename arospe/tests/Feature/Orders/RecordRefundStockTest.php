<?php

use App\Actions\Orders\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderFullyRefunded;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0092b: RecordRefund gains `array $stockReturns = []` (order item id => units to put back in
// stock). Default: no stock moves. Each listed line returns at most min(units refunded now, R) where
// R = (Q - F) - U is read before the refund (D-2). AdjustOrderStock runs before any FOR UPDATE on
// order_items; the items are then locked and re-verified (D-4).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingRefundStockAdmin(string ...$permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions === [] ? ['orders.refund'] : $permissions);
    test()->actingAs($actor);

    return $actor;
}

/**
 * Adds a line to the order and returns it with the catalog row that holds its stock.
 *
 * @param  'product'|'variant'  $target
 * @param  array<string, mixed>  $itemAttributes
 * @return array{item: OrderItem, stocked: Product|ProductVariant}
 */
function refundStockLine(
    Order $order,
    string $target = 'product',
    int $quantity = 4,
    int $stock = 10,
    array $itemAttributes = [],
    bool $legacy = false,
): array {
    $product = Product::factory()->create(['stock' => $target === 'product' ? $stock : 99, 'price' => '10.00']);
    $variant = null;
    $factory = OrderItem::factory()->for($order);

    if ($target === 'variant') {
        $variant = ProductVariant::factory()->for($product)->create(['stock' => $stock, 'price' => '10.00']);
        $factory = $factory->forVariant($variant);
    } else {
        $factory = $factory->state(['product_id' => $product->id]);
    }

    if ($legacy) {
        $factory = $factory->legacyStockUntracked();
    }

    return [
        'item' => $factory->create(['quantity' => $quantity, ...$itemAttributes]),
        'stocked' => $variant ?? $product,
    ];
}

/**
 * Everything a refused refund must leave untouched.
 *
 * @return array<string, mixed>
 */
function refundStockSnapshot(): array
{
    return [
        'products' => Product::query()->orderBy('id')->pluck('stock', 'id')->all(),
        'variants' => ProductVariant::query()->orderBy('id')->pluck('stock', 'id')->all(),
        'items' => OrderItem::query()->orderBy('id')->get([
            'id', 'quantity', 'refunded_quantity', 'stock_undeducted_quantity', 'stock_refunded_not_out_quantity',
        ])->toArray(),
        'orders' => Order::query()->orderBy('id')->get(['id', 'refunded_amount', 'payment_status', 'status'])->toArray(),
        'refunds' => Refund::query()->count(),
    ];
}

/**
 * @param  array<string, int>  $items
 * @param  array<string, mixed>  $stockReturns
 */
function recordStockRefund(Order $order, array $items, array $stockReturns): Order
{
    return app(RecordRefund::class)($order, $items, stockReturns: $stockReturns);
}

/**
 * Asserts the call throws a ValidationException on exactly `$field` and wrote nothing.
 *
 * @param  array<string, mixed>  $before
 */
function expectRefundStockRefused(Closure $call, array $before, string $field, ?string $message = null): void
{
    try {
        $call();
        test()->fail('The refund should have been refused.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);

        if ($message !== null) {
            expect($exception->errors()[$field][0])->toBe($message);
        }
    }

    expect(refundStockSnapshot())->toBe($before);
}

/**
 * Reduces a query log to the tags that matter for the lock-order convention (D-4).
 *
 * @param  array<int, array{query: string}>  $queryLog
 * @return list<string>
 */
function refundStockLockTags(array $queryLog): array
{
    $tags = [];

    foreach (collect($queryLog)->pluck('query') as $sql) {
        if (preg_match('/^\s*select .*? from `(orders|products|product_variants|order_items)` .*for update/is', $sql, $match) === 1) {
            $tags[] = 'lock:'.$match[1];
        } elseif (preg_match('/^\s*update `(products|product_variants)` set `stock`/i', $sql, $match) === 1) {
            $tags[] = 'stock:'.$match[1];
        } elseif (preg_match('/^\s*insert into `refunds`/i', $sql) === 1) {
            $tags[] = 'insert:refunds';
        } elseif (preg_match('/^\s*update `order_items`/i', $sql) === 1) {
            $tags[] = 'update:order_items';
        }
    }

    return $tags;
}

// --- Gherkin scenarios (product and variant lines) ---

test('a refund moves no stock by default', function (string $target) {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, $target);

    app(RecordRefund::class)($order, [$line['item']->id => 2]);

    $item = $line['item']->fresh();

    expect(Refund::query()->count())->toBe(1)
        ->and($item->refunded_quantity)->toBe(2)
        ->and($line['stocked']->fresh()->stock)->toBe(10)
        ->and($item->stock_undeducted_quantity)->toBe(0)
        ->and($item->stock_refunded_not_out_quantity)->toBe(0);
})->with(['product', 'variant']);

test('a refund with an empty stock return list or a zero entry moves no stock', function (array $returns) {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order);
    $itemId = $line['item']->id;

    recordStockRefund($order, [$itemId => 2], $returns === [] ? [] : [$itemId => 0]);

    expect(Refund::query()->count())->toBe(1)
        ->and($line['stocked']->fresh()->stock)->toBe(10)
        ->and($line['item']->fresh()->stock_refunded_not_out_quantity)->toBe(0);
})->with(['empty list' => [[]], 'zero entry' => [[1]]]);

test('returning all refunded units to stock raises the stock and the refunded-not-out counter', function (string $target) {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, $target);

    recordStockRefund($order, [$line['item']->id => 2], [$line['item']->id => 2]);

    $item = $line['item']->fresh();

    expect($line['stocked']->fresh()->stock)->toBe(12)
        ->and($item->refunded_quantity)->toBe(2)
        ->and($item->stock_undeducted_quantity)->toBe(0)
        ->and($item->stock_refunded_not_out_quantity)->toBe(2)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
})->with(['product', 'variant']);

test('returning only part of the refunded units of one line leaves the other lines stock alone', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $mug = refundStockLine($order, 'product', 4);
    $variant = refundStockLine($order, 'variant', 2);

    recordStockRefund($order, [$mug['item']->id => 3, $variant['item']->id => 2], [$mug['item']->id => 1]);

    expect($mug['stocked']->fresh()->stock)->toBe(11)
        ->and($variant['stocked']->fresh()->stock)->toBe(10)
        ->and($mug['item']->fresh()->stock_refunded_not_out_quantity)->toBe(1)
        ->and($variant['item']->fresh()->stock_refunded_not_out_quantity)->toBe(0)
        ->and($variant['item']->fresh()->refunded_quantity)->toBe(2);
});

test('a second refund of different units of a line may return its own units again', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $id = $line['item']->id;

    recordStockRefund($order, [$id => 2], [$id => 2]);
    recordStockRefund($order, [$id => 2], [$id => 2]);

    expect($line['stocked']->fresh()->stock)->toBe(14)
        ->and($line['item']->fresh()->stock_refunded_not_out_quantity)->toBe(4)
        ->and($line['item']->fresh()->stock_undeducted_quantity)->toBe(0);
});

test('a refund of a line whose variant was deleted records the refund but moves no stock', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $orphan = refundStockLine($order, 'product', 4, itemAttributes: ['product_variant_id' => null, 'ordered_as_variant' => true]);
    $before = refundStockSnapshot();

    recordStockRefund($order, [$orphan['item']->id => 1], [$orphan['item']->id => 1]);

    $after = refundStockSnapshot();

    expect(Refund::query()->count())->toBe(1)
        ->and($after['products'])->toBe($before['products'])
        ->and($after['variants'])->toBe($before['variants'])
        ->and($orphan['item']->fresh()->refunded_quantity)->toBe(1)
        ->and($orphan['item']->fresh()->stock_refunded_not_out_quantity)->toBe(0);
});

test('an administrator who may not refund cannot refund or move stock', function () {
    actingRefundStockAdmin('orders.view');
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order);
    $before = refundStockSnapshot();

    expect(fn () => recordStockRefund($order, [$line['item']->id => 2], [$line['item']->id => 2]))
        ->toThrow(AuthorizationException::class);

    expect(refundStockSnapshot())->toBe($before);
});

// --- Last refunded unit / events ---

test('the last refunded unit cancels the order automatically without moving more stock', function () {
    actingRefundStockAdmin('orders.refund', 'orders.edit');
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending]);
    $line = refundStockLine($order, 'product', 2);

    recordStockRefund($order, [$line['item']->id => 2], [$line['item']->id => 2]);

    expect($line['stocked']->fresh()->stock)->toBe(12)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('OrderFullyRefunded is dispatched only when the refund leaves the order fully refunded', function () {
    actingRefundStockAdmin();
    Event::fake([OrderFullyRefunded::class]);
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $id = $line['item']->id;

    recordStockRefund($order, [$id => 2], [$id => 2]);
    Event::assertNotDispatched(OrderFullyRefunded::class);

    recordStockRefund($order, [$id => 2], [$id => 2]);
    Event::assertDispatchedTimes(OrderFullyRefunded::class, 1);
});

// --- Validation matrix: business refusals (field `stock_returns`, custom message) ---

test('returning more units than the refund covers is refused as a whole', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$line['item']->id => 2], [$line['item']->id => 3]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_exceeds_refunded'),
    );
});

test('returning units of a line that is not part of this refund is refused', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $mug = refundStockLine($order, 'product', 4);
    $plate = refundStockLine($order, 'product', 2);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$mug['item']->id => 2], [$plate['item']->id => 1]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_in_refund'),
    );
});

test('returning units of a line of another order is refused as not part of this refund', function () {
    actingRefundStockAdmin();
    $first = Order::factory()->paid()->create();
    $second = Order::factory()->paid()->create();
    $mine = refundStockLine($first, 'product', 4);
    $foreign = refundStockLine($second, 'product', 4);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($first, [$mine['item']->id => 2], [$foreign['item']->id => 1]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_in_refund'),
    );
});

test('returning units of an unknown line id is refused as not part of this refund', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$line['item']->id => 2], ['01900000-0000-7000-8000-000000000000' => 1]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_in_refund'),
    );
});

test('a zero entry for a key that is not in the refund is still refused, the key check runs before the zero skip', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $mug = refundStockLine($order, 'product', 4);
    $plate = refundStockLine($order, 'product', 2);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$mug['item']->id => 2], [$plate['item']->id => 0]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_in_refund'),
    );
});

test('units already returned on cancellation are not returned again by a refund', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Cancelled]);
    // Cancellation gave every unit back: all four units are undeducted, so R = (4 - 0) - 4 = 0.
    $line = refundStockLine($order, 'product', 4, itemAttributes: ['stock_undeducted_quantity' => 4]);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$line['item']->id => 4], [$line['item']->id => 4]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_out_of_stock'),
    );
});

test('a legacy lines refunded units cannot be returned to stock', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4, legacy: true);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$line['item']->id => 2], [$line['item']->id => 2]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_out_of_stock'),
    );
});

test('units added without deducting cannot come back through a refund, only the units that were taken out can', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    // 2 units deducted, raised to 5 without deducting: Q 5, U 3, R = 5 - 3 = 2.
    $line = refundStockLine($order, 'product', 5, stock: 8, itemAttributes: ['stock_undeducted_quantity' => 3]);
    $id = $line['item']->id;
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$id => 3], [$id => 3]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_out_of_stock'),
    );

    recordStockRefund($order, [$id => 3], [$id => 2]);

    $item = $line['item']->fresh();

    expect($line['stocked']->fresh()->stock)->toBe(10)
        ->and($item->stock_undeducted_quantity)->toBe(2)
        ->and($item->stock_refunded_not_out_quantity)->toBe(3);
});

test('exceeding both the refunded units and the returnable units reports the refunded-units refusal first', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4, legacy: true);
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$line['item']->id => 1], [$line['item']->id => 3]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_exceeds_refunded'),
    );
});

// --- Validation matrix: shape refusals (default rule messages, error on stock_returns.<id>) ---

test('a non-integer or negative stock return entry is refused on its own key and writes nothing', function (mixed $badValue) {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $id = $line['item']->id;
    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$id => 2], [$id => $badValue]),
        $before,
        'stock_returns.'.$id,
    );
})->with(['non numeric string' => ['abc'], 'negative' => [-1], 'decimal' => [1.5]]);

// --- Every other refusal happens before any stock moves ---

test('refusals that are not about stock returns move no stock even when a stock return is requested', function (string $case) {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $other = refundStockLine(Order::factory()->paid()->create(), 'product', 4);
    $id = $line['item']->id;

    if ($case === 'pending payment') {
        $order->forceFill(['payment_status' => PaymentStatus::PendingPayment])->save();
    }

    $before = refundStockSnapshot();

    [$items, $field] = match ($case) {
        'pending payment' => [[$id => 2], 'items'],
        'item not owned' => [[$other['item']->id => 2, $id => 2], 'items'],
        'exceeds outstanding' => [[$id => 5], 'items'],
    };

    expectRefundStockRefused(
        fn () => recordStockRefund($order, $items, [$id => 1]),
        $before,
        $field,
    );
})->with(['pending payment', 'item not owned', 'exceeds outstanding']);

// --- Counters ---

test('the over transfer runs for every refunded line, including null-target lines and unlisted lines', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    // Orphaned line: Q 4, U 1 -> R = 3. Refund 4: over = 1, restock 3 is accepted but never applied (no target).
    $orphan = refundStockLine($order, 'product', 4, itemAttributes: [
        'product_variant_id' => null, 'ordered_as_variant' => true, 'stock_undeducted_quantity' => 1,
    ]);
    // Unlisted line: Q 3, U 2 -> R = 1. Refund 3 without restock: over = 2.
    $unlisted = refundStockLine($order, 'product', 3, itemAttributes: ['stock_undeducted_quantity' => 2]);

    recordStockRefund($order, [$orphan['item']->id => 4, $unlisted['item']->id => 3], [$orphan['item']->id => 3]);

    $orphanItem = $orphan['item']->fresh();
    $unlistedItem = $unlisted['item']->fresh();

    expect($orphanItem->stock_undeducted_quantity)->toBe(0)
        ->and($orphanItem->stock_refunded_not_out_quantity)->toBe(1)
        ->and($unlistedItem->stock_undeducted_quantity)->toBe(0)
        ->and($unlistedItem->stock_refunded_not_out_quantity)->toBe(2)
        ->and($unlisted['stocked']->fresh()->stock)->toBe(10);
});

test('a raise without deducting then a refund returning the two units that were taken out is accepted', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4, stock: 8, itemAttributes: ['stock_undeducted_quantity' => 2]);
    $id = $line['item']->id;

    recordStockRefund($order, [$id => 2], [$id => 2]);

    $item = $line['item']->fresh();

    expect($line['stocked']->fresh()->stock)->toBe(10)
        ->and($item->stock_undeducted_quantity)->toBe(2)
        ->and($item->stock_refunded_not_out_quantity)->toBe(2);
});

test('0088 sequence 9 with two units: the over transfer adds to the restocked units', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    // 2 units deducted, raised to 5 without deducting: refunding 2 returns both taken-out units.
    $line = refundStockLine($order, 'product', 5, stock: 8, itemAttributes: ['stock_undeducted_quantity' => 3]);
    $id = $line['item']->id;

    recordStockRefund($order, [$id => 3], [$id => 2]);

    $item = $line['item']->fresh();

    expect($line['stocked']->fresh()->stock)->toBe(10)
        ->and($item->stock_undeducted_quantity)->toBe(2)
        ->and($item->stock_refunded_not_out_quantity)->toBe(3);
});

test('the two counter sequence keeps the out-of-stock accounting consistent', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 5, stock: 10);
    $id = $line['item']->id;

    recordStockRefund($order, [$id => 2], []);

    // Raise to 7 without deducting: two more undeducted units.
    OrderItem::query()->whereKey($id)->update(['quantity' => 7, 'stock_undeducted_quantity' => 2]);

    recordStockRefund($order, [$id => 2], [$id => 2]);

    $item = $line['item']->fresh();

    expect($line['stocked']->fresh()->stock)->toBe(12)
        ->and($item->stock_undeducted_quantity)->toBe(2)
        ->and($item->stock_refunded_not_out_quantity)->toBe(2)
        // 5 units were taken out, 2 came back: 3 are still out of inventory.
        ->and($item->returnableStockUnits() + $item->refundedUnitsOutOfStock())->toBe(3);
});

test('after a refund without restock on a raised line no later refund may restock any unit', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    // Review #3 counterexample: 2 deducted, raised to 5 without deducting.
    $line = refundStockLine($order, 'product', 5, stock: 10, itemAttributes: ['stock_undeducted_quantity' => 3]);
    $id = $line['item']->id;

    recordStockRefund($order, [$id => 3], []);

    $item = $line['item']->fresh();
    expect($item->stock_undeducted_quantity)->toBe(2)
        ->and($item->stock_refunded_not_out_quantity)->toBe(1)
        ->and($item->returnableStockUnits())->toBe(0);

    $before = refundStockSnapshot();

    expectRefundStockRefused(
        fn () => recordStockRefund($order, [$id => 2], [$id => 1]),
        $before,
        'stock_returns',
        __('orders.refunds.stock_return_not_out_of_stock'),
    );

    recordStockRefund($order, [$id => 2], []);

    expect($line['stocked']->fresh()->stock)->toBe(10)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

// --- Lock order (D-4, forward rule appsec L-1) ---

test('stock is returned after the product and variant locks and before any order_items lock', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $product = refundStockLine($order, 'product', 4);
    $variant = refundStockLine($order, 'variant', 4);

    DB::flushQueryLog();
    DB::enableQueryLog();

    recordStockRefund(
        $order,
        [$product['item']->id => 2, $variant['item']->id => 2],
        [$product['item']->id => 2, $variant['item']->id => 1],
    );

    $tags = refundStockLockTags(DB::getQueryLog());
    DB::disableQueryLog();

    $firstItemLock = array_search('lock:order_items', $tags, true);
    $lastStockTag = max(array_keys(array_filter($tags, fn (string $tag): bool => str_starts_with($tag, 'stock:'))));

    expect(array_slice($tags, 0, 6))->toBe([
        'lock:orders', 'lock:products', 'lock:product_variants', 'stock:products', 'stock:product_variants', 'lock:order_items',
    ])
        ->and($firstItemLock)->toBeGreaterThan($lastStockTag)
        ->and(array_count_values($tags)['lock:order_items'])->toBe(1)
        ->and(array_slice($tags, 6))->each->toBeIn(['insert:refunds', 'update:order_items'])
        ->and(array_slice($tags, 6))->toContain('insert:refunds');
});

test('without stock returns the order is locked before its items and no stock lock is taken', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);

    DB::flushQueryLog();
    DB::enableQueryLog();

    recordStockRefund($order, [$line['item']->id => 2], []);

    $tags = refundStockLockTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_slice($tags, 0, 2))->toBe(['lock:orders', 'lock:order_items'])
        ->and($tags)->not->toContain('lock:products')
        ->and($tags)->not->toContain('stock:products');
});

// --- D-4 step 6 re-verify ---

test('a locked re-read whose counters differ from the first read refuses the call and rolls the stock change back', function (string $column, int $value) {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $itemId = $line['item']->id;
    $armed = true;

    Event::listen('eloquent.retrieved: '.OrderItem::class, function (OrderItem $model) use (&$armed, $itemId, $column, $value): void {
        if ($armed && $model->id === $itemId) {
            $armed = false;
            DB::table('order_items')->where('id', $itemId)->update([$column => $value]);
        }
    });

    try {
        expect(fn () => recordStockRefund($order, [$itemId => 2], [$itemId => 2]))->toThrow(LogicException::class);
    } finally {
        $armed = false;
        Event::forget('eloquent.retrieved: '.OrderItem::class);
    }

    expect($line['stocked']->fresh()->stock)->toBe(10)
        ->and(Refund::query()->count())->toBe(0)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and((string) $order->fresh()->refunded_amount)->toBe('0.00');
})->with([
    'undeducted units' => ['stock_undeducted_quantity', 1],
    'refunded units' => ['refunded_quantity', 1],
    'refunded-not-out units' => ['stock_refunded_not_out_quantity', 1],
    'quantity' => ['quantity', 5],
]);

test('a refunded line missing from the locked re-read refuses the call and rolls the stock change back', function () {
    actingRefundStockAdmin();
    $order = Order::factory()->paid()->create();
    $line = refundStockLine($order, 'product', 4);
    $itemId = $line['item']->id;
    $armed = true;

    Event::listen('eloquent.retrieved: '.OrderItem::class, function (OrderItem $model) use (&$armed, $itemId): void {
        if ($armed && $model->id === $itemId) {
            $armed = false;
            DB::table('order_items')->where('id', $itemId)->delete();
        }
    });

    try {
        expect(fn () => recordStockRefund($order, [$itemId => 2], [$itemId => 2]))->toThrow(LogicException::class);
    } finally {
        $armed = false;
        Event::forget('eloquent.retrieved: '.OrderItem::class);
    }

    expect($line['stocked']->fresh()->stock)->toBe(10)
        ->and(Refund::query()->count())->toBe(0);
});
