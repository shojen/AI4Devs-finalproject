<?php

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet, but
// App\Models\Order / App\Models\OrderItem, their factories and both migrations already do (the
// database layer for this story was scaffolded ahead of the write path). This file therefore pins
// the MODEL/SCHEMA layer directly, with no dependency on CreateOrder at all -- it is expected to
// be GREEN already, proving the schema/model layer is sound before the write-path tests (which
// DO depend on the not-yet-existing CreateOrder/OrderPolicy) go red for the right reason.

// This is the sharpest structural test in the story -- the omission-as-guard convention
// (omission from #[Fillable] IS this codebase's mass-assignment guard, per
// docs/conventions/base-standards/stack-and-model-conventions.md#model-conventions) is only real if something fails when it
// is undone. Asserted against getFillable() directly, matching
// tests/Feature/Models/ProductVariantTest.php's own "the fillable set excludes ..." precedent --
// NOT a real ::create() call, since several of the omitted Order/OrderItem columns
// (subtotal/tax_amount/shipping_amount/total; product_name/product_sku/unit_price/line_total) are
// NOT NULL with no database default, so an ::create() omitting them would fail on the INSERT
// itself rather than demonstrate anything about mass assignment.
test('the Order fillable set excludes every derived, status and tax column', function () {
    $fillable = (new Order)->getFillable();

    expect($fillable)->not->toContain('order_number')
        ->and($fillable)->not->toContain('status')
        ->and($fillable)->not->toContain('payment_status')
        ->and($fillable)->not->toContain('subtotal')
        ->and($fillable)->not->toContain('tax_amount')
        ->and($fillable)->not->toContain('shipping_amount')
        ->and($fillable)->not->toContain('total')
        ->and($fillable)->not->toContain('tax_rate')
        ->and($fillable)->not->toContain('flagged_for_review')
        ->and($fillable)->not->toContain('sales_region_id')
        ->and($fillable)->not->toContain('shipping_rate_id')
        ->and($fillable)->toContain('customer_id')
        ->and($fillable)->toContain('payment_method_id');
});

test('the OrderItem fillable set excludes product_name, product_sku, unit_price, line_total and refunded_quantity', function () {
    $fillable = (new OrderItem)->getFillable();

    expect($fillable)->not->toContain('product_name')
        ->and($fillable)->not->toContain('product_sku')
        ->and($fillable)->not->toContain('unit_price')
        ->and($fillable)->not->toContain('line_total')
        ->and($fillable)->not->toContain('refunded_quantity')
        ->and($fillable)->toContain('product_id')
        ->and($fillable)->toContain('product_variant_id')
        ->and($fillable)->toContain('quantity');
});

// Confirms the guard actually bites: a caller attempting to supply `total` via a plain create()
// payload has that key silently dropped from the mass-assignment fill, never reaching the model's
// attributes at all -- proven with a payload restricted to genuinely fillable + nullable columns
// so the INSERT itself succeeds and the assertion is about the dropped key, not a DB error.
test('a total supplied via a plain create() payload never reaches the model, because it is not fillable', function () {
    $order = new Order;
    $order->fill([
        'customer_id' => 'not-used-for-fill-test',
        'total' => '999.99',
    ]);

    expect($order->getAttribute('total'))->toBeNull();
});

// The symmetric case on OrderItem (Phase 5 code review finding F-G): the task file names
// `OrderItem::create([... 'unit_price' => 0.01 ...])` explicitly, alongside Order's `total`, as
// "the sharpest structural test in the story" -- fill() rather than a real create() for the same
// reason as above, since OrderItem's NOT NULL, no-default columns would fail the INSERT itself.
test('a unit_price supplied via a plain fill() payload never reaches the model, because it is not fillable', function () {
    $item = new OrderItem;
    $item->fill([
        'product_id' => 'not-used-for-fill-test',
        'unit_price' => '0.01',
    ]);

    expect($item->getAttribute('unit_price'))->toBeNull();
});

// --- Story 0084 (reworked, Phase 3 red step): no orders.paid_at, Order::payment(), Order::isAwaitingPayment(), OrderFactory::paid() ---

test('isAwaitingPayment reads payment_status and status only and never consults the payment row', function () {
    $legacyPaid = Order::factory()->state(['payment_status' => PaymentStatus::Paid])->create()->fresh();
    $pendingWithRow = Order::factory()->create()->fresh();
    OrderPayment::factory()->create(['order_id' => $pendingWithRow->id]);

    expect($legacyPaid->isAwaitingPayment())->toBeFalse()
        ->and($pendingWithRow->fresh()->isAwaitingPayment())->toBeTrue();
});

// --- Story 0088: stock target and counter helpers (D1, D8, D9) ---

