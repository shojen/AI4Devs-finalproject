<?php

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordRefund;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Events\OrderFullyRefunded;
use App\Exceptions\OrderNotEditableException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Stock counters of order lines (stories 0088 and 0088a).
// Layers: (1) the existing line actions keep the counters right (CreateOrder/AddOrderItem deduct,
// refund/quantity change move no stock); (2) the numbered sequences of the counter model, including the
// review #3 counterexample, at helper level; (3) a seeded-random property test checking the helpers
// against a unit-level ledger. Return-on-lower and return-on-cancel ship in later stories (0090/0091),
// so the simulator below "arranges" those steps by applying the counter model's table rules directly.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingStockCounterActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(...$permissions);
    test()->actingAs($actor);

    return $actor;
}

/**
 * @return array{quantity: int, refunded: int, undeducted: int, refundedNotOut: int}
 */
function persistedStockCounters(OrderItem $item): array
{
    $fresh = $item->fresh();

    return [
        'quantity' => $fresh->quantity,
        'refunded' => $fresh->refunded_quantity,
        'undeducted' => $fresh->stock_undeducted_quantity,
        'refundedNotOut' => $fresh->stock_refunded_not_out_quantity,
    ];
}

/**
 * Write statements and row locks aimed at the stock-bearing tables. Reads of the catalog row for its
 * price/SKU snapshot are legitimate and not reported.
 *
 * @param  array<int, array{query: string}>  $queryLog
 * @return array<int, string>
 */
function stockTableTouches(array $queryLog): array
{
    return collect($queryLog)
        ->pluck('query')
        ->filter(fn (string $sql): bool => preg_match('/^\s*(update|insert|delete)\b.*`?\b(products|product_variants)`?\s/i', $sql) === 1
            || preg_match('/from\s+`?(products|product_variants)`?\b.*for update/is', $sql) === 1)
        ->values()
        ->all();
}

/**
 * @param  array<int, string>  $touches
 * @return list<string>
 */
function touchedStockTables(array $touches): array
{
    return collect($touches)
        ->map(fn (string $sql): string => preg_match('/`(products|product_variants)`/', $sql, $match) === 1 ? $match[1] : 'other')
        ->unique()
        ->sort()
        ->values()
        ->all();
}

// --- CreateOrder (FD-4) ---

test('creating an order writes every line deducted (U 0) and takes the units out of the product and variant stock', function () {
    actingStockCounterActor('orders.create');

    $product = Product::factory()->create(['stock' => 10]);
    $variantProduct = Product::factory()->create(['stock' => 7]);
    $variant = ProductVariant::factory()->for($variantProduct)->create(['stock' => 4]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $order = app(CreateOrder::class)([
        'customer_id' => Customer::factory()->create()->id,
        'payment_method_id' => PaymentMethod::factory()->create()->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 3],
            ['product_id' => $variantProduct->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
        ],
    ]);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $plainLine = $order->items()->where('product_id', $product->id)->sole();
    $variantLine = $order->items()->where('product_variant_id', $variant->id)->sole();

    expect(touchedStockTables(stockTableTouches($queryLog)))->toBe(['product_variants', 'products'])
        ->and($product->fresh()->stock)->toBe(7)
        ->and($variantProduct->fresh()->stock)->toBe(7)
        ->and($variant->fresh()->stock)->toBe(2)
        ->and($plainLine->stock_undeducted_quantity)->toBe(0)
        ->and($plainLine->stock_refunded_not_out_quantity)->toBe(0)
        ->and($plainLine->ordered_as_variant)->toBeFalse()
        ->and($variantLine->stock_undeducted_quantity)->toBe(0)
        ->and($variantLine->stock_refunded_not_out_quantity)->toBe(0)
        ->and($variantLine->ordered_as_variant)->toBeTrue()
        ->and($plainLine->returnableStockUnits())->toBe(3)
        ->and($variantLine->returnableStockUnits())->toBe(2);
});

test('a refused create leaves no order line behind', function () {
    actingStockCounterActor('orders.create');

    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => Customer::factory()->create()->id,
        'payment_method_id' => PaymentMethod::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 0]],
    ]))->toThrow(ValidationException::class);

    expect(OrderItem::query()->count())->toBe(0);
});

