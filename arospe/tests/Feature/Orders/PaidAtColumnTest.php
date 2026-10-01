<?php

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Story 0084 (D-4), Phase 3 red step: the paid_at migration does not exist yet. Schema assertions
// against the migrated schema (repo precedent: Schema::getColumns()/getIndexes()). The reversibility
// of down() is pinned by a file-content check, not a real DDL round-trip: MySQL DDL implicitly
// commits, which would break RefreshDatabase's transaction.

/**
 * @return array<string, mixed>
 */
function paidAtColumn(): array
{
    $column = collect(Schema::getColumns('orders'))->firstWhere('name', 'paid_at');

    return is_array($column) ? $column : [];
}

function paidAtMigrationPath(): ?string
{
    $matches = glob(database_path('migrations/*_add_paid_at_to_orders_table.php'));

    return $matches === [] || $matches === false ? null : $matches[0];
}

test('orders has a paid_at column', function () {
    expect(Schema::hasColumn('orders', 'paid_at'))->toBeTrue();
});

test('paid_at is a nullable timestamp with no default', function () {
    $column = paidAtColumn();

    expect($column)->not->toBe([])
        ->and($column['type_name'])->toBe('timestamp')
        ->and($column['nullable'])->toBeTrue()
        ->and($column['default'])->toBeNull();
});

test('paid_at sits immediately after refunded_amount', function () {
    $names = array_column(Schema::getColumns('orders'), 'name');

    expect(array_search('paid_at', $names, true))->toBe(array_search('refunded_amount', $names, true) + 1);
});

test('no index covers paid_at (D-4: a lone index would not help COALESCE(paid_at, created_at))', function () {
    $indexedColumns = collect(Schema::getIndexes('orders'))->pluck('columns')->flatten()->all();

    expect(Schema::hasColumn('orders', 'paid_at'))->toBeTrue()
        ->and($indexedColumns)->not->toContain('paid_at');
});

test('a default-state factory order has a NULL paid_at', function () {
    $order = Order::factory()->create();

    expect(DB::table('orders')->where('id', $order->id)->value('paid_at'))->toBeNull();
});

test('the migration is additive and reversible and rewrites no existing row', function () {
    $path = paidAtMigrationPath();
    expect($path)->not->toBeNull();

    $source = (string) file_get_contents($path);

    expect($source)->toContain("->timestamp('paid_at')")
        ->and($source)->toContain('->nullable()')
        ->and($source)->toContain("->after('refunded_amount')")
        ->and($source)->toContain("dropColumn('paid_at')")
        ->and($source)->not->toContain('useCurrent')
        ->and($source)->not->toContain('DB::table')
        ->and($source)->not->toContain('DB::statement')
        ->and($source)->not->toContain('->update(')
        ->and($source)->not->toContain('->index(');
});
