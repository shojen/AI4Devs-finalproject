<?php

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Policies\OrderPolicy does not exist yet -- every test
// below is expected to fail until backend-expert implements it (with no registered policy, Gate
// denies every ability by default, so an "allowed" assertion fails rather than throwing "class not
// found" -- the correct RED outcome for a policy test, matching how a missing policy behaves).
//
// D-13: four flat abilities (viewAny/create/update/delete), no per-target rule on any of them,
// modelled on ShippingRatePolicy -- this file's shape is copied from
// tests/Feature/Policies/ShippingRatePolicyTest.php almost verbatim. `create` is the only ability
// with a real caller in this story (CreateOrder); the other three are tested here precisely
// because nothing else exercises them yet (per the task file's own instruction).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('viewAny is allowed for an actor holding orders.view and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('orders.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', Order::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', Order::class))->toBeFalse();
});

test('create is allowed for an actor holding orders.create and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('orders.create');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('create', Order::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('create', Order::class))->toBeFalse();
});

test('update is allowed for an actor holding orders.edit and denied for one without it', function () {
    $target = Order::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('orders.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding orders.delete and denied for one without it', function () {
    $target = Order::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('orders.delete');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('delete', $target))->toBeFalse();
});

// Server-side enforcement -- not merely allows() returning false, per
// docs/testing/qa/what-not-to-test.md's authorization rule.
test('Gate::forUser denies each ability by throwing AuthorizationException, never merely returning false', function () {
    $target = Order::factory()->create();
    $deniedActor = User::factory()->create();

    expect(fn () => Gate::forUser($deniedActor)->authorize('viewAny', Order::class))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Gate::forUser($deniedActor)->authorize('create', Order::class))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Gate::forUser($deniedActor)->authorize('update', $target))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Gate::forUser($deniedActor)->authorize('delete', $target))
        ->toThrow(AuthorizationException::class);
});

test('a Super Admin actor passes every OrderPolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = Order::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', Order::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', Order::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});

// The permission strings are asserted against RolePermissionSeeder's seeded catalog -- a
// permission string not in the catalog throws PermissionDoesNotExist at runtime, so this is a
// correctness test, not a style one. No new permission and no RolePermissionSeeder change.
test('the four permission strings OrderPolicy gates on are all in the seeded orders module', function () {
    expect(in_array('orders', RolePermissionSeeder::MODULES, true))->toBeTrue();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.create', 'orders.edit', 'orders.delete']);

    expect($actor->getAllPermissions())->toHaveCount(4);
});

// One public const <VERB>_PERMISSION per ability, named once on the class that owns the rule --
// naming.md's permission-naming convention.
test('OrderPolicy exposes one VERB_PERMISSION constant per ability, matching the seeded catalog', function () {
    expect(OrderPolicy::VIEW_PERMISSION)->toBe('orders.view')
        ->and(OrderPolicy::CREATE_PERMISSION)->toBe('orders.create')
        ->and(OrderPolicy::EDIT_PERMISSION)->toBe('orders.edit')
        ->and(OrderPolicy::DELETE_PERMISSION)->toBe('orders.delete');
});

// --- Story 0049, Phase 3 (TDD "red" step): transitionStatus() does not exist on OrderPolicy yet --
// every test below is expected to fail (Gate::forUser()->allows() returns false for an unknown
// ability rather than throwing, so the "allowed" half of each assertion below is what goes red;
// the "through the action" test fails with "Target class [TransitionOrderStatus] does not exist")
// until backend-expert adds the ability and the action. D-7: it reuses the EXISTING EDIT_PERMISSION
// constant -- no new permission, no RolePermissionSeeder change.

test('transitionStatus is allowed for an actor holding orders.edit and denied for one without it', function () {
    $target = Order::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('orders.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('transitionStatus', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('transitionStatus', $target))->toBeFalse();
});

test('Gate::forUser denies transitionStatus by throwing AuthorizationException, never merely returning false', function () {
    $target = Order::factory()->create();
    $deniedActor = User::factory()->create();

    expect(fn () => Gate::forUser($deniedActor)->authorize('transitionStatus', $target))
        ->toThrow(AuthorizationException::class);
});

test('a Super Admin actor passes transitionStatus while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = Order::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('transitionStatus', $target))->toBeTrue();
});

// Two layers, per testing/README.md's "asserted with Gate::forUser() as well as through the
// action, since those are two different layers" instruction: Gate::forUser() proves the ability
// itself resolves correctly; this proves TransitionOrderStatus actually calls
// Gate::authorize('transitionStatus', $order) rather than a different ability or none at all.
test('transitionStatus is enforced through TransitionOrderStatus, not only through Gate::forUser', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $deniedActor = User::factory()->create();
    test()->actingAs($deniedActor);

    expect(fn () => app(TransitionOrderStatus::class)($order, OrderStatus::Processing))
        ->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('orders.edit');
    test()->actingAs($allowedActor);

    app(TransitionOrderStatus::class)($order, OrderStatus::Processing);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

// --- Story 0050, Phase 3 (TDD "red" step): cancel() does not exist on OrderPolicy yet -- every
// test below is expected to fail until backend-expert adds it. D-6: cancel() requires BOTH
// orders.edit AND orders.refund -- this repo's first ability composing two permissions rather
// than one.

test('cancel returns true for a holder of both orders.edit and orders.refund against a Pending order', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.edit', 'orders.refund']);

    $target = Order::factory()->create(['status' => OrderStatus::Pending]);

    expect(Gate::forUser($actor)->allows('cancel', $target))->toBeTrue();
});

test('cancel returns false for a holder of exactly one of the two required permissions, and for neither', function (array $permissions) {
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    $target = Order::factory()->create(['status' => OrderStatus::Pending]);

    expect(Gate::forUser($actor)->allows('cancel', $target))->toBeFalse();
})->with([
    'orders.edit only' => [['orders.edit']],
    'orders.refund only' => [['orders.refund']],
    'neither' => [[]],
]);

test('cancel returns false for a both-holder against a Shipped order and against a PartiallyRefunded one', function (OrderStatus $status, PaymentStatus $paymentStatus) {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.edit', 'orders.refund']);

    $target = Order::factory()->create(['status' => $status, 'payment_status' => $paymentStatus]);

    expect(Gate::forUser($actor)->allows('cancel', $target))->toBeFalse();
})->with([
    'Shipped' => [OrderStatus::Shipped, PaymentStatus::Paid],
    'PartiallyRefunded' => [OrderStatus::Pending, PaymentStatus::PartiallyRefunded],
]);

// Documented as the bypass it is, not as correct behaviour -- the real enforcement is
// CancelOrder's own direct throw (see tests/Feature/Orders/CancelOrderTest.php's
// "a Super Admin is still refused against a Shipped order" test).
test('cancel returns true for a Super Admin through Gate::before, even against a Shipped order', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = Order::factory()->create(['status' => OrderStatus::Shipped]);

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('cancel', $target))->toBeTrue();
});

// Two layers, per testing/README.md -- Gate::forUser() proves the ability resolves; this proves
// CancelOrder actually calls Gate::authorize('cancel', $order).
test('cancel is enforced through CancelOrder, not only through Gate::forUser', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $deniedActor = User::factory()->create();
    test()->actingAs($deniedActor);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo(['orders.edit', 'orders.refund']);
    test()->actingAs($allowedActor);

    app(CancelOrder::class)($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});
