<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\RestoreBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Livewire\ProductCategories\Index as ProductCategoriesIndex;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Story 0061, D-13: the actions authorize THEMSELVES as their first statement, so a non-dashboard
// caller (an Artisan command, a queued job, 0064's scheduler) inherits the refusal. Each action gets
// a deny AND a narrowness case: an actor holding every blog.* permission EXCEPT the one under test,
// so a policy that checked "any blog.*" could not pass. (OQ-7: the refusal-logging assertions are
// folded into this file rather than split out, matching 0058 and 0059.)
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An actor holding every blog.* permission EXCEPT $withheld.
 */
function blogPostActorWithout(string $withheld): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(array_values(array_diff(
        ['blog.view', 'blog.create', 'blog.edit', 'blog.delete'],
        [$withheld],
    )));

    return $actor;
}

/**
 * Calls the create action with a valid payload.
 */
function blogPostAuthCreate(): BlogPost
{
    return app(CreateBlogPost::class)('Botas de invierno', '<p>Cuerpo</p>', BlogCategory::factory()->create()->id, 'draft', null, []);
}

/**
 * Calls the update action with a valid retitling payload.
 */
function blogPostAuthUpdate(BlogPost $post): BlogPost
{
    return app(UpdateBlogPost::class)($post, 'Otro título', $post->body, $post->blog_category_id, 'draft', null, []);
}

test('CreateBlogPost refuses an actor without blog.create and writes nothing', function () {
    $this->actingAs(blogPostActorWithout('blog.create'));

    expect(fn () => blogPostAuthCreate())->toThrow(AuthorizationException::class);
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

test('UpdateBlogPost refuses an actor without blog.edit and writes nothing', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $this->actingAs(blogPostActorWithout('blog.edit'));

    expect(fn () => blogPostAuthUpdate($post))->toThrow(AuthorizationException::class);
    expect($post->fresh()->title)->toBe('Botas de invierno');
});

test('DeleteBlogPost refuses an actor without blog.delete and writes nothing', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostActorWithout('blog.delete'));

    expect(fn () => app(DeleteBlogPost::class)($post))->toThrow(AuthorizationException::class);
    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});

// RestoreBlogPost's narrowness case is the interesting one: the actor holds blog.delete and is still
// refused, because restore is gated on blog.edit (D-20).
test('RestoreBlogPost refuses an actor without blog.edit, even one holding blog.delete, and writes nothing', function () {
    $post = BlogPost::factory()->create();
    $post->delete();
    $this->actingAs(blogPostActorWithout('blog.edit'));

    expect(fn () => app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id)))
        ->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('each action is allowed for an actor holding exactly its own permission', function () {
    $post = BlogPost::factory()->create();

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.create'));
    expect(blogPostAuthCreate())->toBeInstanceOf(BlogPost::class);

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.edit'));
    expect(blogPostAuthUpdate($post)->title)->toBe('Otro título');

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.delete'));
    expect(app(DeleteBlogPost::class)($post))->toBeTrue();

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.edit'));
    expect(app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id)))->toBeTrue();
});

test('authorization runs before validation, so an unauthorized blank title is an AuthorizationException', function () {
    $this->actingAs(blogPostActorWithout('blog.create'));

    expect(fn () => app(CreateBlogPost::class)('', null, null, null, null, []))->toThrow(AuthorizationException::class);
});

test('an unauthenticated caller is refused by every action', function () {
    $post = BlogPost::factory()->create();
    $trashed = BlogPost::factory()->create();
    $trashed->delete();

    expect(fn () => blogPostAuthCreate())->toThrow(AuthorizationException::class)
        ->and(fn () => blogPostAuthUpdate($post))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteBlogPost::class)($post))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($trashed->id)))->toThrow(AuthorizationException::class);
});

// The refusal-logging recipe's step 4: every Gate refusal site writes exactly one warning, and its
// context keys are set-equated against an EXISTING screen's, in one Log::spy() session, so the
// recipe generalising to a fourth domain cannot silently grow a fifth context key nobody documented.
test('every refusal site writes exactly one warning with target_type blog_post and the same context keys as an existing screen', function () {
    Log::spy();

    $post = BlogPost::factory()->create();
    $trashed = BlogPost::factory()->create();
    $trashed->delete();
    $trashed = BlogPost::withTrashed()->findOrFail($trashed->id);

    // Site 1: CreateBlogPost.
    $this->actingAs($creator = blogPostActorWithout('blog.create'));
    try {
        blogPostAuthCreate();
    } catch (AuthorizationException) {
        //
    }

    // Site 2: UpdateBlogPost.
    $this->actingAs($editor = blogPostActorWithout('blog.edit'));
    try {
        blogPostAuthUpdate($post);
    } catch (AuthorizationException) {
        //
    }

    // Site 3: DeleteBlogPost.
    $this->actingAs($remover = blogPostActorWithout('blog.delete'));
    try {
        app(DeleteBlogPost::class)($post);
    } catch (AuthorizationException) {
        //
    }

    // Site 4: RestoreBlogPost.
    $this->actingAs($restorer = blogPostActorWithout('blog.edit'));
    try {
        app(RestoreBlogPost::class)($trashed);
    } catch (AuthorizationException) {
        //
    }

    // The reference: an existing admin screen's refusal, in the SAME spy session.
    $this->actingAs($referenceActor = User::factory()->create()->givePermissionTo('products.view'));
    try {
        Livewire::test(ProductCategoriesIndex::class)->call('openCreateModal');
    } catch (AuthorizationException) {
        //
    }

    // Mockery invokes a withArgs() closure more than once while verifying, so nothing is captured
    // in a list here: each expectation matches on the whole line and ->once() counts the matches,
    // which is what proves "exactly one warning per refusal".
    $sortedKeys = function (array $context): array {
        $keys = array_keys($context);
        sort($keys);

        return $keys;
    };

    $referenceKeys = null;

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($referenceActor, $sortedKeys, &$referenceKeys): bool {
            if ($message !== 'Privileged action refused' || $context['actor_id'] !== $referenceActor->id) {
                return false;
            }

            $referenceKeys = $sortedKeys($context);

            return true;
        })
        ->once();

    expect($referenceKeys)->toBe(['ability', 'actor_id', 'target_id', 'target_type']);

    foreach ([
        [$creator, 'create', null],
        [$editor, 'update', $post->id],
        [$remover, 'delete', $post->id],
        [$restorer, 'restore', $trashed->id],
    ] as [$actor, $ability, $targetId]) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
                && $context['actor_id'] === $actor->id
                && $context['ability'] === $ability
                && $context['target_type'] === 'blog_post'
                && $context['target_id'] === $targetId
                && $sortedKeys($context) === $referenceKeys)
            ->once();
    }

    // Nothing else was logged: four blog-post refusals plus the one reference line.
    Log::shouldHaveReceived('warning')->times(5);
});

// The over-logging guard: a permitted action must not write a refusal line.
test('a permitted create, update and delete writes no warning', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(User::factory()->create()->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']));

    Log::spy();

    blogPostAuthCreate();
    blogPostAuthUpdate($post);
    app(DeleteBlogPost::class)($post);
    app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id));

    Log::shouldNotHaveReceived('warning');
});
