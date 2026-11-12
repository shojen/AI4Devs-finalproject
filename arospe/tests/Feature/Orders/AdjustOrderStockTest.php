<?php

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Orders\AdjustOrderStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

// Story 0088a: AdjustOrderStock applies signed stock deltas atomically, in a fixed lock order, and
// carries no authorization of its own (D7), so these tests call it directly inside DB::transaction().

/**
 * @param  list<array{order_item: OrderItem, delta: int}>  $deltas
 * @return list<string>
 */
function adjustStockInTransaction(Order $order, array $deltas): array
{
    return DB::transaction(fn (): array => app(AdjustOrderStock::class)($order, $deltas));
}

/**
 * @param  array<int, array{query: string}>  $queryLog
 * @return list<string>
 */
function stockUpdateStatements(array $queryLog, string $table): array
{
    return collect($queryLog)
        ->pluck('query')
        ->filter(fn (string $sql): bool => preg_match('/^update `'.$table.'` set `stock`/i', $sql) === 1)
        ->values()
        ->all();
}

// --- Deltas, targets, aggregation ---

test('a negative delta takes units out of a product and a positive delta puts them back', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);

    $applied = adjustStockInTransaction($order, [['order_item' => $item, 'delta' => -3]]);

    expect($product->fresh()->stock)->toBe(7)
        ->and($applied)->toBe([$item->id]);

    adjustStockInTransaction($order, [['order_item' => $item, 'delta' => 2]]);

    expect($product->fresh()->stock)->toBe(9);
});

test('a line of a variant moves the variant stock and leaves the parent product stock alone', function () {
    $product = Product::factory()->create(['stock' => 20]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 2]);

    adjustStockInTransaction($order, [['order_item' => $item, 'delta' => -2]]);

    expect($variant->fresh()->stock)->toBe(3)
        ->and($product->fresh()->stock)->toBe(20);
});

test('a virtual product is moved like any other product', function () {
    $product = Product::factory()->virtual()->create(['stock' => 4]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    adjustStockInTransaction($order, [['order_item' => $item, 'delta' => -1]]);

    expect($product->fresh()->stock)->toBe(3);
});

test('two lines of the same variant are summed into one write', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);
    $order = Order::factory()->create();
    $first = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 1]);
    $second = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 2]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $applied = adjustStockInTransaction($order, [
        ['order_item' => $first, 'delta' => -1],
        ['order_item' => $second, 'delta' => -2],
    ]);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect($variant->fresh()->stock)->toBe(2)
        ->and(stockUpdateStatements($queryLog, 'product_variants'))->toHaveCount(1)
        ->and($applied)->toEqualCanonicalizing([$first->id, $second->id]);
});

test('a net-zero target is not written and is not reported as applied', function () {
    $product = Product::factory()->create(['stock' => 6]);
    $other = Product::factory()->create(['stock' => 6]);
    $order = Order::factory()->create();
    $out = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 2]);
    $back = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 2]);
    $moved = OrderItem::factory()->for($order)->create(['product_id' => $other->id, 'quantity' => 1]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $applied = adjustStockInTransaction($order, [
        ['order_item' => $out, 'delta' => -2],
        ['order_item' => $back, 'delta' => 2],
        ['order_item' => $moved, 'delta' => -1],
    ]);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect($product->fresh()->stock)->toBe(6)
        ->and($other->fresh()->stock)->toBe(5)
        ->and(stockUpdateStatements($queryLog, 'products'))->toHaveCount(1)
        ->and($applied)->toBe([$moved->id]);
});

test('stock may go negative, there is no lower bound', function () {
    $product = Product::factory()->create(['stock' => 1]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 4]);

    adjustStockInTransaction($order, [['order_item' => $item, 'delta' => -4]]);

    expect($product->fresh()->stock)->toBe(-3);
});

// --- Skipped lines ---

test('a line whose variant was deleted moves nothing and is reported as not applied', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $orphan = OrderItem::factory()->for($order)->orphanedVariant()->create(['product_id' => $product->id, 'quantity' => 3]);

    $applied = adjustStockInTransaction($order, [['order_item' => $orphan, 'delta' => 3]]);

    expect($applied)->toBe([])
        ->and($product->fresh()->stock)->toBe(10);
});

test('an applicable line is still applied when another line of the same call is skipped', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $orphan = OrderItem::factory()->for($order)->orphanedVariant()->create(['product_id' => $product->id, 'quantity' => 1]);
    $live = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 2]);

    $applied = adjustStockInTransaction($order, [
        ['order_item' => $orphan, 'delta' => 1],
        ['order_item' => $live, 'delta' => -2],
    ]);

    expect($applied)->toBe([$live->id])
        ->and($product->fresh()->stock)->toBe(8);
});

test('a product row deleted before the lock is skipped without throwing', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $survivor = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $doomed = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);
    $kept = OrderItem::factory()->for($order)->create(['product_id' => $survivor->id, 'quantity' => 1]);

    // The in-memory $doomed still carries product_id: the row vanishes between the caller's read and the lock.
    $product->delete();

    $applied = adjustStockInTransaction($order, [
        ['order_item' => $doomed, 'delta' => 1],
        ['order_item' => $kept, 'delta' => -1],
    ]);

    expect($applied)->toBe([$kept->id])
        ->and($survivor->fresh()->stock)->toBe(9);
});

