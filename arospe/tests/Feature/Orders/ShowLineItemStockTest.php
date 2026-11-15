<?php

// Story 0094 -- the stock dialog on a line's quantity change. The component adds NO rule: it asks, previews
// from a fresh read, and calls UpdateOrderItemQuantity with the chosen `adjustStock`. Stock and counters are
// asserted from the database (the real action runs), never from a mock.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Arr;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An order with one line of a stock-tracked product, as an orders.edit actor sees it.
 *
 * @param  array<string, mixed>  $itemAttributes
 * @return array{0: Order, 1: OrderItem, 2: Product}
 */
function stockDialogLine(int $quantity = 2, int $stock = 10, array $itemAttributes = []): array
{
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));

    $product = Product::factory()->named('Mug')->create(['stock' => $stock]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => $quantity, ...$itemAttributes]);
    // A second line so the order always has another one.
    OrderItem::factory()->for($order)->create();

    return [$order, $item, $product];
}

function stockDialogRequest(Order $order, OrderItem $item, int|string $quantity): Testable
{
    return Livewire::test(Show::class, ['order' => $order])
        ->set("editingQuantities.{$item->id}", $quantity)
        ->call('requestQuantityChange', $item->id);
}

// --- The request step ---

test('requesting a changed quantity opens the dialog and records the cast pending state', function () {
    [$order, $item] = stockDialogLine(2);

    Livewire::test(Show::class, ['order' => $order])
        ->set("editingQuantities.{$item->id}", '5')
        ->call('requestQuantityChange', $item->id)
        ->assertSet('showStockConfirm', true)
        ->assertSet('pendingStockAction', 'quantity')
        ->assertSet('pendingItemId', $item->id)
        ->assertSet('pendingQuantity', 5)
        ->assertSet('pendingFromQuantity', 2)
        ->assertHasNoErrors();

    expect($item->fresh()->quantity)->toBe(2);
});

test('requesting the unchanged quantity is a silent no-op', function () {
    [$order, $item] = stockDialogLine(2);

    Livewire::test(Show::class, ['order' => $order])
        ->call('requestQuantityChange', $item->id)
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '')
        ->assertHasNoErrors();
});

test('a line whose catalog row was deleted saves directly without a dialog and without moving stock', function () {
    [$order, , $product] = stockDialogLine(2);
    $orphan = OrderItem::factory()->for($order)->orphanedVariant()->create(['product_id' => $product->id, 'quantity' => 2]);

    stockDialogRequest($order, $orphan, 4)
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '')
        ->assertHasNoErrors();

    $orphan->refresh();
    expect($orphan->quantity)->toBe(4)
        ->and($orphan->stock_undeducted_quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('the request step adds no validation of its own: an invalid quantity opens the dialog and the action refuses it on apply', function (int|string $input) {
    [$order, $item, $product] = stockDialogLine(5);

    $component = stockDialogRequest($order, $item, $input)
        ->assertSet('showStockConfirm', true)
        ->assertHasNoErrors();

    $component->call('applyStockChoice', true)
        ->assertHasErrors(['quantity'])
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '');

    expect($item->fresh()->quantity)->toBe(5)
        ->and($product->fresh()->stock)->toBe(10);
})->with([
    'zero' => [0],
    'negative' => [-2],
    'empty string' => [''],
]);

test('a quantity below the refunded units opens the dialog and is refused inline on apply', function () {
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 5, 'refunded_quantity' => 3]);

    stockDialogRequest($order, $item, 2)
        ->assertSet('showStockConfirm', true)
        ->call('applyStockChoice', true)
        ->assertHasErrors(['quantity'])
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '');

    expect($item->fresh()->quantity)->toBe(5);
});

// --- The apply step ---

test('deducting the added units updates the line and takes them out of stock', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    stockDialogRequest($order, $item, 5)
        ->call('applyStockChoice', true)
        ->assertHasNoErrors()
        ->assertSet('showStockConfirm', false)
        ->assertSet("editingQuantities.{$item->id}", 5);

    $item->refresh();
    expect($item->quantity)->toBe(5)
        ->and($item->stock_undeducted_quantity)->toBe(0)
        ->and($product->fresh()->stock)->toBe(7);
});

test('saving an increase without changing stock updates only the line and counts the units as never taken out', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    stockDialogRequest($order, $item, 5)->call('applyStockChoice', false)->assertHasNoErrors();

    $item->refresh();
    expect($item->quantity)->toBe(5)
        ->and($item->stock_undeducted_quantity)->toBe(3)
        ->and($product->fresh()->stock)->toBe(10);
});

test('returning the removed units updates the line and puts them back in stock', function () {
    [$order, $item, $product] = stockDialogLine(5, 10);

    stockDialogRequest($order, $item, 2)->call('applyStockChoice', true)->assertHasNoErrors();

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(13);
});

