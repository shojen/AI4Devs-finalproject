<?php

use App\Actions\Orders\MarkOrderAsPaid;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0084 (D-3, reworked), Phase 3 red step: the three-parameter MarkOrderAsPaid and order_payments do not exist yet. Deterministic -- no real
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

function markPaidConcurrencyMethod(): PaymentMethod
{
    return PaymentMethod::query()->first() ?? PaymentMethod::factory()->create();
}

function markPaidConcurrencyRun(Order $order): Order
{
    return app(MarkOrderAsPaid::class)($order, markPaidConcurrencyMethod(), OrderPaymentType::Transfer);
}

/**
 * @return list<array<string, mixed>>
 */
function markPaidConcurrencyPayments(Order $order): array
{
    return DB::table('order_payments')->where('order_id', $order->id)->get()
        ->map(fn (object $row): array => (array) $row)->all();
}

/**
 * Registers a hook that applies $columns to the order's row the first time markPaid is authorized
 * and, when $payment is true, also inserts the winner's payment row (the interleaved writer's own
 * transaction: orders UPDATE plus order_payments INSERT).
 *
 * @param  array<string, mixed>  $columns
 */
function markPaidInterleave(string $orderId, array $columns, bool $payment = false): ArrayObject
{
    $state = new ArrayObject(['fired' => 0]);

    Gate::after(function ($user, string $ability) use ($orderId, $columns, $state, $payment): void {
        if ($ability !== 'markPaid' || $state['fired'] > 0) {
            return;
        }

        $state['fired']++;
        DB::table('orders')->where('id', $orderId)->update($columns);

        if ($payment) {
            DB::table('order_payments')->insert([
                'id' => (string) Str::uuid7(),
                'order_id' => $orderId,
                'payment_method_id' => markPaidConcurrencyMethod()->id,
                'type' => 'card',
                'paid_at' => '2026-07-14 09:00:00',
                'created_at' => '2026-07-14 09:00:00',
                'updated_at' => '2026-07-14 09:00:00',
            ]);
        }
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

test('C-1: two stale instances of one order -- the second call is refused; one payment row remains with the first call\'s method, type and paid_at, and updated_at stays the first call\'s', function () {
    markPaidConcurrencyActor();
    $stored = Order::factory()->create()->fresh();
    $first = Order::query()->findOrFail($stored->id);
    $second = Order::query()->findOrFail($stored->id);

    markPaidConcurrencyRun($first);
    $afterFirst = markPaidConcurrencyRow($stored);
    Carbon::setTestNow(Carbon::now()->addHour());

    try {
        markPaidConcurrencyRun($second);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    $payments = markPaidConcurrencyPayments($stored);
    expect(markPaidConcurrencyRow($stored))->toBe($afterFirst)
        ->and($afterFirst['updated_at'])->toBe('2026-07-15 10:30:45')
        ->and($payments)->toHaveCount(1)
        ->and($payments[0]['payment_method_id'])->toBe(markPaidConcurrencyMethod()->id)
        ->and($payments[0]['type'])->toBe('transfer')
        ->and($payments[0]['paid_at'])->toBe('2026-07-15 10:30:45')
        ->and($second->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and($second->relationLoaded('payment'))->toBeFalse();
});

test('C-2: another writer marks the row paid between authorization and the UPDATE -- already-paid refusal, the row keeps the interleaved values', function () {
    markPaidConcurrencyActor();
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, [
        'payment_status' => PaymentStatus::Paid->value,
        'updated_at' => '2026-07-14 09:00:00',
    ], payment: true);

    try {
        markPaidConcurrencyRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    $row = markPaidConcurrencyRow($order);
    $payments = markPaidConcurrencyPayments($order);
    expect($hook['fired'])->toBe(1)
        ->and($row['payment_status'])->toBe('paid')
        ->and($row['updated_at'])->toBe('2026-07-14 09:00:00')
        ->and($payments)->toHaveCount(1)
        ->and($payments[0]['type'])->toBe('card')
        ->and($payments[0]['paid_at'])->toBe('2026-07-14 09:00:00')
        ->and($order->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('C-3: the row turns cancelled between the pre-check and the write -- cancelled refusal, never paid + cancelled, no payment row', function () {
    markPaidConcurrencyActor();
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, ['status' => OrderStatus::Cancelled->value]);

    try {
        markPaidConcurrencyRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.cancelled_blocked')]);
    }

    $row = markPaidConcurrencyRow($order);
    expect($hook['fired'])->toBe(1)
        ->and($row['status'])->toBe('cancelled')
        ->and($row['payment_status'])->toBe('pending_payment')
        ->and(markPaidConcurrencyPayments($order))->toBe([]);
});

test('C-4: the interleaved writer leaves the order refunded or partially refunded -- already-paid refusal, row untouched', function (PaymentStatus $interleaved) {
    markPaidConcurrencyActor();
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, ['payment_status' => $interleaved->value, 'refunded_amount' => '5.00']);

    try {
        markPaidConcurrencyRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    $row = markPaidConcurrencyRow($order);
    expect($hook['fired'])->toBe(1)
        ->and($row['payment_status'])->toBe($interleaved->value)
        ->and($row['refunded_amount'])->toBe('5.00')
        ->and(markPaidConcurrencyPayments($order))->toBe([]);
})->with([
    'refunded' => [PaymentStatus::Refunded],
    'partially refunded' => [PaymentStatus::PartiallyRefunded],
]);

test('C-5: the same interleaving binds a Super Admin -- already-paid and cancelled refusals, never a double write', function (array $columns, string $messageKey, bool $winnerPayment) {
    markPaidConcurrencyActor(superAdmin: true);
    $order = Order::factory()->create()->fresh();
    $hook = markPaidInterleave($order->id, $columns, payment: $winnerPayment);

    try {
        markPaidConcurrencyRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__($messageKey)]);
    }

    expect($hook['fired'])->toBe(1)
        ->and(markPaidConcurrencyPayments($order))->toHaveCount($winnerPayment ? 1 : 0);
})->with([
    'paid in between' => [['payment_status' => 'paid'], 'orders.payment.already_paid', true],
    'refunded in between' => [['payment_status' => 'refunded'], 'orders.payment.already_paid', false],
    'cancelled in between' => [['status' => 'cancelled'], 'orders.payment.cancelled_blocked', false],
]);
