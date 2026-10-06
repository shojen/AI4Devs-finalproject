<?php

// Story 0083 -- what each permission profile sees on the dashboard home, and what it must NOT cause:
// no domain-table query of a hidden module, no "Privileged action refused" warning, no dead editor
// link. Actors are NON-Super-Admin (a Super Admin makes every "hidden" assertion a false negative
// through Gate::before) except in their own positive case.
//
// Red step: the Livewire classes under test do not exist yet.

use App\Livewire\Dashboard\BlogWidget;
use App\Livewire\Dashboard\LatestOrdersWidget;
use App\Livewire\Dashboard\LowStockWidget;
use App\Livewire\Dashboard\Overview;
use App\Models\BlogPost;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;
use Tests\Support\Dashboard\DomainQueryLog;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * One of everything, so every widget has something to show.
 *
 * @return array{post: BlogPost, product: Product, variable: Product, order: Order}
 */
function overviewPermissionStore(): array
{
    User::factory()->count(2)->create();
    Media::factory()->count(2)->create();

    $variable = Product::factory()->active()->physical()->create(['stock' => 40]);
    ProductVariant::factory()->create(['product_id' => $variable->id, 'stock' => 1]);

    return [
        'post' => BlogPost::factory()->published()->create(),
        'product' => Product::factory()->active()->physical()->create(['stock' => 2]),
        'variable' => $variable,
        'order' => Order::factory()->create(),
    ];
}

/**
 * The actor of a profile: a non-Super-Admin holding exactly the permissions, the seeded
 * Administrator role, or a Super Admin.
 *
 * @param  list<string>  $permissions
 */
function overviewProfileActor(string $kind, array $permissions): User
{
    return match ($kind) {
        'super' => Ui::superAdmin(),
        'role' => tap(Ui::actor([]), fn (User $user) => $user->assignRole('Administrator')),
        default => Ui::actor($permissions),
    };
}

// =====================================================================
// The profile dataset
// =====================================================================

dataset('overview profiles', [
    'no permission' => ['partial', [], []],
    'only users.view' => ['partial', ['users.view'], ['counter-users']],
    'only orders.view' => ['partial', ['orders.view'], ['orders']],
    'only products.view' => ['partial', ['products.view'], ['counter-products', 'stock']],
    'only media.view' => ['partial', ['media.view'], ['counter-images']],
    'only blog.view' => ['partial', ['blog.view'], ['blog']],
    'the three counters' => ['partial', ['users.view', 'products.view', 'media.view'], ['counter-users', 'counter-products', 'counter-images', 'stock']],
    'every view ability' => ['partial', ['users.view', 'products.view', 'media.view', 'blog.view', 'orders.view'], ['counter-users', 'counter-products', 'counter-images', 'blog', 'stock', 'orders']],
    'Super Admin' => ['super', [], ['counter-users', 'counter-products', 'counter-images', 'blog', 'stock', 'orders']],
    'Administrator role' => ['role', [], ['counter-users', 'counter-products', 'counter-images', 'blog', 'stock', 'orders']],
]);

