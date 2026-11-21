<?php

// Story 0105 T-8..T-19, T-25, T-26, T-28 -- ordering, failure scopes, retries, idempotence and the
// toggle, driven through the real CreateOrder. Failures are forced by binding a throwing double of
// exactly one collaborator.

use App\Actions\Invoices\CreateStandardInvoice;
use App\Actions\Invoices\IssueAutomaticInvoice;
use App\Actions\Invoices\IssueInvoiceForOrder;
use App\Actions\Invoices\RecordInvoiceDeliveries;
use App\Actions\Invoices\SaveInvoiceSettings;
use App\Actions\Invoices\SendOrderInvoices;
use App\Actions\Orders\NotifyOrderCreated;
use App\Enums\InvoiceDeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderCreated;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;
use Tests\Support\Invoices\Automating;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

/**
 * Bind a double of IssueInvoiceForOrder that throws on its first `$times` calls and then delegates
 * to the real core.
 */
function failingIssuer(Throwable $exception, int $times = PHP_INT_MAX): object
{
    $double = new class(app(CreateStandardInvoice::class), $exception, $times) extends IssueInvoiceForOrder
    {
        public int $calls = 0;

        public function __construct(CreateStandardInvoice $createStandardInvoice, private readonly Throwable $failure, private readonly int $failTimes)
        {
            parent::__construct($createStandardInvoice);
        }

        public function __invoke(Order $order, ?string $amount, ?User $actor): Invoice
        {
            if (++$this->calls <= $this->failTimes) {
                throw $this->failure;
            }

            return parent::__invoke($order, $amount, $actor);
        }
    };

    app()->instance(IssueInvoiceForOrder::class, $double);

    return $double;
}

function mysqlError(int $code, string $message): QueryException
{
    $pdo = new PDOException($message);
    $pdo->errorInfo = [$code === 1213 ? '40001' : 'HY000', $code, $message];

    return new QueryException('mysql', 'select 1', [], $pdo);
}

/**
 * Log lines at the given level raised while the test runs.
 *
 * @param  array<int, string>  $sink
 */
function collectLogs(array &$sink, string $level): void
{
    Log::listen(function (MessageLogged $event) use (&$sink, $level): void {
        if ($event->level === $level) {
            $sink[] = $event->message;
        }
    });
}

/**
 * The order, its lines, its stock deduction and its created-order notification all survive.
 */
function expectOrderIntact(Order $order, Product $product): void
{
    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and(OrderItem::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($product->fresh()->stock)->toBe(49)
        ->and(DatabaseNotification::query()->where('type', OrderCreated::class)->count())->toBeGreaterThanOrEqual(1);
}

beforeEach(function () {
    Cache::flush();
});

// --- T-8 ---

test('inside an outer transaction nothing is invoiced until the outer commit, then exactly one invoice (T-8)', function () {
    Automating::prepare();
    $payload = Automating::payload();
    Automating::orderCreator();
    $insideCount = null;

    DB::transaction(function () use ($payload, &$insideCount): void {
        Automating::createOrder($payload);
        $insideCount = Invoice::query()->count();
    });

    expect($insideCount)->toBe(0)
        ->and(Invoice::query()->count())->toBe(1);
});

test('an outer rollback leaves no order, no invoice and no consumed number (T-8)', function () {
    Automating::prepare();
    $payload = Automating::payload();
    Automating::orderCreator();
    $before = Numbering::snapshot();

    DB::beginTransaction();
    Automating::createOrder($payload);
    DB::rollBack();

    expect(Order::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
});

// --- T-9 ---

test('when the order creation itself throws the invoice hook never runs and the exception is unchanged (T-9)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::orderCreator();
    $this->mock(IssueAutomaticInvoice::class)->shouldNotReceive('__invoke');
    $payload = Automating::payload();
    $payload['items'] = [];

    expect(fn () => Automating::createOrder($payload))->toThrow(ValidationException::class);
    expect(Invoice::query()->count())->toBe(0)
        ->and(Automating::failures())->toHaveCount(0);
});

// --- T-10 ---

test('the invoice hook runs before the created-order notification, and a throwing notification does not undo the invoice (T-10)', function () {
    Automating::prepare();
    Automating::orderCreator();
    $seenAtNotify = null;

    $this->mock(NotifyOrderCreated::class, function ($mock) use (&$seenAtNotify): void {
        $mock->shouldReceive('__invoke')->once()->andReturnUsing(function () use (&$seenAtNotify): void {
            $seenAtNotify = ['invoices' => Invoice::query()->count(), 'deliveries' => InvoiceDelivery::query()->count()];

            throw new RuntimeException('admin notification failed');
        });
    });

    expect(fn () => Automating::createOrder())->toThrow(RuntimeException::class, 'admin notification failed');

    expect($seenAtNotify)->toBe(['invoices' => 1, 'deliveries' => 1])
        ->and(Invoice::query()->count())->toBe(1)
        ->and(InvoiceDelivery::query()->count())->toBe(1);
});

test('a failing invoice hook never stops the created-order notification (T-10)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::holder('orders.view');
    Automating::orderCreator();
    failingIssuer(new RuntimeException('issue exploded'));

    $order = Automating::createOrder();

    expect($order->exists)->toBeTrue()
        ->and(DatabaseNotification::query()->where('type', OrderCreated::class)->count())->toBe(1);
});

