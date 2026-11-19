<?php

// Story 0101b FD-3 -- invoices are immutable through the application. The Invoice model refuses
// saving a dirty frozen column and every delete; only voided_at, the five collection columns and
// updated_at may change. InvoiceLine refuses every update and delete. Rows are built through the
// factories (no issuing action needed). T-19, T-20 boundary, T-18 snapshot dataset.

use App\Enums\InvoiceCorrectionReason;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The raw database row of an invoice, for before/after comparisons.
 *
 * @return array<string, mixed>
 */
function immutabilityRawInvoice(string $id): array
{
    return (array) DB::table('invoices')->where('id', $id)->first();
}

/**
 * @return array<int, array<string, mixed>>
 */
function immutabilityRawLines(string $invoiceId): array
{
    return DB::table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('position')->get()
        ->map(fn (object $row): array => (array) $row)->all();
}

/**
 * Run the callback and return what it threw, or null.
 */
function immutabilityThrown(Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        return $throwable;
    }

    return null;
}

/**
 * A type-valid value that differs from any factory default, so a refusal can only come from the
 * guard, never from the database (T-19).
 */
function immutabilityMutation(string $column): mixed
{
    return match ($column) {
        'order_id', 'corrects_invoice_id', 'logo_media_id', 'issued_by' => (string) Str::uuid7(),
        'type' => InvoiceType::Corrective,
        'reason' => InvoiceCorrectionReason::Refund,
        'sequence_year' => 1999,
        'sequence_number' => 987654,
        'issued_at', 'created_at' => Carbon::parse('1999-01-01 00:00:00'),
        'primary_color' => '#010203',
        'accent_color' => '#040506',
        'issuer_country', 'recipient_country' => 'ZZ',
        'issuer_postal_code', 'recipient_postal_code' => '99999',
        'tax_rate' => '99.999',
        'subtotal' => '12345.67',
        'shipping_amount' => '12345.68',
        'tax_amount' => '12345.69',
        'total' => '12345.70',
        'number' => 'MUTATED-00001',
        default => 'Mutated '.$column,
    };
}

// T-19: every frozen column of the table.
dataset('frozen invoice columns', [
    'order_id',
    'order_number',
    'type',
    'reason',
    'series',
    'sequence_year',
    'sequence_number',
    'number',
    'issued_at',
    'corrects_invoice_id',
    'template',
    'primary_color',
    'accent_color',
    'logo_media_id',
    'issuer_legal_name',
    'issuer_tax_id',
    'issuer_address_line1',
    'issuer_address_line2',
    'issuer_city',
    'issuer_postal_code',
    'issuer_province',
    'issuer_country',
    'recipient_name',
    'recipient_tax_id',
    'recipient_address_line1',
    'recipient_address_line2',
    'recipient_city',
    'recipient_postal_code',
    'recipient_province',
    'recipient_country',
    'tax_rate',
    'subtotal',
    'shipping_amount',
    'tax_amount',
    'total',
    'issued_by',
    'created_at',
]);

test('saving a changed frozen column through the model is refused and the row is unchanged (T-19)', function (string $column) {
    $invoice = Invoice::factory()->standard()->create();
    $before = immutabilityRawInvoice($invoice->id);

    $invoice->{$column} = immutabilityMutation($column);
    $thrown = immutabilityThrown(fn () => $invoice->save());

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and(immutabilityRawInvoice($invoice->id))->toBe($before);
})->with('frozen invoice columns');

test('update() with a frozen column is refused and forceFill()->save() is refused too (T-19)', function () {
    $invoice = Invoice::factory()->standard()->create(['total' => '100.00']);
    $before = immutabilityRawInvoice($invoice->id);

    $viaUpdate = immutabilityThrown(fn () => $invoice->update(['total' => '1.00']));
    $viaForceFill = immutabilityThrown(fn () => $invoice->forceFill(['total' => '2.00'])->save());

    expect($viaUpdate)->toBeInstanceOf(Throwable::class)
        ->and($viaForceFill)->toBeInstanceOf(Throwable::class)
        ->and(immutabilityRawInvoice($invoice->id))->toBe($before);
});

