<?php

// Story 0083 (D-3, R-3) -- the defensive-null seam. Names, titles and customer names are NOT NULL
// today, so real data cannot produce these rows; the tests FAKE the backend actions (container
// bound to a mock) to prove the widgets stay valid once stories 0076/0078 make the fields nullable.
//
// Red step: the widgets do not exist yet.

use App\Actions\Dashboard\GetLatestBlogPosts;
use App\Actions\Dashboard\GetLatestOrders;
use App\Actions\Dashboard\GetLowStockProducts;
use App\Enums\BlogPostStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\BlogWidget;
use App\Livewire\Dashboard\LatestOrdersWidget;
use App\Livewire\Dashboard\LowStockWidget;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  class-string  $action
 * @param  list<array<string, mixed>>  $rows
 */
function overviewSeamFake(string $action, array $rows): void
{
    test()->mock($action, function ($mock) use ($rows): void {
        $mock->shouldReceive('__invoke')->andReturn($rows);
    });
}

// =====================================================================
// Blog
// =====================================================================

test('a null post title renders the translated Untitled placeholder in a valid linked row', function () {
    overviewSeamFake(GetLatestBlogPosts::class, [[
        'id' => 'seam-post-1',
        'title' => null,
        'description' => 'Some description',
        'status' => BlogPostStatus::Published,
        'publishAt' => null,
    ]]);
    test()->actingAs(Ui::actor(['blog.view', 'blog.edit']));

    $html = Livewire::test(BlogWidget::class)->html();

    expect(Ui::text($html, 'dashboard-blog-title-seam-post-1'))->toBe(__('dashboard.untitled'))
        ->and(Ui::isLink($html, 'dashboard-blog-title-seam-post-1'))->toBeTrue()
        ->and(Ui::attribute($html, 'dashboard-blog-title-seam-post-1', 'href'))->toBe(route('blog-posts.edit', 'seam-post-1'))
        ->and(Ui::text($html, 'dashboard-blog-description-seam-post-1'))->toBe('Some description');
});

test('a null post title in a non-clickable row renders the placeholder as plain text', function () {
    overviewSeamFake(GetLatestBlogPosts::class, [[
        'id' => 'seam-post-2',
        'title' => null,
        'description' => '',
        'status' => BlogPostStatus::Scheduled,
        'publishAt' => CarbonImmutable::parse('2026-10-12 10:00:00', config('app.timezone')),
    ]]);
    test()->actingAs(Ui::actor(['blog.view']));
    Log::spy();

    $html = Livewire::test(BlogWidget::class)->html();

    expect(Ui::text($html, 'dashboard-blog-title-seam-post-2'))->toBe(__('dashboard.untitled'))
        ->and(Ui::isLink($html, 'dashboard-blog-title-seam-post-2'))->toBeFalse()
        ->and(Ui::text($html, 'dashboard-blog-date-seam-post-2'))->toContain('12/10/2026 10:00');

    Log::shouldNotHaveReceived('warning');
});