// --- T-11 ---

dataset('issue failures', [
    'issuer data incomplete' => [
        fn () => InvoiceSetting::query()->sole()->forceFill(['issuer_legal_name' => null])->save(),
        null,
    ],
    'a forced exception' => [
        fn () => failingIssuer(new RuntimeException('forced')),
        1,
    ],
    'a number collision' => [
        fn () => failingIssuer(ValidationException::withMessages(['number' => ['collision']])),
        null,
    ],
    'two deadlocks in a row' => [
        fn () => failingIssuer(mysqlError(1213, 'Deadlock found'), 2),
        null,
    ],
]);

test('an issue failure keeps the order, its stock and its notification, consumes no number and notifies once at stage issue (T-11)', function (Closure $arrange, ?int $reported) {
    Automating::prepare(fakeNotifications: false);
    $recipient = Automating::holder('invoices.create');
    Automating::holder('orders.view');
    Automating::orderCreator();
    $payload = Automating::payload();
    $product = Product::query()->findOrFail($payload['items'][0]['product_id']);
    $before = Numbering::snapshot();
    $arrange();
    Exceptions::fake();

    $order = Automating::createOrder($payload);

    expectOrderIntact($order, $product);

    $failures = Automating::failures('issue');

    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before)
        ->and($failures)->toHaveCount(1)
        ->and($failures->first()->notifiable_id)->toBe($recipient->id)
        ->and($failures->first()->data['order_id'])->toBe($order->id)
        ->and(Automating::failures('send'))->toHaveCount(0);

    if ($reported !== null) {
        Exceptions::assertReportedCount($reported);
    }
})->with('issue failures');

test('one deadlock is retried once and the invoice is issued with no failure notification (T-11)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::holder('invoices.create');
    Automating::orderCreator();
    $double = failingIssuer(mysqlError(1213, 'Deadlock found'), 1);

    Automating::createOrder();

    expect($double->calls)->toBe(2)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(Automating::failures())->toHaveCount(0);
});

// --- T-12 ---

test('a send failure leaves the invoice issued, notifies once at stage send, keeps the order and a later manual send is an original (T-12)', function () {
    Automating::prepare(fakeNotifications: false);
    $recipient = Automating::holder('invoices.send');
    Automating::orderCreator();
    $payload = Automating::payload();
    $product = Product::query()->findOrFail($payload['items'][0]['product_id']);
    $this->mock(RecordInvoiceDeliveries::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('queue down'));
    Exceptions::fake();

    $order = Automating::createOrder($payload);

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(OrderItem::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($product->fresh()->stock)->toBe(49)
        ->and(Automating::failures('send'))->toHaveCount(1)
        ->and(Automating::failures('send')->first()->notifiable_id)->toBe($recipient->id)
        ->and(Automating::failures('issue'))->toHaveCount(0);

    Exceptions::assertReportedCount(1);

    app()->forgetInstance(RecordInvoiceDeliveries::class);
    Notification::fake();
    Delivering::sender('invoices.send', 'invoices.create');

    $delivery = app(SendOrderInvoices::class)($order)[0];

    expect($delivery->is_copy)->toBeFalse();
});

test('a queueing failure after the delivery commit marks the row failed, notifies at stage send and the invoice stays issued (T-12)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::holder('invoices.send');
    Automating::orderCreator();
    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('queue unavailable for customer@example.test');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('queue unavailable for customer@example.test');
        }
    });

    $order = Automating::createOrder();

    $delivery = InvoiceDelivery::query()->sole();

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($delivery->status)->toBe(InvoiceDeliveryStatus::Failed)
        ->and(Automating::failures('send'))->toHaveCount(1)
        ->and(json_encode(Automating::failures('send')->first()->data))->not->toContain('customer@example.test');
});

