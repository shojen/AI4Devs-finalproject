<?php

// Story 0055 -- the line-item hard block (0048 / PRD §3.2), at the UI layer. The screen adds NO rule:
// every hint reads Order::isLineItemEditable(), the predicate the three actions throw from.
//
// ⚠️ Disabled-state assertions use a NON-Super-Admin actor (OrdersUi::actor), never a Super Admin.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function ordersUiLineEditor(): void
{
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
}

// --- Availability, both open statuses (0048 R-3) ---

test('add, remove and save-quantity all render enabled for an orders.edit holder on Pending and Processing', function (OrderStatus $status) {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(2)->create(['status' => $status]);
    $item = $order->items()->first();

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    foreach (['add-line-item', 'remove-line-item-'.$item->id, 'save-quantity-'.$item->id] as $hook) {
        expect(OrdersUi::enabled($html, $hook))->toBeTrue($hook.' should be enabled on '.$status->value);
    }
})->with('open_order_statuses');

test('on Shipped and Delivered all three controls render disabled, and the add form is disabled as a unit', function (OrderStatus $status) {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(2)->create(['status' => $status]);
    $item = $order->items()->first();

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    // Three controls x the two blocked statuses, all disabled together (canEditLineItems()).
    foreach (['add-line-item', 'remove-line-item-'.$item->id, 'save-quantity-'.$item->id] as $hook) {
        expect(OrdersUi::disabled($html, $hook))->toBeTrue($hook.' should be disabled on '.$status->value);
    }

    // The add form goes as a unit, not one control at a time.
    foreach (['new-product-id', 'new-quantity'] as $hook) {
        expect(OrdersUi::disabled($html, $hook))->toBeTrue($hook.' should be disabled with the rest of the add form');
    }
})->with('blocked_order_statuses');

// The highest-value assertion in the story. 0048's block has NO confirmation path by design (PRD §3.2:
// "always blocked, with no confirmation path around it") and 0049 ships a confirmation mechanism three
// scenarios away in the same PRD section; a UI "are you sure" here would be a regression against a
// requirement while every other test in this file stayed green (R-3, the UI half of 0048's T-B).
test('on a Shipped order the line-item section contains NO confirmation control of any kind', function (OrderStatus $status) {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(2)->create(['status' => $status]);

    $html = Livewire::test(Show::class, ['order' => $order])->html();
    $section = OrdersUi::section($html, 'line-items-section');

    // A CLOSED dialog emits none of its own markup, so a grep for `confirm-dialog` alone is close to
    // vacuous. The real guarantee is that the section carries NO wire:click at all (every control in it
    // is on its disabled branch) and no `confirm`-named hook of any kind.
    expect($section)->not->toBe('')
        ->not->toContain('wire:click')
        ->not->toContain('confirm-dialog')
        ->not->toMatch('/data-test="[^"]*confirm[^"]*"/')
        ->not->toContain('addLineItem')
        ->not->toContain('removeLineItem')
        ->not->toContain('updateLineItemQuantity');
})->with('blocked_order_statuses');

test('even an ENABLED line-item control opens no confirmation: add/remove/save go straight to the action', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(2)->create();
    $section = OrdersUi::section(Livewire::test(Show::class, ['order' => $order])->html(), 'line-items-section');

    expect($section)->not->toContain('confirm-dialog');
});

// --- Refusals rendered, never a 500 (D-12) ---

test('a forged addLineItem against a Shipped order renders the 409 message as an error and writes nothing', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->active()->create();

    Livewire::test(Show::class, ['order' => $order])
        ->set('newProductId', $product->id)
        ->set('newQuantity', '1')
        ->call('addLineItem')
        ->assertHasErrors(['lineItems'])
        ->assertSee(__('orders.errors.order_not_editable'));

    expect($order->items()->count())->toBe(1);
});

