<?php

use App\Actions\Orders\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0051, Phase 3 (TDD "red" step): App\Actions\Orders\RecordRefund does not exist yet --
// every test below is expected to fail until backend-expert implements it.
//
// D-4/D-6/D-12's ordering (authorize -> validate shape -> transaction -> lock -> payment-state
// guard -> ownership guard -> over-refund guard -> write -> derive) is what several tests below
// are actually pinning, not merely the individual refusals in isolation.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderRefunder(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.refund');
    test()->actingAs($actor);

    return $actor;
}

/**
 * A Paid order with a single line item at the given price/quantity.
 */
function paidOrderWithItem(string $price = '10.00', int $quantity = 3): OrderItem
{
    $order = Order::factory()->paid()->create();
    $product = Product::factory()->create(['price' => $price]);

    return OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => $quantity]);
}

// --- Recording a refund — happy paths ---

test('a full refund of a single-line order sets payment_status to Refunded, refunded_quantity to the items full quantity, writes one refunds row, and sets refunded_amount to the line total', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 3);
    $order = $item->order;

    $result = app(RecordRefund::class)($order, [$item->id => 3]);

    expect($result->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($item->fresh()->refunded_quantity)->toBe(3)
        ->and(Refund::where('order_item_id', $item->id)->count())->toBe(1)
        ->and((string) $order->fresh()->refunded_amount)->toBe('30.00');
});

test('a partial refund (2 of 5) sets PartiallyRefunded, refunded_quantity to 2, and refunded_amount to 2 x unit_price', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 5);
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => 2]);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($item->fresh()->refunded_quantity)->toBe(2)
        ->and((string) $order->fresh()->refunded_amount)->toBe('20.00');
});

test('a two-line order with the first line fully refunded and the second untouched reads PartiallyRefunded', function () {
    actingOrderRefunder();
    $order = Order::factory()->paid()->create();
    $productA = Product::factory()->create(['price' => '10.00']);
    $productB = Product::factory()->create(['price' => '5.00']);
    $itemA = OrderItem::factory()->for($order)->create(['product_id' => $productA->id, 'quantity' => 2]);
    $itemB = OrderItem::factory()->for($order)->create(['product_id' => $productB->id, 'quantity' => 4]);

    app(RecordRefund::class)($order, [$itemA->id => 2]);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($itemA->fresh()->refunded_quantity)->toBe(2)
        ->and($itemB->fresh()->refunded_quantity)->toBe(0);
});

// Derivation property (a): a rule phrased as "some but not all lines have units returned" leaves
// this case unclassified -- an order where EVERY line is partially refunded must read
// PartiallyRefunded, not Refunded.
test('a two-line order with both lines partially refunded reads PartiallyRefunded, not Refunded', function () {
    actingOrderRefunder();
    $order = Order::factory()->paid()->create();
    $productA = Product::factory()->create(['price' => '10.00']);
    $productB = Product::factory()->create(['price' => '5.00']);
    $itemA = OrderItem::factory()->for($order)->create(['product_id' => $productA->id, 'quantity' => 4]);
    $itemB = OrderItem::factory()->for($order)->create(['product_id' => $productB->id, 'quantity' => 4]);

    app(RecordRefund::class)($order, [$itemA->id => 1, $itemB->id => 1]);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});

test('a further refund from PartiallyRefunded is accepted', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 5);
    $order = $item->order;
    app(RecordRefund::class)($order, [$item->id => 2]);

    app(RecordRefund::class)($order->fresh(), [$item->id => 1]);

    expect($item->fresh()->refunded_quantity)->toBe(3)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});

// Derivation property (b): the boundary case a delta-based implementation gets wrong.
test('refunding the last outstanding unit of a PartiallyRefunded order moves it to Refunded within the same call', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 5);
    $order = $item->order;
    app(RecordRefund::class)($order, [$item->id => 4]);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);

    app(RecordRefund::class)($order->fresh(), [$item->id => 1]);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($item->fresh()->refunded_quantity)->toBe(5);
});

test('one call naming units from three line items writes three refunds rows and increments all three refunded_quantity values', function () {
    actingOrderRefunder();
    $order = Order::factory()->paid()->create();
    $items = collect(range(1, 3))->map(function (int $i) use ($order): OrderItem {
        $product = Product::factory()->create(['price' => '10.00']);

        return OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);
    });

    app(RecordRefund::class)($order, $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => 1])->all());

    foreach ($items as $item) {
        expect($item->fresh()->refunded_quantity)->toBe(1);
    }
    expect(Refund::count())->toBe(3);
});

test('the refunds row records refunded_by as the acting user', function () {
    $actor = actingOrderRefunder();
    $item = paidOrderWithItem();
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => 1]);

    expect(Refund::sole()->refunded_by)->toBe($actor->id);
});

