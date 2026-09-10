<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\NotifyCustomerCreated;
use App\Actions\Customers\UpdateCustomer;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0043 -- exercised through story 0041's real CreateCustomer/UpdateCustomer actions, per
// this story's own "driven through 0041's create path" test plan. D-5 (customer_id/customer_name
// only, no email) and D-1 (Super Admin excluded) are pinned here rather than only in
// NotifyCustomerCreatedTest.php, since this file is what proves the WIRING, not only the rule.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function customerCreatorActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.create');

    return $actor;
}

test('creating a customer sends CustomerCreated to each eligible administrator', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $bystander = User::factory()->create();
    $bystander->givePermissionTo('products.view');

    $this->actingAs(customerCreatorActor());

    Notification::fake();

    app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    Notification::assertSentTo($recipient, CustomerCreated::class);
    Notification::assertNotSentTo($bystander, CustomerCreated::class);
});

// R-1 -- the highest-severity risk in this story: Notification::assertSentTo passes against a
// broken `morphs()`-shaped `notifiable_id` column, because it never touches the database. This
// is the mandatory second, un-faked test that is the only thing that actually catches that
// defect.
test('a real notifications row is stored, with notifiable_id equal to the recipient UUID and type equal to CustomerCreated::class', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $this->actingAs(customerCreatorActor());

    app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    $row = DatabaseNotification::query()
        ->where('notifiable_type', $recipient->getMorphClass())
        ->where('notifiable_id', $recipient->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->notifiable_id)->toBe($recipient->id)
        ->and($row->type)->toBe(CustomerCreated::class);
});

test('the stored payload carries exactly customer_id and customer_name, matching the created record', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $this->actingAs(customerCreatorActor());

    $customer = app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    $row = DatabaseNotification::query()
        ->where('notifiable_id', $recipient->id)
        ->where('type', CustomerCreated::class)
        ->firstOrFail();

    expect(array_keys($row->data))->toBe(['customer_id', 'customer_name'])
        ->and($row->data['customer_id'])->toBe($customer->id)
        ->and($row->data['customer_name'])->toBe('Ana Garcia');
});

test('read_at is null on a freshly stored notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $this->actingAs(customerCreatorActor());

    app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    $row = DatabaseNotification::query()->where('notifiable_id', $recipient->id)->firstOrFail();

    expect($row->read_at)->toBeNull();
});

// This test covers the task file's "rejected by duplicate-email validation" scenario. NOTE it does
// NOT reach CreateCustomer's QueryException/23000 catch: App\Concerns\CustomerValidationRules::
// customerEmailRules() carries Rule::unique(Customer::class), so a duplicate email is refused by
// the VALIDATOR (a ValidationException from Validator::make(...)->validate(), before
// Customer::create() is ever called) -- the catch block is a race-condition backstop, reachable
// only when two concurrent requests both pass Rule::unique() before either commits, which this
// sequential test cannot exercise. See the separate "fails after the row is written" test below
// for the task file's distinct "rolled back" scenario (code-reviewer finding F-1).
test('a creation rejected by duplicate-email validation stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    // The pre-existing colliding row is seeded via the model factory, not
    // CreateCustomer -- so its own (real) dispatch never happens, and
    // Notification::fake() below observes ONLY the rejected attempt under
    // test, matching AuthorizationTest.php's own "created via the model
    // factory, which has no authorization concern of its own" convention.
    Customer::factory()->withEmail('duplicado@example.com')->create();

    $this->actingAs(customerCreatorActor());

    Notification::fake();

    try {
        app(CreateCustomer::class)(['name' => 'Segundo', 'email' => 'duplicado@example.com']);
    } catch (ValidationException) {
        // expected
    }

    Notification::assertNothingSent();
    expect(DatabaseNotification::query()->count())->toBe(0);
});

