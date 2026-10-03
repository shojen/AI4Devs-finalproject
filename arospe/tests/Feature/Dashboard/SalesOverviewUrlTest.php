<?php

// Story 0086 -- filter state in the URL (#[Url] on g, from, to, s): the lazy handshake, the round
// trip, and the hostile/broken shared links that must open the default overview (HTTP 200, no 500).
//
// Red step: the component does not exist yet.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\SalesOverview;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;
use Tests\Support\Dashboard\SalesOverviewUi as Sales;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(Ui::actor(['orders.view']));
    Sales::today();
});

// =====================================================================
// SPIKE 1 -- a lazy child reads the page's query string
// =====================================================================

it('SPIKE 1: the lazy child, loaded from the page link, restores the filter that link carries', function () {
    // Fallback if this stays red for a real reason: render SalesOverview eagerly (story R-1).
    Sales::order('2026-05-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-05-10 11:00:00', '50.00', OrderStatus::Shipped, PaymentStatus::Paid);
    Sales::order('2026-05-10 12:00:00', '20.00', OrderStatus::Pending);

    $html = Sales::lazyLoadedHtml('/dashboard?g=day&from=2026-05-01&to=2026-05-31&s[]=delivered&s[]=shipped');

    expect($html)->not->toBeNull('the dashboard must carry a lazy placeholder for the sales overview');

    $buckets = Sales::buckets($html);

    expect($buckets)->toHaveCount(31)
        ->and($buckets[0])->toBe('2026-05-01')
        ->and(Sales::orderColumns($html))->toBe(['shipped', 'delivered'])
        ->and(Sales::kpi($html, 'sales'))->toBe('€ 150.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('2');
});

it('the dashboard page is lazy for the sales overview: a placeholder first, no figures in the page response', function () {
    Sales::order('2026-06-10 10:00:00', '987654.32', OrderStatus::Delivered);

    $page = $this->get('/dashboard')->assertOk()->getContent();

    expect($page)->toContain('__lazyLoad')
        ->and(Ui::present($page, 'sales-money-table'))->toBeFalse()
        ->and(Ui::present($page, 'sales-kpi-sales'))->toBeFalse()
        ->and(Ui::present($page, 'sales-summary'))->toBeFalse();
});

it('the lazy placeholder announces the translated loading text to screen readers', function () {
    $placeholder = (new SalesOverview)->placeholder();

    expect($placeholder)->toContain("__('dashboard.sales.loading')")
        ->and(Blade::render($placeholder))->toContain(__('dashboard.sales.loading'));
});

it('the component declares #[Lazy] and keeps its filter state in the URL under g, from, to and s', function () {
    $reflection = new ReflectionClass(SalesOverview::class);

    expect($reflection->getAttributes(Lazy::class))->toHaveCount(1);

    $urlNames = [];

    foreach (['granularity', 'from', 'to', 'statuses'] as $property) {
        $attributes = $reflection->getProperty($property)->getAttributes(Url::class);

        expect($attributes)->toHaveCount(1, "{$property} must be a #[Url] property");

        $urlNames[$property] = $attributes[0]->newInstance()->as;
    }

    expect($urlNames)->toBe(['granularity' => 'g', 'from' => 'from', 'to' => 'to', 'statuses' => 's']);
});

// =====================================================================
// Round trip and defaults
// =====================================================================

it('starts from the documented defaults when the URL carries nothing (so the URL omits them)', function () {
    Sales::component()
        ->assertSet('granularity', 'day')
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSet('statuses', Sales::defaultStatusValues());
});

it('restores the same overview from a filtered link', function () {
    Sales::order('2026-05-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-05-10 11:00:00', '50.00', OrderStatus::Shipped, PaymentStatus::Paid);
    Sales::order('2026-05-10 12:00:00', '20.00', OrderStatus::Pending);

    $component = Sales::component([
        'g' => 'day',
        'from' => '2026-05-01',
        'to' => '2026-05-31',
        's' => ['delivered', 'shipped'],
    ]);

    $component
        ->assertSet('granularity', 'day')
        ->assertSet('from', '2026-05-01')
        ->assertSet('to', '2026-05-31')
        ->assertSet('statuses', ['delivered', 'shipped']);

    $html = $component->html();

    expect(Sales::buckets($html))->toHaveCount(31)
        ->and(Sales::orderColumns($html))->toBe(['shipped', 'delivered'])
        ->and(Sales::kpi($html, 'sales'))->toBe('€ 150.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('2')
        ->and(Sales::chips($html))->toBe([
            'pending' => false,
            'processing' => false,
            'shipped' => true,
            'delivered' => true,
            'cancelled' => false,
        ]);
});

it('restores a month link and a year link at that granularity default range', function (string $granularity, int $points) {
    $html = Sales::component(['g' => $granularity])->html();

    expect(Sales::buckets($html))->toHaveCount($points);
})->with([
    'month' => ['month', 12],
    'year' => ['year', 5],
]);

it('publishes the four filter properties to the URL on the first render', function () {
    $url = Sales::component()->effects['url'] ?? [];

    expect(array_map(fn (array $entry): string => $entry['as'], $url))->toBe([
        'granularity' => 'g',
        'from' => 'from',
        'to' => 'to',
        'statuses' => 's',
    ]);
});

it('a link with a single bound keeps it, as the UI can reach that state', function () {
    $fromOnly = Sales::component(['from' => '2026-06-01'])->assertSet('from', '2026-06-01')->assertSet('to', '');
    $toOnly = Sales::component(['to' => '2026-05-31'])->assertSet('from', '')->assertSet('to', '2026-05-31');

    $fromBuckets = Sales::buckets($fromOnly->html());
    $toBuckets = Sales::buckets($toOnly->html());

    expect($fromBuckets)->toHaveCount(15)
        ->and($fromBuckets[0])->toBe('2026-06-01')
        ->and($fromBuckets[14])->toBe('2026-06-15')
        ->and($toBuckets)->toHaveCount(30)
        ->and($toBuckets[29])->toBe('2026-05-31');
});

// =====================================================================
// Broken links open the default overview
// =====================================================================

dataset('broken shared links', [
    'unknown period' => [['g' => 'garbage']],
    'period as an array' => [['g' => ['day']]],
    'free-text start' => [['from' => 'not-a-date', 'to' => '2026-05-31']],
    'impossible end' => [['from' => '2026-05-01', 'to' => '2026-13-45']],
    'impossible day' => [['from' => '2026-02-30', 'to' => '2026-03-31']],
    'empty bounds' => [['from' => '', 'to' => '']],
    'start as an array' => [['from' => ['2026-05-01'], 'to' => '2026-05-31']],
    'end as an array' => [['from' => '2026-05-01', 'to' => ['2026-05-31']]],
    'year 99999' => [['from' => '99999-01-01', 'to' => '99999-12-31']],
    'before year 1000' => [['from' => '0999-01-01', 'to' => '2026-05-31']],
    'after year 9998' => [['from' => '2026-05-01', 'to' => '9999-12-31']],
    'start after end' => [['from' => '2026-05-31', 'to' => '2026-05-01']],
    'more than 366 days per day' => [['g' => 'day', 'from' => '2025-01-01', 'to' => '2026-06-01']],
    'unknown status' => [['s' => ['garbage']]],
    'status as a string' => [['s' => 'delivered']],
    'six status entries' => [['s' => ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'pending']]],
    'nested status garbage' => [['s' => [['x' => 'y']]]],
]);

it('a shared link with a broken filter still opens the default overview', function (array $query) {
    $component = Sales::component($query);

    $component
        ->assertSet('granularity', 'day')
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSet('statuses', Sales::defaultStatusValues());

    $buckets = Sales::buckets($component->html());

    expect($buckets)->toHaveCount(30)
        ->and($buckets[0])->toBe('2026-05-17')
        ->and($buckets[29])->toBe('2026-06-15')
        ->and(Sales::orderColumns($component->html()))->toBe(Sales::defaultStatusValues());
})->with('broken shared links');

it('the real lazy load answers 200 with the default overview for every broken link', function (array $query) {
    $html = Sales::lazyLoadedHtml('/dashboard?'.http_build_query($query));

    expect($html)->not->toBeNull()
        ->and(Sales::buckets($html))->toHaveCount(30)
        ->and(Sales::orderColumns($html))->toBe(Sales::defaultStatusValues());
})->with('broken shared links');