// --- T-13 ---

test('a soft-deleted customer gets the invoice issued, the send skipped with one warning, a send notification and no mail (T-13)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::holder('invoices.send');
    $customer = Customer::factory()->create();
    $payload = Automating::payload(customer: $customer);
    Automating::orderCreator();
    $customer->delete();
    $warnings = [];
    collectLogs($warnings, 'warning');

    $order = Automating::createOrder($payload);

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and($warnings)->toHaveCount(1)
        ->and(Automating::failures('send'))->toHaveCount(1)
        ->and(Automating::failures('issue'))->toHaveCount(0);
});

// --- T-14 ---

test('a second call for the same order id leaves one invoice and one delivery (T-14)', function () {
    Automating::prepare();

    $order = Automating::createOrder();
    app(IssueAutomaticInvoice::class)($order->id);

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(InvoiceDelivery::query()->count())->toBe(1);
});

test('an order that already has a manual invoice is not invoiced again (T-14)', function () {
    Automating::prepare();
    $order = Invoicing::thousandOrder();
    Delivering::invoice($order);

    app(IssueAutomaticInvoice::class)($order->id);

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(InvoiceDelivery::query()->count())->toBe(0);
});

// --- T-15 ---

test('two orders back to back get consecutive numbers (T-15)', function () {
    Automating::prepare();
    Automating::orderCreator();

    Automating::createOrder();
    Automating::createOrder();

    $numbers = Invoice::query()->orderBy('sequence_number')->pluck('sequence_number')->all();

    expect($numbers)->toBe([1, 2]);
});

test('a failure on the first order leaves no gap in the numbers of the next one (T-15)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::orderCreator();
    failingIssuer(new RuntimeException('first fails'), 1);

    Automating::createOrder();
    Automating::createOrder();

    expect(Invoice::query()->pluck('sequence_number')->all())->toBe([1]);
});

// --- T-16 ---

test('a sequence row held by another connection is swallowed after the retry: order stays, failure notified, no partial invoice (T-16)', function () {
    Automating::prepare(fakeNotifications: false);
    Automating::holder('invoices.create');
    Automating::orderCreator();
    $payload = Automating::payload();
    $before = Numbering::snapshot();
    $probe = Numbering::probe();
    $pdo = $probe->getPdo();
    $pdo->beginTransaction();

    try {
        $pdo->query("SELECT * FROM invoice_sequences WHERE series = 'standard' FOR UPDATE")->fetchAll();
        Numbering::shortLockWait();

        $order = Automating::createOrder($payload);
    } finally {
        Numbering::restoreLockWait();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        DB::disconnect(Numbering::PROBE);
    }

    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before)
        ->and(Automating::failures('issue'))->toHaveCount(1);
});

// --- T-17 / T-18 ---

test('only orders created after the flag is switched on are invoiced and switching off stops it (T-17)', function () {
    Automating::prepare(on: false);
    Automating::orderCreator();

    Automating::createOrder();
    Automating::createOrder();
    InvoiceSetting::query()->update(['auto_issue_on_order' => true]);

    expect(Invoice::query()->count())->toBe(0);

    $third = Automating::createOrder();

    expect(Invoice::query()->pluck('order_id')->all())->toBe([$third->id]);

    InvoiceSetting::query()->update(['auto_issue_on_order' => false]);
    Automating::createOrder();

    expect(Invoice::query()->count())->toBe(1);
});

