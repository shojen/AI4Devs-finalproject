<?php

use App\Actions\Orders\CreateOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet -- every
// test below is expected to fail with "Target class [App\Actions\Orders\CreateOrder] does not
// exist" until backend-expert implements it. That failure is the correct, intended "red" outcome
// -- NOT a database/schema error, since Order/OrderItem/their migrations already exist.
//
// Payload shape assumed throughout (not pinned verbatim in the task file, but implied by its own
// acceptance criteria -- "the rejection is a ValidationException on `items`" and D-5's
// ['required','array','min:1'] on that same field): a flat array with `customer_id`,
// `payment_method_id`, and an `items` array of {product_id, product_variant_id?, quantity}. If
// Phase 3 lands on a differently-shaped payload, these tests must be updated to match -- the
// behavior they pin (not the exact key names) is the actual contract.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

test('an order with one line item persists both rows, with order_items.order_id pointing at the parent', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    expect(Order::count())->toBe(1)
        ->and(OrderItem::count())->toBe(1);

    $item = OrderItem::sole();
    expect($item->order_id)->toBe($order->id);
});

test('an order with three line items persists exactly three child rows', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $products = Product::factory()->count(3)->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => $products->map(fn (Product $product): array => [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->all(),
    ]);

    expect($order->items()->count())->toBe(3);
});

test('a line item naming a variant persists both product_id and product_variant_id', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1],
        ],
    ]);

    $item = $order->items()->sole();

    expect($item->product_id)->toBe($product->id)
        ->and($item->product_variant_id)->toBe($variant->id);
});

test('a new order status is Pending and payment_status is PendingPayment, read back as enum instances', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $fresh = $order->fresh();

    expect($fresh->status)->toBeInstanceOf(OrderStatus::class)
        ->and($fresh->status)->toBe(OrderStatus::Pending)
        ->and($fresh->payment_status)->toBeInstanceOf(PaymentStatus::class)
        ->and($fresh->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('order_number is non-empty, at most 20 characters, and differs from the order id', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $fresh = $order->fresh();

    expect($fresh->order_number)->not->toBeEmpty()
        ->and(mb_strlen($fresh->order_number))->toBeLessThanOrEqual(20)
        ->and($fresh->order_number)->not->toBe($fresh->id);
});

// D-1 names the format one of three "not negotiable" constraints, distinct from the "non-empty,
// short, not the id" assertion above -- a regression to any other short unique token would still
// pass that one. Phase 5 code review finding F-B.
test('order_number matches the ORD-{current year}-{six digit sequence} format D-1 requires', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    expect($order->fresh()->order_number)->toMatch('/^ORD-'.now()->year.'-\d{6}$/');
});

test('creating two orders in the same request produces two different order_number values', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $payload = fn (): array => [
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ];

    $first = app(CreateOrder::class)($payload());
    $second = app(CreateOrder::class)($payload());

    expect($first->order_number)->not->toBe($second->order_number);
});

test('an order references a configured payment method', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    expect($order->fresh()->payment_method_id)->toBe($paymentMethod->id);
});

// Phase 4 security audit finding F-5: D-12 explicitly allows an order against a soft-deleted
// customer, so their order history is never orphaned -- a plain Customer::query()->findOrFail()
// applies the default SoftDeletingScope and would refuse exactly the case D-12 means to allow.
// CreateOrder resolves the customer with withTrashed() for this reason.
test('an order can be created for a soft-deleted customer', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $customer->delete();

    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    expect(Order::count())->toBe(1)
        ->and($order->fresh()->customer_id)->toBe($customer->id);
});

// Phase 4 re-audit finding F-8: a blank string, not a real null, is what a `wire:model`-bound
// <select> with no selection submits (Livewire opts /livewire/update requests out of Laravel's
// ConvertEmptyStringsToNull middleware -- docs/errors-log.md's maxWeightKg entry is the identical
// mechanism). A bare `isset()` treats '' as present and would 404 a plain "no variant" line item
// once story 0055's form exists; it must resolve as an ordinary product line item instead.
test('a line item whose product_variant_id is a blank string resolves as a plain product, not a 404', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'product_variant_id' => '', 'quantity' => 1],
        ],
    ]);

    $item = $order->items()->sole();

    expect($item->product_id)->toBe($product->id)
        ->and($item->product_variant_id)->toBeNull();
});

// Phase 4 re-audit finding F-6: `total` must be the FULL `subtotal + tax_amount + shipping_amount`
// identity, computed with bcmath, not a bare assignment from `subtotal` that only reads correctly
// today because the other two terms happen to be zero.
test('a new order total equals subtotal plus tax_amount plus shipping_amount, computed rather than copied', function () {
    actingOrderCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['price' => '12.34']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ]);

    $fresh = $order->fresh();

    expect((string) $fresh->subtotal)->toBe('24.68')
        ->and((string) $fresh->tax_amount)->toBe('0.00')
        ->and((string) $fresh->shipping_amount)->toBe('0.00')
        ->and((string) $fresh->total)->toBe('24.68');
});
