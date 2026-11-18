<?php

// Story 0101a -- rollback (FD-2), real row locks (FD-3, T-12, T-13) and the observed lock order (R-8).
// Second-session tests commit nothing the test owns: the two sequence rows come from the migration
// and the settings row is committed on the probe connection and removed in `finally`. A short
// innodb_lock_wait_timeout makes a blocked statement fail deterministically with 1205, no sleeps.

use App\Actions\Invoices\AllocateInvoiceNumber;
use App\Actions\Invoices\SaveInvoiceSettings;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// T-11 / R-10
test('an exception after the counter write restores the counter, year and moment, and the next allocation reuses the number (T-11)', function (string $throwPoint) {
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-03-01 10:00:00'));
    $before = Numbering::snapshot();
    $at = Numbering::at('2027-01-05 10:00:00');
    $thrown = null;

    try {
        Numbering::inTransaction(function () use ($throwPoint, $at): void {
            $allocator = app(AllocateInvoiceNumber::class);

            match ($throwPoint) {
                'one allocation across a year turn' => [
                    $allocator->lock([InvoiceType::Standard], $at),
                    $allocator(InvoiceType::Standard, $at),
                ],
                'two allocations of the same series' => [
                    $allocator->lock([InvoiceType::Standard], $at),
                    $allocator(InvoiceType::Standard, $at),
                    $allocator(InvoiceType::Standard, $at),
                ],
                'both series in one locked call' => [
                    $allocator->lock([InvoiceType::Corrective, InvoiceType::Standard], $at),
                    $allocator(InvoiceType::Corrective, $at),
                    $allocator(InvoiceType::Standard, $at),
                ],
            };

            throw new RuntimeException('the invoice insert failed');
        });
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and(Numbering::snapshot())->toBe($before)
        ->and(Numbering::number(InvoiceType::Standard, Numbering::at('2027-01-06 10:00:00')))->toBe('F2027-00001');
})->with([
    'one allocation across a year turn',
    'two allocations of the same series',
    'both series in one locked call',
]);

test('a rolled back allocation leaves the gap-free sequence intact across later successful ones (T-11)', function () {
    $at = Numbering::at('2026-05-01 10:00:00');

    expect(Numbering::number(InvoiceType::Standard, $at))->toBe('F2026-00001');

    try {
        Numbering::inTransaction(function () use ($at): void {
            Numbering::allocate(InvoiceType::Standard, $at);

            throw new RuntimeException('fail');
        });
    } catch (RuntimeException) {
        //
    }

    expect(Numbering::number(InvoiceType::Standard, $at))->toBe('F2026-00002')
        ->and(Numbering::number(InvoiceType::Standard, $at))->toBe('F2026-00003');
});

// T-12
test('a series row held FOR UPDATE by a second connection makes the allocation fail with 1205, then it takes the next number (T-12)', function () {
    // No arrange(): updating the row here would take this uncommitted transaction's own exclusive lock
    // and make the probe block instead of the allocator. The committed row is the migration's (counter 0).
    $probe = Numbering::probe();
    $before = Numbering::snapshot();
    $probe->beginTransaction();

    try {
        $probe->table('invoice_sequences')->where('series', 'standard')->lockForUpdate()->first();
        Numbering::shortLockWait();

        $failure = null;

        try {
            Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-04-01 10:00:00'));
        } catch (QueryException|DeadlockException $exception) {
            // Inside the test's own transaction Laravel rethrows a concurrency error as a DeadlockException
            // wrapping the QueryException.
            $failure = $exception instanceof DeadlockException ? $exception->getPrevious() : $exception;
        }

        expect($failure)->toBeInstanceOf(QueryException::class)
            ->and((string) $failure->errorInfo[1])->toBe('1205');
    } finally {
        Numbering::restoreLockWait();
        $probe->rollBack();
        DB::disconnect(Numbering::PROBE);
    }

    expect(Numbering::snapshot())->toBe($before)
        ->and(Numbering::number(InvoiceType::Standard, Numbering::at('2026-04-01 10:00:00')))->toBe('F2026-00001');
});

test('the other series is not blocked by a hold on one series row (T-12)', function () {
    $probe = Numbering::probe();
    $probe->beginTransaction();

    try {
        $probe->table('invoice_sequences')->where('series', 'standard')->lockForUpdate()->first();
        Numbering::shortLockWait();

        $number = Numbering::number(InvoiceType::Corrective, Numbering::at('2026-04-01 10:00:00'));
    } finally {
        Numbering::restoreLockWait();
        $probe->rollBack();
        DB::disconnect(Numbering::PROBE);
    }

    expect($number)->toBe('R2026-00001');
});

// T-13: the allocation holds the settings row FOR SHARE, so a concurrent settings save (a different
// session) waits for it. The save runs on the probe session by pointing the default connection at it.
test('a settings row held FOR SHARE by an in-flight allocation makes SaveInvoiceSettings wait and time out with 1205 (T-13)', function () {
    $actor = InvoiceSettingsPayload::actingManager();
    $actor->can('invoice-settings.manage');
    $probe = Numbering::probe();
    $default = config('database.default');

    // A previous aborted run may have left the committed row behind: start from a clean table.
    $probe->table('invoice_settings')->delete();
    $probe->table('invoice_settings')->insert(['id' => 1, 'standard_prefix' => 'HELD']);

    try {
        Numbering::inTransaction(function () use ($default, $probe): void {
            app(AllocateInvoiceNumber::class)->lock([InvoiceType::Standard], Numbering::at('2026-04-01 10:00:00'));

            $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection(Numbering::PROBE);

            $failure = null;

            try {
                app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['standard_prefix' => 'LOST']));
            } catch (QueryException $exception) {
                $failure = $exception;
            } finally {
                DB::setDefaultConnection($default);
            }

            expect($failure)->toBeInstanceOf(QueryException::class)
                ->and((string) $failure->errorInfo[1])->toBe('1205');
        });
    } finally {
        DB::setDefaultConnection($default);

        // The allocator's share lock lives in the test's outer transaction: release it before the probe
        // removes the committed row, otherwise that delete waits for the lock (1205) and the row leaks.
        while (DB::connection($default)->transactionLevel() > 0) {
            DB::connection($default)->rollBack();
        }

        $probe->table('invoice_settings')->delete();
        DB::disconnect(Numbering::PROBE);
    }
});

