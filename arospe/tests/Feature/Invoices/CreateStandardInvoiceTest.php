<?php

// Story 0101b T-25 -- CreateStandardInvoice is the transaction-bound writer (FD-13): it refuses to
// run outside a transaction, for an order other than the one locked in the transaction, or with a
// non-positive / over-ceiling total, and in every refusal writes nothing.

use App\Actions\Invoices\CreateStandardInvoice;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    Invoicing::defaultStoreLanguage('en');
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
    Invoicing::settings();
    $this->actor = Invoicing::manager();
});

function createStandardNothingWritten(array $before): void
{
    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceLine::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
}

test('it writes the invoice and its lines for the order locked in the caller transaction (T-25)', function () {
    $order = Invoicing::thousandOrder();

    $invoice = DB::transaction(function () use ($order): Invoice {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

        return app(CreateStandardInvoice::class)($locked, '200.00', $this->actor);
    });

    expect($invoice->total)->toBe('200.00')
        ->and($invoice->order_id)->toBe($order->id)
        ->and($invoice->issued_by)->toBe($this->actor->id)
        ->and($invoice->lines()->count())->toBe(1);
});

test('it refuses to run outside a transaction and writes nothing (T-25)', function () {
    $order = Invoicing::thousandOrder();
    $before = Numbering::snapshot();

    Numbering::withoutTransaction(function () use ($order): void {
        expect(fn () => app(CreateStandardInvoice::class)($order, '200.00', $this->actor))->toThrow(LogicException::class);
    });

    createStandardNothingWritten($before);
});

test('it refuses an order not read from the database in this transaction, e.g. freshly created, and writes nothing (T-25)', function () {
    $locked = Invoicing::thousandOrder();
    $other = Invoicing::thousandOrder();
    $before = Numbering::snapshot();

    expect(fn () => DB::transaction(function () use ($locked, $other): void {
        Order::query()->lockForUpdate()->findOrFail($locked->getKey());
        app(CreateStandardInvoice::class)($other, '200.00', $this->actor);
    }))->toThrow(LogicException::class);

    createStandardNothingWritten($before);
});

test('it refuses a non-positive or over-ceiling total and writes nothing (T-25)', function (string $total) {
    $order = Invoicing::thousandOrder();
    $before = Numbering::snapshot();

    expect(fn () => DB::transaction(function () use ($order, $total): void {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
        app(CreateStandardInvoice::class)($locked, $total, $this->actor);
    }))->toThrow(LogicException::class);

    createStandardNothingWritten($before);
})->with([
    'zero' => '0.00',
    'negative' => '-1.00',
    'above the column ceiling' => '100000000.00',
]);