// --- AddOrderItem (FD-4) ---

test('adding a plain-product line to an open order writes it deducted (U 0) and takes the units out of the product stock', function () {
    actingStockCounterActor('orders.edit');

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $item = app(AddOrderItem::class)($order, $product->id, null, 2);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $fresh = $item->fresh();

    expect(touchedStockTables(stockTableTouches($queryLog)))->toBe(['products'])
        ->and($product->fresh()->stock)->toBe(8)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(0)
        ->and($fresh->ordered_as_variant)->toBeFalse()
        ->and($fresh->returnableStockUnits())->toBe(2);
});

test('adding a variant line writes ordered_as_variant true, deducted (U 0), and takes the units out of the variant stock only', function () {
    actingStockCounterActor('orders.edit');

    $order = Order::factory()->create(['status' => OrderStatus::Processing]);
    $product = Product::factory()->create(['stock' => 9]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $item = app(AddOrderItem::class)($order, $product->id, $variant->id, 3);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $fresh = $item->fresh();

    expect(touchedStockTables(stockTableTouches($queryLog)))->toBe(['product_variants', 'products'])
        ->and($variant->fresh()->stock)->toBe(2)
        ->and($product->fresh()->stock)->toBe(9)
        ->and($fresh->ordered_as_variant)->toBeTrue()
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(0);
});

test('a refused add (shipped order) leaves no new line', function () {
    actingStockCounterActor('orders.edit');

    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->create();

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 1))->toThrow(OrderNotEditableException::class);

    expect(OrderItem::query()->count())->toBe(0);
});

// --- RecordRefund (FD-6, D10) ---

dataset('refund_counter_cases', [
    'deducted line of 5, refund 2 (R 5, over 0)' => [5, 0, 0, 2, 0, 0],
    'line of 5 raised without deducting (U 3, R 2), refund 3 (over 1)' => [5, 3, 0, 3, 2, 1],
    'legacy line of 3 (U 3, R 0), refund 3 (over 3)' => [3, 3, 0, 3, 0, 3],
    'line cancelled with return (U 4, R 0), refund 4 (over 4)' => [4, 4, 0, 4, 0, 4],
]);

test('recording a refund applies the D8 refund rule to the line counters and moves no stock', function (int $quantity, int $undeducted, int $refundedNotOut, int $refund, int $expectedUndeducted, int $expectedRefundedNotOut) {
    actingStockCounterActor('orders.refund');

    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => $product->id,
        'quantity' => $quantity,
        'stock_undeducted_quantity' => $undeducted,
        'stock_refunded_not_out_quantity' => $refundedNotOut,
    ]);

    // A second untouched line keeps the order from being fully refunded and auto-cancelled.
    OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(RecordRefund::class)($order, [$item->id => $refund]);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect(persistedStockCounters($item))->toBe([
        'quantity' => $quantity,
        'refunded' => $refund,
        'undeducted' => $expectedUndeducted,
        'refundedNotOut' => $expectedRefundedNotOut,
    ])
        ->and(stockTableTouches($queryLog))->toBe([])
        ->and($product->fresh()->stock)->toBe(10);
})->with('refund_counter_cases');

test('recording a refund maintains the counters of a line with a null stock target the same way', function () {
    actingStockCounterActor('orders.refund');

    $order = Order::factory()->paid()->create();
    $orphan = OrderItem::factory()->for($order)->orphanedVariant()->create([
        'quantity' => 4,
        'stock_undeducted_quantity' => 1,
        'stock_refunded_not_out_quantity' => 0,
    ]);
    OrderItem::factory()->for($order)->create(['quantity' => 1]);

    expect($orphan->stockTarget())->toBeNull();

    app(RecordRefund::class)($order, [$orphan->id => 4]);

    // R = (4 - 0) - 1 = 3, over = 1 -> U 0, K 1.
    expect(persistedStockCounters($orphan))->toBe(['quantity' => 4, 'refunded' => 4, 'undeducted' => 0, 'refundedNotOut' => 1]);
});

