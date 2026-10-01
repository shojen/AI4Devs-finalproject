<?php

use App\Actions\Dashboard\GetDashboardCounters;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;
use Tests\Support\Orders\OrdersUi;

// Story 0082 (D-1, D-7), Phase 3 TDD red step: App\Actions\Dashboard\GetDashboardCounters does not
// exist yet. The counters action is the deliberate exception to the authorize-and-throw pattern: it
// never throws, guards each counter with its own Gate::allows, and logs nothing.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // With SUPER_ADMIN_EMAIL configured the seeder provisions one ACTIVE Super Admin account, which
    // the users counter rightly counts; expectations below add this baseline instead of assuming 0.
    $this->seededUsers = User::query()->count();
});

function countersActor(array $permissions = ['users.view', 'products.view', 'media.view']): User
{
    $actor = OrdersUi::actor($permissions);
    test()->actingAs($actor);

    return $actor;
}

it('counts only active users, the actor included', function () {
    countersActor();
    User::factory()->count(3)->create();
    User::factory()->inactive()->create();
    User::factory()->suspended()->create();

    expect(app(GetDashboardCounters::class)()['users'])->toBe(4 + $this->seededUsers);
});

it('does not count a soft-deleted user', function () {
    countersActor();
    User::factory()->count(2)->create()->first()->delete();

    expect(app(GetDashboardCounters::class)()['users'])->toBe(2 + $this->seededUsers);
});

it('counts every product regardless of status or type', function () {
    countersActor();
    Product::factory()->active()->create();
    Product::factory()->draft()->create();
    Product::factory()->virtual()->create();

    expect(app(GetDashboardCounters::class)()['products'])->toBe(3);
});

it('counts every media row, attached to a product or not', function () {
    countersActor();
    Media::factory()->count(2)->create();
    Product::factory()->withGallery(2)->create();

    expect(app(GetDashboardCounters::class)()['images'])->toBe(4);
});

it('returns zero products and images for an empty shop', function () {
    countersActor();

    $counters = app(GetDashboardCounters::class)();

    expect($counters['products'])->toBe(0)
        ->and($counters['images'])->toBe(0);
});

it('returns exactly the users, products and images keys', function () {
    countersActor();

    expect(array_keys(app(GetDashboardCounters::class)()))->toBe(['users', 'products', 'images']);
});

it('returns null for the counters the actor may not see and never reads their tables', function (array $abilities, array $visible) {
    Log::spy();
    countersActor($abilities);
    Product::factory()->count(2)->create();
    Media::factory()->create();

    $counters = [];
    $queries = DomainQueryLog::capture(function () use (&$counters): void {
        $counters = app(GetDashboardCounters::class)();
    });

    foreach (['users', 'products', 'images'] as $key) {
        if (in_array($key, $visible, true)) {
            expect($counters[$key])->toBeInt();
        } else {
            expect($counters[$key])->toBeNull();
        }
    }

    $tableOf = ['users' => 'users', 'products' => 'products', 'images' => 'media'];

    foreach ($tableOf as $key => $table) {
        expect($queries[$table])->toBe(in_array($key, $visible, true) ? 1 : 0, "queries against {$table}");
    }

    expect($queries['product_variants'])->toBe(0);

    Log::shouldNotHaveReceived('warning');
})->with([
    'users only (Uma, the user manager)' => [['users.view'], ['users']],
    'products only' => [['products.view'], ['products']],
    'media only' => [['media.view'], ['images']],
    'users and media' => [['users.view', 'media.view'], ['users', 'images']],
]);

it('returns all null, runs no domain-table query and logs nothing for an actor with none of the abilities', function () {
    Log::spy();
    countersActor([]);
    Product::factory()->create();

    $counters = [];
    $queries = DomainQueryLog::capture(function () use (&$counters): void {
        $counters = app(GetDashboardCounters::class)();
    });

    expect($counters)->toBe(['users' => null, 'products' => null, 'images' => null])
        ->and(DomainQueryLog::total($queries))->toBe(0);

    Log::shouldNotHaveReceived('warning');
});

it('returns all null for a guest instead of throwing', function () {
    expect(app(GetDashboardCounters::class)())->toBe(['users' => null, 'products' => null, 'images' => null]);
});

it('gives a Super Admin all three counters', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);
    Product::factory()->count(2)->create();
    Media::factory()->create();

    expect(app(GetDashboardCounters::class)())->toBe(['users' => 1 + $this->seededUsers, 'products' => 2, 'images' => 1]);
});

it('runs at most three domain-table queries when the actor sees everything', function () {
    countersActor();
    Product::factory()->count(2)->create();

    $statements = DomainQueryLog::statements(fn () => app(GetDashboardCounters::class)());

    expect($statements)->toBe(3);
});
