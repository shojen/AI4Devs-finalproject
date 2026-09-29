<?php

namespace Tests\Support\Blog;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes the next UPDATE(s) against `blog_posts` throw, so a test can prove what the scheduled sweep
 * does when a write fails (story 0064: nothing is announced, and one bad row does not stop the run).
 *
 * The hook runs BEFORE the statement executes, so a refused write leaves the row exactly as it was.
 * A class with static methods rather than a global Pest helper, following tests/Support/Orders: the
 * three sweep test files share it and a redeclared global function is a fatal error.
 */
final class FailingBlogPostWrites
{
    /**
     * @param  int  $times  How many UPDATE statements against `blog_posts` fail before writes succeed again.
     */
    public static function next(int $times = 1): void
    {
        $remaining = $times;

        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$remaining): void {
            if ($remaining > 0 && str_starts_with($query, 'update `blog_posts`')) {
                $remaining--;

                throw new RuntimeException('Simulated blog_posts write failure.');
            }
        });
    }
}
