<?php

use App\Actions\Orders\AdjustOrderStock;
use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0088a: CreateOrder deducts every new line's units in its own transaction (lock order, counters,
// refusals and the order_number retry included).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingStockOrderCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

/**
 * @param  list<array{product_id: string, product_variant_id?: string, quantity: int}>  $items
 * @return array<string, mixed>
 */
function stockOrderPayload(array $items): array
{
    return [
        'customer_id' => Customer::factory()->create()->id,
        'payment_method_id' => PaymentMethod::factory()->create()->id,
        'items' => $items,
    ];
}

if (! function_exists('orderStockFlowTags')) {
    /**
     * Reduces a query log to the ordered tags that matter for the lock-order convention (FD-7).
     *
     * @param  array<int, array{query: string}>  $queryLog
     * @return list<string>
     */
    function orderStockFlowTags(array $queryLog): array
    {
        $tags = [];

        foreach (collect($queryLog)->pluck('query') as $sql) {
            if (preg_match('/^\s*insert into `(orders|order_items)`/i', $sql, $match) === 1) {
                $tags[] = 'insert:'.$match[1];
            } elseif (preg_match('/^\s*select .* from `(orders|products|product_variants)` where .*for update/is', $sql, $match) === 1) {
                $tags[] = 'lock:'.$match[1];
            } elseif (preg_match('/^\s*update `(products|product_variants)` set `stock`/i', $sql, $match) === 1) {
                $tags[] = 'update:'.$match[1];
            }
        }

        return $tags;
    }
}

function duplicateEntryException(int $code): QueryException
{
    $pdoException = new PDOException('simulated');
    $pdoException->errorInfo = ['23000', $code, 'simulated'];

    return new QueryException('mysql', 'update `products` set `stock` = `stock` + -1', [], $pdoException);
}

/** Throws the given exception the first time a stock UPDATE has been executed (i.e. after the deduction). */
function failOnFirstStockUpdate(QueryException $exception): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $exception): void {
        if (! $fired && preg_match('/^\s*update `(products|product_variants)` set `stock`/i', $query->sql) === 1) {
            $fired = true;

            throw $exception;
        }
    });
}

// --- Deduction ---

test('creating an order takes a plain product line out of the product stock', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 10]);

    app(CreateOrder::class)(stockOrderPayload([['product_id' => $product->id, 'quantity' => 3]]));

    expect($product->fresh()->stock)->toBe(7);
});

test('creating an order with a variant line takes the units out of the variant and leaves the product stock alone', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 20]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

    app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
    ]));

    expect($variant->fresh()->stock)->toBe(3)
        ->and($product->fresh()->stock)->toBe(20);
});

test('a mixed order moves each line against its own target and writes U 0, K 0 and the right flag per line', function () {
    actingStockOrderCreator();
    $plain = Product::factory()->create(['stock' => 10]);
    $variantProduct = Product::factory()->create(['stock' => 7]);
    $variant = ProductVariant::factory()->for($variantProduct)->create(['stock' => 4]);

    $order = app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $plain->id, 'quantity' => 3],
        ['product_id' => $variantProduct->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
    ]));

    $plainLine = $order->items()->where('product_id', $plain->id)->sole();
    $variantLine = $order->items()->where('product_variant_id', $variant->id)->sole();

    expect($plain->fresh()->stock)->toBe(7)
        ->and($variantProduct->fresh()->stock)->toBe(7)
        ->and($variant->fresh()->stock)->toBe(2)
        ->and($plainLine->stock_undeducted_quantity)->toBe(0)
        ->and($plainLine->stock_refunded_not_out_quantity)->toBe(0)
        ->and($plainLine->ordered_as_variant)->toBeFalse()
        ->and($plainLine->returnableStockUnits())->toBe(3)
        ->and($variantLine->stock_undeducted_quantity)->toBe(0)
        ->and($variantLine->stock_refunded_not_out_quantity)->toBe(0)
        ->and($variantLine->ordered_as_variant)->toBeTrue()
        ->and($variantLine->returnableStockUnits())->toBe(2);
});

test('a virtual product line is deducted like any other product', function () {
    actingStockOrderCreator();
    $product = Product::factory()->virtual()->create(['stock' => 5]);

    app(CreateOrder::class)(stockOrderPayload([['product_id' => $product->id, 'quantity' => 2]]));

    expect($product->fresh()->stock)->toBe(3);
});

test('two lines of the same variant are deducted as one total in a single locking pass and one write', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1],
        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
    ]));

    $tags = orderStockFlowTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect($variant->fresh()->stock)->toBe(2)
        ->and(OrderItem::query()->where('product_variant_id', $variant->id)->count())->toBe(2)
        ->and(array_count_values($tags)['lock:product_variants'])->toBe(1)
        ->and(array_count_values($tags)['update:product_variants'])->toBe(1);
});

test('all lines of an order are deducted by one adjustment call, not one per line', function () {
    actingStockOrderCreator();
    $first = Product::factory()->create(['stock' => 10]);
    $second = Product::factory()->create(['stock' => 10]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $first->id, 'quantity' => 1],
        ['product_id' => $second->id, 'quantity' => 1],
    ]));

    $tags = orderStockFlowTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_count_values($tags)['lock:products'])->toBe(1)
        ->and(array_count_values($tags)['update:products'])->toBe(2);
});

