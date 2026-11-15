<?php

// Story 0094a -- the stock dialog on a line removal. The component adds NO rule: it asks, previews from a
// fresh read, and calls RemoveOrderItem with the chosen `adjustStock`. Stock and line existence are asserted
// from the database (the real action runs), except where a race is simulated with a mocked action.

use App\Actions\Orders\RemoveOrderItem;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
 * An order with a line of a stock-tracked "Mug" and a second line, as an orders.edit actor sees it.
 *
 * @param  array<string, mixed>  $itemAttributes
 * @return array{0: Order, 1: OrderItem, 2: Product}
 */
function removalDialogLine(int $quantity = 3, int $stock = 10, array $itemAttributes = []): array
{
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));

    $product = Product::factory()->named('Mug')->create(['stock' => $stock]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => $quantity, ...$itemAttributes]);
    OrderItem::factory()->for($order)->create();

    return [$order, $item, $product];
}

function removalDialogRequest(Order $order, OrderItem $item): Testable
{
    return Livewire::test(Show::class, ['order' => $order])->call('requestRemoveLineItem', $item->id);
}

function assertRemovalPendingCleared(Testable $component): void
{
    $component
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingStockAction', '')
        ->assertSet('pendingItemId', '')
        ->assertSet('pendingQuantity', 0)
        ->assertSet('pendingFromQuantity', 0);
}

// --- The request step ---

test('requesting a removal opens the dialog and records the remove pending state without deleting', function () {
    [$order, $item] = removalDialogLine(3);

    Livewire::test(Show::class, ['order' => $order])
        ->call('requestRemoveLineItem', $item->id)
        ->assertSet('showStockConfirm', true)
        ->assertSet('pendingStockAction', 'remove')
        ->assertSet('pendingItemId', $item->id)
        ->assertSet('pendingFromQuantity', 3)
        ->assertHasNoErrors();

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('a line whose catalog row was deleted is removed directly without a dialog and without moving stock', function () {
    [$order, , $product] = removalDialogLine(3);
    $orphan = OrderItem::factory()->for($order)->orphanedVariant()->create(['product_id' => $product->id, 'quantity' => 2]);

    removalDialogRequest($order, $orphan)
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '')
        ->assertHasNoErrors();

    expect(OrderItem::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and($product->fresh()->stock)->toBe(10);
});

test('the request step adds no validation of its own: the only line of an order opens the dialog and the action refuses on apply', function () {
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create();
    $only = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 2]);

    $component = removalDialogRequest($order, $only)
        ->assertSet('showStockConfirm', true)
        ->assertHasNoErrors();

    $component->call('applyStockChoice', true)
        ->assertHasErrors(['order_item_id'])
        ->assertSee(__('orders.errors.last_line_item_cannot_be_removed'));
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($only->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock)->toBe(10);
});

test('a line with refunded units opens the dialog and is refused inline on apply', function () {
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded]);
    $refunded = OrderItem::factory()->for($order)->create(['quantity' => 2, 'refunded_quantity' => 1]);
    OrderItem::factory()->for($order)->create();

    $component = removalDialogRequest($order, $refunded)->assertSet('showStockConfirm', true);

    $component->call('applyStockChoice', true)
        ->assertHasErrors(['order_item_id'])
        ->assertSee(__('orders.errors.refunded_line_item_cannot_be_removed'));
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($refunded->id)->exists())->toBeTrue();
});

// --- The apply step ---

test('returning the units removes the line and puts its units back in stock', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item)->call('applyStockChoice', true)->assertHasNoErrors();
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and($product->fresh()->stock)->toBe(13);
});

test('removing without changing stock deletes the line and leaves stock alone', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item)->call('applyStockChoice', false)->assertHasNoErrors();
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and($product->fresh()->stock)->toBe(10);
});

test('dismissing keeps the line, clears every pending field and changes nothing', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item)->call('dismissStockConfirm');
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock)->toBe(10);
});

// --- Refusals on apply: rendered, pending state cleared, never a 500 ---

test('an order shipped while the dialog was open closes the dialog, renders the refusal and writes nothing', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item);
    $order->forceFill(['status' => OrderStatus::Shipped])->save();

    $component->call('applyStockChoice', true)
        ->assertHasErrors(['lineItems'])
        ->assertSee(__('orders.errors.order_not_editable'));
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock)->toBe(10);
});

