<?php

use App\Models\BlogCategory;
use App\Models\User;
use App\Policies\BlogCategoryPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Story 0058, Phase 3 (TDD "red" step): BlogCategoryPolicy does not exist yet. Every ability gets
// both an allow and a deny test, per docs/testing/qa/what-not-to-test.md's authorization rule.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('viewAny is allowed for an actor holding blog.view and denied for one without it', function () {
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.view');

    expect(Gate::forUser($allowed)->allows('viewAny', BlogCategory::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewAny', BlogCategory::class))->toBeFalse();
});

test('create is allowed for an actor holding blog.create and denied for one without it', function () {
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.create');

    expect(Gate::forUser($allowed)->allows('create', BlogCategory::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('create', BlogCategory::class))->toBeFalse();
});

test('update is allowed for an actor holding blog.edit and denied for one without it', function () {
    $target = BlogCategory::factory()->create();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.edit');

    expect(Gate::forUser($allowed)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding blog.delete and denied for one without it', function () {
    $target = BlogCategory::factory()->create();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.delete');

    expect(Gate::forUser($allowed)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('delete', $target))->toBeFalse();
});

test('holding a different blog.* permission does not grant an ability', function () {
    $target = BlogCategory::factory()->create();
    $actor = User::factory()->create();
    $actor->givePermissionTo('blog.view');

    expect(Gate::forUser($actor)->allows('create', BlogCategory::class))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('update', $target))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('delete', $target))->toBeFalse();
});

test('a Super Admin actor passes every BlogCategoryPolicy ability while holding zero permission rows', function () {
    $target = BlogCategory::factory()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toBeEmpty()
        ->and(Gate::forUser($superAdmin)->allows('viewAny', BlogCategory::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', BlogCategory::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});

test('every permission the policy names exists in the seeded catalog', function () {
    foreach ([
        BlogCategoryPolicy::VIEW_PERMISSION,
        BlogCategoryPolicy::CREATE_PERMISSION,
        BlogCategoryPolicy::EDIT_PERMISSION,
        BlogCategoryPolicy::DELETE_PERMISSION,
    ] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue("{$permission} is not seeded");
    }
});
