<?php

namespace Tests\Support\Dashboard;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Story 0082, D-1's query-count convention: counts the queries a callback runs against the
 * DOMAIN tables only (permission, role, session, cache and migration tables are ignored, because a
 * Gate check on a fresh actor loads roles and permissions through spatie).
 *
 * A static class, never global functions: a global helper declared by two test files is a fatal
 * redeclare (see tests/Support/Orders/OrdersUi.php).
 */
final class DomainQueryLog
{
    /** @var list<string> */
    public const TABLES = ['users', 'products', 'product_variants', 'media', 'blog_posts', 'orders', 'customers'];

    /**
     * Run $callback and count every SELECT/INSERT/UPDATE/DELETE statement per domain table it named
     * (FROM, JOIN, UPDATE, INSERT INTO, DELETE FROM). A statement naming a table several times
     * counts once for it; a join counts once for each domain table it names. Every domain table is
     * present in the result, zero-filled, so callers can index any of them.
     *
     * @return array<string, int>
     */
    public static function capture(callable $callback): array
    {
        $counts = array_fill_keys(self::TABLES, 0);
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$counts, &$recording): void {
            if (! $recording) {
                return;
            }

            foreach (self::tablesNamedBy($query->sql) as $table) {
                $counts[$table]++;
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $counts;
    }

    /**
     * Run $callback and return the number of STATEMENTS that named at least one domain table, each
     * statement counted once however many domain tables it joins (the low-stock query joins
     * products to a product_variants subquery and is still one query).
     */
    public static function statements(callable $callback): int
    {
        $count = 0;
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$count, &$recording): void {
            if ($recording && self::tablesNamedBy($query->sql) !== []) {
                $count++;
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $count;
    }

    /**
     * The total of the per-table counts of capture(). A statement joining two domain tables counts
     * once for each (use statements() for the number of queries).
     *
     * @param  array<string, int>  $counts
     */
    public static function total(array $counts): int
    {
        return array_sum($counts);
    }

    /**
     * @return list<string> the distinct domain tables a statement names
     */
    private static function tablesNamedBy(string $sql): array
    {
        if (! preg_match('/^\s*(select|insert|update|delete)\b/i', $sql)) {
            return [];
        }

        preg_match_all('/\b(?:from|join|update|into)\s+[`"]?(\w+)[`"]?/i', $sql, $matches);

        return array_values(array_intersect(array_unique($matches[1]), self::TABLES));
    }
}
