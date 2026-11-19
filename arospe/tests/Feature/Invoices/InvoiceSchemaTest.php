<?php

// Story 0101b -- the invoices and invoice_lines tables and the orders.invoice_out_of_date_at column
// (FD-1, FD-2, FD-4, FD-18; T-18 logo part, T-22, T-23). Reversibility of down() is pinned by a
// file-content check: MySQL DDL implicitly commits, which would break RefreshDatabase's transaction
// (repo precedent: InvoiceSequenceSchemaTest).

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return array<string, mixed>
 */
function invoiceSchemaColumn(string $table, string $name): array
{
    $column = collect(Schema::getColumns($table))->firstWhere('name', $name);

    return is_array($column) ? $column : [];
}

/**
 * The foreign key of a table on one column, or [] when there is none.
 *
 * @return array<string, mixed>
 */
function invoiceSchemaForeignKey(string $table, string $column): array
{
    $key = collect(Schema::getForeignKeys($table))->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);

    return is_array($key) ? $key : [];
}

test('the invoices table has exactly the specified columns, in order (FD-1)', function () {
    expect(Schema::hasTable('invoices'))->toBeTrue()
        ->and(collect(Schema::getColumns('invoices'))->pluck('name')->all())->toBe([
            'id', 'order_id', 'order_number', 'type', 'reason', 'series', 'sequence_year', 'sequence_number',
            'number', 'issued_at', 'corrects_invoice_id', 'voided_at', 'template', 'primary_color', 'accent_color',
            'logo_media_id', 'issuer_legal_name', 'issuer_tax_id', 'issuer_address_line1', 'issuer_address_line2',
            'issuer_city', 'issuer_postal_code', 'issuer_province', 'issuer_country', 'recipient_name',
            'recipient_tax_id', 'recipient_address_line1', 'recipient_address_line2', 'recipient_city',
            'recipient_postal_code', 'recipient_province', 'recipient_country', 'tax_rate', 'subtotal',
            'shipping_amount', 'tax_amount', 'total', 'issued_by', 'collected_at', 'collected_by',
            'collection_payment_method_id', 'collection_type', 'collection_carried_over', 'created_at', 'updated_at',
        ]);
});

test('invoices column types and nullability follow the spec', function (string $column, string $type, bool $nullable) {
    $definition = invoiceSchemaColumn('invoices', $column);

    expect($definition)->not->toBe([], "Column {$column} is missing")
        ->and($definition['type'])->toBe($type)
        ->and($definition['nullable'])->toBe($nullable);
})->with([
    ['order_number', 'varchar(30)', false],
    ['type', 'varchar(20)', false],
    ['reason', 'varchar(20)', true],
    ['series', 'varchar(20)', false],
    ['sequence_year', 'smallint unsigned', false],
    ['sequence_number', 'int unsigned', false],
    ['number', 'varchar(50)', false],
    ['template', 'varchar(30)', false],
    ['primary_color', 'varchar(7)', true],
    ['accent_color', 'varchar(7)', true],
    ['issuer_legal_name', 'varchar(255)', false],
    ['issuer_tax_id', 'varchar(40)', false],
    ['issuer_address_line1', 'varchar(255)', true],
    ['issuer_address_line2', 'varchar(255)', true],
    ['issuer_city', 'varchar(100)', true],
    ['issuer_postal_code', 'varchar(20)', true],
    ['issuer_province', 'varchar(100)', true],
    ['issuer_country', 'varchar(2)', true],
    ['recipient_name', 'varchar(150)', false],
    ['recipient_tax_id', 'varchar(40)', true],
    ['recipient_address_line1', 'varchar(255)', true],
    ['recipient_address_line2', 'varchar(255)', true],
    ['recipient_city', 'varchar(100)', true],
    ['recipient_postal_code', 'varchar(20)', true],
    ['recipient_province', 'varchar(100)', true],
    ['recipient_country', 'varchar(2)', true],
    ['tax_rate', 'decimal(6,3)', true],
    ['subtotal', 'decimal(10,2)', false],
    ['shipping_amount', 'decimal(10,2)', false],
    ['tax_amount', 'decimal(10,2)', false],
    ['total', 'decimal(10,2)', false],
    ['voided_at', 'timestamp', true],
    ['collected_at', 'timestamp', true],
    ['collection_type', 'varchar(20)', true],
    ['collection_carried_over', 'tinyint(1)', false],
]);