test('a multi-line refund applies the rule to every refunded line independently', function () {
    actingStockCounterActor('orders.refund');

    $order = Order::factory()->paid()->create();
    $deducted = OrderItem::factory()->for($order)->create(['quantity' => 5]);
    $legacy = OrderItem::factory()->for($order)->legacyStockUntracked()->create(['quantity' => 3]);
    $untouched = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    app(RecordRefund::class)($order, [$deducted->id => 2, $legacy->id => 1]);

    expect(persistedStockCounters($deducted))->toMatchArray(['undeducted' => 0, 'refundedNotOut' => 0, 'refunded' => 2])
        ->and(persistedStockCounters($legacy))->toMatchArray(['undeducted' => 2, 'refundedNotOut' => 1, 'refunded' => 1])
        ->and(persistedStockCounters($untouched))->toMatchArray(['undeducted' => 0, 'refundedNotOut' => 0, 'refunded' => 0]);
});

test('a refused refund (order still awaiting payment) leaves the line counters unchanged', function () {
    actingStockCounterActor('orders.refund');

    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => 3, 'stock_undeducted_quantity' => 1]);

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 1]))->toThrow(ValidationException::class);

    expect(persistedStockCounters($item))->toBe(['quantity' => 3, 'refunded' => 0, 'undeducted' => 1, 'refundedNotOut' => 0]);
});

test('a refund above the outstanding units is refused and leaves the line counters unchanged', function () {
    actingStockCounterActor('orders.refund');

    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => 3, 'stock_undeducted_quantity' => 1]);

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 4]))->toThrow(ValidationException::class);

    expect(persistedStockCounters($item))->toBe(['quantity' => 3, 'refunded' => 0, 'undeducted' => 1, 'refundedNotOut' => 0]);
});

// --- UpdateOrderItemQuantity (FD-7, D10) ---

dataset('quantity_counter_cases', [
    'raise 2 to 5 on a deducted line: U 0 -> 3' => [2, 0, 5, 3],
    'raise 3 to 4 on a legacy line (U 3): U 3 -> 4' => [3, 3, 4, 4],
    'lower 5 to 4 with U 3: U 3 -> 2' => [5, 3, 4, 2],
    'lower 5 to 1 with U 3 (d 4 > U): U -> 0' => [5, 3, 1, 0],
    'lower 5 to 3 on a deducted line (U 0): U stays 0' => [5, 0, 3, 0],
]);

test('changing a quantity moves U by +d on an increase and to max(0, U - d) on a decrease, with no stock movement', function (int $quantity, int $undeducted, int $newQuantity, int $expectedUndeducted) {
    actingStockCounterActor('orders.edit');

    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => $product->id,
        'quantity' => $quantity,
        'stock_undeducted_quantity' => $undeducted,
        'stock_refunded_not_out_quantity' => 0,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(UpdateOrderItemQuantity::class)($order, $item->id, $newQuantity);

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect(persistedStockCounters($item))->toBe([
        'quantity' => $newQuantity,
        'refunded' => 0,
        'undeducted' => $expectedUndeducted,
        'refundedNotOut' => 0,
    ])
        ->and(stockTableTouches($queryLog))->toBe([])
        ->and($product->fresh()->stock)->toBe(10);
})->with('quantity_counter_cases');

test('a quantity change leaves K alone on a partially refunded line', function () {
    actingStockCounterActor('orders.edit');

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $item = OrderItem::factory()->for($order)->create([
        'quantity' => 5,
        'refunded_quantity' => 2,
        'stock_undeducted_quantity' => 3,
        'stock_refunded_not_out_quantity' => 2,
    ]);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 7);

    expect(persistedStockCounters($item))->toBe(['quantity' => 7, 'refunded' => 2, 'undeducted' => 5, 'refundedNotOut' => 2]);
});

