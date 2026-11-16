<?php

// Story 0096 -- the refund modal's "Return to stock" choice on the order detail screen. The refund
// quantities and the stock inputs are deferred bindings, so the server maps the choice on submit:
// none -> nothing, all -> every refunded stock-target line, partial -> typed units clamped silently
// to the units refunded on that line. RecordRefund (0092b) still validates everything.

use App\Actions\Orders\RecordRefund;
use App\Enums\OrderStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.refund']));
});

/**
 * @return array{order: Order, mug: OrderItem, plate: OrderItem, mugProduct: Product, plateProduct: Product}
 */
function refundStockOrder(int $mugQuantity = 4, int $plateQuantity = 3): array
{
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $mugProduct = Product::factory()->create(['stock' => 10, 'price' => '10.00']);
    $plateProduct = Product::factory()->create(['stock' => 10, 'price' => '10.00']);

    return [
        'order' => $order,
        'mugProduct' => $mugProduct,
        'plateProduct' => $plateProduct,
        'mug' => OrderItem::factory()->for($order)->state(['product_id' => $mugProduct->id])->create(['quantity' => $mugQuantity]),
        'plate' => OrderItem::factory()->for($order)->state(['product_id' => $plateProduct->id])->create(['quantity' => $plateQuantity]),
    ];
}

function refundModalFor(Order $order): Testable
{
    return Livewire::test(Show::class, ['order' => $order])->call('openRefundModal');
}

test('the refund modal returns nothing to stock by default and again on every open', function () {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->assertSet('refundStockReturn', 'none')
        ->set('refundStockReturn', 'partial')
        ->set('refundStockQuantities', [$fixture['mug']->id => '1'])
        ->call('closeRefundModal')
        ->assertSet('refundStockReturn', 'none')
        ->assertSet('refundStockQuantities', [])
        ->set('refundStockReturn', 'all')
        ->call('openRefundModal')
        ->assertSet('refundStockReturn', 'none')
        ->assertSet('refundStockQuantities', []);
});

test('refunding with none moves no stock', function () {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->call('recordRefund')
        ->assertHasNoErrors()
        ->assertSet('showRefundModal', false);

    expect(Refund::query()->count())->toBe(1)
        ->and($fixture['mugProduct']->fresh()->stock)->toBe(10);
});

test('all returns every refunded unit of every stock-target line', function () {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2, $fixture['plate']->id => 1])
        ->set('refundStockReturn', 'all')
        ->call('recordRefund')
        ->assertHasNoErrors();

    expect($fixture['mugProduct']->fresh()->stock)->toBe(12)
        ->and($fixture['plateProduct']->fresh()->stock)->toBe(11)
        ->and($fixture['mug']->fresh()->refundedUnitsOutOfStock())->toBe(0);
});

test('partial returns the typed units, given as numeric strings', function () {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => '3'])
        ->set('refundStockReturn', 'partial')
        ->set('refundStockQuantities', [$fixture['mug']->id => '1'])
        ->call('recordRefund')
        ->assertHasNoErrors();

    expect($fixture['mugProduct']->fresh()->stock)->toBe(11)
        ->and($fixture['mug']->fresh()->refunded_quantity)->toBe(3);
});

test('partial clamps an amount above the refunded units silently', function () {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->set('refundStockReturn', 'partial')
        ->set('refundStockQuantities', [$fixture['mug']->id => '5'])
        ->call('recordRefund')
        ->assertHasNoErrors();

    expect($fixture['mugProduct']->fresh()->stock)->toBe(12);
});

test('partial ignores lines that are not refunded now and ids that are not on the order', function () {
    $fixture = refundStockOrder();
    $foreign = OrderItem::factory()->create();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->set('refundStockReturn', 'partial')
        ->set('refundStockQuantities', [
            $fixture['mug']->id => '1',
            $fixture['plate']->id => '1',
            $foreign->id => '1',
            'forged' => '4',
        ])
        ->call('recordRefund')
        ->assertHasNoErrors();

    expect($fixture['mugProduct']->fresh()->stock)->toBe(11)
        ->and($fixture['plateProduct']->fresh()->stock)->toBe(10);
});

test('partial with no unit in total is blocked and nothing is written', function (mixed $typed) {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->set('refundStockReturn', 'partial')
        ->set('refundStockQuantities', [$fixture['mug']->id => $typed])
        ->call('recordRefund')
        ->assertHasErrors('refund')
        ->assertSet('showRefundModal', true);

    expect(Refund::query()->count())->toBe(0)
        ->and($fixture['mugProduct']->fresh()->stock)->toBe(10);
})->with(['zero' => ['0'], 'empty' => [''], 'negative' => ['-3'], 'text' => ['abc'], 'array' => [['1']]]);

test('an unknown stock mode is refused, never treated as none', function () {
    $fixture = refundStockOrder();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->set('refundStockReturn', 'everything')
        ->call('recordRefund')
        ->assertHasErrors('refund');

    expect(Refund::query()->count())->toBe(0);
});

test('a backend refusal is shown in the modal and writes nothing', function () {
    $fixture = refundStockOrder();
    $fixture['mug']->forceFill(['stock_undeducted_quantity' => 4])->save();

    refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->set('refundStockReturn', 'all')
        ->call('recordRefund')
        ->assertHasErrors('refund')
        ->assertSee(__('orders.refunds.stock_return_not_out_of_stock'))
        ->assertSet('showRefundModal', true);

    expect(Refund::query()->count())->toBe(0)
        ->and($fixture['mugProduct']->fresh()->stock)->toBe(10);
});

test('a line changed concurrently renders a retry message instead of failing', function () {
    $fixture = refundStockOrder();

    $component = refundModalFor($fixture['order'])
        ->set('refundQuantities', [$fixture['mug']->id => 2])
        ->set('refundStockReturn', 'all');

    $this->mock(RecordRefund::class)
        ->shouldReceive('__invoke')->andThrow(new LogicException('changed'));

    $component->call('recordRefund')->assertHasErrors('refund')->assertSee(__('orders.refunds.changed_meanwhile'));

    expect(Refund::query()->count())->toBe(0);
});

test('the modal offers a stock input per refundable stock-target line and none for a deleted variant', function () {
    $fixture = refundStockOrder();
    $gone = OrderItem::factory()->for($fixture['order'])->orphanedVariant()->create(['quantity' => 2]);

    $html = refundModalFor($fixture['order'])->html();

    expect(OrdersUi::present($html, 'refund-stock-quantity-'.$fixture['mug']->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'refund-stock-quantity-'.$fixture['plate']->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'refund-stock-quantity-'.$gone->id))->toBeFalse()
        ->and(OrdersUi::present($html, 'refund-stock-untracked-'.$gone->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'refund-stock-return-partial'))->toBeTrue()
        ->and($html)->not->toContain('wire:model.live="refundQuantities');
});

test('the stock copy exists in both locales', function () {
    foreach (['stock_return_label', 'stock_none', 'stock_all', 'stock_partial', 'stock_partial_hint', 'stock_units_label', 'stock_partial_empty', 'stock_mode_invalid', 'changed_meanwhile', 'stock_untracked'] as $key) {
        expect(__('orders.refunds.'.$key, [], 'en'))->not->toBe('orders.refunds.'.$key)
            ->and(__('orders.refunds.'.$key, [], 'es'))->not->toBe('orders.refunds.'.$key);
    }
});