test('issued_at is a required timestamp with no default and collection_carried_over defaults to false', function () {
    $issuedAt = invoiceSchemaColumn('invoices', 'issued_at');
    $carriedOver = invoiceSchemaColumn('invoices', 'collection_carried_over');

    expect($issuedAt['nullable'])->toBeFalse()
        ->and($issuedAt['default'])->toBeNull()
        ->and((string) $carriedOver['default'])->toBe('0');
});

test('the only unique index on numbering is UNIQUE(number), plus the issued_at index (FD-2)', function () {
    $indexes = collect(Schema::getIndexes('invoices'));
    $unique = $indexes->where('unique', true)->where('primary', false);

    expect($indexes->firstWhere('primary', true)['columns'])->toBe(['id'])
        ->and($unique)->toHaveCount(1)
        ->and($unique->first()['name'])->toBe('invoices_number_unique')
        ->and($unique->first()['columns'])->toBe(['number'])
        ->and($indexes->firstWhere('name', 'invoices_issued_at_index')['columns'])->toBe(['issued_at'])
        ->and($indexes->firstWhere('name', 'invoices_issued_at_index')['unique'])->toBeFalse();
});

test('no index covers the series, the sequence columns, the type or the recipient (FD-2)', function () {
    $leading = collect(Schema::getIndexes('invoices'))->map(fn (array $index): string => $index['columns'][0])->all();

    expect($leading)->not->toContain('series')
        ->and($leading)->not->toContain('sequence_year')
        ->and($leading)->not->toContain('sequence_number')
        ->and($leading)->not->toContain('type')
        ->and($leading)->not->toContain('recipient_name');
});

test('every invoices foreign key restricts deletion and points at the specified table', function (string $column, string $table) {
    $key = invoiceSchemaForeignKey('invoices', $column);

    expect($key)->not->toBe([], "No foreign key on invoices.{$column}")
        ->and($key['foreign_table'])->toBe($table)
        ->and($key['foreign_columns'])->toBe(['id'])
        ->and(strtolower($key['on_delete']))->toBe('restrict');
})->with([
    ['order_id', 'orders'],
    ['corrects_invoice_id', 'invoices'],
    ['logo_media_id', 'media'],
    ['issued_by', 'users'],
    ['collected_by', 'users'],
    ['collection_payment_method_id', 'payment_methods'],
]);

test('invoices has exactly those six foreign keys', function () {
    expect(collect(Schema::getForeignKeys('invoices'))->pluck('columns')->flatten()->sort()->values()->all())->toBe([
        'collected_by', 'collection_payment_method_id', 'corrects_invoice_id', 'issued_by', 'logo_media_id', 'order_id',
    ]);
});

test('the invoice_lines table has exactly the specified columns, in order', function () {
    expect(Schema::hasTable('invoice_lines'))->toBeTrue()
        ->and(collect(Schema::getColumns('invoice_lines'))->pluck('name')->all())->toBe([
            'id', 'invoice_id', 'order_item_id', 'position', 'kind', 'description', 'sku', 'quantity',
            'unit_price', 'tax_rate', 'line_subtotal', 'tax_amount', 'line_total', 'created_at', 'updated_at',
        ]);
});

test('invoice_lines column types and nullability follow the spec', function (string $column, string $type, bool $nullable) {
    $definition = invoiceSchemaColumn('invoice_lines', $column);

    expect($definition)->not->toBe([], "Column {$column} is missing")
        ->and($definition['type'])->toBe($type)
        ->and($definition['nullable'])->toBe($nullable);
})->with([
    ['order_item_id', 'char(36)', true],
    ['position', 'smallint unsigned', false],
    ['kind', 'varchar(20)', false],
    ['description', 'varchar(255)', false],
    ['sku', 'varchar(128)', true],
    ['quantity', 'int', false],
    ['unit_price', 'decimal(10,2)', false],
    ['tax_rate', 'decimal(6,3)', true],
    ['line_subtotal', 'decimal(10,2)', false],
    ['tax_amount', 'decimal(10,2)', false],
    ['line_total', 'decimal(10,2)', false],
]);

test('invoice_lines.invoice_id restricts deletion and invoice_lines.order_item_id nulls on delete', function () {
    $invoiceKey = invoiceSchemaForeignKey('invoice_lines', 'invoice_id');
    $itemKey = invoiceSchemaForeignKey('invoice_lines', 'order_item_id');

    expect($invoiceKey['foreign_table'])->toBe('invoices')
        ->and(strtolower($invoiceKey['on_delete']))->toBe('restrict')
        ->and($itemKey['foreign_table'])->toBe('order_items')
        ->and(strtolower($itemKey['on_delete']))->toBe('set null')
        ->and(Schema::getForeignKeys('invoice_lines'))->toHaveCount(2);
});