test('a refused quantity change (below the refunded units) leaves the counters unchanged', function () {
    actingStockCounterActor('orders.edit');

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $item = OrderItem::factory()->for($order)->create([
        'quantity' => 5,
        'refunded_quantity' => 3,
        'stock_undeducted_quantity' => 1,
        'stock_refunded_not_out_quantity' => 2,
    ]);

    expect(fn () => app(UpdateOrderItemQuantity::class)($order, $item->id, 2))->toThrow(ValidationException::class);

    expect(persistedStockCounters($item))->toBe(['quantity' => 5, 'refunded' => 3, 'undeducted' => 1, 'refundedNotOut' => 2]);
});

test('a refused quantity change on a shipped order leaves the counters unchanged', function () {
    actingStockCounterActor('orders.edit');

    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    expect(fn () => app(UpdateOrderItemQuantity::class)($order, $item->id, 5))->toThrow(OrderNotEditableException::class);

    expect(persistedStockCounters($item))->toBe(['quantity' => 2, 'refunded' => 0, 'undeducted' => 0, 'refundedNotOut' => 0]);
});

// --- Sequences through the real actions, deducting steps arranged with the factory ---

test('review #3 counterexample through the real actions: 2 deducted, raise to 5, refund 3, then raise to 7 deducting 2 leaves 2 returnable and 2 refunded units out', function () {
    Event::fake([OrderFullyRefunded::class]);
    actingStockCounterActor('orders.edit', 'orders.refund');

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 5);
    app(RecordRefund::class)($order, [$item->id => 3]);

    $afterRefund = $item->fresh();

    expect([$afterRefund->stock_undeducted_quantity, $afterRefund->stock_refunded_not_out_quantity])->toBe([2, 1]);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 7);

    // Arranged step (0090 ships the real one): the 2 added units are deducted, so U drops by 2.
    $item->fresh()->forceFill(['stock_undeducted_quantity' => $item->fresh()->stock_undeducted_quantity - 2])->save();

    $final = $item->fresh();

    expect($final->returnableStockUnits())->toBe(2)
        ->and($final->refundedUnitsOutOfStock())->toBe(2);
});

test('legacy variant through the real actions: legacy line of 3, refund 3, then raise to 4 deducting 1 leaves 1 returnable unit', function () {
    Event::fake([OrderFullyRefunded::class]);
    actingStockCounterActor('orders.edit', 'orders.refund');

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending]);
    $item = OrderItem::factory()->for($order)->legacyStockUntracked()->create(['quantity' => 3]);

    app(RecordRefund::class)($order, [$item->id => 3]);
    app(UpdateOrderItemQuantity::class)($order, $item->id, 4);

    // Arranged step: the added unit is deducted.
    $item->fresh()->forceFill(['stock_undeducted_quantity' => $item->fresh()->stock_undeducted_quantity - 1])->save();

    $final = $item->fresh();

    expect($final->returnableStockUnits())->toBe(1)
        ->and($final->refundedUnitsOutOfStock())->toBe(0);
});

test('sequence 11 through the real actions: a stock-neutral line refunded then cancelled returns nothing', function () {
    actingStockCounterActor('orders.refund');

    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->legacyStockUntracked()->create(['product_id' => $product->id, 'quantity' => 3]);
    OrderItem::factory()->for($order)->create(['quantity' => 1]);

    app(RecordRefund::class)($order, [$item->id => 1]);

    $line = $item->fresh();

    expect([$line->stock_undeducted_quantity, $line->stock_refunded_not_out_quantity])->toBe([2, 1])
        ->and($line->returnableStockUnits())->toBe(0)
        ->and($product->fresh()->stock)->toBe(10);
});

// --- Numbered sequences of the counter model, at helper level ---

/**
 * A single order line and the stock of its product, plus a unit-level ledger used as the independent
 * truth the helpers are compared with. Deduction, return-on-lower and return-on-cancel are arranged
 * here by applying the counter model's table rules; refunds go through the real
 * OrderItem::applyRefundToStockCounters().
 */
final class StockLineSimulator
{
    public OrderItem $item;

    public int $stock;

    /** @var array<int, array{refunded: bool, out: bool}> */
    private array $units = [];

    private function __construct()
    {
        $this->item = new OrderItem;
    }

