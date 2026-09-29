<?php

use App\Models\BlogPost;
use App\Models\User;
use App\Policies\BlogPostPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Story 0061. Every ability gets both an allow and a deny test, per docs/testing/qa/what-not-to-test.md's
// authorization rule. Five abilities over FOUR permission strings (D-20): `restore` gates on blog.edit,
// the one ability whose permission is not the one its name suggests.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A soft-deleted post, as the restore ability is evaluated against.
 */
function blogPostPolicyTrashedPost(): BlogPost
{
    $post = BlogPost::factory()->create();
    $post->delete();

    return $post;
}

test('viewAny is allowed for an actor holding blog.view and denied for one without it', function () {
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.view');

    expect(Gate::forUser($allowed)->allows('viewAny', BlogPost::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewAny', BlogPost::class))->toBeFalse();
});

test('create is allowed for an actor holding blog.create and denied for one without it', function () {
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.create');

    expect(Gate::forUser($allowed)->allows('create', BlogPost::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('create', BlogPost::class))->toBeFalse();
});

test('update is allowed for an actor holding blog.edit and denied for one without it', function () {
    $target = BlogPost::factory()->create();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.edit');

    expect(Gate::forUser($allowed)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding blog.delete and denied for one without it', function () {
    $target = BlogPost::factory()->create();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.delete');

    expect(Gate::forUser($allowed)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('delete', $target))->toBeFalse();
});

test('restore is allowed for an actor holding blog.edit and denied for one without it', function () {
    $target = blogPostPolicyTrashedPost();
    $allowed = User::factory()->create();
    $allowed->givePermissionTo('blog.edit');

    expect(Gate::forUser($allowed)->allows('restore', $target))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('restore', $target))->toBeFalse();
});

// D-20: asserted at the policy level as well as through the action. A method that reused
// DELETE_PERMISSION by copy-paste would pass every test that only exercises a full-permission actor.
test('restore is refused to an actor holding blog.delete but not blog.edit', function () {
    $target = blogPostPolicyTrashedPost();
    $actor = User::factory()->create();
    $actor->givePermissionTo('blog.delete');

    expect(Gate::forUser($actor)->allows('restore', $target))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('delete', $target))->toBeTrue();
});

// Narrowness: a policy that checked "any blog.* permission" would pass every allow test above.
test('holding a different blog.* permission does not grant an ability', function (string $held, array $refused) {
    $live = BlogPost::factory()->create();
    $trashed = blogPostPolicyTrashedPost();
    $actor = User::factory()->create();
    $actor->givePermissionTo($held);

    foreach ($refused as $ability) {
        $subject = match ($ability) {
            'update', 'delete' => $live,
            'restore' => $trashed,
            default => BlogPost::class,
        };

        expect(Gate::forUser($actor)->allows($ability, $subject))->toBeFalse("{$held} must not grant {$ability}");
    }
})->with([
    'view only' => ['blog.view', ['create', 'update', 'delete', 'restore']],
    'create only' => ['blog.create', ['viewAny', 'update', 'delete', 'restore']],
    'edit only' => ['blog.edit', ['viewAny', 'create', 'delete']],
    'delete only' => ['blog.delete', ['viewAny', 'create', 'update', 'restore']],
]);

test('a Super Admin actor passes every BlogPostPolicy ability while holding zero permission rows', function () {
    $live = BlogPost::factory()->create();
    $trashed = blogPostPolicyTrashedPost();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toBeEmpty()
        ->and(Gate::forUser($superAdmin)->allows('viewAny', BlogPost::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', BlogPost::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $live))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $live))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('restore', $trashed))->toBeTrue();
});

// A permission name absent from the catalog throws PermissionDoesNotExist at runtime, so this is a
// correctness test, not a style one. No new permission is added to the seeded catalog (D-20).
test('every permission name the policy uses exists in the seeded catalog', function (string $constant) {
    $name = constant(BlogPostPolicy::class.'::'.$constant);

    expect(Permission::query()->where('name', $name)->exists())->toBeTrue("{$name} is not seeded");
})->with([
    'VIEW_PERMISSION',
    'CREATE_PERMISSION',
    'EDIT_PERMISSION',
    'DELETE_PERMISSION',
]);
