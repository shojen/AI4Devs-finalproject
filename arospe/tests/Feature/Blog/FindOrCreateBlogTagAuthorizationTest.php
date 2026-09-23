<?php

use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\FindOrCreateBlogTag;
use App\Actions\Blog\RenameBlogTag;
use App\Livewire\ProductCategories\Index as ProductCategoriesIndex;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Story 0059, D-11: FindOrCreateBlogTag asks a DIFFERENT ability per branch -- reuse needs to be able
// to read the catalog, insert needs blog.create -- which is this story's one novel authorization
// shape, so it gets a matrix rather than an allow/deny pair. (Phase 2 amendment to OQ-3: the reuse
// branch is open to an actor holding blog.view OR blog.create; refusing a reuse to someone entitled
// to mint the same tag would be incoherent.)
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogTagResolverActor(array $permissions): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('an actor holding blog.view but not blog.create reuses an existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagResolverActor(['blog.view']));

    $resolved = app(FindOrCreateBlogTag::class)('Running');

    expect($resolved->id)->toBe($existing->id)
        ->and(BlogTag::count())->toBe(1);
});

test('the same actor is refused when the name does not exist, and no row is written', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagResolverActor(['blog.view']));

    expect(fn () => app(FindOrCreateBlogTag::class)('invierno'))->toThrow(AuthorizationException::class);
    expect(BlogTag::count())->toBe(1);
});

test('an actor holding blog.create succeeds on both branches', function (array $permissions) {
    $existing = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagResolverActor($permissions));

    expect(app(FindOrCreateBlogTag::class)('running')->id)->toBe($existing->id);

    $created = app(FindOrCreateBlogTag::class)('invierno');

    expect($created->wasRecentlyCreated)->toBeTrue()
        ->and(BlogTag::count())->toBe(2);
})->with([
    'create alone' => [['blog.create']],
    'create and view' => [['blog.create', 'blog.view']],
]);

test('an actor holding neither is refused on both branches and no row is written', function (array $permissions) {
    BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagResolverActor($permissions));

    expect(fn () => app(FindOrCreateBlogTag::class)('running'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(FindOrCreateBlogTag::class)('invierno'))->toThrow(AuthorizationException::class)
        ->and(BlogTag::count())->toBe(1);
})->with([
    'no permission at all' => [[]],
    'only blog.edit and blog.delete' => [['blog.edit', 'blog.delete']],
]);

test('authorization runs before validation, so an unauthorized blank name is an AuthorizationException', function () {
    $this->actingAs(blogTagResolverActor([]));

    expect(fn () => app(FindOrCreateBlogTag::class)(''))->toThrow(AuthorizationException::class);
});

test('an unauthenticated caller is refused', function () {
    expect(fn () => app(FindOrCreateBlogTag::class)('running'))->toThrow(AuthorizationException::class);
});

// The refusal-logging recipe's step 4: every Gate refusal site writes exactly one warning, and its
// context keys are set-equated against an EXISTING screen's, in one Log::spy() session, so a drifting
// key list on either side fails here instead of in a log nobody reads (D-12).
test('every refusal site writes exactly one warning with target_type blog_tag and the same context keys as an existing screen', function () {
    Log::spy();

    $tag = BlogTag::factory()->create(['name' => 'running']);

    // Site 1: CreateBlogTag.
    $this->actingAs($creator = blogTagResolverActor(['blog.view']));
    try {
        app(CreateBlogTag::class)('invierno');
    } catch (AuthorizationException) {
        //
    }

    // Site 2: RenameBlogTag.
    $this->actingAs($editor = blogTagResolverActor(['blog.view']));
    try {
        app(RenameBlogTag::class)($tag, 'invierno');
    } catch (AuthorizationException) {
        //
    }

    // Site 3: DeleteBlogTag.
    $this->actingAs($remover = blogTagResolverActor(['blog.view']));
    try {
        app(DeleteBlogTag::class)($tag);
    } catch (AuthorizationException) {
        //
    }

    // Site 4: FindOrCreateBlogTag, the lookup gate.
    $this->actingAs($looker = blogTagResolverActor([]));
    try {
        app(FindOrCreateBlogTag::class)('running');
    } catch (AuthorizationException) {
        //
    }

    // Site 5: FindOrCreateBlogTag, the insert gate.
    $this->actingAs($minter = blogTagResolverActor(['blog.view']));
    try {
        app(FindOrCreateBlogTag::class)('invierno');
    } catch (AuthorizationException) {
        //
    }

    // The reference: an existing admin screen's refusal, in the SAME spy session.
    $this->actingAs($referenceActor = blogTagResolverActor(['products.view']));
    try {
        Livewire::test(ProductCategoriesIndex::class)->call('openCreateModal');
    } catch (AuthorizationException) {
        //
    }

    $captured = [];

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            $captured[] = $context;

            return $message === 'Privileged action refused';
        })
        ->times(6);

    $expectedSites = [
        [$creator, 'create', null],
        [$editor, 'update', $tag->id],
        [$remover, 'delete', $tag->id],
        [$looker, 'viewAny', null],
        [$minter, 'create', null],
    ];

    $reference = collect($captured)->firstWhere('actor_id', $referenceActor->id);
    $referenceKeys = array_keys($reference);
    sort($referenceKeys);

    foreach ($expectedSites as [$actor, $ability, $targetId]) {
        $lines = collect($captured)->where('actor_id', $actor->id);

        expect($lines)->toHaveCount(1);

        $line = $lines->first();
        $keys = array_keys($line);
        sort($keys);

        expect($line['ability'])->toBe($ability)
            ->and($line['target_type'])->toBe('blog_tag')
            ->and($line['target_id'])->toBe($targetId)
            ->and($keys)->toBe($referenceKeys);
    }
});
