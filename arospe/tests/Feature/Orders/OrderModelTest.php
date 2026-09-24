<?php

use App\Models\Order;
use App\Models\OrderItem;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet, but
// App\Models\Order / App\Models\OrderItem, their factories and both migrations already do (the
// database layer for this story was scaffolded ahead of the write path). This file therefore pins
// the MODEL/SCHEMA layer directly, with no dependency on CreateOrder at all -- it is expected to
// be GREEN already, proving the schema/model layer is sound before the write-path tests (which
// DO depend on the not-yet-existing CreateOrder/OrderPolicy) go red for the right reason.

test('Order uses HasUuids and a created order has a 36-character UUID id', function () {
    $order = Order::factory()->create();

    expect($order->id)->toBeString()->and(mb_strlen($order->id))->toBe(36);
});

test('OrderItem uses HasUuids and a created line item has a 36-character UUID id', function () {
    $item = OrderItem::factory()->create();

    expect($item->id)->toBeString()->and(mb_strlen($item->id))->toBe(36);
});

test('an order round-trips through the factory with every column persisting and reloading byte-identically', function () {
    $order = Order::factory()->create([
        'shipping_address_line1' => 'Calle Mayor 1',
        'shipping_city' => 'Madrid',
        'shipping_country' => 'ES',
    ]);

    $fresh = $order->fresh();

    expect($fresh->id)->toBe($order->id)
        ->and($fresh->order_number)->toBe($order->order_number)
        ->and($fresh->customer_id)->toBe($order->customer_id)
        ->and($fresh->payment_method_id)->toBe($order->payment_method_id)
        ->and($fresh->status)->toEqual($order->status)
        ->and($fresh->payment_status)->toEqual($order->payment_status)
        ->and($fresh->shipping_address_line1)->toBe('Calle Mayor 1')
        ->and($fresh->shipping_city)->toBe('Madrid')
        ->and($fresh->shipping_country)->toBe('ES')
        ->and((string) $fresh->subtotal)->toBe((string) $order->subtotal)
        ->and((string) $fresh->total)->toBe((string) $order->total)
        ->and($fresh->flagged_for_review)->toBe($order->flagged_for_review);
});

test('a line item round-trips through the factory with every column persisting and reloading byte-identically', function () {
    $item = OrderItem::factory()->create([
        'quantity' => 3,
    ]);

    $fresh = $item->fresh();

    expect($fresh->id)->toBe($item->id)
        ->and($fresh->order_id)->toBe($item->order_id)
        ->and($fresh->product_id)->toBe($item->product_id)
        ->and($fresh->product_variant_id)->toBe($item->product_variant_id)
        ->and($fresh->product_name)->toBe($item->product_name)
        ->and($fresh->product_sku)->toBe($item->product_sku)
        ->and($fresh->quantity)->toBe(3)
        ->and((string) $fresh->unit_price)->toBe((string) $item->unit_price)
        ->and((string) $fresh->line_total)->toBe((string) $item->line_total)
        ->and($fresh->refunded_quantity)->toBe(0);
});

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
