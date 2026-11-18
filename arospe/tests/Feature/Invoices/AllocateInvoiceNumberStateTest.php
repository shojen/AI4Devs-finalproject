<?php

// Story 0101a, phase 4 return loop -- the allocator keeps no lock state across transactions (F1) and
// refuses a series that was not locked (F2).

use App\Actions\Invoices\AllocateInvoiceNumber;
use App\Enums\InvoiceType;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Invoices\Numbering;

/**
 * @return list<string>
 */
function lockStatements(callable $callback): array
{
    return array_values(array_filter(
        Numbering::numberingStatements($callback),
        fn (string $sql): bool => str_contains($sql, 'for share') || str_contains($sql, 'for update'),
    ));
}

// F1a
test('a rolled-back savepoint allocation gives its number back on the same allocator instance (F1)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');

    $rolledBack = DB::transaction(function () use ($action, $at): array {
        $taken = DB::transaction(fn (): array => $action(InvoiceType::Standard, $at));

        DB::rollBack();
        DB::beginTransaction();

        return $taken;
    });

    $again = DB::transaction(fn (): array => $action(InvoiceType::Standard, $at));

    expect($rolledBack['sequence_number'])->toBe(1)
        ->and($again['sequence_number'])->toBe(1)
        ->and($again['number'])->toBe($rolledBack['number'])
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1);
});

test('an allocation whose transaction throws does not leave a gap on the same allocator instance (F1)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');

    try {
        DB::transaction(function () use ($action, $at): void {
            $action(InvoiceType::Standard, $at);

            throw new RuntimeException('issuing failed after allocating');
        });
    } catch (RuntimeException) {
    }

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(0);

    $next = DB::transaction(fn (): array => $action(InvoiceType::Standard, $at));

    expect($next['sequence_number'])->toBe(1)
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1);
});

// F1b
test('a second transaction on the same instance re-locks and reads the fresh row (F1)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');

    DB::transaction(fn (): array => $action(InvoiceType::Standard, $at));

    Numbering::arrange(InvoiceType::Standard, 40, 2026, Numbering::at('2026-01-01 00:00:00'));

    $second = null;

    $locks = lockStatements(function () use ($action, $at, &$second): void {
        $second = DB::transaction(fn (): array => $action(InvoiceType::Standard, $at));
    });

    expect($second['sequence_number'])->toBe(41)
        ->and($locks)->toHaveCount(2)
        ->and($locks[0])->toContain('invoice_settings')->toContain('for share')
        ->and($locks[1])->toContain('invoice_sequences')->toContain('for update');
});

test('lock() on a reused instance issues its lock statements again in each transaction (F1)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');

    $first = lockStatements(fn () => DB::transaction(fn (): array => $action->lock([InvoiceType::Standard], $at)));
    $second = lockStatements(fn () => DB::transaction(fn (): array => $action->lock([InvoiceType::Standard], $at)));

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2);
});

// F2
test('allocating a series that was not locked throws a LogicException without a lock query or dropping the earlier lock (F2)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');

    DB::transaction(function () use ($action, $at): void {
        $action->lock([InvoiceType::Standard], $at);

        $failure = null;
        $locks = lockStatements(function () use ($action, $at, &$failure): void {
            try {
                $action(InvoiceType::Corrective, $at);
            } catch (LogicException $exception) {
                $failure = $exception;
            }
        });

        expect($failure)->toBeInstanceOf(LogicException::class)
            ->and($failure->getMessage())->toContain('not locked')
            ->and($locks)->toBe([]);

        $standard = null;
        $standardLocks = lockStatements(function () use ($action, $at, &$standard): void {
            $standard = $action(InvoiceType::Standard, $at);
        });

        expect($standard['sequence_number'])->toBe(1)
            ->and($standardLocks)->toBe([]);
    });

    expect(Numbering::row(InvoiceType::Corrective)->last_number)->toBe(0);
});

test('locking both series up front lets both allocate with exactly one pair of lock statements (F2, R-8)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');
    $numbers = [];

    $locks = lockStatements(function () use ($action, $at, &$numbers): void {
        DB::transaction(function () use ($action, $at, &$numbers): void {
            $action->lock([InvoiceType::Corrective, InvoiceType::Standard], $at);

            $numbers[] = $action(InvoiceType::Standard, $at)['sequence_number'];
            $numbers[] = $action(InvoiceType::Corrective, $at)['sequence_number'];
        });
    });

    expect($numbers)->toBe([1, 1])
        ->and($locks)->toHaveCount(2)
        ->and($locks[0])->toContain('invoice_settings')->toContain('for share')
        ->and($locks[1])->toContain('invoice_sequences')->toContain('for update');
});

// L1
test('lock fails closed when the open transaction has no transaction record instead of caching with no identity (L1)', function () {
    $action = app(AllocateInvoiceNumber::class);
    $at = Numbering::at('2026-04-01 10:00:00');
    $original = app('db.transactions');

    app()->instance('db.transactions', new DatabaseTransactionsManager);

    try {
        expect(fn () => DB::transaction(fn (): array => $action->lock([InvoiceType::Standard], $at)))
            ->toThrow(LogicException::class, 'transaction record');
    } finally {
        app()->instance('db.transactions', $original);
    }
});
