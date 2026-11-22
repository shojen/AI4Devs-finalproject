<?php

// Story 0106 (T-16) -- the add_collected_amount_to_orders_table migration: step 1 carries over the
// collection of paid-like orders' live uncollected standard invoices, step 2 backfills
// orders.collected_amount, and payment_status is never written. Mandatory: RefreshDatabase alone runs
// the migration on empty tables, so each test rolls it back (down() drops the column), inserts the
// "pre-0106" rows with raw queries, re-applies the migration and asserts. The precedent is
// tests/Feature/Orders/OrderStockMigrationTest.php: DDL implicitly commits in MySQL, so the finally
// block re-applies the migration and empties every table except `migrations`.

use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function collectedAmountMigrationPath(): string
{
    $matches = glob(database_path('migrations/*_add_collected_amount_to_orders_table.php')) ?: [];

    expect($matches)->toHaveCount(1);

    return 'database/migrations/'.basename($matches[0]);
}

function collectedAmountEmptyEveryTable(): void
{
    Schema::disableForeignKeyConstraints();

    foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
        if ($table['name'] !== 'migrations') {
            DB::table($table['name'])->delete();
        }
    }

    Schema::enableForeignKeyConstraints();
}

/**
 * Roll the migration back, let $arrange insert the pre-migration rows, re-apply it, hand the
 * arranged ids to $assert.
 *
 * @param  Closure(): array<string, mixed>  $arrange
 * @param  Closure(array<string, mixed>): void  $assert
 */
function withCollectedAmountMigrationReapplied(Closure $arrange, Closure $assert): void
{
    $path = collectedAmountMigrationPath();

    try {
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

        $arranged = $arrange();

        Artisan::call('migrate', ['--path' => $path, '--force' => true]);

        $assert($arranged);
    } finally {
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        collectedAmountEmptyEveryTable();
    }
}

/**
 * Insert a pre-migration order with a raw query (no `collected_amount`).
 *
 * @param  array<string, mixed>  $overrides
 */
function collectedAmountInsertOrder(string $customerId, string $methodId, string $status, string $payment, string $total, string $refunded = '0.00', array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('orders')->insert(array_replace([
        'id' => $id,
        'order_number' => 'ORD-'.substr($id, -12),
        'customer_id' => $customerId,
        'status' => $status,
        'payment_status' => $payment,
        'payment_method_id' => $methodId,
        'subtotal' => $total,
        'tax_amount' => '0.00',
        'shipping_amount' => '0.00',
        'total' => $total,
        'refunded_amount' => $refunded,
        'created_at' => '2026-01-10 08:00:00',
        'updated_at' => '2026-01-10 08:00:00',
    ], $overrides));

    return $id;
}

/**
 * Insert a pre-migration invoice (standard unless `corrects` is given) with a raw query.
 *
 * @param  array<string, mixed>  $overrides
 */
function collectedAmountInsertInvoice(string $orderId, string $total, ?string $corrects = null, array $overrides = []): string
{
    $id = (string) Str::uuid7();
    $number = random_int(1, 4_000_000);

    DB::table('invoices')->insert(array_replace([
        'id' => $id,
        'order_id' => $orderId,
        'order_number' => (string) DB::table('orders')->where('id', $orderId)->value('order_number'),
        'type' => $corrects === null ? 'standard' : 'corrective',
        'series' => $corrects === null ? 'standard' : 'corrective',
        'sequence_year' => 2026,
        'sequence_number' => $number,
        'number' => 'MIG-'.$id,
        'issued_at' => '2026-02-01 09:00:00',
        'corrects_invoice_id' => $corrects,
        'template' => 'classic',
        'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada',
        'issuer_tax_id' => 'ESB12345678',
        'recipient_name' => 'Migration Customer',
        'subtotal' => $total,
        'shipping_amount' => '0.00',
        'tax_amount' => '0.00',
        'total' => $total,
        'created_at' => '2026-02-01 09:00:00',
        'updated_at' => '2026-02-01 09:00:00',
    ], $overrides));

    return $id;
}

