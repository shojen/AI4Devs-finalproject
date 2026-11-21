<?php

// Story 0106 (T-13, T-14) -- concurrency of collecting.
//
// T-13 needs a second session that really holds the order row, so its fixture graph is committed on
// the probe connection (and removed in `finally`) BEFORE the test session reads anything, exactly as
// IssueInvoiceConcurrencyTest does; this file therefore seeds inside the test, never in beforeEach.
// No sleeps: a short innodb_lock_wait_timeout makes the blocked statement fail with 1205.
//
// T-14 plays "the other writer" deterministically: a one-shot Gate::after hook applies the other
// writer's committed effects between authorization and the order lock, so the caller's instances are
// stale and only a recomputation from the locked rows can give the right collected amount.

use App\Actions\Invoices\CollectInvoice;
use App\Enums\OrderPaymentType;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;
use Tests\Support\Invoices\Numbering;

// T-13
test('two clicks collecting one invoice: the second waits on the order lock (1205), then is refused as already collected, counted once (T-13)', function () {
    $probe = Numbering::probe();
    $now = now()->format('Y-m-d H:i:s');
    $customerId = (string) Str::uuid7();
    $orderId = (string) Str::uuid7();
    $invoiceId = (string) Str::uuid7();
    $methodId = $probe->table('payment_methods')->value('id');
    $createdMethod = $methodId === null;
    $methodId ??= (string) Str::uuid7();
    $result = [];

    try {
        if ($createdMethod) {
            $probe->table('payment_methods')->insert(['id' => $methodId, 'code' => 'bank_transfer', 'created_at' => $now, 'updated_at' => $now]);
        }

        $probe->table('customers')->insert(['id' => $customerId, 'name' => 'Probe Customer', 'email' => "probe-{$customerId}@example.test", 'created_at' => $now, 'updated_at' => $now]);
        $probe->table('orders')->insert([
            'id' => $orderId, 'order_number' => 'ORD-'.substr($orderId, 0, 8), 'customer_id' => $customerId, 'payment_method_id' => $methodId,
            'tax_rate' => '21.000', 'subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00',
            'billing_country' => 'ES', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $probe->table('invoices')->insert([
            'id' => $invoiceId, 'order_id' => $orderId, 'order_number' => 'ORD-'.substr($orderId, 0, 8), 'type' => 'standard',
            'series' => 'standard', 'sequence_year' => 2026, 'sequence_number' => 4_000_001,
            'number' => 'PROBE-'.substr($invoiceId, 0, 8), 'issued_at' => $now, 'template' => 'classic',
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada', 'issuer_tax_id' => 'ESB12345678', 'recipient_name' => 'Probe Customer',
            'tax_rate' => '21.000', 'subtotal' => '480.00', 'tax_amount' => '100.80', 'shipping_amount' => '19.20', 'total' => '600.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Only now does the test session read anything.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
        Collecting::actor();
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $method = PaymentMethod::query()->findOrFail($methodId);

        $probe->beginTransaction();
        $probe->table('orders')->where('id', $orderId)->lockForUpdate()->first();
        Numbering::shortLockWait();

        $waiter = null;

        try {
            app(CollectInvoice::class)($invoice, $method, OrderPaymentType::Card);
        } catch (QueryException|DeadlockException $exception) {
            $waiter = $exception instanceof DeadlockException ? $exception->getPrevious() : $exception;
        }

        $result['waiterCode'] = $waiter instanceof QueryException ? (string) $waiter->errorInfo[1] : null;

        // The first click finishes: its collection becomes visible on commit.
        $probe->table('invoices')->where('id', $invoiceId)->update([
            'collected_at' => $now, 'collection_payment_method_id' => $methodId, 'collection_type' => 'card', 'collection_carried_over' => 0,
        ]);
        $probe->table('orders')->where('id', $orderId)->update(['collected_amount' => '600.00', 'payment_status' => 'partially_paid']);
        $probe->commit();

        try {
            app(CollectInvoice::class)($invoice, $method, OrderPaymentType::Card);
        } catch (ValidationException $exception) {
            $result['secondErrors'] = $exception->errors();
        }

        $result['collectedAmount'] = $probe->table('orders')->where('id', $orderId)->value('collected_amount');
    } finally {
        Numbering::restoreLockWait();

        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        if ($probe->transactionLevel() > 0) {
            $probe->rollBack();
        }

        $probe->table('invoices')->where('order_id', $orderId)->delete();
        $probe->table('orders')->where('id', $orderId)->delete();
        $probe->table('customers')->where('id', $customerId)->delete();

        if ($createdMethod) {
            $probe->table('payment_methods')->where('id', $methodId)->delete();
        }
    }

    expect($result['waiterCode'])->toBe('1205')
        ->and(array_keys($result['secondErrors'] ?? []))->toBe(['invoice'])
        ->and($result['collectedAmount'])->toBe('600.00');
});

/**
 * Register a one-shot hook that applies the other writer's effects when `collect` is authorized.
 *
 * @param  Closure(): void  $otherWriter
 */
function interleaveAtCollect(Closure $otherWriter): ArrayObject
{
    $state = new ArrayObject(['fired' => 0]);

    Gate::after(function ($user, string $ability) use ($otherWriter, $state): void {
        if ($ability !== 'collect' || $state['fired'] > 0) {
            return;
        }

        $state['fired']++;
        $otherWriter();
    });

    return $state;
}

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// T-14
test('collect vs collect of the same invoice: the stale caller is refused and the invoice is counted once (T-14)', function () {
    $this->seed(RolePermissionSeeder::class);
    Collecting::actor();
    $method = Collecting::method();
    $order = Collecting::order('1000.00');
    $invoice = Collecting::invoice($order, '600.00');
    $stale = Invoice::query()->findOrFail($invoice->id);
    $hook = interleaveAtCollect(function () use ($invoice, $order, $method): void {
        DB::table('invoices')->where('id', $invoice->id)->update(['collected_at' => now(), 'collection_payment_method_id' => $method->id, 'collection_type' => 'card']);
        DB::table('orders')->where('id', $order->id)->update(['collected_amount' => '600.00', 'payment_status' => 'partially_paid']);
    });

    $errors = Collecting::errors(fn () => app(CollectInvoice::class)($stale, $method, OrderPaymentType::Card));

    expect($hook['fired'])->toBe(1)
        ->and(array_keys($errors))->toBe(['invoice'])
        ->and($order->fresh()->collected_amount)->toBe('600.00');
});

test('collect vs undo of another invoice: the collected amount equals the signed sum of what is collected afterwards (T-14)', function () {
    $this->seed(RolePermissionSeeder::class);
    Collecting::actor();
    $method = Collecting::method();
    $order = Collecting::order('1000.00', ['payment_status' => PaymentStatus::PartiallyPaid, 'collected_amount' => '600.00']);
    $collected = Collecting::invoice($order, '600.00', true);
    $target = Collecting::invoice($order, '400.00');
    $hook = interleaveAtCollect(function () use ($collected, $order): void {
        DB::table('invoices')->where('id', $collected->id)->update(['collected_at' => null, 'collected_by' => null, 'collection_payment_method_id' => null, 'collection_type' => null]);
        DB::table('orders')->where('id', $order->id)->update(['collected_amount' => '0.00', 'payment_status' => 'pending_payment']);
    });

    app(CollectInvoice::class)($target, $method, OrderPaymentType::Card);

    $fresh = $order->fresh();

    expect($hook['fired'])->toBe(1)
        ->and($fresh->collected_amount)->toBe('400.00')
        ->and($fresh->debt())->toBe('600.00')
        ->and($fresh->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});

test('collect vs refund: the refund state wins and the collected amount is still the collected invoice (T-14)', function () {
    $this->seed(RolePermissionSeeder::class);
    Collecting::actor();
    $method = Collecting::method();
    $order = Collecting::order('1000.00');
    $item = Collecting::addLine($order);
    $invoice = Collecting::invoice($order, '600.00');
    Collecting::invoice($order, '400.00');
    $hook = interleaveAtCollect(function () use ($order, $item): void {
        DB::table('order_items')->where('id', $item->id)->update(['refunded_quantity' => 1]);
        DB::table('orders')->where('id', $order->id)->update(['refunded_amount' => '200.00', 'payment_status' => 'partially_refunded']);
    });

    app(CollectInvoice::class)($invoice, $method, OrderPaymentType::Card);

    $fresh = $order->fresh();

    expect($hook['fired'])->toBe(1)
        ->and($fresh->collected_amount)->toBe('600.00')
        ->and($fresh->refunded_amount)->toBe('200.00')
        ->and($fresh->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});

test('collect vs line edit: the debt is computed from the edited total, not the caller stale one (T-14)', function () {
    $this->seed(RolePermissionSeeder::class);
    Collecting::actor();
    $method = Collecting::method();
    $order = Collecting::order('1000.00');
    $invoice = Collecting::invoice($order, '600.00');
    Collecting::invoice($order, '400.00');
    $hook = interleaveAtCollect(function () use ($order): void {
        DB::table('orders')->where('id', $order->id)->update(['subtotal' => '800.00', 'total' => '800.00']);
    });

    app(CollectInvoice::class)($invoice, $method, OrderPaymentType::Card);

    $fresh = Order::query()->findOrFail($order->id);

    expect($hook['fired'])->toBe(1)
        ->and($fresh->collected_amount)->toBe('600.00')
        ->and($fresh->debt())->toBe('200.00')
        ->and($fresh->payment_status)->toBe(PaymentStatus::PartiallyPaid);
});
