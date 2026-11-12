<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0084 (D-4, reworked), Phase 3 red step: the order_payments migration does not exist yet.
// Schema assertions against the migrated schema (repo precedent: RefundModelTest). The reversibility
// of down() is pinned by a file-content check, not a real DDL round-trip: MySQL DDL implicitly
// commits, which would break RefreshDatabase's transaction.

/**
 * @return array<string, mixed>
 */
function orderPaymentsColumn(string $name): array
{
    $column = collect(Schema::getColumns('order_payments'))->firstWhere('name', $name);

    return is_array($column) ? $column : [];
}

function orderPaymentsMigrationPath(): ?string
{
    $matches = glob(database_path('migrations/*_create_order_payments_table.php'));

    return $matches === [] || $matches === false ? null : $matches[0];
}

/**
 * Inserts a raw order_payments row, bypassing the model, so the database constraints are what is under test.
 */
function orderPaymentsInsert(string $orderId, string $methodId): void
{
    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid7(),
        'order_id' => $orderId,
        'payment_method_id' => $methodId,
        'type' => 'transfer',
        'paid_at' => '2026-07-15 10:30:45',
        'created_at' => '2026-07-15 10:30:45',
        'updated_at' => '2026-07-15 10:30:45',
    ]);
}

test('column types and nullability follow D-4: char(36) keys, varchar(20) type, non-null paid_at, nullable timestamps', function () {
    expect(orderPaymentsColumn('id')['type'])->toBe('char(36)')
        ->and(orderPaymentsColumn('order_id')['type'])->toBe('char(36)')
        ->and(orderPaymentsColumn('order_id')['nullable'])->toBeFalse()
        ->and(orderPaymentsColumn('payment_method_id')['type'])->toBe('char(36)')
        ->and(orderPaymentsColumn('payment_method_id')['nullable'])->toBeFalse()
        ->and(orderPaymentsColumn('type')['type'])->toBe('varchar(20)')
        ->and(orderPaymentsColumn('type')['nullable'])->toBeFalse()
        ->and(orderPaymentsColumn('type')['default'])->toBeNull()
        ->and(orderPaymentsColumn('paid_at')['type_name'])->toBe('timestamp')
        ->and(orderPaymentsColumn('paid_at')['nullable'])->toBeFalse()
        ->and(orderPaymentsColumn('paid_at')['default'])->toBeNull()
        ->and(orderPaymentsColumn('recorded_by')['type'])->toBe('char(36)')
        ->and(orderPaymentsColumn('recorded_by')['nullable'])->toBeTrue()
        ->and(orderPaymentsColumn('recorded_by')['default'])->toBeNull()
        ->and(orderPaymentsColumn('created_at')['type_name'])->toBe('timestamp')
        ->and(orderPaymentsColumn('created_at')['nullable'])->toBeTrue()
        ->and(orderPaymentsColumn('updated_at')['nullable'])->toBeTrue();
});

test('recorded_by may be left empty by a raw insert (a future system writer), and a payment row survives with a null recorder', function () {
    $order = Order::factory()->create();

    orderPaymentsInsert($order->id, PaymentMethod::query()->value('id'));

    expect(DB::table('order_payments')->where('order_id', $order->id)->value('recorded_by'))->toBeNull();
});

test('recorded_by rejects an id that is not a user', function () {
    $order = Order::factory()->create();

    expect(fn () => DB::table('order_payments')->insert([
        'id' => (string) Str::uuid7(),
        'order_id' => $order->id,
        'payment_method_id' => PaymentMethod::query()->value('id'),
        'type' => 'transfer',
        'paid_at' => '2026-07-15 10:30:45',
        'recorded_by' => (string) Str::uuid7(),
        'created_at' => '2026-07-15 10:30:45',
        'updated_at' => '2026-07-15 10:30:45',
    ]))->toThrow(QueryException::class, 'foreign key constraint fails');
});

test('a user who recorded a payment cannot be deleted (restrictOnDelete)', function () {
    $user = User::factory()->create();
    OrderPayment::factory()->create(['recorded_by' => $user->id]);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())->toThrow(QueryException::class);

    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
});

test('a second payment row for the same order is rejected by the database with a unique violation', function () {
    $order = Order::factory()->create();
    $methodId = PaymentMethod::query()->value('id');
    orderPaymentsInsert($order->id, $methodId);

    expect(fn () => orderPaymentsInsert($order->id, $methodId))->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('order_payments')->where('order_id', $order->id)->count())->toBe(1);
});

test('two different orders may each have one payment', function () {
    $first = Order::factory()->create();
    $second = Order::factory()->create();
    $methodId = PaymentMethod::query()->value('id');

    orderPaymentsInsert($first->id, $methodId);
    orderPaymentsInsert($second->id, $methodId);

    expect(DB::table('order_payments')->count())->toBe(2);
});

test('a payment method referenced by a payment cannot be deleted', function () {
    $order = Order::factory()->create();
    $method = PaymentMethod::query()->firstOrFail();
    orderPaymentsInsert($order->id, $method->id);

    expect(fn () => DB::table('payment_methods')->where('id', $method->id)->delete())->toThrow(QueryException::class);

    expect(DB::table('payment_methods')->where('id', $method->id)->exists())->toBeTrue();
});

test('an order that has a payment cannot be deleted', function () {
    $order = Order::factory()->create();
    orderPaymentsInsert($order->id, PaymentMethod::query()->value('id'));

    expect(fn () => DB::table('orders')->where('id', $order->id)->delete())->toThrow(QueryException::class);

    expect(DB::table('orders')->where('id', $order->id)->exists())->toBeTrue();
});

test('orders has no paid_at column (the first design was dropped) and its migration no longer exists', function () {
    expect(Schema::hasColumn('orders', 'paid_at'))->toBeFalse()
        ->and(glob(database_path('migrations/*_add_paid_at_to_orders_table.php')))->toBe([]);
});

test('the migration only creates the table, is reversible and rewrites no existing row', function () {
    $path = orderPaymentsMigrationPath();
    expect($path)->not->toBeNull();

    $source = (string) file_get_contents($path);

    expect($source)->toContain("Schema::create('order_payments'")
        ->and($source)->toContain("Schema::dropIfExists('order_payments')")
        ->and($source)->toContain('restrictOnDelete()')
        ->and($source)->not->toContain('cascadeOnDelete')
        ->and($source)->not->toContain('DB::table')
        ->and($source)->not->toContain('DB::statement')
        ->and($source)->not->toContain('->update(')
        ->and($source)->not->toContain('useCurrent')
        ->and($source)->not->toContain('->index(');
});
