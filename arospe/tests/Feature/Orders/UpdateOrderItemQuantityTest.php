<?php

use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0048, Phase 3 (TDD "red" step): App\Actions\Orders\UpdateOrderItemQuantity does not exist
// yet -- every test below is expected to fail with "Target class [...] does not exist" until
// backend-expert implements it. That failure is the correct, intended "red" outcome.
//
// Signature assumed, per the task file's own "Files to create" section:
// __invoke(Order $order, string $orderItemId, int $quantity): OrderItem.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderEditorForQuantity(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    return $actor;
}

// --- Recalculation, both open statuses (R-3's mitigation) ---

test('changing a quantity writes the new quantity, sets line_total, and moves subtotal accordingly', function (OrderStatus $status) {
    actingOrderEditorForQuantity();

    $product = Product::factory()->create(['price' => '10.00']);
    $order = Order::factory()->create(['status' => $status]);
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);
    $order->forceFill(['subtotal' => (string) $item->fresh()->line_total, 'total' => (string) $item->fresh()->line_total])->save();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 3);

    $freshItem = $item->fresh();
    $fresh = $order->fresh();

    expect($freshItem->quantity)->toBe(3)
        ->and((string) $freshItem->line_total)->toBe(bcmul((string) $freshItem->unit_price, '3', 2))
        ->and((string) $fresh->subtotal)->toBe((string) $freshItem->line_total);
})->with('open_order_statuses');

test('total equals subtotal plus tax_amount plus shipping_amount after a quantity change', function (OrderStatus $status) {
    actingOrderEditorForQuantity();

    $order = Order::factory()->withItems(1)->create(['status' => $status]);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 4);

    $fresh = $order->fresh();
    $expectedTotal = bcadd(bcadd($fresh->subtotal, $fresh->tax_amount, 2), $fresh->shipping_amount, 2);

    expect((string) $fresh->total)->toBe($expectedTotal);
})->with('open_order_statuses');

// The exact Gherkin scenario: a line item priced at 10.00 for one unit, changed to a quantity of
// three, records a line total of 30.00.
test('a line item priced at 10.00 changed to a quantity of three records a line total of 30.00', function () {
    actingOrderEditorForQuantity();

    $product = Product::factory()->create(['price' => '10.00']);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 3);

    expect((string) $item->fresh()->line_total)->toBe('30.00');
});

// --- The price snapshot: the single highest-risk test in this story ---

// This is the test that distinguishes a correct implementation from one that "refreshes" the
// snapshot while it has the product in hand -- the failure is invisible until a price moves.
test('changing a quantity re-multiplies the stored unit_price and never re-reads the products live price', function () {
    actingOrderEditorForQuantity();

    $product = Product::factory()->create(['price' => '10.00']);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    expect((string) $item->fresh()->unit_price)->toBe('10.00');

    $product->update(['price' => '25.00']);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 2);

    $fresh = $item->fresh();

    expect((string) $fresh->unit_price)->toBe('10.00')
        ->and((string) $fresh->line_total)->toBe('20.00');
});

test('changing a quantity does not touch the line items product_name or product_sku, even after the product is renamed', function () {
    actingOrderEditorForQuantity();

    $product = Product::factory()->create(['name' => 'Widget', 'sku' => 'WID-001', 'price' => '10.00']);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    $product->update(['name' => 'Renamed Widget', 'sku' => 'WID-002']);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 2);

    $fresh = $item->fresh();

    expect($fresh->product_name)->toBe('Widget')
        ->and($fresh->product_sku)->toBe('WID-001');
});

// --- Rejecting an invalid quantity change ---

