<?php

use App\Actions\Orders\MarkOrderAsPaid;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0084 (D-1 step 1), Phase 3 red step: MarkOrderAsPaid does not exist yet. It calls
// LogRefusedPrivilegedAttempt::authorize() with an EXPLICIT targetType/targetId (the resolver only
// auto-resolves User/Role), so the target_type/target_id assertions below are on VALUES -- both
// would be null if the explicit arguments were forgotten.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('a refused mark logs "Privileged action refused" once, with ability markPaid and the order as the target', function () {
    Log::spy();
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);
    $order = Order::factory()->create()->fresh();

    try {
        app(MarkOrderAsPaid::class)($order);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['actor_id'] === $actor->id
            && $context['ability'] === 'markPaid'
            && $context['target_type'] === 'order'
            && $context['target_id'] === $order->id)
        ->once();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('a guest\'s refused mark is logged against the order with a null actor', function () {
    Log::spy();
    $order = Order::factory()->create()->fresh();

    try {
        app(MarkOrderAsPaid::class)($order);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['actor_id'] === null
            && $context['ability'] === 'markPaid'
            && $context['target_type'] === 'order'
            && $context['target_id'] === $order->id)
        ->once();
});

test('a successful mark logs nothing', function () {
    Log::spy();
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    app(MarkOrderAsPaid::class)(Order::factory()->create()->fresh());

    Log::shouldNotHaveReceived('warning');
});

test('neither state refusal logs, because a state refusal is not a privilege attempt', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    Log::spy();
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);
    $order = Order::factory()->create(['payment_status' => $paymentStatus, 'status' => $status])->fresh();

    expect(fn () => app(MarkOrderAsPaid::class)($order))->toThrow(ValidationException::class);

    Log::shouldNotHaveReceived('warning');
})->with([
    'already paid' => [PaymentStatus::Paid, OrderStatus::Pending],
    'cancelled' => [PaymentStatus::PendingPayment, OrderStatus::Cancelled],
]);