test('saving the settings with the flag switched reads no order and invoices nothing (T-17)', function () {
    Automating::prepare(on: false);
    Invoicing::order();
    Invoicing::order();
    InvoiceSettingsPayload::actingManager();
    $ordersRead = [];

    DB::listen(function ($query) use (&$ordersRead): void {
        if (preg_match('/(from|join)\s+`orders`/i', $query->sql) === 1) {
            $ordersRead[] = $query->sql;
        }
    });

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true]));

    expect(InvoiceSetting::query()->sole()->auto_issue_on_order)->toBeTrue()
        ->and($ordersRead)->toBe([])
        ->and(Invoice::query()->count())->toBe(0);
});

test('the flag value read when the hook runs decides, in both directions, never leaving a partial invoice (T-18)', function () {
    Automating::prepare(on: false);
    $before = Numbering::snapshot();
    $order = Invoicing::thousandOrder();

    InvoiceSetting::query()->update(['auto_issue_on_order' => true]);
    app(IssueAutomaticInvoice::class)($order->id);

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);

    $other = Invoicing::thousandOrder();
    InvoiceSetting::query()->update(['auto_issue_on_order' => false]);
    $snapshot = Numbering::snapshot();
    app(IssueAutomaticInvoice::class)($other->id);

    expect(Invoice::query()->where('order_id', $other->id)->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($snapshot)
        ->and($snapshot)->not->toBe($before);
});

// --- T-19 ---

test("CreateOrder's order-number retry runs the hook once and issues one invoice (T-19)", function () {
    Automating::prepare();
    Automating::orderCreator();
    $payload = Automating::payload();
    $fired = false;

    DB::listen(function ($query) use (&$fired): void {
        if (! $fired && preg_match('/^\s*update `products` set `stock`/i', $query->sql) === 1) {
            $fired = true;
            $pdo = new PDOException('simulated');
            $pdo->errorInfo = ['23000', 1062, 'Duplicate entry'];

            throw new QueryException('mysql', 'insert into `orders`', [], $pdo);
        }
    });

    $order = Automating::createOrder($payload);

    expect($fired)->toBeTrue()
        ->and(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->sole()->order_id)->toBe($order->id)
        ->and(InvoiceDelivery::query()->count())->toBe(1);
});

// --- T-25 ---

dataset('orders that cannot be invoiced', [
    'an unknown id' => [fn () => (string) Str::uuid7()],
    'a non-UUID' => [fn () => 'not-a-uuid'],
    'a cancelled order' => [fn () => Invoicing::thousandOrder(['status' => OrderStatus::Cancelled])->id],
]);

test('an order id that cannot be invoiced has no effect, throws nothing and notifies nothing (T-25)', function (Closure $resolveId) {
    Automating::prepare(fakeNotifications: false);
    Automating::holder('invoices.create', 'invoices.send');
    $id = $resolveId();
    $before = Numbering::snapshot();

    app(IssueAutomaticInvoice::class)($id);

    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceDelivery::query()->count())->toBe(0)
        ->and(Automating::failures())->toHaveCount(0)
        ->and(Numbering::snapshot())->toBe($before);
})->with('orders that cannot be invoiced');

// --- T-26 ---

test('with incomplete issuer data three orders are not invoiced and only one deduplicated notification is sent (T-26)', function () {
    Automating::prepare(issuer: false, fakeNotifications: false);
    Automating::holder('invoices.create');
    Automating::orderCreator();
    $before = Numbering::snapshot();

    Automating::createOrder();
    Automating::createOrder();
    Automating::createOrder();

    expect(Order::query()->count())->toBe(3)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before)
        ->and(Automating::failures('issue'))->toHaveCount(1);
});

// --- T-28 ---

test('a totals write that bypasses the recalculation leaves the invoice as issued and no out-of-date mark (T-28, FD-9)', function () {
    Automating::prepare();

    $order = Automating::createOrder();
    Order::query()->whereKey($order->id)->update(['total' => '150.00']);

    expect(Invoice::query()->sole()->total)->toBe('100.00')
        ->and($order->fresh()->invoice_out_of_date_at)->toBeNull();
});
