<?php

// Story 0083 (D-6) -- the dashboard speaks the administrator's language. The locale comes from the
// actor's stored `ui_locale` through the SetUiLocale middleware (never app()->setLocale in the test:
// the middleware re-applies it on every request), so every case goes through a real HTTP request.
//
// Red step: the Livewire classes and the new lang groups do not exist yet.

use App\Enums\BlogPostStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BlogPost;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

const OVERVIEW_NEW_GROUPS = ['hero', 'counters', 'blog', 'stock', 'orders', 'untitled', 'deleted_customer', 'no_widgets'];

/**
 * The page as an actor with the given ui_locale sees it.
 */
function overviewLocaleHtml(string $locale, array $permissions): string
{
    test()->actingAs(Ui::actor($permissions, ['ui_locale' => $locale, 'name' => 'Laura Gómez']));

    return test()->get(route('dashboard'))->assertOk()->getContent();
}

function overviewLocaleAllViews(): array
{
    return ['users.view', 'products.view', 'media.view', 'blog.view', 'orders.view'];
}

test('the hero, the counter labels and the footers are Spanish for a Spanish admin', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, config('app.timezone')));
    BlogPost::factory()->published()->create();
    Product::factory()->active()->physical()->create(['stock' => 1]);
    Order::factory()->create();

    $html = overviewLocaleHtml('es', overviewLocaleAllViews());

    expect(Ui::text($html, 'dashboard-greeting'))->toContain(__('dashboard.hero.greeting_morning', ['name' => 'Laura'], 'es'))
        ->and($html)->toContain(e(__('dashboard.counters.users', [], 'es')))
        ->toContain(e(__('dashboard.counters.products', [], 'es')))
        ->toContain(e(__('dashboard.counters.images', [], 'es')))
        ->toContain(e(__('dashboard.blog.title', [], 'es')))
        ->toContain(e(__('dashboard.stock.title', [], 'es')))
        ->toContain(e(__('dashboard.orders.title', [], 'es')))
        ->and(Ui::text($html, 'dashboard-view-all-blog'))->toContain(__('dashboard.blog.view_all', [], 'es'))
        ->and(Ui::text($html, 'dashboard-view-all-stock'))->toContain(__('dashboard.stock.view_all', [], 'es'))
        ->and(Ui::text($html, 'dashboard-view-all-orders'))->toContain(__('dashboard.orders.view_all', [], 'es'));
});

test('the new Spanish strings really differ from the English ones', function (string $key) {
    expect(__($key, [], 'es'))->not->toBe($key)
        ->and(__($key, ['name' => 'X'], 'es'))->not->toBe(__($key, ['name' => 'X'], 'en'));
})->with([
    'dashboard.hero.greeting_morning',
    'dashboard.hero.greeting_afternoon',
    'dashboard.hero.greeting_evening',
    'dashboard.counters.users',
    'dashboard.blog.title',
    'dashboard.blog.empty',
    'dashboard.stock.title',
    'dashboard.stock.out_of_stock',
    'dashboard.stock.low_stock',
    'dashboard.orders.title',
    'dashboard.orders.empty',
    'dashboard.no_widgets',
]);

test('the greeting follows the time of day in Spanish', function (int $hour, string $kind) {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, $hour, 0, 0, config('app.timezone')));

    $html = overviewLocaleHtml('es', []);

    expect(Ui::text($html, 'dashboard-greeting'))->toContain(__('dashboard.hero.greeting_'.$kind, ['name' => 'Laura'], 'es'));
})->with([
    'morning' => [9, 'morning'],
    'afternoon' => [15, 'afternoon'],
    'evening' => [22, 'evening'],
]);

test('blog badges and the scheduled date are Spanish, the date format unchanged', function () {
    $published = BlogPost::factory()->published()->create(['created_at' => '2026-03-01 09:00:00']);
    $scheduled = BlogPost::factory()->scheduled()->create([
        'published_at' => '2026-10-12 10:00:00',
        'created_at' => '2026-02-01 09:00:00',
    ]);

    $html = overviewLocaleHtml('es', ['blog.view']);

    expect(Ui::text($html, "dashboard-blog-status-{$published->id}"))->toBe(__('blog-posts.statuses.published', [], 'es'))
        ->not->toBe(BlogPostStatus::Published->value)
        ->and(Ui::text($html, "dashboard-blog-status-{$scheduled->id}"))->toBe(__('blog-posts.statuses.scheduled', [], 'es'))
        ->and(Ui::text($html, "dashboard-blog-date-{$scheduled->id}"))->toContain('12/10/2026 10:00');
});

test('the stock badges, units and the variants hint are Spanish, singular and plural', function () {
    $zero = Product::factory()->active()->physical()->create(['stock' => 0]);
    $two = Product::factory()->active()->physical()->create(['stock' => 2]);
    $oneLow = Product::factory()->active()->physical()->create(['stock' => 99]);
    ProductVariant::factory()->create(['product_id' => $oneLow->id, 'stock' => 3]);
    ProductVariant::factory()->create(['product_id' => $oneLow->id, 'stock' => 90]);

    $html = overviewLocaleHtml('es', ['products.view']);

    expect(Ui::text($html, "dashboard-stock-badge-{$zero->id}"))->toBe(__('dashboard.stock.out_of_stock', [], 'es'))
        ->and(Ui::text($html, "dashboard-stock-badge-{$two->id}"))->toBe(__('dashboard.stock.low_stock', [], 'es'))
        ->and(Ui::text($html, "dashboard-stock-units-{$two->id}"))->toBe(trans_choice('dashboard.stock.units', 2, [], 'es'))
        ->and(Ui::text($html, "dashboard-stock-units-{$zero->id}"))->toBe(trans_choice('dashboard.stock.units', 0, [], 'es'))
        ->and(Ui::text($html, "dashboard-stock-variants-{$oneLow->id}"))->toBe(trans_choice('dashboard.stock.variants_low', 1, [], 'es'));
});

