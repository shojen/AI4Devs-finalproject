<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet -- every
// test below is expected to fail with "Target class [App\Actions\Orders\CreateOrder] does not
// exist" until backend-expert implements it.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingValidationCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

// D-5: an order with zero line items is rejected, and leaves both tables empty.
test('an order with zero line items is rejected with a ValidationException on items and leaves both tables empty', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('a line item quantity of zero, negative, non-integer or non-numeric is rejected, leaving no rows', function (mixed $badQuantity) {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => $badQuantity],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'fractional' => [1.5],
    'non-numeric' => ['abc'],
]);

test('an order naming no customer is rejected, leaving no rows', function () {
    actingValidationCreator();

    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('an order naming an unknown customer is rejected, leaving no rows', function () {
    actingValidationCreator();

    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => (string) Str::uuid(),
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('an order naming no payment method is rejected, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('an order naming an unknown payment method is rejected, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => (string) Str::uuid(),
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('a line item naming a product that does not exist is rejected, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => (string) Str::uuid(), 'quantity' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// Phase 4 security audit finding F-1 (Medium-High): a line item naming product A's id together
// with a DIFFERENT product B's variant id must be refused -- not silently accepted with product A's
// name at product B's price. The two products are deliberately given different prices so a wrong
// implementation (one that resolves the variant independently, ignoring which product it belongs
// to) would otherwise silently succeed at the wrong price.
test('a line item pairing one product id with a DIFFERENT product own variant id is rejected, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $expensiveProduct = Product::factory()->create(['price' => '2000.00']);
    $cheapProduct = Product::factory()->create(['price' => '5.00']);
    $cheapVariant = ProductVariant::factory()->for($cheapProduct)->create(['price' => '5.00']);

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            [
                'product_id' => $expensiveProduct->id,
                'product_variant_id' => $cheapVariant->id,
                'quantity' => 1,
            ],
        ],
    ]))->toThrow(ModelNotFoundException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// Atomicity: an order whose SECOND line item is invalid must write NO orders row and NO
// order_items row -- never a parent row orphaned by a failed second child. Both tables are
// asserted empty, not merely "the order was not created", per the task file's own instruction.
// Phase 5 code review finding F-D: today every rejection in this test throws BEFORE
// DB::transaction() ever opens (validation, then catalog resolution), so the shipped code makes
// this unreachable by construction rather than by a rollback -- this test still earns its place as
// the executable proof of the invariant, but a later story that moves resolution INSIDE the
// transaction must not assume this test still exercises a real rollback without re-checking it.
test('an order whose second line item is invalid writes no orders row and no order_items row', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $validProduct = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $validProduct->id, 'quantity' => 1],
            ['product_id' => (string) Str::uuid(), 'quantity' => 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// Phase 4 security audit finding F-2a: an `items` array over
// App\Concerns\OrderValidationRules::MAX_ITEMS is rejected by the EARLY, separate shape-only
// validation pass -- before a single per-item Rule::exists() query runs. One valid product is
// reused across every item so the fixture cost stays flat regardless of MAX_ITEMS' own value.
test('an items array over MAX_ITEMS is rejected, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $items = array_fill(0, CreateOrder::MAX_ITEMS + 1, [
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => $items,
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// Phase 4 security audit finding F-3 (part 1): a line item quantity over
// App\Concerns\OrderValidationRules::MAX_ITEM_QUANTITY is rejected by ordinary validation --
// closing the obvious runaway-quantity case before arithmetic ever runs.
test('a line item quantity over MAX_ITEM_QUANTITY is rejected, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => CreateOrder::MAX_ITEM_QUANTITY + 1],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// Phase 4 security audit finding F-3 (part 2): a quantity within MAX_ITEM_QUANTITY, paired with a
// near-maximum price, can still drive line_total past the decimal(10,2) column ceiling --
// CreateOrder::assertWithinColumnCeiling() is the residual guard that catches exactly this case,
// which the quantity cap alone cannot.
test('a line item whose price times quantity would exceed the decimal column ceiling is rejected with a ValidationException, leaving no rows', function () {
    actingValidationCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    // The maximum a `decimal(10,2)` products.price column can hold.
    $product = Product::factory()->create(['price' => '99999999.99']);

    expect(fn () => app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});