test('amount equals quantity x unit_price as a decimal string, for quantity 1 and quantity 3', function (int $quantity, string $expectedAmount) {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 3);
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => $quantity]);

    expect((string) Refund::sole()->amount)->toBe($expectedAmount);
})->with([
    'quantity 1' => [1, '10.00'],
    'quantity 3' => [3, '30.00'],
]);

test('two successive refunds against the same line item produce two refunds rows with distinct created_at values', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 5);
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => 1]);
    $this->travel(1)->seconds();
    app(RecordRefund::class)($order->fresh(), [$item->id => 1]);

    $refunds = Refund::where('order_item_id', $item->id)->orderBy('created_at')->get();

    expect($refunds)->toHaveCount(2)
        ->and($refunds[0]->created_at->ne($refunds[1]->created_at))->toBeTrue();
});

// Asserted after a three-call sequence, not after one.
test('after any number of refunds, each items refunded_quantity equals the sum of its refunds rows quantities, and refunded_amount equals the sum of all refunds amounts', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 10);
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => 2]);
    app(RecordRefund::class)($order->fresh(), [$item->id => 3]);
    app(RecordRefund::class)($order->fresh(), [$item->id => 1]);

    $sumQuantity = Refund::where('order_item_id', $item->id)->sum('quantity');
    $sumAmount = Refund::where('order_item_id', $item->id)->get()->reduce(
        fn (string $carry, Refund $refund): string => bcadd($carry, (string) $refund->amount, 2),
        '0.00',
    );

    expect($item->fresh()->refunded_quantity)->toBe((int) $sumQuantity)
        ->and((string) $order->fresh()->refunded_amount)->toBe($sumAmount);
});

// --- Recording a refund — refusals ---

test('a refund is refused with a ValidationException while payment_status is PendingPayment or Refunded, and nothing is recorded', function (PaymentStatus $status) {
    actingOrderRefunder();
    $order = Order::factory()->create(['payment_status' => $status]);
    $product = Product::factory()->create(['price' => '10.00']);
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 1]))
        ->toThrow(ValidationException::class);

    expect(Refund::count())->toBe(0)
        ->and($item->fresh()->refunded_quantity)->toBe(0)
        ->and($order->fresh()->payment_status)->toBe($status);
})->with([
    'PendingPayment' => [PaymentStatus::PendingPayment],
    'Refunded' => [PaymentStatus::Refunded],
]);

test('an invalid quantity is refused and nothing is written', function (mixed $quantity) {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 3);
    $order = $item->order;

    expect(fn () => app(RecordRefund::class)($order, [$item->id => $quantity]))
        ->toThrow(ValidationException::class);

    expect(Refund::count())->toBe(0)
        ->and($item->fresh()->refunded_quantity)->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'fractional' => [1.5],
    'non-numeric' => ['abc'],
]);

test('an empty items array is refused with a ValidationException on items', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem();
    $order = $item->order;

    try {
        app(RecordRefund::class)($order, []);
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('items');
    }
});

test('an over-refund against a pristine line is refused', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 3);
    $order = $item->order;

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 4]))
        ->toThrow(ValidationException::class);

    expect(Refund::count())->toBe(0)
        ->and($item->fresh()->refunded_quantity)->toBe(0);
});

// The guard's real trigger: a guard written against the original `quantity` alone passes the
// previous test and fails only here, once the line has already been partially refunded (D-6).
test('an over-refund against a line that already carries a partial refund is refused', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 5);
    $order = $item->order;
    app(RecordRefund::class)($order, [$item->id => 3]);

    expect(fn () => app(RecordRefund::class)($order->fresh(), [$item->id => 3]))
        ->toThrow(ValidationException::class);

    expect(Refund::where('order_item_id', $item->id)->count())->toBe(1)
        ->and($item->fresh()->refunded_quantity)->toBe(3);
});