    public static function deducted(int $quantity, int $stock): self
    {
        $line = new self;
        $line->stock = $stock - $quantity;
        $line->item->forceFill(['quantity' => $quantity, 'refunded_quantity' => 0, 'stock_undeducted_quantity' => 0, 'stock_refunded_not_out_quantity' => 0]);
        $line->units = array_fill(0, $quantity, ['refunded' => false, 'out' => true]);
        $line->assertInvariants();

        return $line;
    }

    /** A line written stock-neutral (or backfilled): nothing is out of inventory, refunded or not. */
    public static function neutral(int $quantity, int $refunded, int $stock): self
    {
        $line = new self;
        $line->stock = $stock;
        $line->item->forceFill([
            'quantity' => $quantity,
            'refunded_quantity' => $refunded,
            'stock_undeducted_quantity' => $quantity - $refunded,
            'stock_refunded_not_out_quantity' => $refunded,
        ]);
        for ($index = 0; $index < $quantity; $index++) {
            $line->units[] = ['refunded' => $index < $refunded, 'out' => false];
        }
        $line->assertInvariants();

        return $line;
    }

    public function raise(int $units, bool $deduct): self
    {
        $this->item->forceFill([
            'quantity' => $this->item->quantity + $units,
            'stock_undeducted_quantity' => $this->item->stock_undeducted_quantity + ($deduct ? 0 : $units),
        ]);

        for ($index = 0; $index < $units; $index++) {
            $this->units[] = ['refunded' => false, 'out' => $deduct];
        }

        $this->stock -= $deduct ? $units : 0;

        return $this->assertInvariants();
    }

    public function lower(int $units, bool $returnUnits): self
    {
        $undeducted = $this->item->stock_undeducted_quantity;
        $returned = $returnUnits ? max(0, $units - $undeducted) : 0;

        $this->item->forceFill([
            'quantity' => $this->item->quantity - $units,
            'stock_undeducted_quantity' => max(0, $undeducted - $units),
        ]);

        // Ledger: remove non-refunded units, the ones not out of inventory first.
        $removeOrder = array_keys(array_filter($this->units, fn (array $unit): bool => ! $unit['refunded']));
        usort($removeOrder, fn (int $left, int $right): int => (int) $this->units[$left]['out'] <=> (int) $this->units[$right]['out']);

        $ledgerReturned = 0;

        foreach (array_slice($removeOrder, 0, $units) as $index) {
            if ($this->units[$index]['out'] && $returnUnits) {
                $ledgerReturned++;
            }
            unset($this->units[$index]);
        }
        $this->units = array_values($this->units);

        // The counter model's k = max(0, d - U) must equal what the ledger says goes back.
        expect($returned)->toBe($ledgerReturned);
        $this->stock += $ledgerReturned;

        return $this->assertInvariants();
    }

    public function refund(int $units, int $restocked = 0): self
    {
        $this->item->applyRefundToStockCounters($units, $restocked);
        $this->item->forceFill(['refunded_quantity' => $this->item->refunded_quantity + $units]);

        // Ledger: a refund takes units that are out of inventory first.
        $candidates = array_keys(array_filter($this->units, fn (array $unit): bool => ! $unit['refunded']));
        usort($candidates, fn (int $left, int $right): int => (int) $this->units[$right]['out'] <=> (int) $this->units[$left]['out']);

        foreach (array_slice($candidates, 0, $units) as $index) {
            $this->units[$index]['refunded'] = true;
        }

        $toRestock = $restocked;
        foreach ($this->units as $index => $unit) {
            if ($toRestock > 0 && $unit['refunded'] && $unit['out']) {
                $this->units[$index]['out'] = false;
                $this->stock++;
                $toRestock--;
            }
        }

        return $this->assertInvariants();
    }

    public function cancel(bool $returnUnits): self
    {
        if ($returnUnits) {
            $returnable = $this->item->returnableStockUnits();
            $this->stock += $returnable;
            $this->item->forceFill(['stock_undeducted_quantity' => $this->item->stock_undeducted_quantity + $returnable]);

            foreach ($this->units as $index => $unit) {
                if (! $unit['refunded'] && $unit['out']) {
                    $this->units[$index]['out'] = false;
                }
            }
        }

        return $this->assertInvariants();
    }

