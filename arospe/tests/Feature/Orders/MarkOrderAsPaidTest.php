<?php

use App\Actions\Orders\MarkOrderAsPaid;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderFullyRefunded;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0084 (reworked: order_payments design), Phase 3 (TDD "red" step): the three-parameter
// App\Actions\Orders\MarkOrderAsPaid(Order, PaymentMethod, OrderPaymentType), the order_payments
// table, App\Models\OrderPayment and App\Enums\OrderPaymentType do not exist yet -- every test
// below is expected to fail until the implementers deliver them.
//
// The state grid (D-1) is the heart of this file: payment state (4) x fulfilment status (5) = 20
// cells, the expected outcome of each written out literally in Datasets.php.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-07-15 10:30:45.789', 'Europe/Madrid'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function markPaidActingEditor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    return $actor;
}

function markPaidActingSuperAdmin(): User
{
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    return $superAdmin;
}

/**
 * An order in the given state, re-read from the database so its in-memory attributes are exactly
 * what a Livewire request would hold (raw strings, no factory-only values).
 *
 * @param  array<string, mixed>  $overrides
 */
function markPaidOrder(PaymentStatus $paymentStatus, OrderStatus $status, array $overrides = []): Order
{
    return Order::factory()
        ->create(['payment_status' => $paymentStatus, 'status' => $status, ...$overrides])
        ->fresh();
}

/**
 * The raw `orders` row, bypassing every cast -- what "byte-identical" is asserted against.
 *
 * @return array<string, mixed>
 */
function markPaidRawRow(Order $order): array
{
    return (array) DB::table('orders')->where('id', $order->id)->first();
}

function markPaidMethod(): PaymentMethod
{
    return PaymentMethod::query()->first() ?? PaymentMethod::factory()->create();
}

/**
 * Calls the real action with the existing bank-transfer method and the Transfer type unless told otherwise.
 */
function markPaidRun(Order $order, ?PaymentMethod $method = null, OrderPaymentType $type = OrderPaymentType::Transfer): Order
{
    return app(MarkOrderAsPaid::class)($order, $method ?? markPaidMethod(), $type);
}

/**
 * The raw `order_payments` rows of an order, bypassing every cast.
 *
 * @return list<array<string, mixed>>
 */
function markPaidPaymentRows(Order $order): array
{
    return DB::table('order_payments')->where('order_id', $order->id)->orderBy('id')->get()
        ->map(fn (object $row): array => (array) $row)->all();
}

// --- The state grid (D-1 steps 2-4) ---

test('marking an order that awaits payment sets payment_status to paid, for every non-cancelled status', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status);

    markPaidRun($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
})->with('mark_as_paid_markable_cells');

test('marking an order that awaits payment never changes its fulfilment status or any orders column but payment_status and updated_at', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status, ['flagged_for_review' => true, 'refunded_amount' => '0.00']);
    $before = markPaidRawRow($order);

    Carbon::setTestNow(Carbon::now()->addDay());
    markPaidRun($order);
    $after = markPaidRawRow($order);

    expect(Arr::except($after, ['payment_status', 'updated_at']))
        ->toBe(Arr::except($before, ['payment_status', 'updated_at']))
        ->and($after['status'])->toBe($status->value)
        ->and($after['payment_method_id'])->toBe($before['payment_method_id']);
})->with('mark_as_paid_markable_cells');

test('an already-settled order is refused with the already-paid message on payment_status and neither orders nor order_payments change', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status);
    $before = markPaidRawRow($order);

    try {
        markPaidRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['payment_status' => [__('orders.payment.already_paid')]]);
    }

    expect(markPaidRawRow($order))->toBe($before)
        ->and($order->payment_status)->toBe($paymentStatus)
        ->and(markPaidPaymentRows($order))->toBe([]);
})->with('mark_as_paid_already_paid_cells');

test('a cancelled order that awaits payment is refused with the cancelled message on payment_status and neither orders nor order_payments change', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status);
    $before = markPaidRawRow($order);

    try {
        markPaidRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['payment_status' => [__('orders.payment.cancelled_blocked')]]);
    }

    expect(markPaidRawRow($order))->toBe($before)
        ->and(markPaidPaymentRows($order))->toBe([]);
})->with('mark_as_paid_cancelled_cells');

test('the two refusals are distinguishable: their messages differ and neither is empty', function () {
    expect(__('orders.payment.already_paid'))->not->toBe(__('orders.payment.cancelled_blocked'))
        ->and(__('orders.payment.already_paid'))->not->toBe('orders.payment.already_paid')
        ->and(__('orders.payment.cancelled_blocked'))->not->toBe('orders.payment.cancelled_blocked');
});

