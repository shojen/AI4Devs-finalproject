<?php

use App\Actions\Orders\RecordRefund;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0090: UpdateOrderItemQuantity gains `bool $adjustStock = false`. With true the stock follows
// the change (D-2/D-3), the counters follow 0088's rules in both modes, and AdjustOrderStock runs
// before any FOR UPDATE on order_items (D-7).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingQuantityStockEditor(string ...$permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions === [] ? ['orders.edit', 'orders.refund'] : $permissions);
    test()->actingAs($actor);

    return $actor;
}

/**
 * Builds an order with the line under test (plus a 1-unit "Plate" line) and returns the pieces.
 *
 * @param  'product'|'variant'  $target
 * @param  array<string, mixed>  $itemAttributes
 * @param  array<string, mixed>  $orderAttributes
 * @return array{order: Order, item: OrderItem, stocked: Product|ProductVariant}
 */
function quantityStockFixture(
    string $target,
    int $quantity = 2,
    int $stock = 10,
    array $itemAttributes = [],
    bool $legacy = false,
    array $orderAttributes = [],
): array {
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending, ...$orderAttributes]);
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

    $item = $factory->create(['quantity' => $quantity, ...$itemAttributes]);
    OrderItem::factory()->for($order)->create(['quantity' => 1]);

    return ['order' => $order, 'item' => $item, 'stocked' => $variant ?? $product];
}

/**
 * @return array{products: array<string, int>, variants: array<string, int>}
 */
function quantityStockSnapshot(): array
{
    return [
        'products' => Product::query()->orderBy('id')->pluck('stock', 'id')->all(),
        'variants' => ProductVariant::query()->orderBy('id')->pluck('stock', 'id')->all(),
    ];
}

/**
 * @param  array{order: Order, item: OrderItem, stocked: Product|ProductVariant}  $fixture
 */
function changeLineQuantity(array $fixture, int $quantity, ?bool $adjustStock = null): OrderItem
{
    $action = app(UpdateOrderItemQuantity::class);

    return $adjustStock === null
        ? $action($fixture['order'], $fixture['item']->id, $quantity)
        : $action($fixture['order'], $fixture['item']->id, $quantity, adjustStock: $adjustStock);
}

/**
 * Reduces a query log to the ordered tags that matter for the lock-order convention (D-7).
 *
 * @param  array<int, array{query: string}>  $queryLog
 * @return list<string>
 */
function quantityStockLockTags(array $queryLog): array
{
    $tags = [];

    foreach (collect($queryLog)->pluck('query') as $sql) {
        if (preg_match('/^\s*select .*? from `(orders|products|product_variants|order_items)` .*for update/is', $sql, $match) === 1) {
            $tags[] = 'lock:'.$match[1];
        } elseif (preg_match('/^\s*update `(products|product_variants)` set `stock`/i', $sql, $match) === 1) {
            $tags[] = 'stock:'.$match[1];
        } elseif (preg_match('/^\s*update `order_items` set/i', $sql) === 1) {
            $tags[] = 'write:order_items';
        }
    }

    return $tags;
}

// --- Gherkin scenarios, product line and variant line ---

test('raising a quantity without adjusting stock leaves stock unchanged and keeps the new units undeducted', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target);

    changeLineQuantity($fixture, 5, false);

    $fresh = $fixture['item']->fresh();

    expect($fresh->quantity)->toBe(5)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and($fresh->stock_undeducted_quantity)->toBe(3)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(0);
})->with(['product', 'variant']);

test('raising a quantity with stock adjustment takes the added units out and leaves the counters', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target);

    changeLineQuantity($fixture, 5, true);

    $fresh = $fixture['item']->fresh();

    expect($fresh->quantity)->toBe(5)
        ->and($fixture['stocked']->fresh()->stock)->toBe(7)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->returnableStockUnits())->toBe(5);
})->with(['product', 'variant']);

test('raising a quantity beyond the available stock is allowed and stock goes negative', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, stock: 1);

    changeLineQuantity($fixture, 5, true);

    expect($fixture['item']->fresh()->quantity)->toBe(5)
        ->and($fixture['stocked']->fresh()->stock)->toBe(-2);
})->with(['product', 'variant']);

