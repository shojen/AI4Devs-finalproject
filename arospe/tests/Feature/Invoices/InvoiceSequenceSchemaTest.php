<?php

// Story 0101a (R-1) -- the invoice_sequences table and the two rows its migration creates.
// Reversibility of down() is pinned by a file-content check: MySQL DDL implicitly commits, which
// would break RefreshDatabase's transaction (repo precedent: OrderPaymentsTableTest).

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @return array<string, mixed>
 */
function invoiceSequenceColumn(string $name): array
{
    $column = collect(Schema::getColumns('invoice_sequences'))->firstWhere('name', $name);

    return is_array($column) ? $column : [];
}

test('the table has exactly the specified columns and no sequence_year column (R-1)', function () {
    expect(Schema::hasTable('invoice_sequences'))->toBeTrue()
        ->and(collect(Schema::getColumns('invoice_sequences'))->pluck('name')->sort()->values()->all())
        ->toBe(['created_at', 'id', 'last_issued_at', 'last_number', 'last_year', 'series', 'updated_at'])
        ->and(Schema::hasColumn('invoice_sequences', 'sequence_year'))->toBeFalse();
});

test('column types, nullability and defaults follow the spec (R-1)', function () {
    expect(invoiceSequenceColumn('series')['nullable'])->toBeFalse()
        ->and(invoiceSequenceColumn('series')['type'])->toBe('varchar(20)')
        ->and(invoiceSequenceColumn('last_number')['nullable'])->toBeFalse()
        ->and(invoiceSequenceColumn('last_number')['type'])->toContain('int')->toContain('unsigned')
        ->and((string) invoiceSequenceColumn('last_number')['default'])->toBe('0')
        ->and(invoiceSequenceColumn('last_year')['nullable'])->toBeTrue()
        ->and(invoiceSequenceColumn('last_year')['type'])->toContain('smallint')->toContain('unsigned')
        ->and(invoiceSequenceColumn('last_issued_at')['nullable'])->toBeTrue()
        ->and(invoiceSequenceColumn('last_issued_at')['type_name'])->toBe('datetime');
});

test('the migration leaves exactly the standard and corrective rows, empty, with UUIDv7 ids (R-1)', function () {
    $rows = DB::table('invoice_sequences')->orderBy('series')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('series')->all())->toBe(['corrective', 'standard']);

    foreach ($rows as $row) {
        expect($row->last_number)->toBe(0)
            ->and($row->last_year)->toBeNull()
            ->and($row->last_issued_at)->toBeNull()
            ->and(Str::isUuid($row->id, 7))->toBeTrue();
    }
});

test('the only indexes are the primary key and a UNIQUE index on series alone (R-1)', function () {
    $indexes = collect(Schema::getIndexes('invoice_sequences'));
    $nonPrimary = $indexes->where('primary', false);

    expect($indexes->firstWhere('primary', true)['columns'])->toBe(['id'])
        ->and($nonPrimary)->toHaveCount(1)
        ->and($nonPrimary->first()['unique'])->toBeTrue()
        ->and($nonPrimary->first()['columns'])->toBe(['series']);
});

test('the table has no foreign key (R-1)', function () {
    expect(Schema::hasTable('invoice_sequences'))->toBeTrue()
        ->and(Schema::getForeignKeys('invoice_sequences'))->toBe([]);
});

// T-31
test('forcing a second row with the same series is a unique violation and leaves two rows (T-31)', function () {
    $failure = null;

    try {
        DB::table('invoice_sequences')->insert(['id' => (string) Str::uuid7(), 'series' => 'standard']);
    } catch (UniqueConstraintViolationException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and((string) $failure->getCode())->toBe('23000')
        ->and(DB::table('invoice_sequences')->count())->toBe(2);
});

test('last_number rejects a negative value (T-31)', function () {
    expect(Schema::hasTable('invoice_sequences'))->toBeTrue()
        ->and(fn () => DB::table('invoice_sequences')->where('series', 'standard')->update(['last_number' => -1]))
        ->toThrow(QueryException::class)
        ->and(DB::table('invoice_sequences')->where('series', 'standard')->value('last_number'))->toBe(0);
});

test('last_number accepts the unsigned maximum (T-31)', function () {
    DB::table('invoice_sequences')->where('series', 'standard')->update(['last_number' => 4294967295]);

    expect(DB::table('invoice_sequences')->where('series', 'standard')->value('last_number'))->toBe(4294967295);
});

test('the migration inserts both rows in up() and drops the table in down() (R-1)', function () {
    $files = glob(database_path('migrations/*_create_invoice_sequences_table.php'));

    expect($files)->toHaveCount(1);

    $source = (string) file_get_contents($files[0]);

    expect($source)->toContain("Schema::dropIfExists('invoice_sequences')")
        ->toContain('uuid7')
        ->toContain("'standard'")
        ->toContain("'corrective'");
});