test('changing a quantity to a non-positive value is rejected, and the quantity is unchanged', function (int $invalidQuantity) {
    actingOrderEditorForQuantity();

    $order = Order::factory()->withItems(1)->create();
    $item = $order->items()->sole();
    $originalQuantity = $item->quantity;

    expect(fn () => app(UpdateOrderItemQuantity::class)($order, $item->id, $invalidQuantity))
        ->toThrow(ValidationException::class);

    expect($item->fresh()->quantity)->toBe($originalQuantity);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

// UpdateOrderItemQuantity's own signature types $quantity as `int`, matching AddOrderItem's
// identical constraint (see AddOrderItemTest.php's own note) -- a garbage value must still never
// change a row, regardless of which exception class the language's type system produces here.
test('changing a quantity to a garbage value never mutates the row', function () {
    actingOrderEditorForQuantity();

    $order = Order::factory()->withItems(1)->create();
    $item = $order->items()->sole();
    $originalQuantity = $item->quantity;

    try {
        app(UpdateOrderItemQuantity::class)($order, $item->id, /** @phpstan-ignore-next-line */ 'abc');
    } catch (Throwable $e) {
        // Either a ValidationException or a TypeError is acceptable -- see AddOrderItemTest.php.
    }

    expect($item->fresh()->quantity)->toBe($originalQuantity);
});

test('changing the quantity of a line item that belongs to a different order is rejected, and neither orders line items change', function () {
    actingOrderEditorForQuantity();

    $orderA = Order::factory()->withItems(1)->create();
    $orderB = Order::factory()->withItems(1)->create();
    $itemBelongingToOrderB = $orderB->items()->sole();
    $originalQuantity = $itemBelongingToOrderB->quantity;
    $orderASubtotalBefore = (string) $orderA->fresh()->subtotal;
    $orderBSubtotalBefore = (string) $orderB->fresh()->subtotal;

    expect(fn () => app(UpdateOrderItemQuantity::class)($orderA, $itemBelongingToOrderB->id, 5))
        ->toThrow(ValidationException::class);

    expect($itemBelongingToOrderB->fresh()->quantity)->toBe($originalQuantity)
        ->and((string) $orderA->fresh()->subtotal)->toBe($orderASubtotalBefore)
        ->and((string) $orderB->fresh()->subtotal)->toBe($orderBSubtotalBefore);
});

// --- Tax recalculation coupling (D-8) ---

test('changing a quantity recomputes tax_amount from the new subtotal when a rate is already resolved', function () {
    actingOrderEditorForQuantity();

    $order = Order::factory()->create(['tax_rate' => '21.000']);
    $product = Product::factory()->create(['price' => '50.00']);
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    app(UpdateOrderItemQuantity::class)($order, $item->id, 2);

    $fresh = $order->fresh();

    // Literal amounts, never a re-derived formula: 21% of 100.00 (story 0053a).
    expect((string) $fresh->subtotal)->toBe('100.00')
        ->and((string) $fresh->tax_amount)->toBe('21.00')
        ->and((string) $fresh->total)->toBe('121.00');
});

test('changing a quantity on an order with no resolved tax rate leaves tax_amount at zero and resolves no sales region', function () {
    actingOrderEditorForQuantity();

    $order = Order::factory()->withItems(1)->create(['tax_rate' => null, 'sales_region_id' => null]);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 3);

    $fresh = $order->fresh();

    expect((string) $fresh->tax_amount)->toBe('0.00')
        ->and($fresh->sales_region_id)->toBeNull();
});

test('changing a quantity never writes tax_rate itself', function () {
    actingOrderEditorForQuantity();

    $order = Order::factory()->withItems(1)->create(['tax_rate' => '10.000']);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 3);

    expect((string) $order->fresh()->tax_rate)->toBe('10.000');
});

// --- Phase 4 security audit fixes ---

// F-1: the same decimal(10,2) column-overflow guard CreateOrder/AddOrderItem already apply now
// also applies on this edit path -- a near-maximum unit_price at a high enough quantity can still
// overflow order_items.line_total even though `quantity` alone passed orderItemQuantityRules()'s
// own max: bound.
test('changing a quantity whose new line_total would exceed the decimal column ceiling is rejected, and the quantity is unchanged', function () {
    actingOrderEditorForQuantity();

    $product = Product::factory()->create(['price' => '99999999.99']);
    $order = Order::factory()->create();
    // The maximum a `decimal(10,2)` order_items.unit_price column can hold, at quantity 1 -- still
    // within bounds until the change below multiplies it past the column ceiling.
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);
    $originalQuantity = $item->quantity;

    expect(fn () => app(UpdateOrderItemQuantity::class)($order, $item->id, 2))
        ->toThrow(ValidationException::class);

    expect($item->fresh()->quantity)->toBe($originalQuantity);
});