test('the variants hint plural is Spanish too', function () {
    $twoLow = Product::factory()->active()->physical()->create(['stock' => 99]);
    ProductVariant::factory()->create(['product_id' => $twoLow->id, 'stock' => 1]);
    ProductVariant::factory()->create(['product_id' => $twoLow->id, 'stock' => 1]);

    $html = overviewLocaleHtml('es', ['products.view']);

    expect(Ui::text($html, "dashboard-stock-variants-{$twoLow->id}"))->toBe(trans_choice('dashboard.stock.variants_low', 2, [], 'es'));
});

test('order statuses and payment states are Spanish', function () {
    $order = Order::factory()->forCustomer(Customer::factory()->create())->create([
        'status' => OrderStatus::Shipped,
        'payment_status' => PaymentStatus::PartiallyRefunded,
        'total' => '100.00',
    ]);

    $html = overviewLocaleHtml('es', ['orders.view']);

    expect(Ui::text($html, "dashboard-order-status-{$order->id}"))->toBe(__('orders.statuses.shipped', [], 'es'))
        ->and(Ui::text($html, "dashboard-order-payment-{$order->id}"))->toBe(__('orders.payment_statuses.partially_refunded', [], 'es'))
        ->and(Ui::text($html, "dashboard-order-total-{$order->id}"))->toBe('€ 100.00');
});

test('the empty states and the nothing-to-show message are Spanish', function () {
    $html = overviewLocaleHtml('es', ['blog.view', 'products.view', 'orders.view']);

    expect(Ui::text($html, 'dashboard-empty-blog'))->toBe(__('dashboard.blog.empty', [], 'es'))
        ->and(Ui::text($html, 'dashboard-empty-stock'))->toBe(__('dashboard.stock.empty', [], 'es'))
        ->and(Ui::text($html, 'dashboard-empty-orders'))->toBe(__('dashboard.orders.empty', [], 'es'));

    $none = overviewLocaleHtml('es', []);

    expect(Ui::text($none, 'dashboard-no-widgets'))->toBe(__('dashboard.no_widgets', [], 'es'));
});

test('an English admin sees the English strings', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    $html = overviewLocaleHtml('en', ['orders.view', 'blog.view']);

    expect(Ui::text($html, "dashboard-order-status-{$order->id}"))->toBe('Shipped')
        ->and(Ui::text($html, 'dashboard-empty-blog'))->toBe(__('dashboard.blog.empty', [], 'en'));
});

test('no raw dashboard translation key leaks into the render in either locale', function (string $locale) {
    BlogPost::factory()->published()->create();
    BlogPost::factory()->scheduled()->create();
    Product::factory()->active()->physical()->create(['stock' => 0]);
    $variable = Product::factory()->active()->physical()->create(['stock' => 50]);
    ProductVariant::factory()->create(['product_id' => $variable->id, 'stock' => 1]);
    Order::factory()->create();

    $html = overviewLocaleHtml($locale, overviewLocaleAllViews());
    $empty = overviewLocaleHtml($locale, []);

    $leak = '/dashboard\.(hero|counters|blog|stock|orders)\.[a-z_]+|dashboard\.(untitled|deleted_customer|no_widgets)\b|orders\.(statuses|payment_statuses)\.[a-z_]+|blog-posts\.statuses\.[a-z_]+/';

    // Guard against a vacuous pass: the real dashboard must have rendered first.
    expect(Ui::present($html, 'dashboard-widget-orders'))->toBeTrue()
        ->and(Ui::present($empty, 'dashboard-no-widgets'))->toBeTrue()
        ->and($html)->not->toMatch($leak)
        ->and($empty)->not->toMatch($leak);
})->with(['en', 'es']);

test('the new dashboard groups exist in en and es', function (string $group, string $locale) {
    $lang = require base_path("lang/{$locale}/dashboard.php");

    expect(array_key_exists($group, $lang))->toBeTrue("lang/{$locale}/dashboard.php lacks the {$group} group");
})->with(OVERVIEW_NEW_GROUPS)->with(['en', 'es']);

test('the new dashboard groups have identical keys and placeholders in en and es', function () {
    $en = require base_path('lang/en/dashboard.php');
    $es = require base_path('lang/es/dashboard.php');

    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($array as $key => $value) {
            is_array($value)
                ? $flat += $flatten($value, $prefix.$key.'.')
                : $flat[$prefix.$key] = $value;
        }

        return $flat;
    };

    $flatEn = $flatten($en);
    $flatEs = $flatten($es);

    expect(array_keys($flatEs))->toEqualCanonicalizing(array_keys($flatEn));

    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/i', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    };

    foreach ($flatEn as $key => $text) {
        expect($placeholders((string) $flatEs[$key]))->toBe($placeholders((string) $text), "placeholders of {$key}");
    }

    foreach (['hero.greeting_morning', 'hero.greeting_afternoon', 'hero.greeting_evening'] as $key) {
        expect($flatEn)->toHaveKey($key)
            ->and($placeholders($flatEn[$key]))->toBe([':name']);
    }

    foreach (['stock.variants_low', 'stock.units'] as $key) {
        expect($flatEn[$key])->toContain('|')
            ->and($flatEs[$key])->toContain('|');
    }
});
