<?php

// Story 0101b -- concurrency, lock order and rollback of issuing (T-11, T-12, T-13, T-24).
//
// T-11 needs a second session that really holds the order row, so its fixture graph (customer,
// payment method, order, settings, and the competing issuer's committed invoice and counter write)
// is committed on the probe connection and removed in `finally`, restoring the sequence rows to
// their migration state. The fixtures are committed BEFORE the test session reads anything, so its
// REPEATABLE READ snapshot contains them while the competing invoice, committed later, lies outside
// the snapshot: only a locking read of the invoices (FD-12) can see it. No sleeps: a short
// innodb_lock_wait_timeout makes a blocked statement fail deterministically with 1205.

use App\Actions\Invoices\IssueInvoice;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

// T-11
test('a second issuer waits on the order lock, then the cap refuses it because the first one committed 600.00 of 1000.00 (T-11)', function () {
    $probe = Numbering::probe();
    $now = now()->format('Y-m-d H:i:s');
    $customerId = (string) Str::uuid7();
    $orderId = (string) Str::uuid7();
    $methodId = $probe->table('payment_methods')->value('id');
    $createdMethod = $methodId === null;
    $methodId ??= (string) Str::uuid7();
    $result = [];

    try {
        $probe->table('invoice_settings')->delete();
        $probe->table('invoice_settings')->insert([
            'id' => 1, 'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada', 'issuer_tax_id' => 'ESB12345678',
            'issuer_address_line1' => 'Calle Mayor 1', 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($createdMethod) {
            $probe->table('payment_methods')->insert(['id' => $methodId, 'code' => 'bank_transfer', 'created_at' => $now, 'updated_at' => $now]);
        }

        $probe->table('customers')->insert(['id' => $customerId, 'name' => 'Probe Customer', 'email' => "probe-{$customerId}@example.test", 'created_at' => $now, 'updated_at' => $now]);
        $probe->table('orders')->insert([
            'id' => $orderId, 'order_number' => 'ORD-'.substr($orderId, 0, 8), 'customer_id' => $customerId, 'payment_method_id' => $methodId,
            'tax_rate' => '21.000', 'subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00',
            'billing_country' => 'ES', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Only now does the test session read anything.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StoreLanguageSeeder::class);
        Invoicing::manager();
        $order = Order::query()->findOrFail($orderId);

        $probe->beginTransaction();
        $probe->table('orders')->where('id', $orderId)->lockForUpdate()->first();
        Numbering::shortLockWait();

        $waiter = null;

        try {
            app(IssueInvoice::class)($order, '600.00');
        } catch (QueryException|DeadlockException $exception) {
            $waiter = $exception instanceof DeadlockException ? $exception->getPrevious() : $exception;
        }

        $result['waiterCode'] = $waiter instanceof QueryException ? (string) $waiter->errorInfo[1] : null;

        // The first issuer finishes: its 600.00 invoice and counter write become visible on commit.
        $probe->table('invoice_sequences')->where('series', 'standard')->update(['last_number' => 1, 'last_year' => (int) now()->format('Y'), 'last_issued_at' => $now]);
        $probe->table('invoices')->insert([
            'id' => (string) Str::uuid7(), 'order_id' => $orderId, 'order_number' => 'ORD-'.substr($orderId, 0, 8), 'type' => 'standard',
            'series' => 'standard', 'sequence_year' => (int) now()->format('Y'), 'sequence_number' => 1,
            'number' => 'PROBE-'.substr($orderId, 0, 8), 'issued_at' => $now, 'template' => 'classic',
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada', 'issuer_tax_id' => 'ESB12345678', 'recipient_name' => 'Probe Customer',
            'tax_rate' => '21.000', 'subtotal' => '480.00', 'tax_amount' => '100.80', 'shipping_amount' => '19.20', 'total' => '600.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $probe->commit();

        try {
            app(IssueInvoice::class)($order, '600.00');
        } catch (ValidationException $exception) {
            $result['secondErrors'] = $exception->errors();
        }

        $result['invoices'] = $probe->table('invoices')->where('order_id', $orderId)->pluck('total')->all();
    } finally {
        Numbering::restoreLockWait();

        // Release the locks the test session holds in its own outer transaction before the probe deletes.
        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        if ($probe->transactionLevel() > 0) {
            $probe->rollBack();
        }

        $probe->table('invoice_lines')->whereIn('invoice_id', $probe->table('invoices')->where('order_id', $orderId)->select('id'))->delete();
        $probe->table('invoices')->where('order_id', $orderId)->delete();
        $probe->table('orders')->where('id', $orderId)->delete();
        $probe->table('customers')->where('id', $customerId)->delete();

        if ($createdMethod) {
            $probe->table('payment_methods')->where('id', $methodId)->delete();
        }

        $probe->table('invoice_settings')->delete();
        $probe->table('invoice_sequences')->update(['last_number' => 0, 'last_year' => null, 'last_issued_at' => null]);
        DB::disconnect(Numbering::PROBE);
    }

    expect($result['waiterCode'])->toBe('1205')
        ->and(array_keys($result['secondErrors'] ?? []))->toBe(['amount'])
        ->and($result['secondErrors']['amount'][0])->toContain('400.00')
        ->and($result['invoices'])->toBe(['600.00']);
});

// T-12
test('issuing takes the order, then the settings and the sequence, then locks the invoices, then inserts (T-12)', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
    Invoicing::settings();
    Invoicing::manager();
    $order = Invoicing::thousandOrder();
    $labels = [];

    DB::listen(function ($query) use (&$labels): void {
        $sql = strtolower($query->sql);
        $locking = str_contains($sql, 'for update') || str_contains($sql, 'for share');

        $label = match (true) {
            $locking && str_contains($sql, 'from `orders`') => 'order lock',
            $locking && str_contains($sql, 'from `invoices`') => 'invoices lock',
            ! $locking && str_contains($sql, 'from `invoices`') => 'unlocked invoices read',
            str_contains($sql, 'for share') && str_contains($sql, 'from `invoice_settings`') => 'settings share lock',
            str_contains($sql, 'for update') && str_contains($sql, 'from `invoice_sequences`') => 'sequence lock',
            str_starts_with($sql, 'insert into `invoices`') => 'invoice insert',
            str_starts_with($sql, 'insert into `invoice_lines`') => 'line insert',
            default => null,
        };

        if ($label !== null) {
            $labels[] = $label;
        }
    });

    app(IssueInvoice::class)($order, '200.00');

    expect(array_values(array_unique($labels)))->toBe(['order lock', 'settings share lock', 'sequence lock', 'invoices lock', 'invoice insert', 'line insert'])
        ->and($labels)->not->toContain('unlocked invoices read');
});

// F-1
test('two issuers for different orders that both have no invoices wait on the sequence and both succeed, with no deadlock (F-1)', function () {
    $probe = Numbering::probe();
    $now = now()->format('Y-m-d H:i:s');
    $year = (int) now()->format('Y');
    $customerId = (string) Str::uuid7();
    $orderA = (string) Str::uuid7();
    $orderB = (string) Str::uuid7();
    $methodId = $probe->table('payment_methods')->value('id');
    $createdMethod = $methodId === null;
    $methodId ??= (string) Str::uuid7();
    $result = [];

    try {
        $probe->table('invoice_settings')->delete();
        $probe->table('invoice_settings')->insert([
            'id' => 1, 'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada', 'issuer_tax_id' => 'ESB12345678',
            'issuer_address_line1' => 'Calle Mayor 1', 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($createdMethod) {
            $probe->table('payment_methods')->insert(['id' => $methodId, 'code' => 'bank_transfer', 'created_at' => $now, 'updated_at' => $now]);
        }

        $probe->table('customers')->insert(['id' => $customerId, 'name' => 'Probe Customer', 'email' => "probe-{$customerId}@example.test", 'created_at' => $now, 'updated_at' => $now]);

        foreach ([$orderA, $orderB] as $orderId) {
            $probe->table('orders')->insert([
                'id' => $orderId, 'order_number' => 'ORD-'.substr($orderId, -8), 'customer_id' => $customerId, 'payment_method_id' => $methodId,
                'tax_rate' => '21.000', 'subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00',
                'billing_country' => 'ES', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StoreLanguageSeeder::class);
        Invoicing::manager();
        $order = Order::query()->findOrFail($orderA);

        // The competing issuer, for order B, runs in the new lock order and stops right after its
        // locking read of B's (absent) invoices, still holding the sequence row and the gap lock.
        $probe->beginTransaction();
        $probe->table('orders')->where('id', $orderB)->lockForUpdate()->first();
        $probe->table('invoice_settings')->where('id', 1)->sharedLock()->first();
        $probe->table('invoice_sequences')->where('series', 'standard')->lockForUpdate()->first();
        $probe->table('invoices')->where('order_id', $orderB)->lockForUpdate()->get();
        Numbering::shortLockWait();

        $waiter = null;

        try {
            app(IssueInvoice::class)($order, '1000.00');
        } catch (QueryException|DeadlockException $exception) {
            $waiter = $exception instanceof DeadlockException ? $exception->getPrevious() : $exception;
        }

        // The waiter queued on the sequence row (1205) instead of deadlocking (1213).
        $result['waiterCode'] = $waiter instanceof QueryException ? (string) $waiter->errorInfo[1] : null;

        // The competing issuer finishes its insert (no insert-intention deadlock) and commits.
        $probe->table('invoice_sequences')->where('series', 'standard')->update(['last_number' => 1, 'last_year' => $year, 'last_issued_at' => $now]);
        $probe->table('invoices')->insert([
            'id' => (string) Str::uuid7(), 'order_id' => $orderB, 'order_number' => 'ORD-'.substr($orderB, -8), 'type' => 'standard',
            'series' => 'standard', 'sequence_year' => $year, 'sequence_number' => 1,
            'number' => 'PROBE-'.substr($orderB, -8), 'issued_at' => $now, 'template' => 'classic',
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada', 'issuer_tax_id' => 'ESB12345678', 'recipient_name' => 'Probe Customer',
            'tax_rate' => '21.000', 'subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $probe->commit();
        Numbering::restoreLockWait();

        $result['second'] = app(IssueInvoice::class)($order, '1000.00')->sequence_number;
        // The competing issuer's invoice committed after this session's snapshot: only its own is visible here.
        $result['mine'] = Invoice::query()->where('order_id', $orderA)->value('number');
    } finally {
        Numbering::restoreLockWait();

        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        if ($probe->transactionLevel() > 0) {
            $probe->rollBack();
        }

        $orderIds = [$orderA, $orderB];
        $probe->table('invoice_lines')->whereIn('invoice_id', $probe->table('invoices')->whereIn('order_id', $orderIds)->select('id'))->delete();
        $probe->table('invoices')->whereIn('order_id', $orderIds)->delete();
        $probe->table('orders')->whereIn('id', $orderIds)->delete();
        $probe->table('customers')->where('id', $customerId)->delete();

        if ($createdMethod) {
            $probe->table('payment_methods')->where('id', $methodId)->delete();
        }

        $probe->table('invoice_settings')->delete();
        $probe->table('invoice_sequences')->update(['last_number' => 0, 'last_year' => null, 'last_issued_at' => null]);
        DB::disconnect(Numbering::PROBE);
    }

    expect($result['waiterCode'])->toBe('1205')
        ->and($result['second'])->toBe(2)
        ->and($result['mine'])->toBe('F'.now()->format('Y').'-00002');
});

// T-13 / T-24
test('a failure at any point of issuing writes nothing, leaves the counter alone, and the next issue reuses the number (T-13, T-24)', function (string $failure) {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
    Invoicing::settings();
    Invoicing::manager();
    $order = Invoicing::thousandOrder();
    $armed = false;

    DB::listen(function ($query) use (&$armed, $failure): void {
        $sql = strtolower($query->sql);

        if ($armed && $failure === 'invoice insert' && str_starts_with($sql, 'insert into `invoices`')) {
            throw new RuntimeException('invoice insert failed');
        }

        if ($armed && $failure === 'line insert' && str_starts_with($sql, 'insert into `invoice_lines`')) {
            throw new RuntimeException('line insert failed');
        }
    });

    if ($failure === 'collision') {
        Invoice::factory()->standard()->forOrder(Invoicing::thousandOrder())->create(['number' => 'F2026-00001']);
    }

    $before = Numbering::snapshot();
    $invoicesBefore = Invoice::query()->count();
    $armed = true;
    $thrown = null;

    try {
        app(IssueInvoice::class)($order, $failure === 'cap' ? '1000.01' : '100.00');
    } catch (RuntimeException|ValidationException $exception) {
        $thrown = $exception;
    }

    $armed = false;

    expect($thrown)->not->toBeNull()
        ->and(Invoice::query()->count())->toBe($invoicesBefore)
        ->and(InvoiceLine::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);

    if ($failure === 'collision') {
        Invoice::query()->where('number', 'F2026-00001')->delete();
    }

    expect(app(IssueInvoice::class)($order, '100.00')->number)->toBe('F2026-00001')
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1);
})->with(['invoice insert', 'line insert', 'cap', 'collision']);