test('a refused frozen change next to a lifecycle change writes neither (T-19)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $before = immutabilityRawInvoice($invoice->id);

    $invoice->voided_at = now();
    $invoice->total = '1.00';
    $thrown = immutabilityThrown(fn () => $invoice->save());

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and(immutabilityRawInvoice($invoice->id))->toBe($before);
});

test('the lifecycle columns stay writable through the model and nothing frozen moves (T-19)', function () {
    $collector = User::factory()->create();
    $method = PaymentMethod::factory()->create();
    $invoice = Invoice::factory()->standard()->create();
    $frozenBefore = Arr::except(immutabilityRawInvoice($invoice->id), [
        'voided_at', 'collected_at', 'collected_by', 'collection_payment_method_id', 'collection_type',
        'collection_carried_over', 'updated_at',
    ]);

    $invoice->voided_at = Carbon::parse('2026-03-01 10:00:00');
    $invoice->collected_at = Carbon::parse('2026-03-02 10:00:00');
    $invoice->collected_by = $collector->id;
    $invoice->collection_payment_method_id = $method->id;
    $invoice->collection_type = 'transfer';
    $invoice->collection_carried_over = true;
    $invoice->save();

    $after = immutabilityRawInvoice($invoice->id);

    expect($after['voided_at'])->not->toBeNull()
        ->and($after['collected_at'])->not->toBeNull()
        ->and($after['collected_by'])->toBe($collector->id)
        ->and($after['collection_payment_method_id'])->toBe($method->id)
        ->and($after['collection_type'])->toBe('transfer')
        ->and($after['collection_carried_over'])->toBe(1)
        ->and(Arr::except($after, ['voided_at', 'collected_at', 'collected_by', 'collection_payment_method_id', 'collection_type', 'collection_carried_over', 'updated_at']))->toBe($frozenBefore);
});

test('touch() and an unchanged save are allowed (updated_at is not frozen)', function () {
    $invoice = Invoice::factory()->standard()->create();

    $invoice->save();
    $touched = $invoice->touch();

    expect($touched)->toBeTrue();
});

test('delete(), destroy() and a model-level force delete are refused and the row stays (T-19)', function () {
    $invoice = Invoice::factory()->standard()->create();

    $viaDelete = immutabilityThrown(fn () => $invoice->delete());
    $viaDestroy = immutabilityThrown(fn () => Invoice::destroy($invoice->id));
    $viaForce = immutabilityThrown(fn () => $invoice->forceDelete());

    expect($viaDelete)->toBeInstanceOf(Throwable::class)
        ->and($viaDestroy)->toBeInstanceOf(Throwable::class)
        ->and($viaForce)->toBeInstanceOf(Throwable::class)
        ->and(DB::table('invoices')->where('id', $invoice->id)->count())->toBe(1);
});

test('an invoice line cannot be updated through the model, whichever column changes (T-19)', function (string $column, mixed $value) {
    $line = InvoiceLine::factory()->create();
    $before = (array) DB::table('invoice_lines')->where('id', $line->id)->first();

    $line->{$column} = $value;
    $thrown = immutabilityThrown(fn () => $line->save());

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and((array) DB::table('invoice_lines')->where('id', $line->id)->first())->toBe($before);
})->with([
    'description' => ['description', 'Mutated'],
    'sku' => ['sku', 'MUTATED'],
    'quantity' => ['quantity', 99],
    'unit_price' => ['unit_price', '99.99'],
    'line_total' => ['line_total', '99.99'],
    'tax_amount' => ['tax_amount', '9.99'],
    'position' => ['position', 9],
    'invoice_id' => ['invoice_id', 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa'],
]);

test('an invoice line cannot be deleted through the model and update() is refused too (T-19)', function () {
    $line = InvoiceLine::factory()->create();

    $viaDelete = immutabilityThrown(fn () => $line->delete());
    $viaUpdate = immutabilityThrown(fn () => $line->update(['description' => 'Mutated']));

    expect($viaDelete)->toBeInstanceOf(Throwable::class)
        ->and($viaUpdate)->toBeInstanceOf(Throwable::class)
        ->and(DB::table('invoice_lines')->where('id', $line->id)->value('description'))->toBe($line->getOriginal('description'));
});

test('inserting a new invoice line stays allowed', function () {
    $invoice = Invoice::factory()->standard()->create();

    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'position' => 1]);
    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'position' => 2]);

    expect(DB::table('invoice_lines')->where('invoice_id', $invoice->id)->count())->toBe(2);
});

