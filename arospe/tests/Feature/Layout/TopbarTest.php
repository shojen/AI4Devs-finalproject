<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

// Story 0057a -- the persistent topbar, asserted against FULL-PAGE renders
// (never Livewire::test(), which skips the layout and the named slots).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function topbarAdministrator(): User
{
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    return $administrator;
}

/**
 * Every parameterless authenticated app screen and the translation key of
 * the title its topbar must show. A new authenticated screen must be added
 * here (or to the exclusions in the guard test below).
 *
 * @return array<string, array{0: string, 1: string}>
 */
function topbarScreens(): array
{
    return [
        'dashboard' => ['dashboard', 'topbar.dashboard.title'],
        'users' => ['users.index', 'topbar.users.title'],
        'roles' => ['roles.index', 'topbar.roles.title'],
        'sales regions' => ['sales-regions.index', 'sales-regions.index.title'],
        'product categories' => ['product-categories.index', 'topbar.product_categories.title'],
        'products' => ['products.index', 'products.index.title'],
        'new product' => ['products.create', 'products.editor.title_create'],
        'attribute types' => ['product-attribute-types.index', 'topbar.attribute_types.title'],
        'shipping' => ['shipping.index', 'shipping.carriers.index.heading'],
        'shipping zones' => ['shipping.zones.index', 'shipping.zones.index.heading'],
        'payment methods' => ['payment-methods.index', 'payment-methods.index.heading'],
        'customers' => ['customers.index', 'topbar.customers.title'],
        'orders' => ['orders.index', 'topbar.orders.title'],
        'profile settings' => ['profile.edit', 'topbar.settings.profile'],
        'security settings' => ['security.edit', 'topbar.settings.security'],
        'appearance settings' => ['appearance.edit', 'topbar.settings.appearance'],
    ];
}

/** The rendered text of the element carrying a data-test hook. */
function topbarText(string $html, string $hook): ?string
{
    if (! preg_match('/data-test="'.$hook.'"[^>]*>(.*?)<\/(?:h1|div)>/s', $html, $matches)) {
        return null;
    }

    return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES));
}

/**
 * Renders a screen as the administrator would receive it.
 */
function renderScreen(string $routeName, array $parameters = []): string
{
    test()->actingAs(topbarAdministrator());

    return test()
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($routeName, $parameters))
        ->assertOk()
        ->getContent();
}

test('every authenticated screen renders one topbar, its title, and the bell exactly once', function (string $routeName, string $titleKey) {
    $html = renderScreen($routeName);

    expect(substr_count($html, 'data-test="topbar"'))->toBe(1)
        ->and(substr_count($html, 'data-test="topbar-title"'))->toBe(1)
        ->and(substr_count($html, 'data-test="notification-bell"'))->toBe(1)
        ->and(topbarText($html, 'topbar-title'))->toBe(__($titleKey))
        ->and(substr_count($html, '<h1'))->toBe(1);
})->with(fn () => topbarScreens());

test('every screen that declares a subtitle renders it in the topbar', function (string $routeName) {
    $html = renderScreen($routeName);

    expect(topbarText($html, 'topbar-subtitle'))->not->toBeNull()->not->toBe('');
})->with(fn () => collect(topbarScreens())->map(fn (array $screen) => [$screen[0]])->all());

test('the removed inline page heading does not reappear outside the topbar', function (string $routeName, string $titleKey) {
    $html = renderScreen($routeName);
    $title = preg_quote(htmlspecialchars(__($titleKey), ENT_QUOTES), '/');

    expect(preg_match('/data-flux-heading[^>]*>\s*'.$title.'\s*</', $html))->toBe(0);
})->with(fn () => topbarScreens());

test('the customer detail screen titles the topbar with the customer name and has no subtitle', function () {
    $customer = Customer::factory()->create(['name' => 'Ana <b>Pérez</b>']);

    $html = renderScreen('customers.show', ['customer' => $customer]);

    expect(topbarText($html, 'topbar-title'))->toBe('Ana <b>Pérez</b>')
        ->and(substr_count($html, 'data-test="topbar-subtitle"'))->toBe(0)
        ->and(substr_count($html, 'data-test="notification-bell"'))->toBe(1)
        ->and($html)->not->toContain('<b>Pérez</b>');
});

test('the order detail screen titles the topbar with the order number and has no subtitle', function () {
    $order = Order::factory()->create(['order_number' => 'ORD-2026-000777']);

    $html = renderScreen('orders.show', ['order' => $order]);

    expect(topbarText($html, 'topbar-title'))->toBe('ORD-2026-000777')
        ->and(substr_count($html, 'data-test="topbar-subtitle"'))->toBe(0)
        ->and(substr_count($html, 'data-test="notification-bell"'))->toBe(1)
        ->and(substr_count($html, '<h1'))->toBe(1);
});

test('the product editor titles the topbar for editing an existing product', function () {
    $product = Product::factory()->create();

    $html = renderScreen('products.edit', ['product' => $product]);

    expect(topbarText($html, 'topbar-title'))->toBe(__('products.editor.title_edit'))
        ->and(substr_count($html, 'data-test="notification-bell"'))->toBe(1);
});

test('the sidebar no longer contains the bell', function () {
    $html = renderScreen('dashboard');

    expect(preg_match('/data-test="sidebar".*?<\/aside>|data-test="sidebar".*?<\/ui-sidebar>/s', $html, $matches))->toBe(1)
        ->and($matches[0])->not->toContain('notification-bell');
});

test('every authenticated app screen is covered by the dataset or an explicit exclusion', function () {
    $excludedPrefixes = ['verification.', 'password.', 'passkey.', 'two-factor.', 'login', 'register', 'email-change.'];
    $covered = array_merge(
        collect(topbarScreens())->pluck(0)->all(),
        ['customers.show', 'orders.show', 'products.edit'],
    );

    $uncovered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && ($m === 'auth' || str_contains($m, 'Authenticate'))))
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->reject(fn (string $name) => collect($excludedPrefixes)->contains(fn (string $prefix) => str_starts_with($name, $prefix)))
        ->reject(fn (string $name) => in_array($name, $covered, true))
        ->values()
        ->all();

    expect($uncovered)->toBe([]);
});

test('every topbar translation key exists in both locales', function () {
    expect(Arr::dot(require lang_path('es/topbar.php')))
        ->toHaveKeys(array_keys(Arr::dot(require lang_path('en/topbar.php'))));
});