test('a line already removed by someone else meanwhile is not applied: stale message and state cleared', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item);
    $item->delete();

    $component->call('applyStockChoice', true)->assertSee(__('orders.stock.changed_meanwhile'));
    assertRemovalPendingCleared($component);

    expect($product->fresh()->stock)->toBe(10);
});

test('a line whose quantity changed meanwhile is not removed: stale message and state cleared', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item);
    $item->forceFill(['quantity' => 4])->save();

    $component->call('applyStockChoice', true)->assertSee(__('orders.stock.changed_meanwhile'));
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock)->toBe(10);
});

test('a race detected inside the action renders the changed-meanwhile message instead of a 500', function (Throwable $exception) {
    [$order, $item] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item);

    $this->mock(RemoveOrderItem::class, function ($mock) use ($exception): void {
        $mock->shouldReceive('__invoke')->once()->andThrow($exception);
    });

    $component->call('applyStockChoice', true)->assertSee(__('orders.stock.changed_meanwhile'));
    assertRemovalPendingCleared($component);
})->with([
    'LogicException' => fn () => new LogicException('The order line changed while its stock was being adjusted.'),
    'ModelNotFoundException' => fn () => new ModelNotFoundException,
]);

test('applying with empty pending state is refused like a stale request and changes nothing', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('applyStockChoice', true)
        ->assertSee(__('orders.stock.changed_meanwhile'));
    assertRemovalPendingCleared($component);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock)->toBe(10);
});

// --- Security ---

test('the pending props are #[Locked]: the client cannot forge a removal', function (string $property, mixed $value) {
    [$order] = removalDialogLine(3);

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->set($property, $value))
        ->toThrow('Cannot update locked property');
})->with([
    'pendingStockAction' => ['pendingStockAction', 'remove'],
    'pendingItemId' => ['pendingItemId', 'x'],
    'pendingQuantity' => ['pendingQuantity', 9],
    'pendingFromQuantity' => ['pendingFromQuantity', 9],
]);

test('requesting a removal without orders.edit is forbidden and opens no dialog', function () {
    [$order, $item] = removalDialogLine(3);
    test()->actingAs(OrdersUi::actor(['orders.view']));

    Livewire::test(Show::class, ['order' => $order])
        ->call('requestRemoveLineItem', $item->id)
        ->assertForbidden()
        ->assertSet('showStockConfirm', false);
});

test('applying a removal re-authorizes: permission revoked between request and apply is forbidden and writes nothing', function () {
    [$order, $item, $product] = removalDialogLine(3, 10);
    $actor = OrdersUi::actor(['orders.view', 'orders.edit']);
    test()->actingAs($actor);

    $component = removalDialogRequest($order, $item);
    $actor->revokePermissionTo('orders.edit');

    $component->call('applyStockChoice', true)->assertForbidden();

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and($product->fresh()->stock)->toBe(10);
});

