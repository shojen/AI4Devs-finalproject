<?php

use App\Actions\Dashboard\GetDashboardCounters;
use App\Actions\Dashboard\GetLatestBlogPosts;
use App\Actions\Dashboard\GetLatestOrders;
use App\Actions\Dashboard\GetLowStockProducts;
use App\Actions\Dashboard\GetOrdersSeries;
use App\Actions\Dashboard\GetSalesSeries;
use App\Enums\SalesGranularity;
use App\Models\BlogPost;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;

// Story 0082 (D-1 query-count convention, acceptance criteria), Phase 3 TDD red step. Counts are
// STATEMENTS against domain tables (permission/role/session queries excluded): counters <= 3, posts
// 1, low stock 2, latest orders 2, each series action 1 -- constant however big the data is -- and
// every statement an action issues is a read.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);
});

/**
 * @return list<string> the SQL of every statement run while $callback executes
 */
function sqlRunDuring(callable $callback): array
{
    $statements = [];
    $recording = true;

    DB::listen(function ($query) use (&$statements, &$recording): void {
        if ($recording) {
            $statements[] = $query->sql;
        }
    });

    try {
        $callback();
    } finally {
        $recording = false;
    }

    return $statements;
}

function seedQueryCountFixtures(int $scale): void
{
    foreach (range(1, $scale) as $index) {
        $product = Product::factory()->active()->physical()->create(['stock' => $index]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => $index + 1]);
        BlogPost::factory()->published()->create(['created_at' => Carbon::parse('2026-05-01')->addMinutes($index)]);
        Order::factory()->create(['created_at' => Carbon::parse('2026-05-02 10:00:00', 'Europe/Madrid')->addMinutes($index)]);
        Media::factory()->create();
    }
}

/**
 * The action under measurement, by dataset key (a dataset row holding a closure would be resolved
 * by Pest before the test runs, so the rows carry names instead).
 */
function invokeDashboardAction(string $name): mixed
{
    $from = Carbon::parse('2026-05-01', 'Europe/Madrid');
    $to = Carbon::parse('2026-05-31', 'Europe/Madrid');

    return match ($name) {
        'counters' => app(GetDashboardCounters::class)(),
        'posts' => app(GetLatestBlogPosts::class)(),
        'low-stock' => app(GetLowStockProducts::class)(),
        'orders' => app(GetLatestOrders::class)(),
        'sales-series' => app(GetSalesSeries::class)(SalesGranularity::Day, $from, $to),
        'orders-series' => app(GetOrdersSeries::class)(SalesGranularity::Day, $from, $to),
    };
}

dataset('dashboardActionCounts', [
    'counters (at most 3)' => ['counters', 3],
    'latest blog posts' => ['posts', 1],
    'low stock products' => ['low-stock', 2],
    'latest orders' => ['orders', 2],
    'sales series' => ['sales-series', 1],
    'orders series' => ['orders-series', 1],
]);

it('issues the documented number of domain-table statements regardless of data volume', function (string $action, int $expected, int $scale) {
    seedQueryCountFixtures($scale);

    expect(DomainQueryLog::statements(fn () => invokeDashboardAction($action)))->toBe($expected);
})->with('dashboardActionCounts')->with([3, 30]);

it('issues at most the documented number of domain-table statements on empty tables', function (string $action, int $expected) {
    expect(DomainQueryLog::statements(fn () => invokeDashboardAction($action)))->toBeLessThanOrEqual($expected);
})->with('dashboardActionCounts');

it('runs only SELECT statements', function (string $action) {
    seedQueryCountFixtures(3);

    $statements = sqlRunDuring(fn () => invokeDashboardAction($action));

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect($sql)->toMatch('/^\s*select\b/i');
    }
})->with('dashboardActionCounts');