// R-8
test('lock takes the settings row FOR SHARE, then one ordered FOR UPDATE query on the sequences, and never inserts (R-8)', function () {
    $statements = Numbering::numberingStatements(function (): void {
        Numbering::inTransaction(function (): void {
            app(AllocateInvoiceNumber::class)->lock([InvoiceType::Standard, InvoiceType::Corrective], Numbering::at('2026-04-01 10:00:00'));
        });
    });

    $locks = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'for share') || str_contains($sql, 'for update')));

    expect($locks)->toHaveCount(2)
        ->and($locks[0])->toContain('invoice_settings')->toContain('for share')
        ->and($locks[1])->toContain('invoice_sequences')->toContain('order by `series`')->toContain('for update')
        ->and(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert')))->toBe([]);
});

test('an allocation after lock reads nothing again, writes one update and never inserts (R-8)', function () {
    $statements = Numbering::numberingStatements(function (): void {
        Numbering::inTransaction(function (): void {
            $allocator = app(AllocateInvoiceNumber::class);
            $allocator->lock([InvoiceType::Standard], Numbering::at('2026-04-01 10:00:00'));
            $allocator(InvoiceType::Standard, Numbering::at('2026-04-01 10:00:00'));
        });
    });

    $updates = array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'update')));
    $sequenceLocks = array_filter($statements, fn (string $sql): bool => str_contains($sql, 'invoice_sequences') && str_contains($sql, 'for update'));

    expect($sequenceLocks)->toHaveCount(1)
        ->and($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('invoice_sequences')
        ->and(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert')))->toBe([]);
});

test('the settings share lock is taken before any sequence lock even when no settings row exists yet (R-8)', function () {
    expect(InvoiceSetting::query()->count())->toBe(0);

    $statements = Numbering::numberingStatements(function (): void {
        Numbering::inTransaction(function (): void {
            app(AllocateInvoiceNumber::class)->lock([InvoiceType::Corrective], Numbering::at('2026-04-01 10:00:00'));
        });
    });

    $firstLock = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'for share') || str_contains($sql, 'for update'));

    expect($firstLock)->toContain('invoice_settings')->toContain('for share');
});
