<?php

use App\Actions\Orders\RemoveOrderItem;
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

// Story 0090: RemoveOrderItem gains `bool $adjustStock = false`. With true the line's returnable
// units (R = quantity - undeducted) go back to stock (D-4); AdjustOrderStock runs before any
// FOR UPDATE on order_items, including the last-line range count (D-7).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingRemovalStockEditor(string ...$permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions === [] ? ['orders.edit'] : $permissions);
    test()->actingAs($actor);

    return $actor;
}

/**
 * Builds an order with the line under test plus a 1-unit "Plate" line (so the last-line guard
 * does not apply) and returns the pieces.
 *
 * @param  'product'|'variant'  $target
 * @param  array<string, mixed>  $itemAttributes
 * @param  array<string, mixed>  $orderAttributes
 * @return array{order: Order, item: OrderItem, stocked: Product|ProductVariant, plate: OrderItem}
 */
function removalStockFixture(
    string $target,
    int $quantity = 3,
    int $stock = 10,
    array $itemAttributes = [],
    bool $legacy = false,
    array $orderAttributes = [],
    bool $withPlate = true,
): array {
    $order = Order::factory()->create(['status' => OrderStatus::Pending, ...$orderAttributes]);
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
    $plate = $withPlate ? OrderItem::factory()->for($order)->create(['quantity' => 1]) : $item;

    return ['order' => $order, 'item' => $item, 'stocked' => $variant ?? $product, 'plate' => $plate];
}

/**
 * @return array{products: array<string, int>, variants: array<string, int>}
 */
function removalStockSnapshot(): array
{
    return [
        'products' => Product::query()->orderBy('id')->pluck('stock', 'id')->all(),
        'variants' => ProductVariant::query()->orderBy('id')->pluck('stock', 'id')->all(),
    ];
}

/**
 * @param  array{order: Order, item: OrderItem}  $fixture
 */
function removeLine(array $fixture, ?bool $adjustStock = null): void
{
    $action = app(RemoveOrderItem::class);

    if ($adjustStock === null) {
        $action($fixture['order'], $fixture['item']->id);

        return;
    }

    $action($fixture['order'], $fixture['item']->id, adjustStock: $adjustStock);
}

/**
 * Reduces a query log to the ordered tags that matter for the lock-order convention (D-7).
 *
 * @param  array<int, array{query: string}>  $queryLog
 * @return list<string>
 */
function removalStockLockTags(array $queryLog): array
{
    $tags = [];

    foreach (collect($queryLog)->pluck('query') as $sql) {
        if (preg_match('/^\s*select .*? from `(orders|products|product_variants|order_items)` .*for update/is', $sql, $match) === 1) {
            $tags[] = 'lock:'.$match[1];
        } elseif (preg_match('/^\s*update `(products|product_variants)` set `stock`/i', $sql, $match) === 1) {
            $tags[] = 'stock:'.$match[1];
        } elseif (preg_match('/^\s*delete from `order_items`/i', $sql) === 1) {
            $tags[] = 'delete:order_items';
        }
    }

    return $tags;
}

// --- Gherkin scenarios, product line and variant line ---

test('removing a line with stock adjustment returns its units', function (string $target) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target);

    removeLine($fixture, true);

    expect(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeFalse()
        ->and(OrderItem::query()->whereKey($fixture['plate']->id)->exists())->toBeTrue()
        ->and($fixture['stocked']->fresh()->stock)->toBe(13);
})->with(['product', 'variant']);

test('removing a line without adjusting stock leaves stock unchanged', function (string $target) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target);

    removeLine($fixture, false);

    expect(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeFalse()
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);
})->with(['product', 'variant']);

test('removing a line without the parameter leaves stock unchanged', function (string $target) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target);

    removeLine($fixture);

    expect(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeFalse()
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);
})->with(['product', 'variant']);

test('removing a line returns only its returnable units: quantity minus undeducted', function (string $target, int $quantity, int $undeducted, int $expectedDelta) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target, quantity: $quantity, itemAttributes: ['stock_undeducted_quantity' => $undeducted]);

    removeLine($fixture, true);

    expect($fixture['stocked']->fresh()->stock)->toBe(10 + $expectedDelta);
})->with(['product', 'variant'])->with([
    'fully deducted line' => [3, 0, 3],
    'partly undeducted line' => [3, 1, 2],
    'fully undeducted line' => [3, 3, 0],
]);

test('removing a legacy line returns nothing even when stock adjustment is requested', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product', quantity: 3, legacy: true);

    removeLine($fixture, true);

    expect(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeFalse()
        ->and($fixture['stocked']->fresh()->stock)->toBe(10);
});

test('removing a line recalculates the order totals as before', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product', quantity: 3);

    removeLine($fixture, true);

    expect((string) $fixture['order']->fresh()->subtotal)->toBe((string) $fixture['plate']->fresh()->line_total);
});

// --- Null stock target (D-8) ---

