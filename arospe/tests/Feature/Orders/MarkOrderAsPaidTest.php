<?php

use App\Actions\Orders\MarkOrderAsPaid;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderFullyRefunded;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0084, Phase 3 (TDD "red" step): App\Actions\Orders\MarkOrderAsPaid, the orders.paid_at
// column, OrderPolicy::markPaid() and the orders.payment.* lang keys do not exist yet -- every
// test below is expected to fail until backend-expert implements them.
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

// --- The state grid (D-1 steps 2-4) ---

test('marking an order that awaits payment sets payment_status to paid, for every non-cancelled status', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status);

    app(MarkOrderAsPaid::class)($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
})->with('mark_as_paid_markable_cells');

test('marking an order that awaits payment never changes its fulfilment status or any column but payment_status, paid_at and updated_at', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status, ['flagged_for_review' => true, 'refunded_amount' => '0.00']);
    $before = markPaidRawRow($order);

    Carbon::setTestNow(Carbon::now()->addDay());
    app(MarkOrderAsPaid::class)($order);
    $after = markPaidRawRow($order);

    expect(Arr::except($after, ['payment_status', 'paid_at', 'updated_at']))
        ->toBe(Arr::except($before, ['payment_status', 'paid_at', 'updated_at']))
        ->and($after['status'])->toBe($status->value);
})->with('mark_as_paid_markable_cells');

test('an already-settled order is refused with the already-paid message on payment_status and does not change', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status);
    $before = markPaidRawRow($order);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['payment_status' => [__('orders.payment.already_paid')]]);
    }

    expect(markPaidRawRow($order))->toBe($before)
        ->and($order->payment_status)->toBe($paymentStatus);
})->with('mark_as_paid_already_paid_cells');

test('a cancelled order that awaits payment is refused with the cancelled message on payment_status and does not change', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    markPaidActingEditor();
    $order = markPaidOrder($paymentStatus, $status);
    $before = markPaidRawRow($order);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['payment_status' => [__('orders.payment.cancelled_blocked')]]);
    }

    expect(markPaidRawRow($order))->toBe($before);
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
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }
});

test('a cancelled order still awaiting payment reports "cancelled", not "already paid" (guard order, D-1)', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Cancelled);

    try {
        app(MarkOrderAsPaid::class)($order);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.cancelled_blocked')]);
    }
});

test('the cancelled refusal binds a Super Admin too, because it is a direct throw and not a Gate check', function () {
    markPaidActingSuperAdmin();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Cancelled);
    $before = markPaidRawRow($order);

    expect(fn () => app(MarkOrderAsPaid::class)($order))->toThrow(ValidationException::class);
    expect(markPaidRawRow($order))->toBe($before);
});

test('the already-paid refusal binds a Super Admin too', function () {
    markPaidActingSuperAdmin();
    $order = markPaidOrder(PaymentStatus::Paid, OrderStatus::Processing);

    expect(fn () => app(MarkOrderAsPaid::class)($order))->toThrow(ValidationException::class);
});

test('a Super Admin can mark an order that awaits payment as paid', function () {
    markPaidActingSuperAdmin();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);

    app(MarkOrderAsPaid::class)($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

// --- The happy path in detail (D-3, D-4) ---

test('the write stores paid_at as the click moment truncated to the second, readable through the datetime cast', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);

    app(MarkOrderAsPaid::class)($order);

    $raw = markPaidRawRow($order);
    $fresh = $order->fresh();

    expect($raw['paid_at'])->toBe('2026-07-15 10:30:45')
        ->and($raw['updated_at'])->toBe('2026-07-15 10:30:45')
        ->and($raw['payment_status'])->toBe('paid')
        ->and($fresh->paid_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->paid_at->equalTo(Carbon::now()->startOfSecond()))->toBeTrue();
});

test('the returned instance is the caller\'s own, carries the stored state and has no pending changes', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Processing);

    $result = app(MarkOrderAsPaid::class)($order);
    $stored = Order::query()->findOrFail($order->id);

    expect($result)->toBe($order)
        ->and($result->isDirty())->toBeFalse()
        ->and($result->payment_status)->toBe(PaymentStatus::Paid)
        ->and($result->paid_at->equalTo($stored->paid_at))->toBeTrue()
        ->and($result->updated_at->equalTo($stored->updated_at))->toBeTrue()
        ->and($result->getAttributes())->toEqual($stored->getAttributes());
});

test('a second attempt is refused and leaves paid_at and updated_at at the first mark\'s values', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    app(MarkOrderAsPaid::class)($order);
    $afterFirst = markPaidRawRow($order);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    expect(fn () => app(MarkOrderAsPaid::class)($order->fresh()))->toThrow(ValidationException::class);
    expect(markPaidRawRow($order))->toBe($afterFirst)
        ->and($afterFirst['paid_at'])->toBe('2026-07-15 10:30:45');
});

test('paid_at records the same wall-clock instant in summer and winter under the application timezone', function (string $instant) {
    markPaidActingEditor();
    $moment = Carbon::parse($instant, 'Europe/Madrid');
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending);
    Carbon::setTestNow($moment);

    app(MarkOrderAsPaid::class)($order);

    expect(config('app.timezone'))->toBe('Europe/Madrid')
        ->and(markPaidRawRow($order)['paid_at'])->toBe($moment->format('Y-m-d H:i:s'))
        ->and($order->fresh()->paid_at->timestamp)->toBe($moment->timestamp);
})->with([
    'summer (CEST, UTC+2)' => ['2026-07-15 10:30:45'],
    'winter (CET, UTC+1)' => ['2026-01-15 10:30:45'],
]);

