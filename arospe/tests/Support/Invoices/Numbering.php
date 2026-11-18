<?php

namespace Tests\Support\Invoices;

use App\Actions\Invoices\AllocateInvoiceNumber;
use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;

/**
 * Story 0101a -- shared fixtures of the invoice numbering tests. No `invoices` table exists yet, so
 * the helpers run the allocator inside a transaction exactly like the issuing actions of 0101b will.
 *
 * The two `invoice_sequences` rows are created by the migration (never by a test), so arranging a
 * series means updating the existing row, never inserting one.
 */
final class Numbering
{
    /** The connection name of the second (competing) session used by the lock tests. */
    public const PROBE = 'invoice_numbering_probe';

    /**
     * A moment in the application timezone.
     */
    public static function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, (string) config('app.timezone'));
    }

    /**
     * A moment given in UTC (the instant is returned in UTC, as a caller holding a raw UTC clock would).
     */
    public static function utc(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'UTC');
    }

    /**
     * Put the series row of a series into the given state. `$issuedAt` defaults to 1 January of
     * `$lastYear` (so any later test instant is not a clock skew) and to null when there is no year.
     */
    public static function arrange(InvoiceType $series, int $lastNumber, ?int $lastYear = null, ?CarbonInterface $issuedAt = null): void
    {
        $issuedAt ??= $lastYear === null ? null : self::at("{$lastYear}-01-01 00:00:00");

        InvoiceSequence::query()->where('series', $series->value)->sole()->forceFill([
            'last_number' => $lastNumber,
            'last_year' => $lastYear,
            'last_issued_at' => $issuedAt,
        ])->save();
    }

    /**
     * The persisted row of a series.
     */
    public static function row(InvoiceType $series): InvoiceSequence
    {
        return InvoiceSequence::query()->where('series', $series->value)->sole();
    }

    /**
     * Both rows as plain scalars, for before/after comparisons.
     *
     * @return array<string, array{last_number: int, last_year: int|null, last_issued_at: int|null}>
     */
    public static function snapshot(): array
    {
        return InvoiceSequence::query()->orderBy('series')->get()->mapWithKeys(fn (InvoiceSequence $row): array => [
            $row->series => [
                'last_number' => $row->last_number,
                'last_year' => $row->last_year,
                'last_issued_at' => $row->last_issued_at?->getTimestamp(),
            ],
        ])->all();
    }

    /**
     * Run a closure inside a transaction, like the issuing actions will.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inTransaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    /**
     * Lock the series and take the next number in one transaction (what an issuing action does).
     * Pass `$lockFirst = false` to let the allocator lock for itself.
     *
     * @return array{number: string, series: string, sequence_year: int, sequence_number: int}
     */
    public static function allocate(InvoiceType $series, CarbonInterface $at, bool $lockFirst = true): array
    {
        return DB::transaction(function () use ($series, $at, $lockFirst): array {
            $allocator = app(AllocateInvoiceNumber::class);

            if ($lockFirst) {
                $allocator->lock([$series], $at);
            }

            return $allocator($series, $at);
        });
    }

    /**
     * Allocate and return only the printed number.
     */
    public static function number(InvoiceType $series, CarbonInterface $at): string
    {
        return self::allocate($series, $at)['number'];
    }

    /**
     * Run the closure with the connection's transaction level counter zeroed, reproducing a caller
     * that opened no transaction (RefreshDatabase keeps every test inside one).
     *
     * @param  Closure(): mixed  $callback
     */
    public static function withoutTransaction(Closure $callback): void
    {
        $connection = DB::connection();
        $counter = new ReflectionProperty($connection, 'transactions');
        $previous = $counter->getValue($connection);
        $counter->setValue($connection, 0);

        try {
            $callback();
        } finally {
            $counter->setValue($connection, $previous);
        }
    }

    /**
     * A second, independent session on the same database (the test's own connection is inside an
     * uncommitted transaction; rows that must be visible to both are already committed by the
     * migration).
     */
    public static function probe(): Connection
    {
        config(['database.connections.'.self::PROBE => config('database.connections.'.config('database.default'))]);

        return DB::connection(self::PROBE);
    }

    /**
     * Lower the test session's lock wait so a blocked statement fails fast with 1205.
     */
    public static function shortLockWait(): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    public static function restoreLockWait(): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    /**
     * Sign in a user holding exactly the given permissions.
     */
    public static function actingWith(string ...$permissions): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo($permissions);
        test()->actingAs($actor);

        return $actor;
    }

    /**
     * SQL (lower-cased) of every statement touching one of the invoice numbering tables, in order.
     *
     * @param  Closure(): mixed  $callback
     * @return list<string>
     */
    public static function numberingStatements(Closure $callback): array
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'invoice_sequences') || str_contains($sql, 'invoice_settings')) {
                $statements[] = $sql;
            }
        });

        $callback();

        return $statements;
    }
}
