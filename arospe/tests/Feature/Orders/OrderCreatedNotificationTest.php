<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderCreated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0046 -- exercised through story 0045's real CreateOrder, per this story's own "driven
// through CreateOrder" test plan. Shape-copies
// tests/Feature/Customers/CustomerCreatedNotificationTest.php (story 0043), which this story's own
// task file names as the reference to mirror wholesale. D-5 (order_id/order_number/customer_name
// only) and D-1 (Super Admin excluded) are pinned here rather than only in
// NotifyOrderCreatedTest.php, since this file is what proves the WIRING, not only the rule.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function orderNotificationCreatorActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

/**
 * A distinct name from tests/Feature/Orders/AuthorizationTest.php's own `validOrderPayload()`
 * (which takes no arguments and builds its own rows) -- this one takes already-resolved rows so a
 * test can inspect/soft-delete the customer before creating the order.
 *
 * @return array<string, mixed>
 */
function orderNotificationPayload(Customer $customer, PaymentMethod $paymentMethod, Product $product): array
{
    return [
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ];
}

test('creating an order sends OrderCreated to each eligible administrator', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    $bystander = User::factory()->create();
    $bystander->givePermissionTo('products.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    Notification::fake();

    app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    Notification::assertSentTo($recipient, OrderCreated::class);
    Notification::assertNotSentTo($bystander, OrderCreated::class);
});

// R-1's equivalent for this story -- the highest-severity risk shape 0043 already established:
// Notification::assertSentTo passes against a broken notifiable_id column, because it never
// touches the database. This is the mandatory second, un-faked test that is the only thing that
// actually catches that class of defect for a second consumer of the notifications table.
test('a real notifications row is stored, with notifiable_id equal to the recipient UUID and type equal to OrderCreated::class', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    $row = DatabaseNotification::query()
        ->where('notifiable_type', $recipient->getMorphClass())
        ->where('notifiable_id', $recipient->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->notifiable_id)->toBe($recipient->id)
        ->and($row->type)->toBe(OrderCreated::class);
});

// D-5: exactly three keys, no more -- pinning the exact key set so the deliberate exclusion of the
// total, the line items and every customer field beyond the name cannot be silently reversed.
test('the stored payload carries exactly order_id, order_number and customer_name, matching the created record', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create(['name' => 'Ana Garcia']);
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    $row = DatabaseNotification::query()
        ->where('notifiable_id', $recipient->id)
        ->where('type', OrderCreated::class)
        ->firstOrFail();

    expect(array_keys($row->data))->toBe(['order_id', 'order_number', 'customer_name'])
        ->and($row->data['order_id'])->toBe($order->id)
        ->and($row->data['customer_name'])->toBe('Ana Garcia');
});

// Dispatch-site constraint 2 (R-2): a notification dispatched before 0045's order_number retry
// loop settles would snapshot a value the retry then changed -- and since the payload is an
// immutable JSON column with no update path (D-5), the wrong value would be permanent.
test('the stored order_number equals the persisted order\'s order_number and is not empty', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    $row = DatabaseNotification::query()
        ->where('notifiable_id', $recipient->id)
        ->where('type', OrderCreated::class)
        ->firstOrFail();

    expect($row->data['order_number'])->not->toBeEmpty()
        ->and($row->data['order_number'])->toBe($order->fresh()->order_number);
});

test('read_at is null on a freshly stored notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    $row = DatabaseNotification::query()->where('notifiable_id', $recipient->id)->firstOrFail();

    expect($row->read_at)->toBeNull();
});

// The Gherkin's "rejected with a validation message" scenario -- a zero-line-item payload is
// refused by CreateOrder's own OrderValidationRules before any row is written, so no dispatch can
// ever be reached.
test('a creation rejected by validation (zero line items) stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();

    Notification::fake();

    try {
        app(CreateOrder::class)([
            'customer_id' => $customer->id,
            'payment_method_id' => $paymentMethod->id,
            'items' => [],
        ]);
    } catch (ValidationException) {
        // expected
    }

    Notification::assertNothingSent();
    expect(DatabaseNotification::query()->count())->toBe(0)
        ->and(Order::count())->toBe(0);
});

test('a creation refused by authorization (orders.create absent) stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    // Holds .view, not .create -- refused by OrderPolicy::create().
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    Notification::fake();

    try {
        app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));
    } catch (AuthorizationException) {
        // expected
    }

    Notification::assertNothingSent();
    expect(Order::count())->toBe(0);
});

// R-1 -- the highest-value test in this story, per the task file's own risk analysis: it pins
// dispatch-site constraint 1 (after the transaction commits, never inside it). Unlike story 0043's
// CreateCustomer (a single non-transactional INSERT), CreateOrder wraps its whole write in
// DB::transaction() -- forcing a failure strictly AFTER the orders row is written (via an
// OrderItem::creating() listener, which fires on the item insert immediately following it, still
// inside the same transaction) rolls the ENTIRE transaction back, orders row included. Without this
// test, a dispatch placed inside the transaction -- or before it -- would still pass every other
// test in this file, since none of the others force a mid-transaction failure.
test('a creation that fails inside the transaction after the orders row is written stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    Notification::fake();

    OrderItem::creating(function (): void {
        throw new RuntimeException('forced post-order-insert failure inside the transaction (test only)');
    });

    try {
        app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('forced post-order-insert failure inside the transaction (test only)');
    }

    // The orders row briefly existed inside the transaction but is rolled back along with
    // everything else -- proving the failure genuinely landed after a real write, not before one.
    expect(Order::count())->toBe(0);
    Notification::assertNothingSent();
    expect(DatabaseNotification::query()->count())->toBe(0);
});

// F-1 (Phase 2 INVEST gap this story's own dispatcher must close): D-12 explicitly allows an order
// against a soft-deleted customer, so their order history is never orphaned -- the notification
// dispatch must not fatal, and customer_name must still be the real name at order time, not null
// and not an exception. Fails until NotifyOrderCreated resolves the customer relation with
// withTrashed(), matching CreateOrder's own resolution (F-5 there).
test('an order for a soft-deleted customer still stores a notification with the correct customer_name', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create(['name' => 'Ana Garcia']);
    $customer->delete();

    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    $row = DatabaseNotification::query()
        ->where('notifiable_id', $recipient->id)
        ->where('type', OrderCreated::class)
        ->firstOrFail();

    expect($row->data['order_id'])->toBe($order->id)
        ->and($row->data['customer_name'])->toBe('Ana Garcia');
});

test('multiple eligible recipients each get their own notifications row, not one shared row', function () {
    $recipients = User::factory()->count(3)->create();
    $recipients->each(fn (User $user) => $user->givePermissionTo('orders.view'));

    orderNotificationCreatorActor();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    expect(DatabaseNotification::query()->where('type', OrderCreated::class)->count())->toBe(3);

    foreach ($recipients as $recipient) {
        expect(DatabaseNotification::query()->where('notifiable_id', $recipient->id)->count())->toBe(1);
    }
});

test('a Super Admin is not notified of routine order creation, even though they can create the order themselves', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    Notification::fake();

    app(CreateOrder::class)(orderNotificationPayload($customer, $paymentMethod, $product));

    Notification::assertNotSentTo($superAdmin, OrderCreated::class);
});