test('lowering a quantity with stock adjustment returns the removed units', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 5);

    changeLineQuantity($fixture, 2, true);

    $fresh = $fixture['item']->fresh();

    expect($fresh->quantity)->toBe(2)
        ->and($fixture['stocked']->fresh()->stock)->toBe(13)
        ->and($fresh->stock_undeducted_quantity)->toBe(0);
})->with(['product', 'variant']);

test('lowering a quantity without adjusting stock leaves stock unchanged', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 5);

    changeLineQuantity($fixture, 2, false);

    expect($fixture['item']->fresh()->quantity)->toBe(2)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);
})->with(['product', 'variant']);

test('lowering a legacy line returns nothing even when stock adjustment is requested', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 5, legacy: true);

    changeLineQuantity($fixture, 2, true);

    $fresh = $fixture['item']->fresh();

    expect($fresh->quantity)->toBe(2)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and($fresh->stock_undeducted_quantity)->toBe(2);
});

test('units added without adjusting stock are not returned when the quantity goes back down', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 2);

    changeLineQuantity($fixture, 5, false);
    changeLineQuantity($fixture, 2, true);

    expect($fixture['item']->fresh()->quantity)->toBe(2)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);
})->with(['product', 'variant']);

test('after raising without deducting and lowering with adjustment the line still counts exactly the units first taken out', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 2, stock: 8);

    changeLineQuantity($fixture, 5, false);
    expect($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(3);

    changeLineQuantity($fixture, 2, true);

    $fresh = $fixture['item']->fresh();

    expect($fixture['stocked']->fresh()->stock)->toBe(8)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->returnableStockUnits())->toBe(2);
})->with(['product', 'variant']);

test('lowering a line that was refunded and then raised without deducting returns only units taken out', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 5, stock: 10);
    $fixture['stocked']->forceFill(['stock' => 5])->save();

    app(RecordRefund::class)($fixture['order'], [$fixture['item']->id => 2]);
    changeLineQuantity($fixture, 7, false);

    $afterRaise = $fixture['item']->fresh();
    expect($afterRaise->stock_undeducted_quantity)->toBe(2)
        ->and($fixture['stocked']->fresh()->stock)->toBe(5);

    changeLineQuantity($fixture, 2, true);

    $fresh = $fixture['item']->fresh();

    expect($fixture['stocked']->fresh()->stock)->toBe(8)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(0)
        ->and($fresh->returnableStockUnits())->toBe(0)
        ->and($fresh->refundedUnitsOutOfStock())->toBe(2);
})->with(['product', 'variant']);

test('review 3 sequence (a): lowering after a refund and a deducted raise returns the units taken out by that raise', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 2, stock: 8);

    changeLineQuantity($fixture, 5, false);
    app(RecordRefund::class)($fixture['order'], [$fixture['item']->id => 3]);
    changeLineQuantity($fixture, 7, true);

    expect($fixture['stocked']->fresh()->stock)->toBe(6);

    changeLineQuantity($fixture, 3, true);

    expect($fixture['item']->fresh()->quantity)->toBe(3)
        ->and($fixture['stocked']->fresh()->stock)->toBe(8)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(0);
})->with(['product', 'variant']);

test('review 3 sequence (b): lowering a refunded legacy line after a deducted raise returns the deducted unit', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 3, stock: 7, legacy: true);

    app(RecordRefund::class)($fixture['order'], [$fixture['item']->id => 3]);
    changeLineQuantity($fixture, 4, true);

    expect($fixture['stocked']->fresh()->stock)->toBe(6);

    changeLineQuantity($fixture, 3, true);

    expect($fixture['item']->fresh()->quantity)->toBe(3)
        ->and($fixture['stocked']->fresh()->stock)->toBe(7);
});

test('lowering a quantity after a refund with stock return returns only the units that no longer fit', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 5, itemAttributes: ['refunded_quantity' => 2, 'stock_refunded_not_out_quantity' => 2]);

    changeLineQuantity($fixture, 3, true);

    expect($fixture['item']->fresh()->quantity)->toBe(3)
        ->and($fixture['stocked']->fresh()->stock)->toBe(12);
})->with(['product', 'variant']);

// --- Null stock target (D-8) ---

test('a line whose variant was deleted moves no stock and still changes quantity', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 5, itemAttributes: ['product_variant_id' => null, 'ordered_as_variant' => true]);
    $before = quantityStockSnapshot();

    changeLineQuantity($fixture, 2, true);

    expect($fixture['item']->fresh()->quantity)->toBe(2)
        ->and(quantityStockSnapshot())->toBe($before);
});