// The task file's own "fails to persist / rolled back" scenario: CreateCustomer performs one
// non-transactional INSERT (no surrounding DB::transaction()), so the regression this test pins
// is narrower than a real rollback -- it proves that ANY failure occurring after Customer::create()
// starts but before NotifyCustomerCreated's dispatch line is reached stores no notification, which
// is exactly the guard the task file's own test plan describes: "without it, a dispatch moved
// inside the transaction -- or before it -- passes every other test here." A `Customer::created`
// model-event listener that throws is the cheapest way to force a failure strictly AFTER the row
// is written and strictly BEFORE CreateCustomer's own dispatch line runs (code-reviewer F-1).
test('a creation that fails after the customer row is written stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $this->actingAs(customerCreatorActor());

    Notification::fake();

    Customer::created(function (): void {
        throw new RuntimeException('forced post-insert failure (test only)');
    });

    try {
        app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'post-insert-failure@example.com']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('forced post-insert failure (test only)');
    }

    // The row itself DOES persist (there is no transaction to roll it back) -- what this test
    // pins is that the notification dispatch, which sits after the create() call, never ran.
    expect(Customer::where('email', 'post-insert-failure@example.com')->exists())->toBeTrue();
    Notification::assertNothingSent();
    expect(DatabaseNotification::query()->count())->toBe(0);
});

test('a creation refused by authorization stores no notification', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view'); // holds .view, not .create
    $this->actingAs($actor);

    Notification::fake();

    try {
        app(CreateCustomer::class)(['name' => 'Cliente', 'email' => 'rechazado@example.com']);
    } catch (AuthorizationException) {
        // expected
    }

    Notification::assertNothingSent();
    expect(Customer::count())->toBe(0);
});

test('editing an existing customer stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $customer = Customer::factory()->create();

    $editor = User::factory()->create();
    $editor->givePermissionTo('customers.edit');
    $this->actingAs($editor);

    Notification::fake();

    app(UpdateCustomer::class)($customer, array_merge(
        $customer->only(['name', 'email']),
        ['shipping_city' => 'Valencia'],
    ));

    Notification::assertNothingSent();
});

// No DeleteCustomer action exists in this codebase state (story 0042, soft-delete, is a separate,
// not-yet-merged worktree) -- nothing in this story wires ANY delete path to NotifyCustomerCreated
// in the first place, so this proves the negative directly at the model layer rather than through
// an action that does not exist here yet.
test('deleting a customer stores no notification', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    $customer = Customer::factory()->create();

    Notification::fake();

    $customer->delete();

    Notification::assertNothingSent();
});

test('multiple eligible recipients each get their own notifications row, not one shared row', function () {
    $recipients = User::factory()->count(3)->create();
    $recipients->each(fn (User $user) => $user->givePermissionTo('customers.view'));

    $this->actingAs(customerCreatorActor());

    app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    expect(DatabaseNotification::query()->where('type', CustomerCreated::class)->count())->toBe(3);

    foreach ($recipients as $recipient) {
        expect(DatabaseNotification::query()->where('notifiable_id', $recipient->id)->count())->toBe(1);
    }
});

test('a Super Admin is not notified of routine customer creation, even though they can create the customer themselves', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    Notification::fake();

    app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    Notification::assertNotSentTo($superAdmin, CustomerCreated::class);
});

test('a creation with zero eligible recipients still succeeds', function () {
    $this->actingAs(customerCreatorActor());

    $customer = app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    expect($customer->fresh())->not->toBeNull()
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

// Phase 4 security-audit finding F-1: a notification-dispatch failure must not turn an
// already-persisted, already-committed customer into a failed request -- the customer is the
// user-visible operation, the notification has no reader anywhere in the app yet (R-3/OQ-3), and
// there is no surrounding transaction for a throw here to roll back anyway.
test('a notification dispatch failure does not fail the creation, and is logged rather than swallowed silently', function () {
    $this->actingAs(customerCreatorActor());

    $spy = Mockery::mock(NotifyCustomerCreated::class);
    $spy->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('forced dispatch failure (test only)'));
    $this->instance(NotifyCustomerCreated::class, $spy);

    Log::shouldReceive('warning')
        ->once()
        ->with('CustomerCreated notification dispatch failed', Mockery::on(
            fn (array $context): bool => $context['exception'] === RuntimeException::class && is_string($context['customer_id']),
        ));

    $customer = app(CreateCustomer::class)(['name' => 'Ana Garcia', 'email' => 'ana.garcia@example.com']);

    expect($customer->fresh())->not->toBeNull()
        ->and(Customer::where('email', 'ana.garcia@example.com')->exists())->toBeTrue();
});
