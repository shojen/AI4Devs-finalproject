<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Thin console entry point for `DemoDataSeeder` (story 0081): the command owns the environment
 * guard, the seeder owns none of its own. Per D-1, the command runs unconditionally in
 * `local`/`testing`; any other environment (including `production` and any future
 * `staging`/`demo`) is refused unless `--force` is passed, matching `DatabaseSeeder.php`'s own
 * `app()->environment(['local', 'testing'])` allow-list precedent.
 */
#[Signature('demo:generate-data {--force : Allow running outside the local/testing environment allow-list}')]
#[Description('Generate demo data (customers, orders, products, categories, blog content) for a local or demo environment')]
class GenerateDemoData extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            // Three separate output calls, each carrying exactly one of the substrings the test
            // asserts on ("local", "testing", "--force") -- Laravel's `expectsOutputToContain()`
            // test double matches at most one expectation per underlying `doWrite()` call, so a
            // single line naming all three would leave two of them silently unmatched.
            $this->error('Demo data generation is refused in this environment.');
            $this->line('It only runs automatically in the local environment.');
            $this->line('It also runs automatically in the testing environment.');
            $this->line('Pass --force to run it anyway in any other environment, including this one -- this mixes demo rows into whatever real data the target environment already holds, irreversibly.');

            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => DemoDataSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
