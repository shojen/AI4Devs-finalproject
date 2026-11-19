<?php

// Story 0104 -- exactly one original per invoice under concurrency (FD-1, FD-13; T-10, T-11).
//
// T-10 needs a second session that really holds the order row, so its fixture graph (customer,
// payment method, order, invoice) is committed on the probe connection BEFORE the test session reads
// anything, and removed in `finally`. The delivery the first sender "commits" later lies outside the
// waiter's REPEATABLE READ snapshot: only a locking read of the deliveries can see it, which is what
// makes the waiter a copy. No sleeps: a short innodb_lock_wait_timeout fails the blocked statement with 1205.

use App\Actions\Invoices\SendInvoice;
use App\Actions\Invoices\SendOrderInvoices;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Notifications\InvoiceSent;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

// T-10
test('a second sender waits on the order lock with 1205, then after the first commits it is a copy and exactly one delivery is an original (T-10)', function () {
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
            'series' => 'standard', 'sequence_year' => (int) now()->format('Y'), 'sequence_number' => random_int(3_000_000, 3_999_999),
            'number' => 'PROBE-'.substr($invoiceId, 0, 8), 'issued_at' => $now, 'template' => 'classic',
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada', 'issuer_tax_id' => 'ESB12345678', 'recipient_name' => 'Probe Customer',
            'tax_rate' => '21.000', 'subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Only now does the test session read anything.
        Delivering::prepare(issuer: false);
        Delivering::sender('invoices.send');
        $invoice = Invoice::query()->findOrFail($invoiceId);

        $probe->beginTransaction();
        $probe->table('orders')->where('id', $orderId)->lockForUpdate()->first();
        Numbering::shortLockWait();

        $waiter = null;

        try {
            app(SendInvoice::class)($invoice);
        } catch (QueryException|DeadlockException $exception) {
            $waiter = $exception instanceof DeadlockException ? $exception->getPrevious() : $exception;
        }

        $result['waiterCode'] = $waiter instanceof QueryException ? (string) $waiter->errorInfo[1] : null;
        $result['sentWhileBlocked'] = count(Delivering::sentNotifications());

        // The first sender finishes: its original becomes visible on commit.
        $probe->table('invoice_deliveries')->insert([
            'id' => (string) Str::uuid7(), 'invoice_id' => $invoiceId, 'recipient_email' => "probe-{$customerId}@example.test", 'is_copy' => 0,
            'status' => 'queued', 'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $probe->commit();
        Numbering::restoreLockWait();

        $second = app(SendInvoice::class)($invoice);

        $result['secondIsCopy'] = $second->is_copy;
        $result['ownCopies'] = InvoiceDelivery::query()->where('invoice_id', $invoiceId)->where('is_copy', true)->count();
        $result['committedOriginals'] = $probe->table('invoice_deliveries')->where('invoice_id', $invoiceId)->where('is_copy', 0)->count();
    } finally {
        Numbering::restoreLockWait();

        // Release the locks and the uncommitted rows of the test session before the probe deletes.
        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        if ($probe->transactionLevel() > 0) {
            $probe->rollBack();
        }

        $probe->table('invoice_deliveries')->where('invoice_id', $invoiceId)->delete();
        $probe->table('invoices')->where('id', $invoiceId)->delete();
        $probe->table('orders')->where('id', $orderId)->delete();
        $probe->table('customers')->where('id', $customerId)->delete();

        if ($createdMethod) {
            $probe->table('payment_methods')->where('id', $methodId)->delete();
        }

        DB::disconnect(Numbering::PROBE);
    }

    expect($result['waiterCode'])->toBe('1205')
        ->and($result['sentWhileBlocked'])->toBe(0)
        ->and($result['secondIsCopy'])->toBeTrue()
        ->and($result['ownCopies'])->toBe(1)
        ->and($result['committedOriginals'])->toBe(1);
});

// T-11
test('back-to-back sends and an order send racing a single send leave exactly one original per invoice (T-11)', function (string $firstOperation) {
    Delivering::prepare();
    Delivering::sender('invoices.send');
    $order = Invoicing::thousandOrder();
    $first = Delivering::invoice($order);
    $second = Delivering::invoice($order);

    if ($firstOperation === 'single') {
        app(SendInvoice::class)($first);
        app(SendOrderInvoices::class)($order);
    } else {
        app(SendOrderInvoices::class)($order);
        app(SendInvoice::class)($first);
    }

    app(SendInvoice::class)($first);

    foreach ([$first, $second] as $invoice) {
        expect(InvoiceDelivery::query()->where('invoice_id', $invoice->id)->where('is_copy', false)->count())->toBe(1);
    }

    expect(InvoiceDelivery::query()->where('invoice_id', $first->id)->count())->toBe(3)
        ->and(InvoiceDelivery::query()->where('invoice_id', $second->id)->count())->toBe(1);
    Notification::assertSentOnDemandTimes(InvoiceSent::class, 3);
})->with(['single first' => ['single'], 'order first' => ['order']]);

test('the send locks the order row first, then the invoices by id, then inserts the rows (FD-13)', function () {
    Delivering::prepare();
    Delivering::sender('invoices.send');
    $invoice = Delivering::invoice();
    $labels = [];

    DB::listen(function ($query) use (&$labels): void {
        $sql = strtolower($query->sql);
        $locking = str_contains($sql, 'for update');

        $label = match (true) {
            $locking && str_contains($sql, 'from `orders`') => 'order lock',
            $locking && str_contains($sql, 'from `invoices`') => 'invoices lock',
            str_starts_with($sql, 'insert into `invoice_deliveries`') => 'delivery insert',
            str_contains($sql, 'invoice_settings') || str_contains($sql, 'invoice_sequences') => 'numbering table touched',
            default => null,
        };

        if ($label !== null) {
            $labels[] = $label;
        }
    });

    app(SendInvoice::class)($invoice);

    expect(array_values(array_unique($labels)))->toBe(['order lock', 'invoices lock', 'delivery insert']);
});