test('each profile sees exactly the counters and widgets of the modules it may view', function (string $kind, array $permissions, array $visible) {
    $store = overviewPermissionStore();
    $this->actingAs(overviewProfileActor($kind, $permissions));

    $html = Livewire::test(Overview::class)->html();

    $hooks = [
        'counter-users' => 'dashboard-counter-users',
        'counter-products' => 'dashboard-counter-products',
        'counter-images' => 'dashboard-counter-images',
        'blog' => 'dashboard-widget-blog',
        'stock' => 'dashboard-widget-stock',
        'orders' => 'dashboard-widget-orders',
    ];

    foreach ($hooks as $key => $hook) {
        expect(Ui::present($html, $hook))->toBe(in_array($key, $visible, true), "hook {$hook} for this profile");
    }

    $anyCounter = array_intersect($visible, ['counter-users', 'counter-products', 'counter-images']) !== [];

    expect(Ui::present($html, 'dashboard-greeting'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-counters'))->toBe($anyCounter)
        ->and(Ui::present($html, 'dashboard-no-widgets'))->toBe($visible === []);

    // Rows follow their widget, never leak past it.
    expect(Ui::present($html, "dashboard-blog-row-{$store['post']->id}"))->toBe(in_array('blog', $visible, true))
        ->and(Ui::present($html, "dashboard-stock-row-{$store['product']->id}"))->toBe(in_array('stock', $visible, true))
        ->and(Ui::present($html, "dashboard-order-row-{$store['order']->id}"))->toBe(in_array('orders', $visible, true));
})->with('overview profiles');

test('an actor causes no domain query of a hidden module and no refusal warning', function (string $kind, array $permissions, array $visible) {
    overviewPermissionStore();
    $this->actingAs(overviewProfileActor($kind, $permissions));
    Log::spy();

    $counts = DomainQueryLog::capture(fn () => Livewire::test(Overview::class)->html());

    $tablesOfModule = [
        'counter-users' => ['users'],
        'counter-images' => ['media'],
        'blog' => ['blog_posts'],
        'stock' => ['products', 'product_variants'],
        'counter-products' => ['products'],
        'orders' => ['orders', 'customers'],
    ];

    $allowed = [];

    foreach ($visible as $key) {
        array_push($allowed, ...$tablesOfModule[$key]);
    }

    // A Super Admin or Administrator hides nothing, so only partial actors have tables to forbid.
    foreach (DomainQueryLog::TABLES as $table) {
        if ($kind === 'partial' && ! in_array($table, $allowed, true)) {
            expect($counts[$table])->toBe(0, "no query may touch {$table} for this profile");
        }
    }

    Log::shouldNotHaveReceived('warning');
})->with('overview profiles');

test('a hidden widget mounted on its own renders nothing, runs no query and logs nothing', function (string $widget, array $tables) {
    overviewPermissionStore();
    $this->actingAs(Ui::actor([]));
    Log::spy();

    $counts = DomainQueryLog::capture(function () use ($widget): void {
        $html = Livewire::test($widget)->html();

        expect(Ui::hooksStartingWith($html, 'dashboard-blog-row-'))->toBe([])
            ->and(Ui::hooksStartingWith($html, 'dashboard-stock-row-'))->toBe([])
            ->and(Ui::hooksStartingWith($html, 'dashboard-order-row-'))->toBe([]);
    });

    foreach ($tables as $table) {
        expect($counts[$table])->toBe(0);
    }

    Log::shouldNotHaveReceived('warning');
})->with([
    'blog widget' => [BlogWidget::class, ['blog_posts']],
    'low-stock widget' => [LowStockWidget::class, ['products', 'product_variants']],
    'orders widget' => [LatestOrdersWidget::class, ['orders', 'customers']],
]);

test('the dashboard route stays reachable for every profile without permissions', function () {
    $this->actingAs(Ui::actor([]))->get(route('dashboard'))->assertOk();
});

// =====================================================================
// The editor-link rule
// =====================================================================

test('a blog row links to its editor only when the actor may edit posts, otherwise it is plain text', function (array $permissions, bool $linked) {
    $post = BlogPost::factory()->published()->create(['title' => 'Linked or not']);
    $this->actingAs(Ui::actor($permissions));
    Log::spy();

    $html = Livewire::test(Overview::class)->html();
    $hook = "dashboard-blog-title-{$post->id}";

    expect(Ui::text($html, $hook))->toBe('Linked or not')
        ->and(Ui::isLink($html, $hook))->toBe($linked);

    if ($linked) {
        expect(Ui::attribute($html, $hook, 'href'))->toBe(route('blog-posts.edit', $post));
    } else {
        expect(Ui::tagName($html, $hook))->not->toBe('a');
        expect($html)->not->toContain(route('blog-posts.edit', $post));
    }

    Log::shouldNotHaveReceived('warning');
})->with([
    'view only' => [['blog.view'], false],
    'view and edit' => [['blog.view', 'blog.edit'], true],
    'view and create only' => [['blog.view', 'blog.create'], false],
    'view and delete only' => [['blog.view', 'blog.delete'], false],
]);

test('a product row links to the parent editor only when the actor may edit products, otherwise it is plain text', function (array $permissions, bool $linked) {
    $parent = Product::factory()->active()->physical()->create(['stock' => 40, 'name' => 'Parent product']);
    ProductVariant::factory()->create(['product_id' => $parent->id, 'stock' => 1]);
    $this->actingAs(Ui::actor($permissions));
    Log::spy();

    $html = Livewire::test(Overview::class)->html();
    $hook = "dashboard-stock-name-{$parent->id}";

    expect(Ui::text($html, $hook))->toBe('Parent product')
        ->and(Ui::isLink($html, $hook))->toBe($linked);

    if ($linked) {
        expect(Ui::attribute($html, $hook, 'href'))->toBe(route('products.edit', $parent));
    } else {
        expect(Ui::tagName($html, $hook))->not->toBe('a');
        expect($html)->not->toContain(route('products.edit', $parent));
    }

    Log::shouldNotHaveReceived('warning');
})->with([
    'view only' => [['products.view'], false],
    'view and edit' => [['products.view', 'products.edit'], true],
    'view and create only' => [['products.view', 'products.create'], false],
]);

test('an order row always links to the order detail, with or without edit rights', function (array $permissions) {
    $order = Order::factory()->create();
    $this->actingAs(Ui::actor($permissions));
    Log::spy();

    $html = Livewire::test(Overview::class)->html();

    expect(Ui::isLink($html, "dashboard-order-link-{$order->id}"))->toBeTrue()
        ->and(Ui::attribute($html, "dashboard-order-link-{$order->id}", 'href'))->toBe(route('orders.show', $order));

    Log::shouldNotHaveReceived('warning');
})->with([
    'view only' => [['orders.view']],
    'view and edit' => [['orders.view', 'orders.edit']],
]);

test('a Super Admin gets every editor link', function () {
    $post = BlogPost::factory()->published()->create();
    $product = Product::factory()->active()->physical()->create(['stock' => 1]);
    $this->actingAs(Ui::superAdmin());

    $html = Livewire::test(Overview::class)->html();

    expect(Ui::isLink($html, "dashboard-blog-title-{$post->id}"))->toBeTrue()
        ->and(Ui::isLink($html, "dashboard-stock-name-{$product->id}"))->toBeTrue();
});

test('following a rendered editor link never reaches a refusal', function () {
    $post = BlogPost::factory()->published()->create();
    $this->actingAs(Ui::actor(['blog.view', 'blog.edit']));

    $html = Livewire::test(Overview::class)->html();
    $href = Ui::attribute($html, "dashboard-blog-title-{$post->id}", 'href');

    expect($href)->not->toBeNull();

    $this->get($href)->assertOk();
});

// =====================================================================
// No public state, no mutating method
// =====================================================================

test('the widgets declare no public property', function (string $widget) {
    $declared = array_filter(
        (new ReflectionClass($widget))->getProperties(ReflectionProperty::IS_PUBLIC),
        fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $widget,
    );

    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), $declared))->toBe([]);
})->with([BlogWidget::class, LowStockWidget::class, LatestOrdersWidget::class]);