test('the blog widget never calls its action for an actor without blog.view', function () {
    test()->mock(GetLatestBlogPosts::class, function ($mock): void {
        $mock->shouldNotReceive('__invoke');
    });
    test()->actingAs(Ui::actor(['orders.view']));
    Log::spy();

    $html = Livewire::test(BlogWidget::class)->html();

    expect(Ui::hooksStartingWith($html, 'dashboard-blog-row-'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// Low stock
// =====================================================================

test('a null product name renders the SKU in a valid linked row', function () {
    overviewSeamFake(GetLowStockProducts::class, [[
        'id' => 'seam-product-1',
        'name' => null,
        'sku' => 'SEAM-SKU-001',
        'effectiveStock' => 0,
        'isOutOfStock' => true,
        'hasVariants' => false,
        'lowVariantCount' => 0,
    ]]);
    test()->actingAs(Ui::actor(['products.view', 'products.edit']));

    $html = Livewire::test(LowStockWidget::class)->html();

    expect(Ui::text($html, 'dashboard-stock-name-seam-product-1'))->toBe('SEAM-SKU-001')
        ->and(Ui::isLink($html, 'dashboard-stock-name-seam-product-1'))->toBeTrue()
        ->and(Ui::attribute($html, 'dashboard-stock-name-seam-product-1', 'href'))->toBe(route('products.edit', 'seam-product-1'))
        ->and(Ui::text($html, 'dashboard-stock-badge-seam-product-1'))->toBe(__('dashboard.stock.out_of_stock'));
});

test('a null product name in a non-clickable row renders the SKU as plain text', function () {
    overviewSeamFake(GetLowStockProducts::class, [[
        'id' => 'seam-product-2',
        'name' => null,
        'sku' => 'SEAM-SKU-002',
        'effectiveStock' => 3,
        'isOutOfStock' => false,
        'hasVariants' => false,
        'lowVariantCount' => 0,
    ]]);
    test()->actingAs(Ui::actor(['products.view']));
    Log::spy();

    $html = Livewire::test(LowStockWidget::class)->html();

    expect(Ui::text($html, 'dashboard-stock-name-seam-product-2'))->toBe('SEAM-SKU-002')
        ->and(Ui::isLink($html, 'dashboard-stock-name-seam-product-2'))->toBeFalse()
        ->and(Ui::text($html, 'dashboard-stock-badge-seam-product-2'))->toBe(__('dashboard.stock.low_stock'));

    Log::shouldNotHaveReceived('warning');
});

test('a variants hint needs both variants and a positive low-variant count', function (bool $hasVariants, int $lowVariantCount, bool $shown) {
    overviewSeamFake(GetLowStockProducts::class, [[
        'id' => 'seam-product-3',
        'name' => 'Hint product',
        'sku' => 'SEAM-SKU-003',
        'effectiveStock' => 1,
        'isOutOfStock' => false,
        'hasVariants' => $hasVariants,
        'lowVariantCount' => $lowVariantCount,
    ]]);
    test()->actingAs(Ui::actor(['products.view']));

    $html = Livewire::test(LowStockWidget::class)->html();

    expect(Ui::present($html, 'dashboard-stock-variants-seam-product-3'))->toBe($shown);

    if ($shown) {
        expect(Ui::text($html, 'dashboard-stock-variants-seam-product-3'))->toBe(trans_choice('dashboard.stock.variants_low', $lowVariantCount));
    }
})->with([
    'variants and one low' => [true, 1, true],
    'variants and three low' => [true, 3, true],
    'variants but none low' => [true, 0, false],
    'no variants' => [false, 0, false],
]);

test('the low-stock widget never calls its action for an actor without products.view', function () {
    test()->mock(GetLowStockProducts::class, function ($mock): void {
        $mock->shouldNotReceive('__invoke');
    });
    test()->actingAs(Ui::actor(['blog.view']));
    Log::spy();

    $html = Livewire::test(LowStockWidget::class)->html();

    expect(Ui::hooksStartingWith($html, 'dashboard-stock-row-'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// Orders
// =====================================================================

test('a null customer name renders the translated deleted-customer text in a valid row', function () {
    overviewSeamFake(GetLatestOrders::class, [[
        'id' => 'seam-order-1',
        'orderNumber' => 'ORD-SEAM-1',
        'customerName' => null,
        'total' => '12.50',
        'status' => OrderStatus::Pending,
        'paymentStatus' => PaymentStatus::PendingPayment,
        'createdAt' => CarbonImmutable::parse('2026-03-01 09:00:00', config('app.timezone')),
    ]]);
    test()->actingAs(Ui::actor(['orders.view']));
    Log::spy();

    $html = Livewire::test(LatestOrdersWidget::class)->html();

    expect(Ui::text($html, 'dashboard-order-customer-seam-order-1'))->toBe(__('dashboard.deleted_customer'))
        ->and(Ui::isLink($html, 'dashboard-order-link-seam-order-1'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-order-link-seam-order-1'))->toContain('ORD-SEAM-1')
        ->and(Ui::text($html, 'dashboard-order-total-seam-order-1'))->toBe('€ 12.50')
        ->and(Ui::text($html, 'dashboard-order-status-seam-order-1'))->toBe(OrderStatus::Pending->label());

    Log::shouldNotHaveReceived('warning');
});

test('the orders widget never calls its action for an actor without orders.view', function () {
    test()->mock(GetLatestOrders::class, function ($mock): void {
        $mock->shouldNotReceive('__invoke');
    });
    test()->actingAs(Ui::actor(['products.view']));
    Log::spy();

    $html = Livewire::test(LatestOrdersWidget::class)->html();

    expect(Ui::hooksStartingWith($html, 'dashboard-order-row-'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

test('no seam row produces an empty link text', function () {
    overviewSeamFake(GetLatestBlogPosts::class, [[
        'id' => 'seam-post-9',
        'title' => null,
        'description' => '',
        'status' => BlogPostStatus::Published,
        'publishAt' => null,
    ]]);
    test()->actingAs(Ui::actor(['blog.view', 'blog.edit']));

    $html = Livewire::test(BlogWidget::class)->html();

    expect(Ui::text($html, 'dashboard-blog-title-seam-post-9'))->not->toBe('')->not->toBeNull();
});