test('a cancelled order whose payment is refunded reports "already paid", not "cancelled" (guard order, D-1)', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::Refunded, OrderStatus::Cancelled);

    try {
        markPaidRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }
});

test('a cancelled order still awaiting payment reports "cancelled", not "already paid" (guard order, D-1)', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Cancelled);

    try {
        markPaidRun($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.cancelled_blocked')]);
    }
});

test('the cancelled refusal binds a Super Admin too, because it is a direct throw and not a Gate check', function () {
    markPaidActingSuperAdmin();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Cancelled);
    $before = markPaidRawRow($order);

    expect(fn () => markPaidRun($order))->toThrow(ValidationException::class);
    expect(markPaidRawRow($order))->toBe($before)
        ->and(markPaidPaymentRows($order))->toBe([]);
});

test('the already-paid refusal binds a Super Admin too', function () {
    markPaidActingSuperAdmin();
    $order = markPaidOrder(PaymentStatus::Paid, OrderStatus::Processing);

    expect(fn () => markPaidRun($order))->toThrow(ValidationException::class);
});

test('a Super Admin can mark an order that awaits payment as paid', function () {
    markPaidActingSuperAdmin();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);

    markPaidRun($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(markPaidPaymentRows($order))->toHaveCount(1);
});

// --- The happy path in detail (D-3, D-4) ---

test('a successful mark creates exactly one order_payments row with the passed method, the passed type and the click moment truncated to the second', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    $method = markPaidMethod();

    markPaidRun($order, $method, OrderPaymentType::Transfer);

    $rows = markPaidPaymentRows($order);
    $raw = markPaidRawRow($order);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['order_id'])->toBe($order->id)
        ->and($rows[0]['payment_method_id'])->toBe($method->id)
        ->and($rows[0]['type'])->toBe('transfer')
        ->and($rows[0]['paid_at'])->toBe('2026-07-15 10:30:45')
        ->and(Str::isUuid($rows[0]['id']))->toBeTrue()
        ->and($raw['payment_status'])->toBe('paid')
        ->and($raw['updated_at'])->toBe('2026-07-15 10:30:45');

    $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
    expect($payment->type)->toBe(OrderPaymentType::Transfer)
        ->and($payment->paid_at->equalTo(Carbon::now()->startOfSecond()))->toBeTrue();
});

test('the type the caller passes is stored verbatim, for every OrderPaymentType case', function (string $typeValue) {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);

    markPaidRun($order, markPaidMethod(), OrderPaymentType::from($typeValue));

    expect(markPaidPaymentRows($order)[0]['type'])->toBe($typeValue);
})->with([
    'transfer' => ['transfer'],
    'card' => ['card'],
    'paypal' => ['paypal'],
]);

test('the payment stores the method the caller passes and orders.payment_method_id, the method chosen at creation, is never changed', function () {
    markPaidActingEditor();
    $chosen = markPaidMethod();
    // A second row cannot come from the factory: `code` is cast to the single-case PaymentMethodCode enum.
    $usedId = (string) Str::uuid7();
    DB::table('payment_methods')->insert(['id' => $usedId, 'code' => 'card_gateway', 'iban' => null, 'created_at' => now(), 'updated_at' => now()]);
    $used = PaymentMethod::query()->whereKey($usedId)->firstOrFail();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending, ['payment_method_id' => $chosen->id]);

    markPaidRun($order, $used, OrderPaymentType::Card);

    expect(markPaidPaymentRows($order)[0]['payment_method_id'])->toBe($used->id)
        ->and(markPaidRawRow($order)['payment_method_id'])->toBe($chosen->id);
});

test('the returned instance is the caller\'s own, carries the stored state, has no pending changes and exposes the new payment', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Processing);

    $result = markPaidRun($order);
    $stored = Order::query()->findOrFail($order->id);
    $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();

    expect($result)->toBe($order)
        ->and($result->isDirty())->toBeFalse()
        ->and($result->payment_status)->toBe(PaymentStatus::Paid)
        ->and($result->updated_at->equalTo($stored->updated_at))->toBeTrue()
        ->and($result->getAttributes())->toEqual($stored->getAttributes())
        ->and($result->relationLoaded('payment'))->toBeTrue()
        ->and($result->payment->is($payment))->toBeTrue()
        ->and($result->payment->paid_at->equalTo($payment->paid_at))->toBeTrue()
        ->and($result->payment->type)->toBe(OrderPaymentType::Transfer);
});