test('removing the last remaining line item shows the refusal against the line items and the item stays', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();
    $item = $order->items()->sole();

    Livewire::test(Show::class, ['order' => $order])
        ->call('removeLineItem', $item->id)
        ->assertHasErrors(['order_item_id'])
        ->assertSee(__('orders.errors.last_line_item_cannot_be_removed'))
        ->assertSee($item->product_name);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('removing one of TWO line items succeeds -- a rule asserted only from its refusing side cannot tell count<=1 from count<=2', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(2)->create();
    [$keep, $remove] = [$order->items()->orderBy('id')->first(), $order->items()->orderByDesc('id')->first()];

    $html = Livewire::test(Show::class, ['order' => $order])
        ->call('removeLineItem', $remove->id)
        ->assertHasNoErrors()
        ->html();

    expect(OrderItem::query()->whereKey($remove->id)->exists())->toBeFalse()
        ->and($html)->toContain($keep->product_name)
        ->and($html)->not->toContain($remove->product_name);
});

// --- Totals re-render from STORED values, never stale (amendment 7) ---

test('removing a line re-renders the order totals in the same response (no stale memoised computed)', function () {
    ordersUiLineEditor();

    $order = Order::factory()->create();
    $a = OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->create(['price' => '10.00']), 'quantity' => 1]);
    $b = OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->create(['price' => '25.00']), 'quantity' => 1]);
    $order->forceFill(['subtotal' => '35.00', 'total' => '35.00'])->save();

    $component = Livewire::test(Show::class, ['order' => $order]);
    expect($component->html())->toContain('€ 35.00');

    $component->call('removeLineItem', $b->id);

    expect($component->html())->toContain('€ 10.00')->not->toContain('€ 35.00');
});

test('a quantity change re-renders the line total and the totals from the STORED unit_price, unmoved by a catalog price change', function () {
    ordersUiLineEditor();

    $product = Product::factory()->create(['price' => '10.00']);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);
    $order->forceFill(['subtotal' => '10.00', 'total' => '10.00'])->save();

    // The catalog price moves AFTER the order snapshotted it.
    $product->forceFill(['price' => '999.00'])->save();

    $html = Livewire::test(Show::class, ['order' => $order])
        ->set("editingQuantities.{$item->id}", 3)
        ->call('updateLineItemQuantity', $item->id)
        ->assertHasNoErrors()
        ->html();

    expect($html)->toContain('10.00')->toContain('30.00')->not->toContain('999.00');
    expect((string) $item->fresh()->unit_price)->toBe('10.00');
});

test('adding a line item for an existing product lists it and updates the totals in the same response', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();
    $product = Product::factory()->active()->create(['name' => 'Producto Añadido', 'price' => '7.50']);

    $html = Livewire::test(Show::class, ['order' => $order])
        ->set('newProductId', $product->id)
        ->set('newQuantity', '2')
        ->call('addLineItem')
        ->assertHasNoErrors()
        ->html();

    expect($order->items()->count())->toBe(2)
        ->and($html)->toContain('Producto Añadido')
        ->toContain('15.00');
});

test('a successful add resets the add form so the next add starts clean', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();
    $product = Product::factory()->active()->create();

    Livewire::test(Show::class, ['order' => $order])
        ->set('newProductId', $product->id)
        ->set('newQuantity', 4)
        ->call('addLineItem')
        ->assertSet('newProductId', '')
        ->assertSet('newProductVariantId', '')
        ->assertSet('newQuantity', '1');
});

// --- Refunded lines (amendment 3) ---

test('Remove is disabled on a line that carries refunded units, and a forced call renders the refusal instead of a 500', function () {
    ordersUiLineEditor();

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded]);
    $refunded = OrderItem::factory()->for($order)->create(['quantity' => 2, 'refunded_quantity' => 1]);
    OrderItem::factory()->for($order)->create();

    $component = Livewire::test(Show::class, ['order' => $order]);

    expect(OrdersUi::disabled($component->html(), 'remove-line-item-'.$refunded->id))->toBeTrue();

    $component->call('removeLineItem', $refunded->id)
        ->assertHasErrors(['order_item_id'])
        ->assertSee(__('orders.errors.refunded_line_item_cannot_be_removed'));

    expect(OrderItem::query()->whereKey($refunded->id)->exists())->toBeTrue();
});

test('a quantity below the refunded units renders the refusal against the quantity', function () {
    ordersUiLineEditor();

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 5, 'refunded_quantity' => 3]);

    Livewire::test(Show::class, ['order' => $order])
        ->set("editingQuantities.{$item->id}", 2)
        ->call('updateLineItemQuantity', $item->id)
        ->assertHasErrors(['quantity']);

    expect($item->fresh()->quantity)->toBe(5);
});