    public function returnable(): int
    {
        return $this->item->returnableStockUnits();
    }

    public function refundedOut(): int
    {
        return $this->item->refundedUnitsOutOfStock();
    }

    public function counters(): array
    {
        return [$this->item->stock_undeducted_quantity, $this->item->stock_refunded_not_out_quantity];
    }

    /** Checks the helpers and invariants against the unit-level ledger after every step. */
    public function assertInvariants(): self
    {
        $units = collect($this->units);
        $nonRefundedOut = $units->filter(fn (array $unit): bool => ! $unit['refunded'] && $unit['out'])->count();
        $refundedOut = $units->filter(fn (array $unit): bool => $unit['refunded'] && $unit['out'])->count();
        $refundedNotOut = $units->filter(fn (array $unit): bool => $unit['refunded'] && ! $unit['out'])->count();

        $item = $this->item;

        expect($item->quantity)->toBe($units->count())
            ->and($item->refunded_quantity)->toBe($units->where('refunded', true)->count())
            ->and($item->returnableStockUnits())->toBe($nonRefundedOut)
            ->and($item->refundedUnitsOutOfStock())->toBe($refundedOut)
            ->and($item->stock_refunded_not_out_quantity)->toBe($refundedNotOut)
            ->and($item->stock_undeducted_quantity)->toBeGreaterThanOrEqual(0)
            ->and($item->stock_undeducted_quantity)->toBeLessThanOrEqual($item->quantity - $item->refunded_quantity)
            ->and($item->stock_refunded_not_out_quantity)->toBeLessThanOrEqual($item->refunded_quantity);

        return $this;
    }
}

test('sequence 1, round trip: raise without deducting, lower with return, cancel with return restores the original stock', function () {
    $line = StockLineSimulator::deducted(2, 10);

    $line->raise(3, deduct: false)->lower(3, returnUnits: true);

    expect($line->counters())->toBe([0, 0]);

    $line->cancel(returnUnits: true);

    expect($line->stock)->toBe(10);
});

test('sequence 2, the single-counter failing sequence ends with stock 8, U 0, R 0 and H 2', function () {
    $line = StockLineSimulator::deducted(5, 10);

    $line->refund(2)->raise(2, deduct: false)->lower(5, returnUnits: true);

    expect($line->stock)->toBe(8)
        ->and($line->counters())->toBe([0, 0])
        ->and($line->returnable())->toBe(0)
        ->and($line->refundedOut())->toBe(2);
});

test('sequence 3, the review #3 counterexample ends with stock 8, R 0 and H 2', function () {
    $line = StockLineSimulator::deducted(2, 10);

    $line->raise(3, deduct: false)->refund(3);

    expect($line->counters())->toBe([2, 1]);

    $line->raise(2, deduct: true);

    expect($line->stock)->toBe(6)
        ->and($line->returnable())->toBe(2)
        ->and($line->refundedOut())->toBe(2);

    $line->lower(4, returnUnits: true);

    expect($line->stock)->toBe(8)
        ->and($line->counters())->toBe([0, 1])
        ->and($line->returnable())->toBe(0)
        ->and($line->refundedOut())->toBe(2);
});

test('sequence 4, the legacy variant ends with stock 7', function () {
    $line = StockLineSimulator::neutral(3, 0, 7);

    $line->refund(3);

    expect($line->counters())->toBe([0, 3]);

    $line->raise(1, deduct: true);

    expect($line->stock)->toBe(6)
        ->and($line->returnable())->toBe(1);

    $line->lower(1, returnUnits: true);

    expect($line->stock)->toBe(7);
});

test('sequence 5, a refund with restock then a lower with return ends with stock 9, R 1 and H 0', function () {
    $line = StockLineSimulator::deducted(5, 10);

    $line->refund(2, restocked: 2);

    expect($line->stock)->toBe(7)
        ->and($line->counters())->toBe([0, 2]);

    $line->lower(2, returnUnits: true);

    expect($line->stock)->toBe(9)
        ->and($line->returnable())->toBe(1)
        ->and($line->refundedOut())->toBe(0);
});

