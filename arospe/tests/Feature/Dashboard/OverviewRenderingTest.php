<?php

// Story 0083 -- rendering of the dashboard home (App\Livewire\Dashboard\Overview and its three
// widgets). Every assertion is against the RENDERED html, probed by `data-test` hook, never by
// markup structure or classes (except the badge colours the story names).
//
// Red step: none of the Livewire classes exists yet.

use App\Enums\BlogPostStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\Overview;
use App\Models\BlogPost;
use App\Models\Customer;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Every ability the dashboard reads, on a non-Super-Admin actor. */
const OVERVIEW_ALL_VIEW = ['users.view', 'products.view', 'media.view', 'blog.view', 'orders.view'];

/**
 * @param  array<int, string>  $permissions
 * @param  array<string, mixed>  $attributes
 */
function overviewRenderHtml(array $permissions, array $attributes = []): string
{
    test()->actingAs(Ui::actor($permissions, $attributes));

    return Livewire::test(Overview::class)->html();
}

// =====================================================================
// Hero: greeting and counters
// =====================================================================

test('the hero greets the actor by first name only and shows the three counters', function () {
    User::factory()->count(5)->create();
    User::factory()->inactive()->create();
    Product::factory()->count(6)->create();
    Media::factory()->count(12)->create();

    $html = overviewRenderHtml(OVERVIEW_ALL_VIEW, ['name' => 'Laura Gómez Ruiz']);

    expect(Ui::present($html, 'dashboard-greeting'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-greeting'))->toContain('Laura')->not->toContain('Gómez')->not->toContain('Ruiz')
        ->and(Ui::present($html, 'dashboard-counters'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-counter-users'))->toContain('6')
        ->and(Ui::text($html, 'dashboard-counter-products'))->toContain('6')
        ->and(Ui::text($html, 'dashboard-counter-images'))->toContain('12')
        ->and($html)->toContain(__('dashboard.counters.users'))
        ->toContain(__('dashboard.counters.products'))
        ->toContain(__('dashboard.counters.images'));
});

test('a name without a space is greeted whole', function () {
    $html = overviewRenderHtml([], ['name' => 'Madonna']);

    expect(Ui::text($html, 'dashboard-greeting'))->toContain('Madonna');
});

test('the greeting follows the time of day in the application timezone', function (string $time, string $kind) {
    [$hour, $minute] = array_map('intval', explode(':', $time));
    Carbon::setTestNow(Carbon::create(2026, 10, 1, $hour, $minute, 0, config('app.timezone')));

    $html = overviewRenderHtml([], ['name' => 'Laura Gómez']);
    $greeting = Ui::text($html, 'dashboard-greeting');

    expect($greeting)->toContain(__('dashboard.hero.greeting_'.$kind, ['name' => 'Laura']));

    foreach (array_diff(['morning', 'afternoon', 'evening'], [$kind]) as $other) {
        expect($greeting)->not->toContain(__('dashboard.hero.greeting_'.$other, ['name' => 'Laura']));
    }
})->with([
    '04:59 is evening' => ['04:59', 'evening'],
    '05:00 is morning' => ['05:00', 'morning'],
    '11:59 is morning' => ['11:59', 'morning'],
    '12:00 is afternoon' => ['12:00', 'afternoon'],
    '19:59 is afternoon' => ['19:59', 'afternoon'],
    '20:00 is evening' => ['20:00', 'evening'],
]);

test('a counter whose value is zero renders 0, not nothing', function () {
    $html = overviewRenderHtml(['products.view', 'media.view']);

    expect(Ui::text($html, 'dashboard-counter-products'))->toMatch('/(^|\D)0(\D|$)/')
        ->and(Ui::text($html, 'dashboard-counter-images'))->toMatch('/(^|\D)0(\D|$)/');
});

test('partially hidden counters render only the visible ones', function () {
    Product::factory()->count(2)->create();
    Media::factory()->count(3)->create();

    $html = overviewRenderHtml(['products.view', 'media.view']);

    expect(Ui::present($html, 'dashboard-counters'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-counter-products'))->toContain('2')
        ->and(Ui::text($html, 'dashboard-counter-images'))->toContain('3')
        ->and(Ui::present($html, 'dashboard-counter-users'))->toBeFalse();
});

test('with no visible counter the stats container is omitted but the greeting stays', function () {
    $html = overviewRenderHtml(['orders.view'], ['name' => 'Olga Pérez']);

    expect(Ui::present($html, 'dashboard-greeting'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-counters'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-counter-users'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-counter-products'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-counter-images'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-no-widgets'))->toBeFalse();
});

test('an actor who may see nothing is told there is nothing to show yet', function () {
    $html = overviewRenderHtml([]);

    expect(Ui::present($html, 'dashboard-greeting'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-no-widgets'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-no-widgets'))->toBe(__('dashboard.no_widgets'))
        ->and(Ui::present($html, 'dashboard-counters'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-widget-blog'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-widget-stock'))->toBeFalse()
        ->and(Ui::present($html, 'dashboard-widget-orders'))->toBeFalse();
});

test('the nothing-to-show message is absent as soon as one counter or widget is visible', function (string $permission) {
    $html = overviewRenderHtml([$permission]);

    expect(Ui::present($html, 'dashboard-no-widgets'))->toBeFalse();
})->with(['users.view', 'media.view', 'blog.view', 'orders.view', 'products.view']);

// =====================================================================
// Blog widget
// =====================================================================

test('the blog widget lists the 3 newest published or scheduled posts with title, badge and no image', function () {
    $oldest = BlogPost::factory()->published()->create(['title' => 'Oldest post', 'created_at' => '2026-01-01 09:00:00']);
    $middle = BlogPost::factory()->published()->create(['title' => 'Middle post', 'created_at' => '2026-02-01 09:00:00']);
    $newest = BlogPost::factory()->published()->create(['title' => 'Newest post', 'created_at' => '2026-03-01 09:00:00']);
    $scheduled = BlogPost::factory()->scheduled()->create(['title' => 'Scheduled post', 'created_at' => '2026-02-15 09:00:00']);
    $draft = BlogPost::factory()->draft()->create(['title' => 'Draft post', 'created_at' => '2026-04-01 09:00:00']);

    $html = overviewRenderHtml(['blog.view']);

    expect(Ui::present($html, 'dashboard-widget-blog'))->toBeTrue()
        ->and($html)->toContain(__('dashboard.blog.title'))
        ->and(Ui::text($html, "dashboard-blog-title-{$newest->id}"))->toBe('Newest post')
        ->and(Ui::text($html, "dashboard-blog-title-{$scheduled->id}"))->toBe('Scheduled post')
        ->and(Ui::text($html, "dashboard-blog-title-{$middle->id}"))->toBe('Middle post')
        ->and(Ui::present($html, "dashboard-blog-row-{$oldest->id}"))->toBeFalse()
        ->and(Ui::present($html, "dashboard-blog-row-{$draft->id}"))->toBeFalse()
        ->and(Ui::position($html, "dashboard-blog-row-{$newest->id}"))->toBeLessThan(Ui::position($html, "dashboard-blog-row-{$scheduled->id}"))
        ->and(Ui::position($html, "dashboard-blog-row-{$scheduled->id}"))->toBeLessThan(Ui::position($html, "dashboard-blog-row-{$middle->id}"))
        ->and($html)->not->toContain('<img');
});

test('a post state decides its badge and whether a go-live date is shown', function () {
    $published = BlogPost::factory()->published()->create(['created_at' => '2026-03-01 09:00:00']);
    $scheduled = BlogPost::factory()->scheduled()->create([
        'published_at' => '2026-10-12 10:00:00',
        'created_at' => '2026-02-01 09:00:00',
    ]);

    $html = overviewRenderHtml(['blog.view']);

    expect(Ui::text($html, "dashboard-blog-status-{$published->id}"))->toBe(BlogPostStatus::Published->label())
        ->and(Ui::text($html, "dashboard-blog-status-{$scheduled->id}"))->toBe(BlogPostStatus::Scheduled->label())
        ->and(Ui::present($html, "dashboard-blog-date-{$published->id}"))->toBeFalse()
        ->and(Ui::text($html, "dashboard-blog-date-{$scheduled->id}"))->toContain('12/10/2026 10:00');
});

test('the blog badge keeps its English words and colours', function () {
    $published = BlogPost::factory()->published()->create(['created_at' => '2026-03-01 09:00:00']);
    $scheduled = BlogPost::factory()->scheduled()->create(['created_at' => '2026-02-01 09:00:00']);

    $html = overviewRenderHtml(['blog.view']);

    expect(Ui::text($html, "dashboard-blog-status-{$published->id}"))->toBe('Published')
        ->and(Ui::tag($html, "dashboard-blog-status-{$published->id}"))->toContain('text-lime-800')
        ->and(Ui::text($html, "dashboard-blog-status-{$scheduled->id}"))->toBe('Scheduled')
        ->and(Ui::tag($html, "dashboard-blog-status-{$scheduled->id}"))->toContain('text-amber-700');
});

test('the description is plain text of at most 80 characters', function () {
    $post = BlogPost::factory()->published()->create([
        'body' => '<h2>Heading</h2><p>'.str_repeat('palabra ', 60).'</p><script>alert(1)</script>',
    ]);
    $short = BlogPost::factory()->published()->create([
        'body' => '<p>Short <b>body</b></p>',
        'created_at' => '2025-01-01 00:00:00',
    ]);

    $html = overviewRenderHtml(['blog.view']);
    $description = Ui::text($html, "dashboard-blog-description-{$post->id}");

    expect(mb_strlen($description))->toBeLessThanOrEqual(80)
        ->and($description)->toStartWith('Heading palabra')
        ->and($description)->not->toContain('<')
        ->and(Ui::text($html, "dashboard-blog-description-{$short->id}"))->toBe('Short body');
});

test('hostile blog titles and descriptions render as text, never as markup', function (string $payload) {
    $post = BlogPost::factory()->published()->create([
        'title' => $payload,
        'body' => '&lt;img src=x onerror=alert(1)&gt; '.$payload,
    ]);

    $html = overviewRenderHtml(['blog.view', 'blog.edit']);

    expect(Ui::text($html, "dashboard-blog-title-{$post->id}"))->toBe($payload)
        ->and($html)->not->toContain('<img src=x')
        ->not->toContain('<script>alert(1)')
        ->not->toContain('onerror=alert(1)>')
        ->and(Ui::text($html, "dashboard-blog-description-{$post->id}"))->not->toBeNull();
})->with([
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'script breakout' => ['"><script>alert(1)</script>'],
    'blade echo' => ['{{ 7*7 }} {!! 8*8 !!}'],
    'blade directive' => ['@php echo 1; @endphp @if(true) yes @endif'],
]);

test('the blog widget footer links to the blog list', function () {
    BlogPost::factory()->published()->create();

    $html = overviewRenderHtml(['blog.view']);

    expect(Ui::isLink($html, 'dashboard-view-all-blog'))->toBeTrue()
        ->and(Ui::attribute($html, 'dashboard-view-all-blog', 'href'))->toBe(route('blog-posts.index'))
        ->and(Ui::text($html, 'dashboard-view-all-blog'))->toContain(__('dashboard.blog.view_all'));
});

test('the blog widget shows its empty state when there are no posts to show', function () {
    BlogPost::factory()->draft()->create();

    $html = overviewRenderHtml(['blog.view']);

    expect(Ui::present($html, 'dashboard-widget-blog'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-empty-blog'))->toBe(__('dashboard.blog.empty'))
        ->and(Ui::hooksStartingWith($html, 'dashboard-blog-row-'))->toBe([]);
});

test('the blog empty state is absent when there are posts', function () {
    BlogPost::factory()->published()->create();

    expect(Ui::present(overviewRenderHtml(['blog.view']), 'dashboard-empty-blog'))->toBeFalse();
});

// =====================================================================
// Low-stock widget
// =====================================================================

/**
 * @param  list<int>  $variantStocks
 * @param  array<string, mixed>  $attributes
 */
function overviewStockProduct(int $stock, array $variantStocks = [], array $attributes = []): Product
{
    $product = Product::factory()->active()->physical()->create(array_merge(['stock' => $stock], $attributes));

    foreach ($variantStocks as $variantStock) {
        ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => $variantStock]);
    }

    return $product;
}

test('the low-stock widget lists the emptiest products with the right badge and units', function () {
    $fifty = overviewStockProduct(50, [], ['name' => 'Fifty product']);
    $two = overviewStockProduct(2, [], ['name' => 'Two product']);
    $zero = overviewStockProduct(0, [], ['name' => 'Zero product']);
    $seven = overviewStockProduct(7, [], ['name' => 'Seven product']);
    $virtual = Product::factory()->active()->virtual()->create(['stock' => 0, 'name' => 'Virtual product']);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::present($html, 'dashboard-widget-stock'))->toBeTrue()
        ->and($html)->toContain(__('dashboard.stock.title'))
        ->and(Ui::text($html, "dashboard-stock-name-{$zero->id}"))->toBe('Zero product')
        ->and(Ui::text($html, "dashboard-stock-name-{$two->id}"))->toBe('Two product')
        ->and(Ui::text($html, "dashboard-stock-name-{$seven->id}"))->toBe('Seven product')
        ->and(Ui::present($html, "dashboard-stock-row-{$fifty->id}"))->toBeFalse()
        ->and(Ui::present($html, "dashboard-stock-row-{$virtual->id}"))->toBeFalse()
        ->and(Ui::position($html, "dashboard-stock-row-{$zero->id}"))->toBeLessThan(Ui::position($html, "dashboard-stock-row-{$two->id}"))
        ->and(Ui::position($html, "dashboard-stock-row-{$two->id}"))->toBeLessThan(Ui::position($html, "dashboard-stock-row-{$seven->id}"))
        ->and(Ui::text($html, "dashboard-stock-units-{$two->id}"))->toContain('2')
        ->and(Ui::text($html, "dashboard-stock-units-{$seven->id}"))->toContain('7');
});

test('stock 0 carries a red Out of stock badge and low stock an amber Low stock badge', function () {
    $zero = overviewStockProduct(0);
    $two = overviewStockProduct(2);
    $seven = overviewStockProduct(7);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::text($html, "dashboard-stock-badge-{$zero->id}"))->toBe('Out of stock')
        ->toBe(__('dashboard.stock.out_of_stock'))
        ->and(Ui::tag($html, "dashboard-stock-badge-{$zero->id}"))->toContain('text-red-700')->not->toContain('text-amber-700')
        ->and(Ui::text($html, "dashboard-stock-badge-{$two->id}"))->toBe('Low stock')
        ->toBe(__('dashboard.stock.low_stock'))
        ->and(Ui::tag($html, "dashboard-stock-badge-{$two->id}"))->toContain('text-amber-700')->not->toContain('text-red-700')
        ->and(Ui::text($html, "dashboard-stock-badge-{$seven->id}"))->toBe('Low stock');
});

test('negative stock is out of stock too', function () {
    $negative = overviewStockProduct(-3);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::text($html, "dashboard-stock-badge-{$negative->id}"))->toBe('Out of stock');
});

test('a variable product shows once as its parent with a singular or plural variants-low hint', function (array $variantStocks, string $hint) {
    $parent = overviewStockProduct(99, $variantStocks, ['name' => 'Variable parent']);

    $html = overviewRenderHtml(['products.view']);

    expect(substr_count($html, 'data-test="dashboard-stock-row-'.$parent->id.'"'))->toBe(1)
        ->and(Ui::text($html, "dashboard-stock-name-{$parent->id}"))->toBe('Variable parent')
        ->and(Ui::text($html, "dashboard-stock-variants-{$parent->id}"))->toBe($hint)
        ->and(Ui::text($html, "dashboard-stock-units-{$parent->id}"))->toContain('1')
        ->and(Ui::text($html, "dashboard-stock-units-{$parent->id}"))->not->toContain('99');
})->with([
    'one low variant' => [[1, 50], '1 variant low'],
    'two low variants' => [[1, 1], '2 variants low'],
]);

test('the variants-low hint is the trans_choice of its key', function () {
    $one = overviewStockProduct(99, [1, 50]);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::text($html, "dashboard-stock-variants-{$one->id}"))->toBe(trans_choice('dashboard.stock.variants_low', 1));
});

test('a simple product shows no variants hint', function () {
    $simple = overviewStockProduct(1);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::present($html, "dashboard-stock-row-{$simple->id}"))->toBeTrue()
        ->and(Ui::present($html, "dashboard-stock-variants-{$simple->id}"))->toBeFalse();
});

test('hostile product names render as text', function (string $payload) {
    $product = overviewStockProduct(1, [], ['name' => $payload]);

    $html = overviewRenderHtml(['products.view', 'products.edit']);

    expect(Ui::text($html, "dashboard-stock-name-{$product->id}"))->toBe($payload)
        ->and($html)->not->toContain('<img src=x')->not->toContain('<script>alert(1)');
})->with([
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'script breakout' => ['"><script>alert(1)</script>'],
    'blade echo' => ['{{ 7*7 }}'],
]);

test('the low-stock footer links to the product list', function () {
    overviewStockProduct(1);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::isLink($html, 'dashboard-view-all-stock'))->toBeTrue()
        ->and(Ui::attribute($html, 'dashboard-view-all-stock', 'href'))->toBe(route('products.index'))
        ->and(Ui::text($html, 'dashboard-view-all-stock'))->toContain(__('dashboard.stock.view_all'));
});

test('the low-stock widget shows its empty state when no product can run out', function () {
    Product::factory()->active()->virtual()->create(['stock' => 0]);
    Product::factory()->draft()->physical()->create(['stock' => 0]);

    $html = overviewRenderHtml(['products.view']);

    expect(Ui::text($html, 'dashboard-empty-stock'))->toBe(__('dashboard.stock.empty'))
        ->and(Ui::hooksStartingWith($html, 'dashboard-stock-row-'))->toBe([]);
});

// =====================================================================
// Latest orders widget
// =====================================================================

test('the orders widget lists the 5 newest orders, each linking to its detail page', function () {
    $orders = [];

    foreach (range(1, 7) as $index) {
        $orders[$index] = Order::factory()->create([
            'order_number' => 'ORD-LIST-'.$index,
            'created_at' => sprintf('2026-03-%02d 09:00:00', $index),
        ]);
    }

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::present($html, 'dashboard-widget-orders'))->toBeTrue()
        ->and($html)->toContain(__('dashboard.orders.title'))
        ->and(count(Ui::hooksStartingWith($html, 'dashboard-order-row-')))->toBe(5)
        ->and(Ui::present($html, "dashboard-order-row-{$orders[1]->id}"))->toBeFalse()
        ->and(Ui::present($html, "dashboard-order-row-{$orders[2]->id}"))->toBeFalse();

    foreach (range(3, 7) as $index) {
        expect(Ui::isLink($html, "dashboard-order-link-{$orders[$index]->id}"))->toBeTrue()
            ->and(Ui::attribute($html, "dashboard-order-link-{$orders[$index]->id}", 'href'))->toBe(route('orders.show', $orders[$index]))
            ->and(Ui::text($html, "dashboard-order-link-{$orders[$index]->id}"))->toContain('ORD-LIST-'.$index);
    }

    expect(Ui::position($html, "dashboard-order-row-{$orders[7]->id}"))->toBeLessThan(Ui::position($html, "dashboard-order-row-{$orders[6]->id}"))
        ->and(Ui::position($html, "dashboard-order-row-{$orders[4]->id}"))->toBeLessThan(Ui::position($html, "dashboard-order-row-{$orders[3]->id}"));
});

test('an order row shows the customer, the exact total, both statuses', function () {
    $customer = Customer::factory()->create(['name' => 'Marta Ruiz']);
    $order = Order::factory()->forCustomer($customer)->create([
        'total' => '100.00',
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Paid,
    ]);

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, "dashboard-order-customer-{$order->id}"))->toBe('Marta Ruiz')
        ->and(Ui::tagName($html, "dashboard-order-customer-{$order->id}"))->not->toBe('a')
        ->and(Ui::text($html, "dashboard-order-total-{$order->id}"))->toBe('€ 100.00')
        ->and(Ui::text($html, "dashboard-order-status-{$order->id}"))->toBe(OrderStatus::Processing->label())
        ->and(Ui::text($html, "dashboard-order-payment-{$order->id}"))->toBe(__('orders.payment_statuses.paid'));
});