test('a line whose product was deleted moves no stock and still changes quantity', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 5);
    $fixture['item']->forceFill(['product_id' => null])->save();
    $before = quantityStockSnapshot();

    changeLineQuantity($fixture, 2, true);

    expect($fixture['item']->fresh()->quantity)->toBe(2)
        ->and(quantityStockSnapshot())->toBe($before);
});

test('an orphaned-variant line raised with stock adjustment moves no stock', function () {
    actingQuantityStockEditor();
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending]);
    $item = OrderItem::factory()->for($order)->orphanedVariant()->create(['quantity' => 2]);
    OrderItem::factory()->for($order)->create(['quantity' => 1]);
    $before = quantityStockSnapshot();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 5, adjustStock: true);

    expect($item->fresh()->quantity)->toBe(5)
        ->and(quantityStockSnapshot())->toBe($before);
});

// --- D-3 numeric table ---

test('the D-3 numeric table: stock delta and counters per (Q, F, K, U) row', function (
    string $target,
    int $quantity,
    int $refunded,
    int $notOutRefunded,
    int $undeducted,
    int $newQuantity,
    bool $adjust,
    int $expectedDelta,
    int $expectedUndeducted,
) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: $quantity, itemAttributes: [
        'refunded_quantity' => $refunded,
        'stock_refunded_not_out_quantity' => $notOutRefunded,
        'stock_undeducted_quantity' => $undeducted,
    ]);

    changeLineQuantity($fixture, $newQuantity, $adjust);

    $fresh = $fixture['item']->fresh();

    expect($fresh->quantity)->toBe($newQuantity)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10 + $expectedDelta)
        ->and($fresh->stock_undeducted_quantity)->toBe($expectedUndeducted)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe($notOutRefunded)
        ->and($fresh->refunded_quantity)->toBe($refunded);
})->with(['product', 'variant'])->with([
    'deducted line lowered 5 to 2' => [5, 0, 0, 0, 2, true, 3, 0],
    'legacy line lowered 5 to 2' => [5, 0, 0, 5, 2, true, 0, 2],
    'refund with stock return, lowered 5 to 3' => [5, 2, 2, 0, 3, true, 2, 0],
    'partly undeducted, lowered 5 to 4' => [5, 0, 0, 3, 4, true, 0, 2],
    'refunded and undeducted, lowered 7 to 2' => [7, 2, 0, 2, 2, true, 3, 0],
    'deducted line lowered 5 to 2 without adjust' => [5, 0, 0, 0, 2, false, 0, 0],
    'partly undeducted, lowered 5 to 4 without adjust' => [5, 0, 0, 3, 4, false, 0, 2],
    'review 3 counterexample, 7 to 3' => [7, 3, 1, 2, 3, true, 2, 0],
    'legacy refunded line, 4 to 3' => [4, 3, 3, 0, 3, true, 1, 0],
]);

test('an unchanged quantity is a no-op for stock and counters in both modes', function (string $target, bool $adjust) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 4, itemAttributes: ['stock_undeducted_quantity' => 1]);
    $before = quantityStockSnapshot();

    changeLineQuantity($fixture, 4, $adjust);

    $fresh = $fixture['item']->fresh();

    expect($fresh->quantity)->toBe(4)
        ->and($fresh->stock_undeducted_quantity)->toBe(1)
        ->and(quantityStockSnapshot())->toBe($before);
})->with(['product', 'variant'])->with([true, false]);

test('without the parameter stock never changes and the counters follow the existing rules', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 2);

    changeLineQuantity($fixture, 5);
    $afterRaise = $fixture['item']->fresh();
    expect($afterRaise->stock_undeducted_quantity)->toBe(3)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);

    changeLineQuantity($fixture, 1);

    expect($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(0)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);
})->with(['product', 'variant']);

test('two-counter sequence: stock ends at 8 with nothing left to return after a no-restock refund and a lowered raise', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 5, stock: 5);

    app(RecordRefund::class)($fixture['order'], [$fixture['item']->id => 2]);
    changeLineQuantity($fixture, 7, false);
    changeLineQuantity($fixture, 2, true);

    $fresh = $fixture['item']->fresh();

    expect($fixture['stocked']->fresh()->stock)->toBe(8)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->returnableStockUnits())->toBe(0)
        ->and($fresh->refundedUnitsOutOfStock())->toBe(2);
});