test('the widgets expose only render and parameterless computed methods', function (string $widget) {
    $allowed = ['render', 'mount', 'boot', 'booted'];
    $offending = [];

    foreach ((new ReflectionClass($widget))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $widget || in_array($method->getName(), $allowed, true)) {
            continue;
        }

        $computed = $method->getAttributes(Computed::class) !== [];

        if (! $computed || $method->getNumberOfParameters() !== 0) {
            $offending[] = $method->getName();
        }
    }

    expect($offending)->toBe([]);
})->with([BlogWidget::class, LowStockWidget::class, LatestOrdersWidget::class]);

test('the widgets cannot be written to or called from the client', function (string $widget) {
    $this->actingAs(Ui::actor(['blog.view', 'products.view', 'orders.view', 'blog.edit', 'orders.edit', 'products.edit']));
    overviewPermissionStore();

    $component = Livewire::test($widget);

    expect(fn () => $component->set('rows', ['x']))->toThrow(Exception::class);
    expect(fn () => $component->call('delete'))->toThrow(Exception::class);
})->with([BlogWidget::class, LowStockWidget::class, LatestOrdersWidget::class]);

// =====================================================================
// Missing permission rows: the page must never 500, a missing row means "not allowed"
// =====================================================================

/**
 * Empties the permission catalogue, as in a database where RolePermissionSeeder never ran.
 */