test('an item id of another order is not found: error rendered, no dialog, nothing deleted', function () {
    [$order] = removalDialogLine(3, 10);
    $foreign = OrderItem::factory()->for(Order::factory()->create())->create(['quantity' => 2]);

    Livewire::test(Show::class, ['order' => $order])
        ->call('requestRemoveLineItem', $foreign->id)
        ->assertHasErrors()
        ->assertSet('showStockConfirm', false)
        ->assertSet('pendingItemId', '');

    expect(OrderItem::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

// --- The preview ---

test('the removal preview shows the units to return with current and resulting stock, and the removal copy', function () {
    [$order, $item] = removalDialogLine(3, 10);

    $component = removalDialogRequest($order, $item);
    $rows = $component->instance()->stockPreview;

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'name' => $item->product_name,
            'units' => 3,
            'current' => 10,
            'resulting' => 13,
            'willGoNegative' => false,
        ]);

    $html = $component->html();
    expect(OrdersUi::present($html, 'stock-preview-row-'.$item->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-confirm'))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-secondary'))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-dismiss'))->toBeTrue()
        ->and(OrdersUi::tag($html, 'confirm-dialog-line-stock-confirm'))->toContain('applyStockChoice(true)')
        ->and(OrdersUi::tag($html, 'confirm-dialog-line-stock-secondary'))->toContain('applyStockChoice(false)')
        ->and($html)->toContain(__('orders.stock.remove_heading'))
        ->toContain(__('orders.stock.remove_body'))
        ->toContain('Return 3 units to stock')
        ->toContain(__('orders.stock.remove_without_stock'))
        ->toContain('Keep editing');
});

test('removal mode never shows the quantity-change wording', function () {
    [$order, $item] = removalDialogLine(3, 10);

    $html = removalDialogRequest($order, $item)->html();

    expect($html)->not->toContain(__('orders.stock.dialog_heading'))
        ->not->toContain(__('orders.stock.dialog_body'))
        ->not->toContain(__('orders.stock.save_without_stock'))
        ->not->toContain('Deduct');
});

test('the removal preview is never flagged negative, even when stock is already below zero', function () {
    [$order, $item] = removalDialogLine(3, -5);

    $component = removalDialogRequest($order, $item);

    expect($component->instance()->stockPreview[0])->toMatchArray(['current' => -5, 'resulting' => -2, 'willGoNegative' => false])
        ->and(OrdersUi::present($component->html(), 'stock-negative-warning'))->toBeFalse();
});

test('the previewed units equal the stock the action really returns on removal', function (int $undeducted) {
    [$order, $item, $product] = removalDialogLine(5, 10, ['stock_undeducted_quantity' => $undeducted]);

    $component = removalDialogRequest($order, $item);
    $units = $component->instance()->stockPreview[0]['units'];

    $component->call('applyStockChoice', true)->assertHasNoErrors();

    expect($units)->toBe(5 - $undeducted)
        ->and($product->fresh()->stock)->toBe(10 + $units);
})->with([
    'nothing undeducted' => [0],
    'partly undeducted' => [2],
    'fully undeducted' => [5],
]);

// --- Zero returnable units (OQ-3) ---

test('a removal with nothing to return still asks, in the two-button form without a Return option', function () {
    [$order, $item, $product] = removalDialogLine(3, 10, ['stock_undeducted_quantity' => 3]);

    $component = removalDialogRequest($order, $item)->assertSet('showStockConfirm', true);
    $html = $component->html();

    expect($component->instance()->stockPreview[0]['units'])->toBe(0)
        ->and(OrdersUi::present($html, 'stock-nothing-to-return'))->toBeTrue()
        ->and($html)->toContain('No units of this line are currently out of stock')
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-secondary'))->toBeFalse()
        ->and(OrdersUi::present($html, 'confirm-dialog-line-stock-dismiss'))->toBeTrue()
        ->and(OrdersUi::tag($html, 'confirm-dialog-line-stock-confirm'))->toContain('applyStockChoice(false)')
        ->and($html)->toContain('Remove without changing stock')->toContain('Keep editing')
        ->not->toContain('units to stock')
        ->not->toContain('Save without changing stock')
        ->not->toContain(__('orders.stock.dialog_heading'));

    $component->call('applyStockChoice', false)->assertHasNoErrors();
    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and($product->fresh()->stock)->toBe(10);
});

// --- Lang parity ---

test('orders.stock has identical key sets in en and es and carries the removal keys', function () {
    $stockKeys = fn (string $locale): array => collect(Arr::dot(require lang_path($locale.'/orders.php')))
        ->filter(fn (mixed $text, string $key): bool => str_starts_with($key, 'stock.'))
        ->all();

    $en = $stockKeys('en');
    $es = $stockKeys('es');

    expect(array_keys($es))->toEqualCanonicalizing(array_keys($en))
        ->and($en)->toHaveKeys(['stock.remove_heading', 'stock.remove_body', 'stock.remove_without_stock', 'stock.changed_meanwhile'])
        ->and(array_filter([...$en, ...$es], fn (mixed $text): bool => $text === ''))->toBeEmpty();
});

test('changed_meanwhile is mode-neutral: it no longer talks about a quantity', function (string $locale) {
    $text = Arr::get(require lang_path($locale.'/orders.php'), 'stock.changed_meanwhile');

    expect(mb_strtolower($text))->not->toContain('quantity')->not->toContain('cantidad');
})->with(['en', 'es']);