test('saving a decrease without changing stock leaves stock alone', function () {
    [$order, $item, $product] = stockDialogLine(5, 10);

    stockDialogRequest($order, $item, 2)->call('applyStockChoice', false)->assertHasNoErrors();

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('a variant line moves the variant stock, not the product stock', function () {
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
    $product = Product::factory()->create(['stock' => 50]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 10]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 2]);
    OrderItem::factory()->for($order)->create();

    stockDialogRequest($order, $item, 5)->call('applyStockChoice', true)->assertHasNoErrors();

    expect($variant->fresh()->stock)->toBe(7)
        ->and($product->fresh()->stock)->toBe(50);
});

test('dismissing clears every pending field and changes nothing', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    stockDialogRequest($order, $item, 5)
        ->call('dismissStockConfirm')
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingStockAction', '')
        ->assertSet('pendingItemId', '')
        ->assertSet('pendingQuantity', 0)
        ->assertSet('pendingFromQuantity', 0);

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('pending state is cleared on a successful apply', function (bool $adjust) {
    [$order, $item] = stockDialogLine(2, 10);

    stockDialogRequest($order, $item, 5)
        ->call('applyStockChoice', $adjust)
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingStockAction', '')
        ->assertSet('pendingItemId', '')
        ->assertSet('pendingQuantity', 0)
        ->assertSet('pendingFromQuantity', 0);
})->with([[true], [false]]);

// --- Refusals on apply: rendered, pending state cleared, never a 500 ---

test('an order shipped while the dialog was open closes the dialog, renders the refusal and writes nothing', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    $component = stockDialogRequest($order, $item, 5);
    $order->forceFill(['status' => OrderStatus::Shipped])->save();

    $component->call('applyStockChoice', true)
        ->assertHasErrors(['lineItems'])
        ->assertSee(__('orders.errors.order_not_editable'))
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingStockAction', '')
        ->assertSet('pendingItemId', '')
        ->assertSet('pendingQuantity', 0)
        ->assertSet('pendingFromQuantity', 0);

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('a line changed by someone else meanwhile is not applied: stale message, state cleared, inputs refreshed', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    $component = stockDialogRequest($order, $item, 5);
    $item->forceFill(['quantity' => 4])->save();

    $component->call('applyStockChoice', true)
        ->assertSee(__('orders.stock.changed_meanwhile'))
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingStockAction', '')
        ->assertSet('pendingItemId', '')
        ->assertSet('pendingQuantity', 0)
        ->assertSet('pendingFromQuantity', 0)
        ->assertSet("editingQuantities.{$item->id}", 4);

    expect($item->fresh()->quantity)->toBe(4)
        ->and($product->fresh()->stock)->toBe(10);
});

test('applying with empty pending state is refused like a stale request and changes nothing', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    Livewire::test(Show::class, ['order' => $order])
        ->set("editingQuantities.{$item->id}", 5)
        ->call('applyStockChoice', true)
        ->assertSee(__('orders.stock.changed_meanwhile'))
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '');

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

// --- Security ---

test('the pending props are #[Locked]: the client cannot forge them', function (string $property, mixed $value) {
    [$order] = stockDialogLine(2);

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->set($property, $value))
        ->toThrow('Cannot update locked property');
})->with([
    'pendingStockAction' => ['pendingStockAction', 'quantity'],
    'pendingItemId' => ['pendingItemId', 'x'],
    'pendingQuantity' => ['pendingQuantity', 9],
    'pendingFromQuantity' => ['pendingFromQuantity', 9],
]);

test('requesting a change without orders.edit is forbidden and opens no dialog', function () {
    [$order, $item] = stockDialogLine(2);
    test()->actingAs(OrdersUi::actor(['orders.view']));

    Livewire::test(Show::class, ['order' => $order])
        ->set("editingQuantities.{$item->id}", 5)
        ->call('requestQuantityChange', $item->id)
        ->assertForbidden()
        ->assertSet('showStockConfirm', false);
});

test('applying re-authorizes: permission revoked between request and apply is forbidden and writes nothing', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);
    $actor = OrdersUi::actor(['orders.view', 'orders.edit']);
    test()->actingAs($actor);

    $component = stockDialogRequest($order, $item, 5);
    $actor->revokePermissionTo('orders.edit');

    $component->call('applyStockChoice', true)->assertForbidden();

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('an item id of another order is not found: error rendered, no dialog, nothing written', function () {
    [$order] = stockDialogLine(2, 10);
    $foreign = OrderItem::factory()->for(Order::factory()->create())->create(['quantity' => 2]);

    Livewire::test(Show::class, ['order' => $order])
        ->call('requestQuantityChange', $foreign->id)
        ->assertHasErrors()
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '');

    expect($foreign->fresh()->quantity)->toBe(2);
});

// --- The preview ---

test('the preview shows the line with its current and resulting stock from the deduction', function () {
    [$order, $item] = stockDialogLine(2, 10);

    $component = stockDialogRequest($order, $item, 5);
    $rows = $component->instance()->stockPreview;

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'name' => $item->product_name,
            'units' => 3,
            'current' => 10,
            'resulting' => 7,
            'willGoNegative' => false,
        ]);

    $html = $component->html();
    expect(OrdersUi::present($html, 'stock-preview-row-'.$item->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-confirm'))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-secondary'))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-dismiss'))->toBeTrue()
        ->and($html)->toContain('Deduct 3 units from stock')->toContain('Save without changing stock')->toContain('Keep editing');
});