test('a variant row deleted before the lock is skipped without throwing', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);
    $order = Order::factory()->create();
    $doomed = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 1]);
    $variant->delete();

    $applied = adjustStockInTransaction($order, [['order_item' => $doomed, 'delta' => 1]]);

    expect($applied)->toBe([])
        ->and($product->fresh()->stock)->toBe(10);
});

test('an empty delta list changes nothing and returns an empty list', function () {
    $order = Order::factory()->create();

    expect(adjustStockInTransaction($order, []))->toBe([]);
});

// --- Programming-error guards ---

test('adjusting outside an open transaction throws a LogicException and moves nothing', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);

    // RefreshDatabase keeps the test inside a transaction, so the connection's own level counter is
    // zeroed for the call only (and restored) to reproduce a caller that opened no transaction.
    $connection = DB::connection();
    $counter = new ReflectionProperty($connection, 'transactions');
    $previous = $counter->getValue($connection);
    $counter->setValue($connection, 0);

    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => app(AdjustOrderStock::class)($order, [['order_item' => $item, 'delta' => -3]]))
            ->toThrow(LogicException::class);
    } finally {
        $counter->setValue($connection, $previous);
    }

    expect($product->fresh()->stock)->toBe(10);
});

test('a line belonging to another order throws a LogicException and moves nothing, not even for lines that do belong', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $otherOrder = Order::factory()->create();
    $own = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);
    $foreign = OrderItem::factory()->for($otherOrder)->create(['product_id' => $product->id, 'quantity' => 2]);

    expect(fn () => adjustStockInTransaction($order, [
        ['order_item' => $own, 'delta' => -1],
        ['order_item' => $foreign, 'delta' => -2],
    ]))->toThrow(LogicException::class);

    expect($product->fresh()->stock)->toBe(10);
});

// --- Atomicity ---

test('the write is a relative UPDATE, so a concurrent change made after the caller read the row is not lost', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);

    $stale = Product::query()->findOrFail($product->id);
    DB::table('products')->where('id', $product->id)->update(['stock' => 4]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    adjustStockInTransaction($order, [['order_item' => $item, 'delta' => -3]]);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $relativeWrites = collect(stockUpdateStatements($queryLog, 'products'))
        ->filter(fn (string $sql): bool => preg_match('/`stock` = `stock` [+-]/', $sql) === 1);

    expect($stale->stock)->toBe(10)
        ->and($product->fresh()->stock)->toBe(1)
        ->and($relativeWrites)->toHaveCount(1);
});

// --- Lock order (FD-5, FD-7) ---

test('products are locked before product_variants, each in one id-ordered locking read, then written', function () {
    $productA = Product::factory()->create(['stock' => 10]);
    $productB = Product::factory()->create(['stock' => 10]);
    $variant = ProductVariant::factory()->for($productA)->create(['stock' => 10]);
    $order = Order::factory()->create();
    // Variant line first in the input: the lock order must not follow the input order.
    $variantLine = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 1]);
    $lineB = OrderItem::factory()->for($order)->create(['product_id' => $productB->id, 'quantity' => 1]);
    $lineA = OrderItem::factory()->for($order)->create(['product_id' => $productA->id, 'quantity' => 1]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    adjustStockInTransaction($order, [
        ['order_item' => $variantLine, 'delta' => -1],
        ['order_item' => $lineB, 'delta' => -1],
        ['order_item' => $lineA, 'delta' => -1],
    ]);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $statements = collect($queryLog)->pluck('query')->values();
    $productLocks = $statements->filter(fn (string $sql): bool => preg_match('/from `products` where `id` in .*order by `id` asc for update/is', $sql) === 1);
    $variantLocks = $statements->filter(fn (string $sql): bool => preg_match('/from `product_variants` where `id` in .*order by `id` asc for update/is', $sql) === 1);
    $firstUpdate = $statements->search(fn (string $sql): bool => preg_match('/^update `(products|product_variants)` set `stock`/i', $sql) === 1);

    expect($productLocks)->toHaveCount(1)
        ->and($variantLocks)->toHaveCount(1)
        ->and($productLocks->keys()->first())->toBeLessThan($variantLocks->keys()->first())
        ->and($variantLocks->keys()->first())->toBeLessThan($firstUpdate);
});

// --- Architecture (D7) ---

arch('AdjustOrderStock carries no authorization of its own')
    ->expect(AdjustOrderStock::class)
    ->not->toUse([
        Gate::class,
        AuthorizesRequests::class,
        LogRefusedPrivilegedAttempt::class,
    ]);

arch('AdjustOrderStock is only used from the order actions')
    ->expect(AdjustOrderStock::class)
    ->toOnlyBeUsedIn('App\Actions\Orders');

test('AdjustOrderStock source never calls authorize() or the Gate', function () {
    $source = file_get_contents(app_path('Actions/Orders/AdjustOrderStock.php'));

    expect($source)->not->toMatch('/->authorize\(|Gate::|->can\(|->cannot\(/');
});