test('removing a line whose variant was deleted moves no stock', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product', itemAttributes: ['product_variant_id' => null, 'ordered_as_variant' => true]);
    $before = removalStockSnapshot();

    removeLine($fixture, true);

    expect(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeFalse()
        ->and(removalStockSnapshot())->toBe($before);
});

test('removing a line whose product was deleted moves no stock', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product');
    $fixture['item']->forceFill(['product_id' => null])->save();
    $before = removalStockSnapshot();

    removeLine($fixture, true);

    expect(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeFalse()
        ->and(removalStockSnapshot())->toBe($before);
});

// --- Refusals move no stock ---

test('removing a line of a shipped order is refused and moves no stock', function (string $target) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target, orderAttributes: ['status' => OrderStatus::Shipped]);
    $before = removalStockSnapshot();

    expect(fn () => removeLine($fixture, true))->toThrow(OrderNotEditableException::class);

    expect(removalStockSnapshot())->toBe($before)
        ->and(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeTrue();
})->with(['product', 'variant']);

test('removing a line that has refunded units is refused and moves no stock', function (string $target) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target, itemAttributes: ['refunded_quantity' => 1]);
    $before = removalStockSnapshot();

    expect(fn () => removeLine($fixture, true))->toThrow(ValidationException::class);

    expect(removalStockSnapshot())->toBe($before)
        ->and(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeTrue();
})->with(['product', 'variant']);

test('removing the last line of an order is refused and moves no stock', function (string $target) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target, withPlate: false);
    $before = removalStockSnapshot();

    expect(fn () => removeLine($fixture, true))->toThrow(ValidationException::class);

    expect(removalStockSnapshot())->toBe($before)
        ->and(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeTrue();
})->with(['product', 'variant']);

test('removing an already removed line is refused and moves no stock', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product');

    removeLine($fixture, true);
    $afterFirst = removalStockSnapshot();

    expect(fn () => removeLine($fixture, true))->toThrow(ValidationException::class);

    expect(removalStockSnapshot())->toBe($afterFirst)
        ->and($fixture['stocked']->fresh()->stock)->toBe(13);
});

test('removing a line id that belongs to another order is refused and moves no stock', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product');
    $foreign = removalStockFixture('product', stock: 20);
    $before = removalStockSnapshot();

    expect(fn () => app(RemoveOrderItem::class)($fixture['order'], $foreign['item']->id, adjustStock: true))
        ->toThrow(ValidationException::class);

    expect(removalStockSnapshot())->toBe($before)
        ->and(OrderItem::query()->whereKey($foreign['item']->id)->exists())->toBeTrue();
});

test('an administrator who may not edit orders cannot move stock through a line removal', function (string $target) {
    actingRemovalStockEditor('orders.view');
    $fixture = removalStockFixture($target);
    $before = removalStockSnapshot();

    expect(fn () => removeLine($fixture, true))->toThrow(AuthorizationException::class);

    expect(removalStockSnapshot())->toBe($before)
        ->and(OrderItem::query()->whereKey($fixture['item']->id)->exists())->toBeTrue();
})->with(['product', 'variant']);

// --- Lock order (D-7, forward rule appsec L-1) ---

test('stock is returned after the product and variant locks and before the item lock and the last-line range count', function (string $target, array $expectedStockTags) {
    actingRemovalStockEditor();
    $fixture = removalStockFixture($target);

    DB::flushQueryLog();
    DB::enableQueryLog();

    removeLine($fixture, true);

    $tags = removalStockLockTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect($tags)->toBe(['lock:orders', ...$expectedStockTags, 'lock:order_items', 'lock:order_items', 'delete:order_items']);
})->with([
    'product line' => ['product', ['lock:products', 'stock:products']],
    'variant line' => ['variant', ['lock:products', 'lock:product_variants', 'stock:product_variants']],
]);

test('without stock adjustment the removal skips the stock locks but keeps the order then item then range-count lock order', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product');

    DB::flushQueryLog();
    DB::enableQueryLog();

    removeLine($fixture, false);

    $tags = removalStockLockTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect($tags)->toBe(['lock:orders', 'lock:order_items', 'lock:order_items', 'delete:order_items']);
});

// --- D-7 step 5 re-verify ---

test('a locked re-read whose counters differ from the first read refuses the call and rolls the stock change back', function () {
    actingRemovalStockEditor();
    $fixture = removalStockFixture('product', quantity: 3);
    $itemId = $fixture['item']->id;
    $armed = true;

    Event::listen('eloquent.retrieved: '.OrderItem::class, function (OrderItem $model) use (&$armed, $itemId): void {
        if ($armed && $model->id === $itemId) {
            $armed = false;
            DB::table('order_items')->where('id', $itemId)->update(['stock_undeducted_quantity' => 1]);
        }
    });

    try {
        expect(fn () => removeLine($fixture, true))->toThrow(LogicException::class);
    } finally {
        $armed = false;
        Event::forget('eloquent.retrieved: '.OrderItem::class);
    }

    expect($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and(OrderItem::query()->whereKey($itemId)->exists())->toBeTrue();
});