// The positive boundary beside it: without this the guard could be off by one in the safe
// direction and nothing would notice.
test('refunding exactly the remaining outstanding units on an already-partially-refunded line is accepted, and the order flips to Refunded', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 5);
    $order = $item->order;
    app(RecordRefund::class)($order, [$item->id => 3]);

    app(RecordRefund::class)($order->fresh(), [$item->id => 2]);

    expect($item->fresh()->refunded_quantity)->toBe(5)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('refunding order A while naming order Bs order_item_id is refused, and neither order records anything', function () {
    actingOrderRefunder();
    $itemA = paidOrderWithItem('10.00', 3);
    $orderA = $itemA->order;
    $itemB = paidOrderWithItem('10.00', 3);
    $orderB = $itemB->order;

    expect(fn () => app(RecordRefund::class)($orderA, [$itemB->id => 1]))
        ->toThrow(ValidationException::class);

    expect(Refund::count())->toBe(0)
        ->and($itemA->fresh()->refunded_quantity)->toBe(0)
        ->and($itemB->fresh()->refunded_quantity)->toBe(0)
        ->and($orderA->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($orderB->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('an order_item_id matching no row at all is refused', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem();
    $order = $item->order;

    expect(fn () => app(RecordRefund::class)($order, ['00000000-0000-7000-8000-000000000000' => 1]))
        ->toThrow(ValidationException::class);

    expect(Refund::count())->toBe(0);
});

// Asserting only "the refund failed" would pass against an implementation that writes the first
// two rows and then throws -- this test asserts every affected row and column is untouched.
test('a three-item refund whose third item exceeds its outstanding units writes nothing anywhere', function () {
    actingOrderRefunder();
    $order = Order::factory()->paid()->create();
    $product = Product::factory()->create(['price' => '10.00']);
    $itemA = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);
    $itemB = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);
    $itemC = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);
    $refundedAmountBefore = (string) $order->fresh()->refunded_amount;

    expect(fn () => app(RecordRefund::class)($order, [
        $itemA->id => 1,
        $itemB->id => 1,
        $itemC->id => 4,
    ]))->toThrow(ValidationException::class);

    expect(Refund::count())->toBe(0)
        ->and($itemA->fresh()->refunded_quantity)->toBe(0)
        ->and($itemB->fresh()->refunded_quantity)->toBe(0)
        ->and($itemC->fresh()->refunded_quantity)->toBe(0)
        ->and((string) $order->fresh()->refunded_amount)->toBe($refundedAmountBefore)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

// --- Authorization ---

test('an administrator holding orders.edit and orders.view but not orders.refund is refused, and nothing is written', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.edit', 'orders.view']);
    test()->actingAs($actor);
    $item = paidOrderWithItem();
    $order = $item->order;

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 1]))
        ->toThrow(AuthorizationException::class);

    expect(Refund::count())->toBe(0)
        ->and($item->fresh()->refunded_quantity)->toBe(0);
});

test('an administrator holding orders.refund but not orders.edit succeeds', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.refund');
    test()->actingAs($actor);
    $item = paidOrderWithItem();
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => 1]);

    expect($item->fresh()->refunded_quantity)->toBe(1);
});

test('a Super Admin holding no individual orders permission succeeds via Gate::before', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);
    $item = paidOrderWithItem();
    $order = $item->order;

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    app(RecordRefund::class)($order, [$item->id => 1]);

    expect($item->fresh()->refunded_quantity)->toBe(1);
});

// The permission refusal always wins: an unauthorized caller must not learn the order's payment
// state from a validation refusal.
test('a missing permission is refused before the orders payment state is considered', function () {
    $actor = User::factory()->create();
    test()->actingAs($actor);
    $item = paidOrderWithItem();
    $order = $item->order;
    $order->forceFill(['payment_status' => PaymentStatus::Refunded])->save();

    try {
        app(RecordRefund::class)($order, [$item->id => 1]);
        test()->fail('Expected an AuthorizationException.');
    } catch (ValidationException $e) {
        test()->fail('The permission refusal must win over the payment-state refusal.');
    } catch (AuthorizationException $e) {
        expect($e)->toBeInstanceOf(AuthorizationException::class);
    }
});

// --- What this story deliberately leaves alone ---

test('a full refund leaves orders.status unchanged, including for a shipped order', function () {
    actingOrderRefunder();
    $item = paidOrderWithItem('10.00', 3);
    $order = $item->order;
    $order->forceFill(['status' => OrderStatus::Shipped])->save();

    app(RecordRefund::class)($order->fresh(), [$item->id => 3]);

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

// No bare Event::fake()/assertNothingDispatched() here -- Eloquent's own internal model
// lifecycle events (eloquent.created, eloquent.saved, ...) fire on every write this action makes
// regardless, so that assertion would fail for reasons unrelated to what this test actually
// checks. This repo's own convention (tests/Feature/Auth/EmailVerificationTest.php,
// tests/Feature/Settings/EmailChangeTest.php) is Event::assertNotDispatched(SpecificClass) --
// but this story ships no event class at all (OQ-2's OrderFullyRefunded is 0052's), so there is
// no candidate to name. Notification::assertNothingSent() is the real, checkable half of the
// scope fence.
test('recording a refund dispatches no notification', function () {
    Notification::fake();
    actingOrderRefunder();
    $item = paidOrderWithItem();
    $order = $item->order;

    app(RecordRefund::class)($order, [$item->id => 1]);

    Notification::assertNothingSent();
});