test('orders gains a nullable invoice_out_of_date_at timestamp right after refunded_amount', function () {
    $columns = collect(Schema::getColumns('orders'))->pluck('name')->values();
    $column = invoiceSchemaColumn('orders', 'invoice_out_of_date_at');

    expect($column['nullable'])->toBeTrue()
        ->and($column['type'])->toContain('timestamp')
        ->and($column['default'])->toBeNull()
        ->and($columns->search('invoice_out_of_date_at'))->toBe($columns->search('refunded_amount') + 1);
});

test('the three migrations are reversible: lines before invoices, and the orders column dropped', function () {
    $invoices = glob(database_path('migrations/*_create_invoices_table.php'));
    $lines = glob(database_path('migrations/*_create_invoice_lines_table.php'));
    $orders = glob(database_path('migrations/*_add_invoice_out_of_date_at_to_orders_table.php'));

    expect($invoices)->toHaveCount(1)->and($lines)->toHaveCount(1)->and($orders)->toHaveCount(1);

    expect((string) file_get_contents($invoices[0]))->toContain("Schema::dropIfExists('invoices')")
        ->and((string) file_get_contents($lines[0]))->toContain("Schema::dropIfExists('invoice_lines')")
        ->and((string) file_get_contents($orders[0]))->toContain('dropColumn')->toContain('invoice_out_of_date_at')
        // The migration filenames sort in the order the story requires.
        ->and(basename($invoices[0]) < basename($lines[0]))->toBeTrue()
        ->and(basename($lines[0]) < basename($orders[0]))->toBeTrue();
});

// T-22
test('deleting an invoiced order row by query builder fails on the foreign key and keeps both rows (T-22)', function () {
    $order = Order::factory()->create();
    $invoice = Invoice::factory()->standard()->forOrder($order)->create();

    $failure = null;

    try {
        DB::table('orders')->where('id', $order->id)->delete();
    } catch (QueryException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(QueryException::class)
        ->and((string) $failure->getCode())->toBe('23000')
        ->and(DB::table('orders')->where('id', $order->id)->exists())->toBeTrue()
        ->and(DB::table('invoices')->where('id', $invoice->id)->exists())->toBeTrue();
});

test('an order without invoices can still be deleted by query builder (T-22)', function () {
    $order = Order::factory()->create();

    DB::table('orders')->where('id', $order->id)->delete();

    expect(DB::table('orders')->where('id', $order->id)->exists())->toBeFalse();
});

// T-23
test('removing an invoiced order line succeeds and the invoice line keeps its snapshot with a null order_item_id (T-23)', function () {
    $order = Order::factory()->withItems(1)->create();
    $item = OrderItem::query()->where('order_id', $order->id)->sole();
    $invoice = Invoice::factory()->standard()->forOrder($order)->create();
    $line = InvoiceLine::factory()->create([
        'invoice_id' => $invoice->id,
        'order_item_id' => $item->id,
        'description' => 'Frozen description',
        'sku' => 'FROZEN-SKU',
        'quantity' => 3,
        'unit_price' => '12.34',
        'line_subtotal' => '37.02',
        'tax_amount' => '7.77',
        'line_total' => '44.79',
    ]);

    DB::table('order_items')->where('id', $item->id)->delete();

    $fresh = DB::table('invoice_lines')->where('id', $line->id)->first();

    expect(DB::table('order_items')->where('id', $item->id)->exists())->toBeFalse()
        ->and($fresh->order_item_id)->toBeNull()
        ->and($fresh->description)->toBe('Frozen description')
        ->and($fresh->sku)->toBe('FROZEN-SKU')
        ->and($fresh->quantity)->toBe(3)
        ->and($fresh->unit_price)->toBe('12.34')
        ->and($fresh->line_subtotal)->toBe('37.02')
        ->and($fresh->tax_amount)->toBe('7.77')
        ->and($fresh->line_total)->toBe('44.79');
});

// T-18 (logo part, FD-18)
test('deleting the gallery image used as an issued invoice logo is refused by the foreign key (T-18, FD-18)', function () {
    $media = Media::factory()->create();
    $invoice = Invoice::factory()->standard()->create(['logo_media_id' => $media->id]);

    $failure = null;

    try {
        DB::table('media')->where('id', $media->id)->delete();
    } catch (QueryException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(QueryException::class)
        ->and((string) $failure->getCode())->toBe('23000')
        ->and(DB::table('media')->where('id', $media->id)->exists())->toBeTrue()
        ->and($invoice->fresh()->logo_media_id)->toBe($media->id);
});
