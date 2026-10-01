<?php

use App\Actions\Orders\MarkOrderAsPaid;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0084 (D-3), Phase 3 red step: MarkOrderAsPaid does not exist yet. Deterministic -- no real
// parallelism. A one-shot Gate::after hook plays "the other writer" between authorization and the
// UPDATE: the caller's instance is stale (it still says pending_payment / not cancelled), so only
// the status-aware compare-and-set can notice. LogRefusedPrivilegedAttempt checks the gate twice
// (denies() then authorize()), hence the hook guards itself to fire exactly once; every test
// asserts it fired, so a refactor that stops consulting the Gate cannot turn these into no-ops.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-07-15 10:30:45', 'Europe/Madrid'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Registers a hook that applies $columns to the order's row the first time markPaid is authorized.
 *
 * @param  array<string, mixed>  $columns
 */
function markPaidInterleave(string $orderId, array $columns): ArrayObject
{
    $state = new ArrayObject(['fired' => 0]);

    Gate::after(function ($user, string $ability) use ($orderId, $columns, $state): void {
        if ($ability !== 'markPaid' || $state['fired'] > 0) {
            return;
        }

        $state['fired']++;
        DB::table('orders')->where('id', $orderId)->update($columns);
    });

    return $state;
}

function markPaidConcurrencyActor(bool $superAdmin = false): User
{
    $actor = User::factory()->create();
    if ($superAdmin) {
        $actor->assignRole('Super Admin');
    } else {
        $actor->givePermissionTo('orders.edit');
    }
    test()->actingAs($actor);

    return $actor;
}

/**
 * @return array<string, mixed>
 */
function markPaidConcurrencyRow(Order $order): array
{
    return (array) DB::table('orders')->where('id', $order->id)->first();
}

test('C-1: two stale instances of one order -- the second call is refused and paid_at/updated_at stay the first call\'s', function () {
    markPaidConcurrencyActor();
    $stored = Order::factory()->create()->fresh();
    $first = Order::query()->findOrFail($stored->id);
    $second = Order::query()->findOrFail($stored->id);

    app(MarkOrderAsPaid::class)($first);
    $afterFirst = markPaidConcurrencyRow($stored);
    Carbon::setTestNow(Carbon::now()->addHour());

    try {
        app(MarkOrderAsPaid::class)($second);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    expect(markPaidConcurrencyRow($stored))->toBe($afterFirst)
        ->and($afterFirst['paid_at'])->toBe('2026-07-15 10:30:45')
        ->and($second->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and($second->paid_at)->toBeNull();
});

test('C-2: another writer marks the row paid between authorization and the UPDATE -- already-paid refusal, the row keeps the interleaved values', function () {
    markPaidConcurrencyActor();
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, [
        'payment_status' => PaymentStatus::Paid->value,
        'paid_at' => '2026-07-14 09:00:00',
        'updated_at' => '2026-07-14 09:00:00',
    ]);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    $row = markPaidConcurrencyRow($order);
    expect($hook['fired'])->toBe(1)
        ->and($row['payment_status'])->toBe('paid')
        ->and($row['paid_at'])->toBe('2026-07-14 09:00:00')
        ->and($row['updated_at'])->toBe('2026-07-14 09:00:00')
        ->and($order->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('C-3: the row turns cancelled between the pre-check and the write -- cancelled refusal, never paid + cancelled, paid_at stays null', function () {
    markPaidConcurrencyActor();
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, ['status' => OrderStatus::Cancelled->value]);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.cancelled_blocked')]);
    }

    $row = markPaidConcurrencyRow($order);
    expect($hook['fired'])->toBe(1)
        ->and($row['status'])->toBe('cancelled')
        ->and($row['payment_status'])->toBe('pending_payment')
        ->and($row['paid_at'])->toBeNull();
});

test('C-4: the interleaved writer leaves the order refunded or partially refunded -- already-paid refusal, row untouched', function (PaymentStatus $interleaved) {
    markPaidConcurrencyActor();
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, ['payment_status' => $interleaved->value, 'refunded_amount' => '5.00']);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    $row = markPaidConcurrencyRow($order);
    expect($hook['fired'])->toBe(1)
        ->and($row['payment_status'])->toBe($interleaved->value)
        ->and($row['refunded_amount'])->toBe('5.00')
        ->and($row['paid_at'])->toBeNull();
})->with([
    'refunded' => [PaymentStatus::Refunded],
    'partially refunded' => [PaymentStatus::PartiallyRefunded],
]);

test('C-5: the same interleaving binds a Super Admin -- already-paid and cancelled refusals, never a double write', function (array $columns, string $messageKey) {
    markPaidConcurrencyActor(superAdmin: true);
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, $columns);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__($messageKey)]);
    }

    expect($hook['fired'])->toBe(1)
        ->and(markPaidConcurrencyRow($order)['paid_at'])->toBe($columns['paid_at'] ?? null);
})->with([
    'paid in between' => [['payment_status' => 'paid', 'paid_at' => '2026-07-14 09:00:00'], 'orders.payment.already_paid'],
    'refunded in between' => [['payment_status' => 'refunded'], 'orders.payment.already_paid'],
    'cancelled in between' => [['status' => 'cancelled'], 'orders.payment.cancelled_blocked'],
]);