// --- The interim product picker (D-1, amendment 13) ---

test('the picker lists active products only, ordered by name; a draft product is not offered', function () {
    ordersUiLineEditor();

    Product::factory()->active()->create(['name' => 'Zeta Active']);
    Product::factory()->active()->create(['name' => 'Alfa Active']);
    Product::factory()->draft()->create(['name' => 'Beta Draft']);
    $order = Order::factory()->withItems(1)->create();

    $component = Livewire::test(Show::class, ['order' => $order]);
    $names = collect($component->instance()->productOptions())->pluck('name')->all();

    expect($names)->toBe(['Alfa Active', 'Zeta Active']);
});

test('a forged draft product id is refused by the component and writes nothing', function () {
    ordersUiLineEditor();

    $draft = Product::factory()->draft()->create();
    $order = Order::factory()->withItems(1)->create();

    Livewire::test(Show::class, ['order' => $order])
        ->set('newProductId', $draft->id)
        ->call('addLineItem')
        ->assertHasErrors(['newProductId']);

    expect($order->items()->count())->toBe(1);
});

test('an empty catalog renders an explicit empty state instead of an empty select', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();
    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect($html)->toContain(e(__('orders.line_items.catalog_empty')));
});

test('a catalog larger than the bound shows a visible truncation notice, never a silent limit', function () {
    ordersUiLineEditor();

    Product::factory()->active()->count(Show::PRODUCT_PICKER_LIMIT + 1)->create();
    $order = Order::factory()->withItems(1)->create();

    $component = Livewire::test(Show::class, ['order' => $order]);

    expect(count($component->instance()->productOptions()))->toBe(Show::PRODUCT_PICKER_LIMIT)
        ->and($component->html())->toContain(e(__('orders.line_items.catalog_truncated', ['max' => Show::PRODUCT_PICKER_LIMIT])));
});

test('the variant select appears only once a product with variants is chosen, and a variant is then required', function () {
    ordersUiLineEditor();

    $product = Product::factory()->active()->create();
    ProductVariant::factory()->for($product)->create();
    $plain = Product::factory()->active()->create();
    $order = Order::factory()->withItems(1)->create();

    $component = Livewire::test(Show::class, ['order' => $order]);
    expect(OrdersUi::present($component->html(), 'new-variant-id'))->toBeFalse();

    $component->set('newProductId', $plain->id);
    expect(OrdersUi::present($component->html(), 'new-variant-id'))->toBeFalse();

    $component->set('newProductId', $product->id);
    expect(OrdersUi::present($component->html(), 'new-variant-id'))->toBeTrue();

    $component->call('addLineItem')->assertHasErrors(['newProductVariantId']);
    expect($order->items()->count())->toBe(1);
});

test('the picker binds string properties defaulting to empty, never null (native-select desync)', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();

    Livewire::test(Show::class, ['order' => $order])
        ->assertSet('newProductId', '')
        ->assertSet('newProductVariantId', '')
        ->assertSet('selectedStatus', $order->status->value);
});

test('a cleared quantity box renders a validation error instead of a 500 (typed-int unset trap)', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();
    $product = Product::factory()->active()->create();

    Livewire::test(Show::class, ['order' => $order])
        ->set('newProductId', $product->id)
        ->set('newQuantity', '')
        ->call('addLineItem')
        ->assertHasErrors(['quantity']);

    expect($order->items()->count())->toBe(1);
});

test('a failed add does not leave its error behind after a later successful add (persisted error bag)', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();
    $product = Product::factory()->active()->create();

    Livewire::test(Show::class, ['order' => $order])
        ->call('addLineItem')
        ->assertHasErrors(['newProductId'])
        ->set('newProductId', $product->id)
        ->call('addLineItem')
        ->assertHasNoErrors();
});

test('a field error is rendered exactly once, not once by the field and once by the error region', function () {
    ordersUiLineEditor();

    $order = Order::factory()->withItems(1)->create();

    $html = Livewire::test(Show::class, ['order' => $order])
        ->call('addLineItem')
        ->html();

    expect(substr_count($html, e(__('orders.line_items.product_unavailable'))))->toBe(1);
});