test('totals keep their trailing zeros and never gain a thousands separator', function (string $total) {
    $order = Order::factory()->create(['total' => $total]);

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, "dashboard-order-total-{$order->id}"))->toBe("€ {$total}");
})->with(['1234.50', '10.00', '0.00', '99999.99']);

test('the orders widget hands each order status to the badge', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status]);

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, "dashboard-order-status-{$order->id}"))->toBe($status->label());
})->with([
    'pending' => [OrderStatus::Pending],
    'processing' => [OrderStatus::Processing],
    'shipped' => [OrderStatus::Shipped],
    'delivered' => [OrderStatus::Delivered],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('the payment state resolves through the orders.payment_statuses keys', function (PaymentStatus $status) {
    $order = Order::factory()->create(['payment_status' => $status]);

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, "dashboard-order-payment-{$order->id}"))->toBe(__('orders.payment_statuses.'.$status->value));
})->with([
    'pending_payment' => [PaymentStatus::PendingPayment],
    'paid' => [PaymentStatus::Paid],
    'refunded' => [PaymentStatus::Refunded],
    'partially_refunded' => [PaymentStatus::PartiallyRefunded],
]);

test('an order from a deleted customer still shows that customer name', function () {
    $customer = Customer::factory()->trashed()->create(['name' => 'Gone Customer']);
    $order = Order::factory()->forCustomer($customer)->create();

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, "dashboard-order-customer-{$order->id}"))->toBe('Gone Customer');
});

