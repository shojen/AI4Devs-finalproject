<?php

// Story 0083 -- the dashboard home as a real browser journey. RED until App\Livewire\Dashboard\Overview and
// its three widgets exist (today /dashboard is the placeholder page, so none of the data-test hooks below
// are present).
//
// SELECTOR STRATEGY: data-test hooks only (`@hook`), never visible text -- the contract is fixed by the
// story: dashboard-blog-title-{id}, dashboard-stock-name-{id}, dashboard-order-link-{id}, and the widget
// containers dashboard-widget-blog|stock|orders. A title/name is an <a> when the actor may edit and a <span>
// otherwise, so the view-only journeys assert the element's tag AND that no anchor wraps it. Each journey
// is one test with one actor and relative-to-now() data; none waits on networkidle and none needs retry():
// every flow is a single click followed by a path assertion, like tests/Browser/Orders/OrdersListTest.php.
// The editor abilities are the ones the editors' mount() authorizes (`blog.edit`, `products.edit`); the
// routes themselves only need the `*.view` abilities, which is exactly why view-only actors must not be
// offered a link.

use App\Models\BlogPost;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function dashboardJourneyActor(array $permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/** JS expression: the element behind a data-test hook is a plain <span> with no anchor around it. */
function dashboardJourneyIsPlainText(string $hook): string
{
    return "(() => { const el = document.querySelector('[data-test=\"{$hook}\"]'); return el !== null && el.tagName === 'SPAN' && el.closest('a') === null; })()";
}

test('a blog editor opens a post editor from the dashboard blog widget', function () {
    $this->actingAs(dashboardJourneyActor(['blog.view', 'blog.edit']));
    $post = BlogPost::factory()->published()->create(['title' => 'Journey post']);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@dashboard-widget-blog')
        ->assertPresent('@dashboard-blog-row-'.$post->id)
        ->click('@dashboard-blog-title-'.$post->id)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/blog/posts/'.$post->id.'/edit');
});

test('a catalog manager opens the parent product editor from the low-stock widget', function () {
    $this->actingAs(dashboardJourneyActor(['products.view', 'products.edit']));
    $parent = Product::factory()->active()->physical()->create(['name' => 'Journey parent', 'stock' => 90]);
    // The low-stock variant is what ranks the parent; the link must still go to the PARENT's editor.
    ProductVariant::factory()->create(['product_id' => $parent->id, 'stock' => 1]);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@dashboard-widget-stock')
        ->assertPresent('@dashboard-stock-row-'.$parent->id)
        ->click('@dashboard-stock-name-'.$parent->id)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/products/'.$parent->id.'/edit');
});

test('an order manager opens an order from the latest orders widget', function () {
    $this->actingAs(dashboardJourneyActor(['orders.view', 'customers.view']));
    $order = Order::factory()
        ->forCustomer(Customer::factory()->create(['name' => 'Journey Customer']))
        ->create(['order_number' => 'ORD-JOURNEY-1']);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@dashboard-widget-orders')
        ->assertPresent('@dashboard-order-row-'.$order->id)
        ->click('@dashboard-order-link-'.$order->id)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/orders/'.$order->id);
});

test('a blog viewer who may not edit sees the post title as plain text, not a link', function () {
    $this->actingAs(dashboardJourneyActor(['blog.view']));
    $post = BlogPost::factory()->published()->create(['title' => 'View only post']);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@dashboard-blog-row-'.$post->id)
        ->assertScript(dashboardJourneyIsPlainText('dashboard-blog-title-'.$post->id))
        ->assertPresent('@dashboard-view-all-blog');
});

test('a product viewer who may not edit sees the product name as plain text, not a link', function () {
    $this->actingAs(dashboardJourneyActor(['products.view']));
    $product = Product::factory()->active()->physical()->create(['name' => 'View only product', 'stock' => 2]);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@dashboard-stock-row-'.$product->id)
        ->assertScript(dashboardJourneyIsPlainText('dashboard-stock-name-'.$product->id))
        ->assertPresent('@dashboard-view-all-stock');
});

test('the dashboard fits a 375px phone in light mode with no horizontal scroll', function () {
    $this->actingAs(dashboardJourneyActor(['blog.view', 'products.view', 'orders.view', 'customers.view', 'users.view', 'media.view']));
    BlogPost::factory()->scheduled()->create(['title' => 'Phone post with a rather long title that must wrap instead of overflowing']);
    Product::factory()->active()->physical()->create(['name' => 'Phone product with a rather long name that must wrap', 'stock' => 0]);
    Order::factory()->create(['order_number' => 'ORD-PHONE-1']);

    visit('/dashboard')
        ->resize(375, 800)
        ->assertPresent('@dashboard-greeting')
        ->assertPresent('@dashboard-counters')
        ->assertPresent('@dashboard-widget-blog')
        ->assertPresent('@dashboard-widget-stock')
        ->assertPresent('@dashboard-widget-orders')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

test('the dashboard fits a 375px phone in dark mode with no horizontal scroll', function () {
    $this->actingAs(dashboardJourneyActor(['blog.view', 'products.view', 'orders.view', 'customers.view', 'users.view', 'media.view']));
    BlogPost::factory()->published()->create(['title' => 'Dark post']);
    Product::factory()->active()->physical()->create(['name' => 'Dark product', 'stock' => 3]);
    Order::factory()->create(['order_number' => 'ORD-DARK-1']);

    visit('/dashboard')
        ->inDarkMode()
        ->resize(375, 800)
        ->assertPresent('@dashboard-greeting')
        ->assertPresent('@dashboard-widget-blog')
        ->assertPresent('@dashboard-widget-stock')
        ->assertPresent('@dashboard-widget-orders')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});