function overviewWithoutPermissionRows(): void
{
    Permission::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('the dashboard renders for a bare user in an unseeded database, without a refusal warning', function () {
    overviewWithoutPermissionRows();
    $this->actingAs(User::factory()->create(['name' => 'Nora Quiroga']));
    Log::spy();

    $response = $this->get(route('dashboard'))->assertOk();
    $html = $response->getContent();

    expect(Ui::text($html, 'dashboard-greeting'))->toContain('Nora')
        ->and(Ui::present($html, 'dashboard-no-widgets'))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-counters'))->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});

test('the dashboard renders for a user whose role holds only one existing permission', function (string $permission, string $visibleHook) {
    overviewWithoutPermissionRows();
    Permission::create(['name' => $permission, 'guard_name' => 'web']);
    $role = Role::create(['name' => 'Single Permission Role', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user = User::factory()->create(['name' => 'Nora Quiroga']);
    $user->assignRole($role);
    $this->actingAs($user);
    Log::spy();

    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    expect(Ui::text($html, 'dashboard-greeting'))->toContain('Nora')
        ->and(Ui::present($html, $visibleHook))->toBeTrue()
        ->and(Ui::present($html, 'dashboard-no-widgets'))->toBeFalse();

    Log::shouldNotHaveReceived('warning');
})->with([
    'users.view shows the counters' => ['users.view', 'dashboard-counter-users'],
    'blog.view shows the blog widget' => ['blog.view', 'dashboard-widget-blog'],
    'products.view shows the stock widget' => ['products.view', 'dashboard-widget-stock'],
    'orders.view shows the orders widget' => ['orders.view', 'dashboard-widget-orders'],
]);

test('the widgets mounted on their own render no rows and never throw when permission rows are missing', function (string $widget) {
    overviewWithoutPermissionRows();
    $this->actingAs(User::factory()->create());
    Log::spy();

    $html = Livewire::test($widget)->assertOk()->html();

    expect(Ui::hooksStartingWith($html, 'dashboard-blog-row-'))->toBe([])
        ->and(Ui::hooksStartingWith($html, 'dashboard-stock-row-'))->toBe([])
        ->and(Ui::hooksStartingWith($html, 'dashboard-order-row-'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
})->with([BlogWidget::class, LowStockWidget::class, LatestOrdersWidget::class]);

test('the widgets render no rows for an actor holding one other existing permission', function (string $widget) {
    overviewWithoutPermissionRows();
    Permission::create(['name' => 'users.view', 'guard_name' => 'web']);
    $actor = User::factory()->create();
    $actor->givePermissionTo('users.view');
    $this->actingAs($actor);
    Log::spy();

    $html = Livewire::test($widget)->assertOk()->html();

    expect(Ui::hooksStartingWith($html, 'dashboard-blog-row-'))->toBe([])
        ->and(Ui::hooksStartingWith($html, 'dashboard-stock-row-'))->toBe([])
        ->and(Ui::hooksStartingWith($html, 'dashboard-order-row-'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
})->with([BlogWidget::class, LowStockWidget::class, LatestOrdersWidget::class]);