test('a hostile customer name renders as text', function (string $payload) {
    $customer = Customer::factory()->create(['name' => $payload]);
    $order = Order::factory()->forCustomer($customer)->create();

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, "dashboard-order-customer-{$order->id}"))->toBe($payload)
        ->and($html)->not->toContain('<img src=x')->not->toContain('<script>alert(1)');
})->with([
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'script breakout' => ['"><script>alert(1)</script>'],
    'blade echo' => ['{{ 7*7 }}'],
    'blade directive' => ['@php echo 1; @endphp'],
]);

test('the latest-orders widget carries no mark-as-paid control even for an actor who may edit orders', function () {
    $order = Order::factory()->create(['payment_status' => PaymentStatus::PendingPayment]);

    $html = overviewRenderHtml(['orders.view', 'orders.edit']);

    expect(Ui::present($html, "dashboard-order-row-{$order->id}"))->toBeTrue()
        ->and(Ui::isLink($html, "dashboard-order-link-{$order->id}"))->toBeTrue()
        ->and(Ui::hooksStartingWith($html, 'mark-as-paid'))->toBe([])
        ->and($html)->not->toContain('data-test="mark-as-paid');
});

test('the orders footer links to the orders list', function () {
    Order::factory()->create();

    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::isLink($html, 'dashboard-view-all-orders'))->toBeTrue()
        ->and(Ui::attribute($html, 'dashboard-view-all-orders', 'href'))->toBe(route('orders.index'))
        ->and(Ui::text($html, 'dashboard-view-all-orders'))->toContain(__('dashboard.orders.view_all'));
});