test('an order for more units than in stock is created and stock goes negative', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 1]);

    $order = app(CreateOrder::class)(stockOrderPayload([['product_id' => $product->id, 'quantity' => 4]]));

    expect($order->exists)->toBeTrue()
        ->and($product->fresh()->stock)->toBe(-3);
});

// --- Lock order (FD-7, D2) ---

test('the order is inserted, then stock rows locked (products before variants) and written, and only then the lines inserted', function () {
    actingStockOrderCreator();
    $plain = Product::factory()->create(['stock' => 10]);
    $variantProduct = Product::factory()->create(['stock' => 10]);
    $variant = ProductVariant::factory()->for($variantProduct)->create(['stock' => 10]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $variantProduct->id, 'product_variant_id' => $variant->id, 'quantity' => 1],
        ['product_id' => $plain->id, 'quantity' => 1],
    ]));

    $tags = orderStockFlowTags(DB::getQueryLog());
    DB::disableQueryLog();

    $itemInserts = array_keys($tags, 'insert:order_items', true);
    $stockUpdates = array_merge(array_keys($tags, 'update:products', true), array_keys($tags, 'update:product_variants', true));

    expect($itemInserts)->toHaveCount(2)
        ->and(array_search('insert:orders', $tags, true))->not->toBeFalse()
        ->and(array_search('insert:orders', $tags, true))->toBeLessThan(array_search('lock:products', $tags, true))
        ->and(array_search('lock:products', $tags, true))->toBeLessThan(array_search('lock:product_variants', $tags, true))
        ->and(array_search('lock:product_variants', $tags, true))->toBeLessThan(min($stockUpdates))
        ->and(max($stockUpdates))->toBeLessThan(min($itemInserts))
        ->and(min($itemInserts))->toBeGreaterThan(array_search('lock:products', $tags, true));
});

// --- Refusals and retry ---

test('an order refused by validation takes nothing out of stock', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => app(CreateOrder::class)(stockOrderPayload([['product_id' => $product->id, 'quantity' => 0]])))
        ->toThrow(ValidationException::class);

    expect($product->fresh()->stock)->toBe(10)
        ->and(OrderItem::query()->count())->toBe(0);
});

test('an order refused by the decimal column ceiling takes nothing out of stock', function () {
    actingStockOrderCreator();
    $safe = Product::factory()->create(['stock' => 10]);
    $expensive = Product::factory()->create(['price' => '99999999.99', 'stock' => 10]);

    expect(fn () => app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $safe->id, 'quantity' => 1],
        ['product_id' => $expensive->id, 'quantity' => 2],
    ])))->toThrow(ValidationException::class);

    expect($safe->fresh()->stock)->toBe(10)
        ->and($expensive->fresh()->stock)->toBe(10);
});

test('a non-1062 QueryException after the deduction propagates and rolls the deduction back', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 10]);
    $payload = stockOrderPayload([['product_id' => $product->id, 'quantity' => 3]]);

    failOnFirstStockUpdate(duplicateEntryException(1452));

    expect(fn () => app(CreateOrder::class)($payload))->toThrow(QueryException::class);

    expect($product->fresh()->stock)->toBe(10)
        ->and(OrderItem::query()->count())->toBe(0);
});

test('a forced 1062 after the deduction retries the whole write and deducts exactly once', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 10]);
    $payload = stockOrderPayload([['product_id' => $product->id, 'quantity' => 3]]);

    failOnFirstStockUpdate(duplicateEntryException(1062));

    $order = app(CreateOrder::class)($payload);

    expect($product->fresh()->stock)->toBe(7)
        ->and($order->items()->count())->toBe(1)
        ->and(OrderItem::query()->count())->toBe(1);
});

// --- End to end with the counter model ---

test('after the variant of a created line is deleted its stock target is null and AdjustOrderStock skips it', function () {
    actingStockOrderCreator();
    $product = Product::factory()->create(['stock' => 10]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

    $order = app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
    ]));

    $variant->delete();
    $line = $order->items()->sole();

    $applied = DB::transaction(fn (): array => app(AdjustOrderStock::class)($order, [['order_item' => $line, 'delta' => 2]]));

    expect($line->stockTarget())->toBeNull()
        ->and($applied)->toBe([])
        ->and($product->fresh()->stock)->toBe(10);
});

test('after the product of a created line is deleted its stock target is null and AdjustOrderStock moves no stock', function () {
    actingStockOrderCreator();
    $doomed = Product::factory()->create(['stock' => 10]);
    $survivor = Product::factory()->create(['stock' => 8]);
    $survivorVariant = ProductVariant::factory()->for($survivor)->create(['stock' => 6]);

    $order = app(CreateOrder::class)(stockOrderPayload([
        ['product_id' => $doomed->id, 'quantity' => 2],
    ]));

    $doomed->delete();
    $line = $order->items()->sole();

    $applied = DB::transaction(fn (): array => app(AdjustOrderStock::class)($order, [['order_item' => $line, 'delta' => 2]]));

    expect($line->product_id)->toBeNull()
        ->and($line->stockTarget())->toBeNull()
        ->and($applied)->toBe([])
        ->and($survivor->fresh()->stock)->toBe(8)
        ->and($survivorVariant->fresh()->stock)->toBe(6);
});