// --- Mass assignment (D-4) ---

test('payment_status and paid_at supplied through fill() never reach the model, because neither is fillable', function () {
    $order = new Order;
    $order->fill([
        'customer_id' => 'not-used-for-fill-test',
        'payment_status' => PaymentStatus::Paid->value,
        'paid_at' => '2026-07-15 10:30:45',
    ]);

    expect($order->getAttribute('payment_status'))->toBeNull()
        ->and($order->getAttribute('paid_at'))->toBeNull()
        ->and($order->getAttribute('customer_id'))->toBe('not-used-for-fill-test');
});

// --- D-6 characterizations ---

test('a flagged-for-review order can be marked as paid and stays flagged', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Pending, ['flagged_for_review' => true]);

    app(MarkOrderAsPaid::class)($order);

    $fresh = $order->fresh();
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid)
        ->and($fresh->flagged_for_review)->toBeTrue();
});

test('data anomaly (D-6): an order with refunds recorded but still awaiting payment is marked paid and its refunds are not touched', function () {
    markPaidActingEditor();
    $order = Order::factory()->withItems(1)->create(['refunded_amount' => '5.00'])->fresh();
    $item = $order->items()->firstOrFail();
    $refund = Refund::factory()->create(['order_item_id' => $item->id, 'quantity' => 1]);
    $refundBefore = DB::table('refunds')->where('id', $refund->id)->first();

    app(MarkOrderAsPaid::class)($order);

    $fresh = $order->fresh();
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid)
        ->and($fresh->refunded_amount)->toBe('5.00')
        ->and(DB::table('refunds')->where('id', $refund->id)->first())->toEqual($refundBefore);
});

test('a legacy paid order with a NULL paid_at reads back with a null paid_at and is still refused', function () {
    markPaidActingEditor();
    $order = Order::factory()->paid()->create(['paid_at' => null])->fresh();

    expect($order->paid_at)->toBeNull();
    expect(fn () => app(MarkOrderAsPaid::class)($order))->toThrow(ValidationException::class);
    expect(markPaidRawRow($order)['paid_at'])->toBeNull();
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

    app(MarkOrderAsPaid::class)($order);

    Event::assertNotDispatched(OrderFullyRefunded::class);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
    expect(Refund::query()->count())->toBe(0)
        ->and(DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get()->all())->toEqual($itemsBefore)
        ->and(DB::table('products')->orderBy('id')->get()->all())->toEqual($productsBefore);
});

// --- The write itself (D-3) ---

test('a successful mark issues exactly one UPDATE on orders, carrying both the pending-payment and the not-cancelled predicates', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::PendingPayment, OrderStatus::Shipped);
    $updates = [];
    DB::listen(function ($query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update `orders`')) {
            $updates[] = $query;
        }
    });

    app(MarkOrderAsPaid::class)($order);

    expect($updates)->toHaveCount(1);
    $sql = strtolower($updates[0]->sql);
    expect($sql)->toContain('`payment_status` = ?')
        ->and($sql)->toContain('`paid_at` = ?')
        ->and($sql)->toContain('`status` != ?')
        ->and($updates[0]->bindings)->toContain(PaymentStatus::PendingPayment->value)
        ->and($updates[0]->bindings)->toContain(OrderStatus::Cancelled->value);
});

test('a refused mark issues no write at all against orders', function () {
    markPaidActingEditor();
    $order = markPaidOrder(PaymentStatus::Paid, OrderStatus::Pending);
    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^(update|insert into|delete from) `orders`/i', $query->sql) === 1) {
            $writes++;
        }
    });

    expect(fn () => app(MarkOrderAsPaid::class)($order))->toThrow(ValidationException::class);

    expect($writes)->toBe(0);
});

test('MarkOrderAsPaid::__invoke takes exactly one Order parameter and returns an Order, so the moment can never be supplied by the caller', function () {
    $method = new ReflectionMethod(MarkOrderAsPaid::class, '__invoke');

    expect($method->getNumberOfParameters())->toBe(1)
        ->and((string) $method->getParameters()[0]->getType())->toBe(Order::class)
        ->and((string) $method->getReturnType())->toBe(Order::class);
});

// --- Acceptance criterion: the only production writer ---

test('only MarkOrderAsPaid writes PaymentStatus::Paid or paid_at anywhere under app/', function () {
    $actionPath = app_path('Actions/Orders/MarkOrderAsPaid.php');
    expect(file_exists($actionPath))->toBeTrue();

    $writePatterns = [
        '/[\'"]payment_status[\'"]\s*=>\s*(PaymentStatus::Paid\b|[\'"]paid[\'"])/',
        '/[\'"]paid_at[\'"]\s*=>\s*+(?![\'"]datetime[\'"])/',
        '/->paid_at\s*=(?!=)/',
        '/->payment_status\s*=(?!=)\s*PaymentStatus::Paid\b/',
    ];

    $writers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        foreach ($writePatterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $writers[] = $file->getPathname();
                break;
            }
        }
    }

    expect($writers)->toBe([$actionPath]);
});