test('sequence 6, a fully refunded line has no returnable unit so a cancel with return gives nothing back', function () {
    $line = StockLineSimulator::deducted(3, 10);

    $line->refund(3);

    expect($line->returnable())->toBe(0);

    $line->cancel(returnUnits: true);

    expect($line->stock)->toBe(7);
});

test('sequence 7, a refund after a cancel with return cannot restock and moves its units from U to K when not restocking', function () {
    $line = StockLineSimulator::deducted(4, 10);

    $line->cancel(returnUnits: true);

    expect($line->stock)->toBe(10)
        ->and($line->counters())->toBe([4, 0]);

    expect(fn () => $line->refund(4, restocked: 4))->toThrow(LogicException::class);

    $line->refund(4);

    expect($line->counters())->toBe([0, 4])
        ->and($line->returnable())->toBe(0)
        ->and($line->refundedOut())->toBe(0);
});

test('sequence 8, legacy lines never have returnable or refunded-out units, with or without refunds, and a cancel gives nothing back', function () {
    $withoutRefund = StockLineSimulator::neutral(3, 0, 10);
    $withRefund = StockLineSimulator::neutral(5, 2, 10);

    expect($withoutRefund->counters())->toBe([3, 0])
        ->and($withRefund->counters())->toBe([3, 2]);

    foreach ([$withoutRefund, $withRefund] as $line) {
        expect($line->returnable())->toBe(0)
            ->and($line->refundedOut())->toBe(0);

        $line->cancel(returnUnits: true);

        expect($line->stock)->toBe(10);
    }
});

test('sequence 9, a refund with restock after a raise without deducting is capped at the returnable units', function () {
    $line = StockLineSimulator::deducted(2, 10);

    $line->raise(3, deduct: false);

    expect(fn () => $line->refund(3, restocked: 3))->toThrow(LogicException::class);

    $line->refund(3, restocked: 2);

    expect($line->stock)->toBe(10)
        ->and($line->counters())->toBe([2, 3])
        ->and($line->returnable())->toBe(0)
        ->and($line->refundedOut())->toBe(0);
});

test('sequence 10, an increase with deduction then a cancel with return restores the original stock', function () {
    $line = StockLineSimulator::deducted(2, 10);

    $line->raise(3, deduct: true);

    expect($line->stock)->toBe(5);

    $line->cancel(returnUnits: true);

    expect($line->stock)->toBe(10);
});

test('sequence 11, a stock-neutral line refunded without restock then cancelled with return gives nothing back', function () {
    $line = StockLineSimulator::neutral(3, 0, 10);

    $line->refund(1);

    expect($line->counters())->toBe([2, 1])
        ->and($line->returnable())->toBe(0);

    $line->cancel(returnUnits: true);

    expect($line->stock)->toBe(10);
});

// --- Invariant property test ---

test('over seeded random sequences of raise, lower, refund and cancel the helpers always match the unit-level ledger and the invariants hold', function () {
    mt_srand(88);

    $stepsChecked = 0;

    for ($sequence = 0; $sequence < 300; $sequence++) {
        $quantity = mt_rand(1, 5);
        $line = mt_rand(0, 1) === 1
            ? StockLineSimulator::deducted($quantity, 20)
            : StockLineSimulator::neutral($quantity, mt_rand(0, $quantity), 20);

        for ($step = 0; $step < 12; $step++) {
            $outstanding = $line->item->quantity - $line->item->refunded_quantity;

            match (mt_rand(0, 3)) {
                0 => $line->raise(mt_rand(1, 3), mt_rand(0, 1) === 1),
                1 => $outstanding > 0 ? $line->lower(mt_rand(1, $outstanding), mt_rand(0, 1) === 1) : null,
                2 => $outstanding > 0
                    ? (function () use ($line, $outstanding): void {
                        $refund = mt_rand(1, $outstanding);
                        $line->refund($refund, mt_rand(0, 1) === 1 ? mt_rand(0, min($refund, $line->returnable())) : 0);
                    })()
                    : null,
                default => $line->cancel(mt_rand(0, 1) === 1),
            };

            $stepsChecked++;
        }
    }

    expect($stepsChecked)->toBe(3600);
});
