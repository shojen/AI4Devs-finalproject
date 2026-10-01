<?php

// Story 0083 -- what reaches the browser. For a restricted actor, no data of a hidden module may
// appear in the rendered HTML nor in any wire:snapshot of the component tree, and no public state
// anywhere may hold a model, Collection, enum or Carbon (it would hydrate from the client).
//
// Red step: the Livewire classes under test do not exist yet.

use App\Livewire\Dashboard\Overview;
use App\Models\BlogPost;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

const OVERVIEW_SENTINELS = [
    'blog' => ['SENTINEL-POST-TITLE-4711'],
    'products' => ['SENTINEL-SKU-4711', 'Sentinel Product Name 4711'],
    'orders' => ['ORD-SENTINEL-4711', 'Sentinel Customer 4711', 'sentinel4711@customer.test', '7654.32'],
];

function overviewSeedSentinels(): void
{
    BlogPost::factory()->published()->create(['title' => 'SENTINEL-POST-TITLE-4711', 'body' => '<p>Body of the sentinel post</p>']);
    Product::factory()->active()->physical()->create([
        'name' => 'Sentinel Product Name 4711',
        'sku' => 'SENTINEL-SKU-4711',
        'stock' => 0,
    ]);
    $customer = Customer::factory()->withEmail('sentinel4711@customer.test')->create(['name' => 'Sentinel Customer 4711']);
    Order::factory()->forCustomer($customer)->create(['order_number' => 'ORD-SENTINEL-4711', 'total' => '7654.32']);
}

/**
 * The snapshots of the whole tree as one string, raw and decoded, to search for sentinels.
 */
function overviewSnapshotText(Testable $component): string
{
    return json_encode(Ui::snapshotOf($component), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

test('a restricted actor receives no sentinel of a module it cannot see, in the html or in any snapshot', function (array $permissions, array $hiddenModules) {
    overviewSeedSentinels();
    $this->actingAs(Ui::actor($permissions));

    $component = Livewire::test(Overview::class);
    $html = $component->html();
    $snapshots = overviewSnapshotText($component);

    expect(Ui::snapshotOf($component))->not->toBe([]);

    foreach ($hiddenModules as $module) {
        foreach (OVERVIEW_SENTINELS[$module] as $sentinel) {
            expect($html)->not->toContain($sentinel, "html leaks {$sentinel}");
            expect($snapshots)->not->toContain($sentinel, "snapshot leaks {$sentinel}");
            expect(html_entity_decode($html))->not->toContain($sentinel);
        }
    }
})->with([
    'no permission' => [[], ['blog', 'products', 'orders']],
    'only users.view' => [['users.view'], ['blog', 'products', 'orders']],
    'only media.view' => [['media.view'], ['blog', 'products', 'orders']],
    'only blog.view' => [['blog.view'], ['products', 'orders']],
    'only products.view' => [['products.view'], ['blog', 'orders']],
    'only orders.view' => [['orders.view'], ['blog', 'products']],
]);

test('the customer email and the line items never reach any actor', function () {
    overviewSeedSentinels();
    $this->actingAs(Ui::actor(['orders.view', 'blog.view', 'products.view', 'users.view', 'media.view']));

    $component = Livewire::test(Overview::class);

    expect($component->html())->not->toContain('sentinel4711@customer.test')
        ->and(overviewSnapshotText($component))->not->toContain('sentinel4711@customer.test');
});

test('a permitted actor does see its own module data (the sentinels are reachable)', function () {
    overviewSeedSentinels();
    $this->actingAs(Ui::actor(['blog.view', 'products.view', 'orders.view']));

    $html = Livewire::test(Overview::class)->html();

    expect($html)->toContain('SENTINEL-POST-TITLE-4711')
        ->toContain('Sentinel Product Name 4711')
        ->toContain('ORD-SENTINEL-4711')
        ->toContain('Sentinel Customer 4711')
        ->toContain('7654.32');
});

test('no public property anywhere in the tree holds a model, a Collection, an enum or a Carbon', function () {
    overviewSeedSentinels();
    $this->actingAs(Ui::actor(['users.view', 'products.view', 'media.view', 'blog.view', 'orders.view']));

    $component = Livewire::test(Overview::class);
    $snapshots = Ui::snapshotOf($component);

    // Overview and its three widgets.
    expect(count($snapshots))->toBeGreaterThanOrEqual(4);

    foreach ($snapshots as $snapshot) {
        $encoded = json_encode($snapshot['data'] ?? []);

        // Livewire serialises a model as `mdl`, a Collection as `clctn`, an enum as `enm`, a Carbon as `cbn`.
        expect($encoded)->not->toMatch('/"s":"(mdl|clctn|elcln|enm|cbn|dte|fil|str)"/');
    }

    foreach ($snapshots as $snapshot) {
        if (str_contains((string) ($snapshot['memo']['name'] ?? ''), 'widget')) {
            expect($snapshot['data'])->toBe([]);
        }
    }

    foreach ([Overview::class] as $class) {
        $properties = array_filter(
            (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class,
        );

        foreach ($properties as $property) {
            $value = $property->getValue($component->instance());

            expect($value)->not->toBeObject();
        }
    }
});