test('a second attempt is refused and leaves the single payment row and orders.updated_at byte-identical to the first mark\'s', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    markPaidRun($order);
    $afterFirst = markPaidRawRow($order);
    $paymentsAfterFirst = markPaidPaymentRows($order);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    expect(fn () => markPaidRun($order->fresh(), markPaidMethod(), OrderPaymentType::Card))->toThrow(ValidationException::class);
    expect(markPaidRawRow($order))->toBe($afterFirst)
        ->and(markPaidPaymentRows($order))->toBe($paymentsAfterFirst)
        ->and($paymentsAfterFirst)->toHaveCount(1)
        ->and($paymentsAfterFirst[0]['paid_at'])->toBe('2026-07-15 10:30:45')
        ->and($paymentsAfterFirst[0]['type'])->toBe('transfer');
});

test('paid_at records the same wall-clock instant in summer and winter under the application timezone', function (string $instant) {
    markPaidActingEditor();
    $moment = Carbon::parse($instant, 'Europe/Madrid');
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    Carbon::setTestNow($moment);

    markPaidRun($order);

    expect(config('app.timezone'))->toBe('Europe/Madrid')
        ->and(markPaidPaymentRows($order)[0]['paid_at'])->toBe($moment->format('Y-m-d H:i:s'))
        ->and(OrderPayment::query()->where('order_id', $order->id)->firstOrFail()->paid_at->timestamp)->toBe($moment->timestamp);
})->with([
    'summer (CEST, UTC+2)' => ['2026-07-15 10:30:45'],
    'winter (CET, UTC+1)' => ['2026-01-15 10:30:45'],
]);

// --- Mass assignment (D-4) ---

test('payment_status supplied through fill() never reaches the model, because it is not fillable, and orders has no paid_at', function () {
    $order = new Order;
    $order->fill([
        'customer_id' => 'not-used-for-fill-test',
        'payment_status' => PaymentStatus::Paid->value,
    ]);

    expect($order->getAttribute('payment_status'))->toBeNull()
        ->and($order->getAttribute('customer_id'))->toBe('not-used-for-fill-test');
});

// --- D-6 characterizations ---

test('a flagged-for-review order can be marked as paid and stays flagged', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending, ['flagged_for_review' => true]);

    markPaidRun($order);

    $fresh = $order->fresh();
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid)
        ->and($fresh->flagged_for_review)->toBeTrue()
        ->and(markPaidPaymentRows($order))->toHaveCount(1);
});

test('data anomaly (D-6): an order with refunds recorded but still awaiting payment is marked paid and its refunds are not touched', function () {
    markPaidActingEditor();
    $order = Order::factory()->withItems(1)->create(['refunded_amount' => '5.00'])->fresh();
    $item = $order->items()->firstOrFail();
    $refund = Refund::factory()->create(['order_item_id' => $item->id, 'quantity' => 1]);
    $refundBefore = DB::table('refunds')->where('id', $refund->id)->first();

    markPaidRun($order);

    $fresh = $order->fresh();
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid)
        ->and($fresh->refunded_amount)->toBe('5.00')
        ->and(DB::table('refunds')->where('id', $refund->id)->first())->toEqual($refundBefore);
});

test('a legacy paid order without a payment row reads back with a null payment, is refused and still gets no row', function () {
    markPaidActingEditor();
    $order = Order::factory()->state(['payment_status' => PaymentStatus::Paid])->create()->fresh();

    expect($order->payment)->toBeNull();
    expect(fn () => markPaidRun($order))->toThrow(ValidationException::class);
    expect(markPaidPaymentRows($order))->toBe([]);
});

test('a pending order that already has a payment row (data anomaly): the unique violation propagates and the orders update is rolled back (Q-6)', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    OrderPayment::factory()->create(['order_id' => $order->id]);
    $before = markPaidRawRow($order);
    $paymentsBefore = markPaidPaymentRows($order);

    Carbon::setTestNow(Carbon::now()->addHour());

    expect(fn () => markPaidRun($order))->toThrow(UniqueConstraintViolationException::class);

    expect(markPaidRawRow($order))->toBe($before)
        ->and(markPaidRawRow($order)['payment_status'])->toBe('pending_payment')
        ->and(markPaidPaymentRows($order))->toBe($paymentsBefore)
        ->and($order->payment_status)->toBe(PaymentStatus::PendingPayment);
});

// --- No side effects ---