test('stockTarget() names the variant for a variant line, the product for a plain line, and null for a deleted product or an orphaned variant line', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $variantLine = OrderItem::factory()->forVariant($variant)->create();
    $productLine = OrderItem::factory()->create(['product_id' => $product->id]);
    $orphanedLine = OrderItem::factory()->orphanedVariant()->create();

    $deletedProduct = Product::factory()->create();
    $deletedProductLine = OrderItem::factory()->create(['product_id' => $deletedProduct->id]);
    $deletedProduct->delete();

    expect($variantLine->stockTarget())->toBe(['type' => 'variant', 'id' => $variant->id])
        ->and($productLine->stockTarget())->toBe(['type' => 'product', 'id' => $product->id])
        ->and($orphanedLine->stockTarget())->toBeNull()
        ->and($orphanedLine->fresh()->stockTarget())->toBeNull()
        ->and($deletedProductLine->fresh()->product_id)->toBeNull()
        ->and($deletedProductLine->fresh()->stockTarget())->toBeNull();
});

test('stockTarget() runs no query', function () {
    $item = OrderItem::factory()->create()->fresh();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $item->stockTarget();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

test('a legacy-untracked line has no returnable units and no refunded units out, with or without refunds', function (int $quantity, int $refunded) {
    $item = OrderItem::factory()->legacyStockUntracked()->create([
        'quantity' => $quantity,
        'refunded_quantity' => $refunded,
    ])->fresh();

    expect($item->stock_undeducted_quantity)->toBe($quantity - $refunded)
        ->and($item->stock_refunded_not_out_quantity)->toBe($refunded)
        ->and($item->returnableStockUnits())->toBe(0)
        ->and($item->refundedUnitsOutOfStock())->toBe(0);
})->with([
    'no refund' => [3, 0],
    'partly refunded' => [5, 2],
    'fully refunded' => [4, 4],
]);

test('returnableStockUnits() is (Q - F) - U and refundedUnitsOutOfStock() is F - K on a mixed line', function () {
    $item = OrderItem::factory()->create([
        'quantity' => 7,
        'refunded_quantity' => 3,
        'stock_undeducted_quantity' => 1,
        'stock_refunded_not_out_quantity' => 1,
    ]);

    expect($item->returnableStockUnits())->toBe(3)
        ->and($item->refundedUnitsOutOfStock())->toBe(2);
});

dataset('refund_rule_cases', [
    'R 5, f 2, k 0' => [5, 0, 0, 0, 2, 0, 0, 0],
    'R 2 (Q 5, U 3), f 3, k 0' => [5, 3, 0, 0, 3, 0, 2, 1],
    'R 0 after a cancel (Q 4, U 4), f 4, k 0' => [4, 4, 0, 0, 4, 0, 0, 4],
    'R 2 (Q 5, U 3), f 3, k 2' => [5, 3, 0, 0, 3, 2, 2, 3],
]);

test('applyRefundToStockCounters(f, k) moves over = max(0, f - R) from U to K and adds k to K, keeping the invariants', function (int $quantity, int $undeducted, int $refundedNotOut, int $refundedBefore, int $refund, int $restocked, int $expectedUndeducted, int $expectedRefundedNotOut) {
    $item = OrderItem::factory()->create([
        'quantity' => $quantity,
        'refunded_quantity' => $refundedBefore,
        'stock_undeducted_quantity' => $undeducted,
        'stock_refunded_not_out_quantity' => $refundedNotOut,
    ]);

    $item->applyRefundToStockCounters($refund, $restocked);

    // The caller increments refunded_quantity afterwards; the method must not.
    expect($item->stock_undeducted_quantity)->toBe($expectedUndeducted)
        ->and($item->stock_refunded_not_out_quantity)->toBe($expectedRefundedNotOut)
        ->and($item->refunded_quantity)->toBe($refundedBefore)
        ->and($item->stock_undeducted_quantity)->toBeLessThanOrEqual($quantity - ($refundedBefore + $refund))
        ->and($item->stock_refunded_not_out_quantity)->toBeLessThanOrEqual($refundedBefore + $refund);
})->with('refund_rule_cases');

test('applyRefundToStockCounters() refuses a restock above min(f, R) and leaves the counters unchanged', function (int $refund, int $restocked) {
    $item = OrderItem::factory()->create([
        'quantity' => 5,
        'stock_undeducted_quantity' => 3,
    ]);

    expect(fn () => $item->applyRefundToStockCounters($refund, $restocked))->toThrow(LogicException::class);

    expect($item->stock_undeducted_quantity)->toBe(3)
        ->and($item->stock_refunded_not_out_quantity)->toBe(0);
})->with([
    'k above R (R 2, f 3, k 3)' => [3, 3],
    'k above f (R 2, f 1, k 2)' => [1, 2],
]);
