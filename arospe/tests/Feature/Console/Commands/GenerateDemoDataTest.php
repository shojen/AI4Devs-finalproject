<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Artisan;

// Story 0081: the console entry point for DemoDataSeeder. Thin by design -- these tests prove the
// command's OWN responsibilities (registration, the environment guard, delegating to the seeder),
// not the seeder's data-generation detail, which tests/Feature/Seeders/DemoDataSeederTest.php
// already owns in full. Assertions here are deliberately coarse counts.
//
// Environment forcing: `app()->instance('env', $environment)` overrides the container's 'env'
// binding, which Illuminate\Foundation\Application::environment() reads directly -- the same
// mechanism tests/Feature/Seeders/DatabaseSeederTest.php already relies on for DatabaseSeeder's
// own ['local', 'testing'] allow-list. Each Pest test boots a fresh Application instance, so
// nothing needs to be restored afterwards.

/**
 * Snapshot counts for the seven tables DemoDataSeeder writes to -- used by the environment-guard
 * negative test to prove a refused run makes zero writes to any of them.
 *
 * @return array<string, int>
 */
function demoDataTableCounts(): array
{
    return [
        'customers' => Customer::count(),
        'orders' => Order::count(),
        'products' => Product::count(),
        'product_categories' => ProductCategory::count(),
        'blog_posts' => BlogPost::count(),
        'blog_categories' => BlogCategory::count(),
        'blog_tags' => BlogTag::count(),
    ];
}

test('demo:generate-data is registered with its description', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('demo:generate-data');

    expect($commands['demo:generate-data']->getDescription())
        ->toBe('Generate demo data (customers, orders, products, categories, blog content) for a local or demo environment');
});

test('demo:generate-data succeeds in the testing environment and adds the expected demo rows', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();

    expect(Customer::count())->toBe(10)
        ->and(ProductCategory::count())->toBe(10)
        ->and(Product::count())->toBe(10)
        ->and(BlogCategory::count())->toBe(10)
        ->and(BlogTag::count())->toBe(10)
        ->and(BlogPost::count())->toBe(10)
        ->and(Order::count())->toBeGreaterThanOrEqual(10);
});

// A blocklist-shaped bug (e.g. `if (app()->environment('production'))`) would let `staging`
// through, so both environments are tested rather than `production` alone.
test('demo:generate-data refuses to run outside local/testing without --force, and writes nothing', function (string $environment) {
    app()->instance('env', $environment);
    $before = demoDataTableCounts();

    $this->artisan('demo:generate-data')
        ->expectsOutputToContain('local')
        ->expectsOutputToContain('testing')
        ->expectsOutputToContain('--force')
        ->assertFailed();

    expect(demoDataTableCounts())->toBe($before);
})->with(['production', 'staging']);

test('demo:generate-data runs anyway when --force is passed in a non-allow-listed environment', function () {
    app()->instance('env', 'staging');

    $this->artisan('demo:generate-data', ['--force' => true])->assertSuccessful();

    expect(Customer::count())->toBe(10)
        ->and(ProductCategory::count())->toBe(10)
        ->and(Product::count())->toBe(10)
        ->and(BlogCategory::count())->toBe(10)
        ->and(BlogTag::count())->toBe(10)
        ->and(BlogPost::count())->toBe(10)
        ->and(Order::count())->toBeGreaterThanOrEqual(10);
});

// D-2's additive behaviour, together with D-4's per-customer order guarantee. Both runs happen in
// one PHP process, where `fake()->unique()` state is shared, so this does NOT guarantee against a
// Faker-uniqueness collision across two SEPARATE `php artisan demo:generate-data` invocations --
// that cross-process case is a documented, accepted limitation (story 0081, Dependencies/risks),
// not something this test asserts against.
test('running the command twice adds a second batch, and every customer old and new still has an order', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();
    $this->artisan('demo:generate-data')->assertSuccessful();

    expect(Customer::count())->toBe(20)
        ->and(ProductCategory::count())->toBe(20)
        ->and(Product::count())->toBe(20)
        ->and(BlogCategory::count())->toBe(20)
        ->and(BlogTag::count())->toBe(20)
        ->and(BlogPost::count())->toBe(20)
        ->and(Order::count())->toBeGreaterThanOrEqual(20)
        ->and(Customer::query()->doesntHave('orders')->exists())->toBeFalse();
});

// Story 0082 (D-6/"Modify DemoDataSeeder"): the dashboard's Real income and cancelled-orders views
// need demonstrable data. The seeder makes the FIRST seeded order paid and the SECOND cancelled
// (deterministic, like 0081's always-tagged first post), and never produces a refund (refunds need
// line-level RecordRefund data the seeder does not build). 0081's invariants must survive.
test('one seeding run yields a paid non-cancelled order and a cancelled order', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();

    $paidAndLive = Order::query()
        ->where('payment_status', PaymentStatus::Paid)
        ->where('status', '!=', OrderStatus::Cancelled)
        ->count();
    $cancelled = Order::query()->where('status', OrderStatus::Cancelled)->count();

    expect($paidAndLive)->toBeGreaterThanOrEqual(1)
        ->and($cancelled)->toBeGreaterThanOrEqual(1);
});

test('the cancelled demo order is a different order from the paid one, and is not paid-and-live', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();

    $paidIds = Order::query()
        ->where('payment_status', PaymentStatus::Paid)
        ->where('status', '!=', OrderStatus::Cancelled)
        ->pluck('id');
    $cancelledIds = Order::query()->where('status', OrderStatus::Cancelled)->pluck('id');

    expect($paidIds)->not->toBeEmpty()
        ->and($cancelledIds)->not->toBeEmpty()
        ->and($paidIds->intersect($cancelledIds))->toBeEmpty();
});

test('the seeder never creates a refunded or partially refunded order, nor a refunded amount', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();

    expect(Order::query()->whereIn('payment_status', [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])->count())->toBe(0)
        ->and(Order::query()->where('refunded_amount', '>', 0)->count())->toBe(0);
});

test('every seeding run contributes its own paid and cancelled order, deterministically', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();
    $this->artisan('demo:generate-data')->assertSuccessful();

    expect(Order::query()->where('payment_status', PaymentStatus::Paid)->where('status', '!=', OrderStatus::Cancelled)->count())->toBeGreaterThanOrEqual(2)
        ->and(Order::query()->where('status', OrderStatus::Cancelled)->count())->toBeGreaterThanOrEqual(2)
        ->and(Order::query()->whereIn('payment_status', [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])->count())->toBe(0);
});

test('with paid and cancelled orders seeded, 0081s invariants hold: 1-3 orders per customer and totals equal their items', function () {
    $this->artisan('demo:generate-data')->assertSuccessful();

    foreach (Customer::query()->withCount('orders')->get() as $customer) {
        expect($customer->orders_count)->toBeBetween(1, 3);
    }

    foreach (Order::query()->with('items')->get() as $order) {
        $itemsTotal = $order->items->sum(fn ($item): float => (float) $item->line_total);

        expect((float) $order->total)->toEqualWithDelta($itemsTotal + (float) $order->tax_amount + (float) $order->shipping_amount, 0.001);
    }
});