test('the backfill sets collected_amount for orders without invoices: GREATEST(total - refunded, 0) when paid-like, 0.00 otherwise, never negative (T-16)', function () {
    withCollectedAmountMigrationReapplied(
        function (): array {
            $customerId = (string) Str::uuid7();
            $methodId = (string) Str::uuid7();
            DB::table('payment_methods')->insert(['id' => $methodId, 'code' => 'bank_transfer', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('customers')->insert(['id' => $customerId, 'name' => 'Migration Customer', 'email' => "mig-{$customerId}@example.test", 'created_at' => now(), 'updated_at' => now()]);

            return [
                'paid' => collectedAmountInsertOrder($customerId, $methodId, 'pending', 'paid', '100.00'),
                'refunded' => collectedAmountInsertOrder($customerId, $methodId, 'cancelled', 'refunded', '100.00', '100.00'),
                'partiallyRefunded' => collectedAmountInsertOrder($customerId, $methodId, 'pending', 'partially_refunded', '100.00', '30.00'),
                'overRefunded' => collectedAmountInsertOrder($customerId, $methodId, 'pending', 'partially_refunded', '100.00', '150.00'),
                'pending' => collectedAmountInsertOrder($customerId, $methodId, 'pending', 'pending_payment', '100.00'),
                'cancelledPaid' => collectedAmountInsertOrder($customerId, $methodId, 'cancelled', 'paid', '100.00'),
            ];
        },
        function (array $ids): void {
            $amount = fn (string $key): string => (string) DB::table('orders')->where('id', $ids[$key])->value('collected_amount');

            expect($amount('paid'))->toBe('100.00')
                ->and($amount('refunded'))->toBe('0.00')
                ->and($amount('partiallyRefunded'))->toBe('70.00')
                ->and($amount('overRefunded'))->toBe('0.00')
                ->and($amount('pending'))->toBe('0.00')
                ->and($amount('cancelledPaid'))->toBe('100.00');
        },
    );
});

test('the migration carries over the collection of paid-like orders live invoices, then backfills collected_amount as net collected, and never writes payment_status (T-16)', function () {
    $startedAt = now()->startOfSecond();

    withCollectedAmountMigrationReapplied(
        function (): array {
            $customerId = (string) Str::uuid7();
            $orderMethodId = (string) Str::uuid7();
            $paymentMethodId = (string) Str::uuid7();
            DB::table('payment_methods')->insert([
                ['id' => $orderMethodId, 'code' => 'bank_transfer', 'created_at' => now(), 'updated_at' => now()],
                ['id' => $paymentMethodId, 'code' => 'paypal', 'created_at' => now(), 'updated_at' => now()],
            ]);
            DB::table('customers')->insert(['id' => $customerId, 'name' => 'Migration Customer', 'email' => "mig-{$customerId}@example.test", 'created_at' => now(), 'updated_at' => now()]);

            // (a) a paid order with a payment row and one uncollected live invoice
            $paidWithPayment = collectedAmountInsertOrder($customerId, $orderMethodId, 'pending', 'paid', '100.00');
            DB::table('order_payments')->insert([
                'id' => (string) Str::uuid7(), 'order_id' => $paidWithPayment, 'payment_method_id' => $paymentMethodId, 'type' => 'card',
                'paid_at' => '2026-03-01 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $invoiceWithPayment = collectedAmountInsertInvoice($paidWithPayment, '100.00');

            // (a') a paid order without a payment row: fallbacks
            $paidWithoutPayment = collectedAmountInsertOrder($customerId, $orderMethodId, 'pending', 'paid', '100.00');
            $invoiceWithoutPayment = collectedAmountInsertInvoice($paidWithoutPayment, '100.00');

            // (b) a partially refunded order with an invoice and a corrective of it
            $partiallyRefunded = collectedAmountInsertOrder($customerId, $orderMethodId, 'pending', 'partially_refunded', '100.00', '30.00');
            $invoiceRefunded = collectedAmountInsertInvoice($partiallyRefunded, '100.00');
            $corrective = collectedAmountInsertInvoice($partiallyRefunded, '-30.00', $invoiceRefunded);

            // (c) a voided invoice of a paid order, and a pending order's invoice
            $paidWithVoided = collectedAmountInsertOrder($customerId, $orderMethodId, 'pending', 'paid', '100.00');
            $voided = collectedAmountInsertInvoice($paidWithVoided, '100.00', null, ['voided_at' => '2026-02-02 09:00:00']);
            $pending = collectedAmountInsertOrder($customerId, $orderMethodId, 'pending', 'pending_payment', '100.00');
            $pendingInvoice = collectedAmountInsertInvoice($pending, '100.00');

            return compact(
                'orderMethodId', 'paymentMethodId', 'paidWithPayment', 'invoiceWithPayment', 'paidWithoutPayment', 'invoiceWithoutPayment',
                'partiallyRefunded', 'invoiceRefunded', 'corrective', 'paidWithVoided', 'voided', 'pending', 'pendingInvoice',
            ) + ['statuses' => DB::table('orders')->pluck('payment_status', 'id')->all()];
        },
        function (array $ids) use ($startedAt): void {
            $invoice = fn (string $key): object => DB::table('invoices')->where('id', $ids[$key])->first();
            $amount = fn (string $key): string => (string) DB::table('orders')->where('id', $ids[$key])->value('collected_amount');

            $withPayment = $invoice('invoiceWithPayment');
            expect((bool) $withPayment->collection_carried_over)->toBeTrue()
                ->and($withPayment->collected_at)->toBe('2026-03-01 10:00:00')
                ->and($withPayment->collection_payment_method_id)->toBe($ids['paymentMethodId'])
                ->and($withPayment->collection_type)->toBe('card')
                ->and($withPayment->collected_by)->toBeNull()
                ->and($amount('paidWithPayment'))->toBe('100.00');

            $withoutPayment = $invoice('invoiceWithoutPayment');
            expect((bool) $withoutPayment->collection_carried_over)->toBeTrue()
                ->and($withoutPayment->collected_at)->not->toBeNull()
                ->and(strtotime((string) $withoutPayment->collected_at))->toBeGreaterThanOrEqual($startedAt->getTimestamp())
                ->and($withoutPayment->collection_payment_method_id)->toBe($ids['orderMethodId'])
                ->and($withoutPayment->collection_type)->toBeNull()
                ->and($withoutPayment->collected_by)->toBeNull()
                ->and($amount('paidWithoutPayment'))->toBe('100.00');

            $refunded = $invoice('invoiceRefunded');
            expect((bool) $refunded->collection_carried_over)->toBeTrue()
                ->and($refunded->collected_at)->not->toBeNull()
                ->and($invoice('corrective')->collected_at)->toBeNull()
                ->and((bool) $invoice('corrective')->collection_carried_over)->toBeFalse()
                ->and($amount('partiallyRefunded'))->toBe('70.00');

            expect($invoice('voided')->collected_at)->toBeNull()
                ->and($amount('paidWithVoided'))->toBe('0.00')
                ->and($invoice('pendingInvoice')->collected_at)->toBeNull()
                ->and($amount('pending'))->toBe('0.00');

            // The migration never writes payment_status.
            expect(DB::table('orders')->pluck('payment_status', 'id')->all())->toBe($ids['statuses']);

            // The invariant: collected_amount = net collected for every order with invoices.
            foreach (DB::table('orders')->whereIn('id', DB::table('invoices')->select('order_id'))->get() as $order) {
                $net = DB::table('invoices as i')
                    ->leftJoin('invoices as corrected', 'corrected.id', '=', 'i.corrects_invoice_id')
                    ->where(fn ($query) => $query->where('i.type', 'standard')->whereNotNull('i.collected_at')
                        ->orWhere(fn ($inner) => $inner->where('i.type', 'corrective')->whereNotNull('corrected.collected_at')))
                    ->where('i.order_id', $order->id)
                    ->sum('i.total');

                expect(bcadd((string) $net, '0', 2))->toBe((string) $order->collected_amount);
            }
        },
    );
});

test('the column is a non-null decimal that defaults to 0.00 on a new order (T-16)', function () {
    $column = collect(Schema::getColumns('orders'))->firstWhere('name', 'collected_amount');

    expect($column['type_name'])->toBe('decimal')
        ->and($column['nullable'])->toBeFalse()
        ->and(Order::factory()->create()->fresh()->collected_amount)->toBe('0.00');
});
