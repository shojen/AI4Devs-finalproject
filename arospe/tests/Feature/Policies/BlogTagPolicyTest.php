<?php

use App\Models\BlogTag;
use App\Models\User;
use App\Policies\BlogTagPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Story 0059. Every ability gets both an allow and a deny test, per docs/testing/qa/what-not-to-test.md's authorization rule.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('viewAny is allowed for an actor holding blog.view and denied for one without it', function () {
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.view');

    expect(Gate::forUser($allowed)->allows('viewAny', BlogTag::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewAny', BlogTag::class))->toBeFalse();
});

test('create is allowed for an actor holding blog.create and denied for one without it', function () {
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.create');

    expect(Gate::forUser($allowed)->allows('create', BlogTag::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('create', BlogTag::class))->toBeFalse();
});

test('update is allowed for an actor holding blog.edit and denied for one without it', function () {
    $target = BlogTag::factory()->create();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.edit');

    expect(Gate::forUser($allowed)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding blog.delete and denied for one without it', function () {
    $target = BlogTag::factory()->create();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.delete');

    expect(Gate::forUser($allowed)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('delete', $target))->toBeFalse();
});

// Narrowness: a policy that checked "any blog.* permission" would pass every allow test above.
test('holding a different blog.* permission does not grant an ability', function (string $held, array $refused) {
    $target = BlogTag::factory()->create();
    $actor = User::factory()->create();
    $actor->givePermissionTo($held);

    foreach ($refused as $ability) {
        $subject = in_array($ability, ['update', 'delete'], true) ? $target : BlogTag::class;

        expect(Gate::forUser($actor)->allows($ability, $subject))->toBeFalse("{$held} must not grant {$ability}");
    }
})->with([
    'view only' => ['blog.view', ['create', 'update', 'delete']],
    'create only' => ['blog.create', ['viewAny', 'update', 'delete']],
    'edit only' => ['blog.edit', ['viewAny', 'create', 'delete']],
    'delete only' => ['blog.delete', ['viewAny', 'create', 'update']],
]);

test('a Super Admin actor passes every BlogTagPolicy ability while holding zero permission rows', function () {
    $target = BlogTag::factory()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toBeEmpty()
        ->and(Gate::forUser($superAdmin)->allows('viewAny', BlogTag::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', BlogTag::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});

// A permission string missing from the catalog throws PermissionDoesNotExist at runtime, so this is
// a correctness test rather than a style one.
test('every permission the policy names exists in the seeded catalog', function () {
    foreach ([
        BlogTagPolicy::VIEW_PERMISSION,
        BlogTagPolicy::CREATE_PERMISSION,
        BlogTagPolicy::EDIT_PERMISSION,
        BlogTagPolicy::DELETE_PERMISSION,
    ] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue("{$permission} is not seeded");
    }
});