test('the orders widget shows its empty state when there are no orders', function () {
    $html = overviewRenderHtml(['orders.view']);

    expect(Ui::text($html, 'dashboard-empty-orders'))->toBe(__('dashboard.orders.empty'))
        ->and(Ui::hooksStartingWith($html, 'dashboard-order-row-'))->toBe([]);
});

// =====================================================================
// A store with nothing to show
// =====================================================================

test('on an empty store every widget shows its empty state and the counters read 0', function () {
    $html = overviewRenderHtml(OVERVIEW_ALL_VIEW);

    expect(Ui::present($html, 'dashboard-empty-blog'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-empty-stock'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-empty-orders'))->toBeTrue()
        ->and(Ui::text($html, 'dashboard-counter-products'))->toMatch('/(^|\D)0(\D|$)/')
        ->and(Ui::text($html, 'dashboard-counter-images'))->toMatch('/(^|\D)0(\D|$)/')
        ->and(Ui::present($html, 'dashboard-no-widgets'))->toBeFalse();
});

test('the dashboard route renders the same hooks through a full page request', function () {
    $this->actingAs(Ui::actor(OVERVIEW_ALL_VIEW));

    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    expect(Ui::present($html, 'dashboard-greeting'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-widget-blog'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-widget-stock'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-widget-orders'))->toBeTrue();
});