// T-20 -- the documented boundary: the guard lives in the model events, so a bulk query-builder
// write bypasses it. This test pins that boundary; the architecture test in tests/Unit
// guarantees no code under app/ ever does it.
test('a bulk query update bypasses the model guard, which is the documented boundary (T-20)', function () {
    $invoice = Invoice::factory()->standard()->create(['total' => '100.00']);

    Invoice::query()->whereKey($invoice->id)->update(['total' => '1.00']);

    expect(DB::table('invoices')->where('id', $invoice->id)->value('total'))->toBe('1.00');
});

// T-18 -- later edits elsewhere never reach an issued invoice. No action is needed: the invoice is a
// factory row, the mutation is a real edit of the source data, and the invoice must read the same.
/**
 * Apply one later edit of the invoice's source data.
 */
function immutabilityApplyEdit(string $edit, Order $order): void
{
    match ($edit) {
        'issuer address in the settings' => InvoiceSetting::factory()->withIssuer()->create()
            ->forceFill(['issuer_address_line1' => 'New issuer street 9', 'issuer_legal_name' => 'New Legal Name', 'issuer_tax_id' => 'NEWTAX1'])->save(),
        'look in the settings' => InvoiceSetting::factory()->withIssuer()->create()
            ->forceFill(['template' => 'modern', 'primary_color' => '#0A0B0C', 'accent_color' => '#0D0E0F'])->save(),
        'numbering format in the settings' => InvoiceSetting::factory()->withIssuer()->create()
            ->forceFill(['standard_prefix' => 'NEW', 'standard_padding' => 9])->save(),
        'customer edit' => $order->customer->forceFill(['email' => 'new@example.test', 'tax_id' => 'NEWCUSTTAX'])->save(),
        'customer rename' => $order->customer->forceFill(['name' => 'Renamed Customer'])->save(),
        'customer soft delete' => $order->customer->delete(),
        'order billing address' => $order->forceFill([
            'billing_address_line1' => 'New billing 5', 'billing_city' => 'New City', 'billing_postal_code' => '00000',
        ])->save(),
        'order line prices' => OrderItem::query()->where('order_id', $order->id)
            ->update(['unit_price' => '999.00', 'line_total' => '999.00', 'product_name' => 'Renamed product']),
    };
}

dataset('later edits', [
    'issuer address in the settings',
    'look in the settings',
    'numbering format in the settings',
    'customer edit',
    'customer rename',
    'customer soft delete',
    'order billing address',
    'order line prices',
]);

test('every invoice column and line stays unchanged after a later edit of its sources (T-18)', function (string $edit) {
    $order = Order::factory()->withItems(1)->create();
    $item = OrderItem::query()->where('order_id', $order->id)->sole();
    $invoice = Invoice::factory()->standard()->forOrder($order)->create();
    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'order_item_id' => $item->id, 'position' => 1]);

    $invoiceBefore = immutabilityRawInvoice($invoice->id);
    $linesBefore = immutabilityRawLines($invoice->id);

    immutabilityApplyEdit($edit, $order->fresh());

    expect(immutabilityRawInvoice($invoice->id))->toBe($invoiceBefore)
        ->and(immutabilityRawLines($invoice->id))->toBe($linesBefore);
})->with('later edits');

// T-35 -- no entry point writes invoices through HTTP: every registered route that is not a plain
// read must name nothing invoice-related (uri, name or action). The static twin is in
// tests/Unit/ArchitectureTest.php.
test('no registered route with a write verb touches invoices (T-35)', function () {
    $offenders = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->filter(fn (Route $route): bool => preg_match('/invoice/i', $route->uri().' '.$route->getName().' '.$route->getActionName()) === 1)
        ->map(fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