test('the preview of a decrease shows the units returned', function () {
    [$order, $item] = stockDialogLine(5, 10);

    $component = stockDialogRequest($order, $item, 2);

    expect($component->instance()->stockPreview[0])->toMatchArray(['units' => 3, 'current' => 10, 'resulting' => 13, 'willGoNegative' => false])
        ->and($component->html())->toContain('Return 3 units to stock');
});

test('the preview is read fresh: stock changed after opening shows the new value on the next render', function () {
    [$order, $item, $product] = stockDialogLine(2, 10);

    $component = stockDialogRequest($order, $item, 5);
    $product->forceFill(['stock' => 20])->save();
    $component->call('$refresh');

    expect($component->instance()->stockPreview[0])->toMatchArray(['current' => 20, 'resulting' => 17]);
});

test('an increase that leaves stock below zero is flagged and warned about but never blocked', function () {
    [$order, $item, $product] = stockDialogLine(2, 1);

    $component = stockDialogRequest($order, $item, 5);
    $html = $component->html();

    expect($component->instance()->stockPreview[0])->toMatchArray(['resulting' => -2, 'willGoNegative' => true])
        ->and(OrdersUi::present($html, 'stock-negative-warning'))->toBeTrue()
        ->and($html)->toContain('role="alert"')
        ->and(OrdersUi::enabled($html, 'confirm-dialog-line-stock-confirm'))->toBeTrue()
        ->and(OrdersUi::enabled($html, 'confirm-dialog-line-stock-secondary'))->toBeTrue();

    $component->call('applyStockChoice', true)->assertHasNoErrors();
    expect($product->fresh()->stock)->toBe(-2);
});

test('willGoNegative is false when the resulting stock is exactly zero', function () {
    [$order, $item] = stockDialogLine(2, 3);

    $component = stockDialogRequest($order, $item, 5);

    expect($component->instance()->stockPreview[0])->toMatchArray(['resulting' => 0, 'willGoNegative' => false])
        ->and(OrdersUi::present($component->html(), 'stock-negative-warning'))->toBeFalse();
});

test('the previewed units equal the stock the action really returns on a decrease', function (int $undeducted, int $newQuantity) {
    [$order, $item, $product] = stockDialogLine(5, 10, ['stock_undeducted_quantity' => $undeducted]);

    $component = stockDialogRequest($order, $item, $newQuantity);
    $units = $component->instance()->stockPreview[0]['units'];

    $component->call('applyStockChoice', true)->assertHasNoErrors();

    expect($product->fresh()->stock)->toBe(10 + $units);
})->with([
    'nothing undeducted' => [0, 2],
    'partly undeducted, large cut' => [2, 1],
    'partly undeducted, small cut' => [2, 3],
    'fully undeducted' => [5, 1],
]);

test('the preview units are clamped to what the line holds for input the action will refuse', function (int $input, int $refunded) {
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 5, 'refunded_quantity' => $refunded]);
    $returnable = $item->returnableStockUnits();

    $units = stockDialogRequest($order, $item, $input)->instance()->stockPreview[0]['units'];

    expect($units)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($returnable);
})->with([
    'negative input' => [-3, 0],
    'zero input' => [0, 0],
    'below refunded' => [1, 3],
]);

// --- Zero returnable units (OQ-3) ---

test('a decrease with nothing to return still asks, in the two-button form without a Return or Deduct option', function () {
    [$order, $item, $product] = stockDialogLine(5, 10, ['stock_undeducted_quantity' => 5]);

    $component = stockDialogRequest($order, $item, 2)->assertSet('showStockConfirm', true);
    $html = $component->html();

    expect($component->instance()->stockPreview[0]['units'])->toBe(0)
        ->and(OrdersUi::present($html, 'stock-nothing-to-return'))->toBeTrue()
        ->and($html)->toContain('No units of this line are currently out of stock')
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-secondary'))->toBeFalse()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-dismiss'))->toBeTrue()
        ->and(OrdersUi::tag($html, 'confirm-dialog-line-stock-confirm'))->toContain('applyStockChoice(false)')
        ->and($html)->toContain('Save without changing stock')->toContain('Keep editing')
        ->not->toContain('units to stock')
        ->not->toContain('units from stock');

    $component->call('applyStockChoice', false)->assertHasNoErrors();
    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

// --- Lang parity ---

test('orders.stock has identical key sets in en and es and carries the required keys', function () {
    $stockKeys = fn (string $locale): array => collect(Arr::dot(require lang_path($locale.'/orders.php')))
        ->filter(fn (mixed $text, string $key): bool => str_starts_with($key, 'stock.'))
        ->all();

    $en = $stockKeys('en');
    $es = $stockKeys('es');

    expect($en)->not->toBeEmpty()
        ->and(array_keys($es))->toEqualCanonicalizing(array_keys($en))
        ->and($en)->toHaveKeys(['stock.changed_meanwhile', 'stock.nothing_to_return'])
        ->and(array_filter([...$en, ...$es], fn (mixed $text): bool => $text === ''))->toBeEmpty();
});