test('marking as paid dispatches no event, notification or job and touches no refund, line item or product row', function () {
    markPaidActingEditor();
    $order = Order::factory()->withItems(2)->create()->fresh();
    $itemsBefore = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get()->all();
    $productsBefore = DB::table('products')->orderBy('id')->get()->all();
    Event::fake([OrderFullyRefunded::class]);
    Notification::fake();
    Queue::fake();

    markPaidRun($order);

    Event::assertNotDispatched(OrderFullyRefunded::class);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
    expect(Refund::query()->count())->toBe(0)
        ->and(DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get()->all())->toEqual($itemsBefore)
        ->and(DB::table('products')->orderBy('id')->get()->all())->toEqual($productsBefore);
});

// --- The write itself (D-3) ---

test('a successful mark issues exactly one UPDATE on orders carrying both predicates, then one INSERT on order_payments, in that order', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Shipped);
    $method = markPaidMethod();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (preg_match('/^(update|insert into) `(orders|order_payments)`/i', $query->sql) === 1) {
            $statements[] = $query;
        }
    });

    markPaidRun($order, $method);

    expect($statements)->toHaveCount(2);
    $update = $statements[0];
    $insert = $statements[1];
    $updateSql = strtolower($update->sql);

    expect($updateSql)->toStartWith('update `orders`')
        ->and($updateSql)->toContain('`payment_status` = ?')
        ->and($updateSql)->toContain('`status` != ?')
        ->and($updateSql)->not->toContain('paid_at')
        ->and($update->bindings)->toContain(PaymentStatus::PendingPayment->value)
        ->and($update->bindings)->toContain(OrderStatus::Cancelled->value)
        ->and($update->bindings)->toContain(PaymentStatus::Paid->value)
        ->and(strtolower($insert->sql))->toStartWith('insert into `order_payments`')
        ->and($insert->bindings)->toContain($order->id)
        ->and($insert->bindings)->toContain($method->id)
        ->and($insert->bindings)->toContain('transfer');
});

test('both writes run inside one transaction: each statement sees the transaction level one above the level before the call', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (preg_match('/^(update|insert into) `(orders|order_payments)`/i', $query->sql, $match) === 1) {
            $levels[strtolower($match[2])] = DB::transactionLevel();
        }
    });
    $baseline = DB::transactionLevel();

    markPaidRun($order);

    expect($levels)->toBe(['orders' => $baseline + 1, 'order_payments' => $baseline + 1])
        ->and(DB::transactionLevel())->toBe($baseline);
});

test('a refused mark (pre-check) issues no write at all against orders or order_payments', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::Paid, OrderStatus::Pending);
    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^(update|insert into|delete from) `(orders|order_payments)`/i', $query->sql) === 1) {
            $writes++;
        }
    });

    expect(fn () => markPaidRun($order))->toThrow(ValidationException::class);

    expect($writes)->toBe(0);
});

test('MarkOrderAsPaid::__invoke takes exactly (Order, PaymentMethod, OrderPaymentType) and returns an Order, so the moment can never be supplied by the caller', function () {
    $method = new ReflectionMethod(MarkOrderAsPaid::class, '__invoke');

    expect($method->getNumberOfParameters())->toBe(3)
        ->and(array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $method->getParameters()))
        ->toBe([Order::class, PaymentMethod::class, OrderPaymentType::class])
        ->and((string) $method->getReturnType())->toBe(Order::class);
});

// --- Acceptance criterion: the only production writer ---

test('only MarkOrderAsPaid writes PaymentStatus::Paid or creates order_payments rows anywhere under app/', function () {
    $actionPath = app_path('Actions/Orders/MarkOrderAsPaid.php');
    expect(file_exists($actionPath))->toBeTrue();

    $writePatterns = [
        '/[\'"]payment_status[\'"]\s*=>\s*(PaymentStatus::Paid\b|[\'"]paid[\'"])/',
        '/->payment_status\s*=(?!=)\s*PaymentStatus::Paid\b/',
        '/\bOrderPayment::(create|forceCreate|insert|firstOrCreate|updateOrCreate|upsert|query\(\)->(create|forceCreate|insert))\b/',
        '/new\s+OrderPayment\b/',
        '/->payment\(\)\s*->\s*(create|forceCreate|save|insert|updateOrCreate|firstOrCreate)\b/',
        '/DB::table\(\s*[\'"]order_payments[\'"]\s*\)/',
        '/into\s+`?order_payments\b/i',
    ];

    $writers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Comments and docblocks may legitimately name these calls; only executable code counts.
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach ($writePatterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $writers[] = $file->getPathname();
                break;
            }
        }
    }

    expect($writers)->toBe([$actionPath]);
});