// --- Refusals move no stock and no counters ---

test('a quantity change on a shipped order is refused and moves no stock', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, orderAttributes: ['status' => OrderStatus::Shipped]);
    $before = quantityStockSnapshot();

    expect(fn () => changeLineQuantity($fixture, 5, true))->toThrow(OrderNotEditableException::class);

    expect(quantityStockSnapshot())->toBe($before)
        ->and($fixture['item']->fresh()->quantity)->toBe(2);
})->with(['product', 'variant']);

test('a quantity below the refunded units is refused without moving stock or counters', function (string $target) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target, quantity: 4, itemAttributes: ['refunded_quantity' => 3, 'stock_refunded_not_out_quantity' => 1]);
    $before = quantityStockSnapshot();

    expect(fn () => changeLineQuantity($fixture, 2, true))->toThrow(ValidationException::class);

    $fresh = $fixture['item']->fresh();

    expect(quantityStockSnapshot())->toBe($before)
        ->and($fresh->quantity)->toBe(4)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(1);
})->with(['product', 'variant']);

test('a quantity below one is refused without moving stock', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 3);
    $before = quantityStockSnapshot();

    expect(fn () => changeLineQuantity($fixture, 0, true))->toThrow(ValidationException::class);

    expect(quantityStockSnapshot())->toBe($before)
        ->and($fixture['item']->fresh()->quantity)->toBe(3);
});

test('a line id that does not belong to the order is refused without moving stock', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 3);
    $otherOrder = Order::factory()->create(['status' => OrderStatus::Pending]);
    $foreignItem = OrderItem::factory()->for($otherOrder)->create(['quantity' => 3]);
    $before = quantityStockSnapshot();

    expect(fn () => app(UpdateOrderItemQuantity::class)($fixture['order'], $foreignItem->id, 5, adjustStock: true))
        ->toThrow(ValidationException::class);

    expect(quantityStockSnapshot())->toBe($before)
        ->and($foreignItem->fresh()->quantity)->toBe(3);
});

test('an administrator who may not edit orders cannot move stock through a line change', function (string $target) {
    actingQuantityStockEditor('orders.view');
    $fixture = quantityStockFixture($target);
    $before = quantityStockSnapshot();

    expect(fn () => changeLineQuantity($fixture, 5, true))->toThrow(AuthorizationException::class);

    expect(quantityStockSnapshot())->toBe($before)
        ->and($fixture['item']->fresh()->quantity)->toBe(2);
})->with(['product', 'variant']);

// --- Lock order (D-7, forward rule appsec L-1) ---

test('stock is adjusted after the product and variant locks and before any order_items locking read', function (string $target, array $expectedStockTags) {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture($target);

    DB::flushQueryLog();
    DB::enableQueryLog();

    changeLineQuantity($fixture, 5, true);

    $tags = quantityStockLockTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect($tags)->toBe(['lock:orders', ...$expectedStockTags, 'lock:order_items', 'write:order_items']);
})->with([
    'product line' => ['product', ['lock:products', 'stock:products']],
    'variant line' => ['variant', ['lock:products', 'lock:product_variants', 'stock:product_variants']],
]);

test('a zero delta never takes the stock locks but still locks the line after the order', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product');

    DB::flushQueryLog();
    DB::enableQueryLog();

    changeLineQuantity($fixture, 5, false);

    $tags = quantityStockLockTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect($tags)->toBe(['lock:orders', 'lock:order_items', 'write:order_items']);
});

// --- D-7 step 5 re-verify ---

test('a locked re-read whose counters differ from the first read refuses the call and rolls the stock change back', function () {
    actingQuantityStockEditor();
    $fixture = quantityStockFixture('product', quantity: 5);
    $itemId = $fixture['item']->id;
    $armed = true;

    Event::listen('eloquent.retrieved: '.OrderItem::class, function (OrderItem $model) use (&$armed, $itemId): void {
        if ($armed && $model->id === $itemId) {
            $armed = false;
            DB::table('order_items')->where('id', $itemId)->update(['stock_undeducted_quantity' => 1]);
        }
    });

    try {
        expect(fn () => changeLineQuantity($fixture, 2, true))->toThrow(LogicException::class);
    } finally {
        $armed = false;
        Event::forget('eloquent.retrieved: '.OrderItem::class);
    }

    expect($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and($fixture['item']->fresh()->quantity)->toBe(5);
});
