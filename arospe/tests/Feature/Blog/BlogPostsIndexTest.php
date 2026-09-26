<?php

// Story 0063 (layer 1) -- App\Livewire\BlogPosts\Index, the blog post list. This file holds the
// route-level HTTP block for all three post routes, the component's behaviour (row shape, ordering,
// the two filters, soft delete) and its authorization and refusal-logging layers. The executed SQL
// lives in BlogPostsIndexQueryTest.php and the RENDERED markup (badges, hooks, the trashed
// section) in BlogPostsIndexRenderingTest.php; the real-browser round trips in
// tests/Browser/BlogPosts/IndexTest.php.
//
// Deliberately NOT re-run here: 0061's action-level delete/restore semantics
// (DeleteBlogPostTest / RestoreBlogPostTest). This file only proves the SCREEN routes into them.
//
// Written against the pre-Epic-5 schema (`blog_posts.title`, `blog_categories.name`), which is
// what ships today.

use App\Livewire\BlogPosts\Index;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An actor holding every blog.* CRUD permission -- the default fixture for tests whose subject is
 * not authorization itself.
 *
 * @param  array<int, string>  $permissions
 */
function blogPostsIndexActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Takes a permission away from an actor whose component is already mounted, the only way to reach
 * a method's own authorization when the opener that would normally gate it is itself refused.
 */
function blogPostsIndexRevoke(User $actor, string $permission): void
{
    $actor->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * The URL of one of the three post routes; the edit route needs a live post to bind.
 */
function blogPostsIndexUrl(string $routeName): string
{
    return $routeName === 'blog-posts.edit'
        ? route($routeName, BlogPost::factory()->create())
        : route($routeName);
}

/**
 * @return list<string> the titles of the rows the list currently renders, in order
 */
function blogPostsIndexTitles(Testable $component): array
{
    return collect($component->get('posts')->items())->pluck('title')->all();
}

/**
 * Drives one refused call and returns the context of the single 'Privileged action refused'
 * warning it wrote.
 *
 * @param  Closure(): void  $refuse  performs the call that is expected to be refused
 * @return array<string, mixed>
 */
function blogPostsIndexRefusedContext(Closure $refuse): array
{
    Log::spy();
    $captured = [];

    $refuse();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            if ($message !== 'Privileged action refused') {
                return false;
            }
            $captured = $context;

            return true;
        })
        ->once();

    return $captured;
}

// =====================================================================
// HTTP layer -- the four-case gate on all three routes (D-3)
// =====================================================================

$postRoutes = ['blog-posts.index', 'blog-posts.create', 'blog-posts.edit'];

test('guests are redirected to the login page on every post route', function (string $routeName) {
    $this->get(blogPostsIndexUrl($routeName))->assertRedirect(route('login'));
})->with($postRoutes);

test('a signed-in user without blog.view is forbidden on every post route', function (string $routeName) {
    $url = blogPostsIndexUrl($routeName);
    $this->actingAs(blogPostsIndexActor([]));

    $this->get($url)->assertForbidden();
})->with($postRoutes);

test('a user holding only the related-but-different blog.edit permission is forbidden on every post route', function (string $routeName) {
    $url = blogPostsIndexUrl($routeName);
    $this->actingAs(blogPostsIndexActor(['blog.edit']));

    $this->get($url)->assertForbidden();
})->with($postRoutes);

test('a user holding exactly blog.view reaches the list', function () {
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $this->get(route('blog-posts.index'))->assertOk();
});

test('a Super Admin holding zero permission rows reaches every post route and sees no rows', function (string $routeName) {
    $url = blogPostsIndexUrl($routeName);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get($url)->assertOk();
})->with($postRoutes);

test('a Super Admin with no posts at all opens an empty list', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    Livewire::test(Index::class)->assertOk();
    expect(Livewire::test(Index::class)->get('posts')->total())->toBe(0);
});

// D-3: the route gate is `can:blog.view` on ALL three; the finer ability (create / update) is the
// component's own second layer, so a holder of blog.view alone passes the route and is refused by
// the Editor's mount() -- 403, not a redirect and not a 200.
test('the create and edit routes pass the route gate on blog.view but need their finer ability in the component', function () {
    $post = BlogPost::factory()->create();

    $this->actingAs(blogPostsIndexActor(['blog.view']));
    $this->get(route('blog-posts.create'))->assertForbidden();
    $this->get(route('blog-posts.edit', $post))->assertForbidden();

    $this->actingAs(blogPostsIndexActor(['blog.view', 'blog.create']));
    $this->get(route('blog-posts.create'))->assertOk();

    $this->actingAs(blogPostsIndexActor(['blog.view', 'blog.edit']));
    $this->get(route('blog-posts.edit', $post))->assertOk();
});

test('the edit URL of a trashed post is a 404, never a 200 or a 403', function () {
    $post = BlogPost::factory()->create();
    $post->delete();
    $this->actingAs(blogPostsIndexActor());

    $this->get(route('blog-posts.edit', $post->id))->assertNotFound();
});

test('the three post routes live under /blog/posts behind the can: gate, never Spatie\'s permission: middleware', function (string $routeName, string $uri) {
    $route = app('router')->getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe($uri)
        ->and($route->gatherMiddleware())->toContain('can:blog.view')
        ->and(collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')))->toBeEmpty();
})->with([
    'index' => ['blog-posts.index', 'blog/posts'],
    'create' => ['blog-posts.create', 'blog/posts/create'],
    'edit' => ['blog-posts.edit', 'blog/posts/{blogPost}/edit'],
]);

// =====================================================================
// Livewire::test(Index::class) -- the component's own authorization
// =====================================================================

test('mounting the component without blog.view is refused, independently of the route', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(blogPostsIndexActor([]));

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

test('mounting the component with blog.view succeeds', function () {
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    Livewire::test(Index::class)->assertOk();
});

// =====================================================================
// Row shape and the per-row policy hints
// =====================================================================

test('each row carries the documented shape, with the category name and the tag names resolved', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = BlogPost::factory()->published()->create(['title' => 'Botas de invierno', 'blog_category_id' => $category->id]);
    $post->tags()->attach([
        BlogTag::factory()->create(['name' => 'running'])->id,
        BlogTag::factory()->create(['name' => 'invierno'])->id,
    ]);
    $this->actingAs(blogPostsIndexActor());

    $rows = Livewire::test(Index::class)->get('posts')->items();

    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]))->toEqualCanonicalizing(['id', 'title', 'categoryName', 'status', 'date', 'tags', 'canEdit', 'canDelete'])
        ->and($rows[0]['id'])->toBe($post->id)
        ->and($rows[0]['title'])->toBe('Botas de invierno')
        ->and($rows[0]['categoryName'])->toBe('Guías')
        ->and($rows[0]['status'])->toBe($post->status)
        ->and($rows[0]['tags'])->toEqualCanonicalizing(['running', 'invierno']);
});

test('the date is the publication date when the post has one and the creation date otherwise', function () {
    $published = BlogPost::factory()->create([
        'status' => 'published',
        'published_at' => '2026-03-15 10:30:00',
        'created_at' => '2026-03-01 08:00:00',
    ]);
    $draft = BlogPost::factory()->create(['created_at' => '2026-02-01 09:15:00']);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $dates = collect(Livewire::test(Index::class)->get('posts')->items())->pluck('date', 'id');

    expect($dates[$published->id])->toBe('15/03/2026 10:30')
        ->and($dates[$draft->id])->toBe('01/02/2026 09:15');
});

test('canEdit and canDelete agree with BlogPostPolicy for each permission combination', function (array $permissions, bool $canEdit, bool $canDelete) {
    BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor($permissions));

    $row = Livewire::test(Index::class)->get('posts')->items()[0];

    expect($row['canEdit'])->toBe($canEdit)->and($row['canDelete'])->toBe($canDelete);
})->with([
    'view only' => [['blog.view'], false, false],
    'view + edit' => [['blog.view', 'blog.edit'], true, false],
    'view + delete' => [['blog.view', 'blog.delete'], false, true],
    'view + edit + delete' => [['blog.view', 'blog.edit', 'blog.delete'], true, true],
]);

test('a soft-deleted post is not among the rows', function () {
    $live = BlogPost::factory()->create(['title' => 'Live post']);
    BlogPost::factory()->create(['title' => 'Trashed post'])->delete();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    expect(blogPostsIndexTitles(Livewire::test(Index::class)))->toBe(['Live post']);
    expect($live->exists)->toBeTrue();
});

// =====================================================================
// Ordering: created_at DESC, id ASC (D-4)
// =====================================================================

test('rows are ordered newest created first, with a decoy created between the two ends', function () {
    // Inserted OLDEST last on purpose: an implementation ordering by insertion order / id would
    // return the reverse of what the created_at ordering must.
    BlogPost::factory()->create(['title' => 'Newest', 'created_at' => '2026-03-03 12:00:00']);
    BlogPost::factory()->create(['title' => 'Decoy', 'created_at' => '2026-03-02 12:00:00']);
    BlogPost::factory()->create(['title' => 'Oldest', 'created_at' => '2026-03-01 12:00:00']);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    expect(blogPostsIndexTitles(Livewire::test(Index::class)))->toBe(['Newest', 'Decoy', 'Oldest']);
});

test('two posts sharing a creation instant fall back to id ascending, so pages never reshuffle', function () {
    $sharedInstant = '2026-03-03 12:00:00';
    $first = BlogPost::factory()->create(['title' => 'Created first', 'created_at' => $sharedInstant]);
    $second = BlogPost::factory()->create(['title' => 'Created second', 'created_at' => $sharedInstant]);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $expected = collect([$first, $second])->sortBy('id')->pluck('title')->values()->all();

    expect(blogPostsIndexTitles(Livewire::test(Index::class)))->toBe($expected);
});

test('the list is paginated at 25 rows per page', function () {
    BlogPost::factory()->count(27)->create();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $page1 = Livewire::test(Index::class)->get('posts');
    $page2 = Livewire::test(Index::class)->call('gotoPage', 2)->get('posts');

    expect($page1->total())->toBe(27)->and($page1->count())->toBe(25)
        ->and($page2->count())->toBe(2)->and($page2->currentPage())->toBe(2);
});

// =====================================================================
// Filters (D-5): both #[Url]-bound, both compose, both reset the page
// =====================================================================

test('the category filter narrows the list to that category, leaving a decoy category out', function () {
    $guides = BlogCategory::factory()->create(['name' => 'Guías']);
    $news = BlogCategory::factory()->create(['name' => 'Novedades']);
    BlogPost::factory()->create(['title' => 'Guide one', 'blog_category_id' => $guides->id, 'created_at' => '2026-03-02 00:00:00']);
    BlogPost::factory()->create(['title' => 'Guide two', 'blog_category_id' => $guides->id, 'created_at' => '2026-03-01 00:00:00']);
    BlogPost::factory()->create(['title' => 'News one', 'blog_category_id' => $news->id]);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $component = Livewire::test(Index::class)->set('categoryFilter', $guides->id);

    expect(blogPostsIndexTitles($component))->toBe(['Guide one', 'Guide two']);
});

test('the tag filter narrows the list to posts carrying that tag, leaving a decoy tag out', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $winter = BlogTag::factory()->create(['name' => 'invierno']);
    BlogPost::factory()->create(['title' => 'Runner'])->tags()->attach($running->id);
    BlogPost::factory()->create(['title' => 'Winter'])->tags()->attach($winter->id);
    BlogPost::factory()->create(['title' => 'Untagged']);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $component = Livewire::test(Index::class)->set('tagFilter', $running->id);

    expect(blogPostsIndexTitles($component))->toBe(['Runner']);
});

test('the two filters compose: category AND tag narrow further, they do not replace each other', function () {
    $guides = BlogCategory::factory()->create(['name' => 'Guías']);
    $news = BlogCategory::factory()->create(['name' => 'Novedades']);
    $running = BlogTag::factory()->create(['name' => 'running']);
    BlogPost::factory()->create(['title' => 'Guide + running', 'blog_category_id' => $guides->id])->tags()->attach($running->id);
    BlogPost::factory()->create(['title' => 'Guide only', 'blog_category_id' => $guides->id]);
    BlogPost::factory()->create(['title' => 'News + running', 'blog_category_id' => $news->id])->tags()->attach($running->id);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $component = Livewire::test(Index::class)
        ->set('categoryFilter', $guides->id)
        ->set('tagFilter', $running->id);

    expect(blogPostsIndexTitles($component))->toBe(['Guide + running']);
});

test('clearing a filter restores the full list', function () {
    $guides = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['blog_category_id' => $guides->id]);
    BlogPost::factory()->count(2)->create();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $component = Livewire::test(Index::class)->set('categoryFilter', $guides->id);
    expect($component->get('posts')->total())->toBe(1);

    $component->set('categoryFilter', '');
    expect($component->get('posts')->total())->toBe(3);
});

test('changing either filter returns to page 1', function (string $property) {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $tag = BlogTag::factory()->create(['name' => 'running']);
    BlogPost::factory()->count(30)->create(['blog_category_id' => $category->id])
        ->each(fn (BlogPost $post) => $post->tags()->attach($tag->id));
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $value = $property === 'categoryFilter' ? $category->id : $tag->id;

    Livewire::test(Index::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set($property, $value)
        ->assertSet('paginators.page', 1);
})->with(['category' => 'categoryFilter', 'tag' => 'tagFilter']);

test('both filters are bound to the URL as ?category= and ?tag=', function () {
    $properties = collect(['categoryFilter' => 'category', 'tagFilter' => 'tag']);

    foreach ($properties as $property => $alias) {
        $attributes = (new ReflectionProperty(Index::class, $property))->getAttributes(Url::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->as)->toBe($alias);
    }

    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    Livewire::withQueryParams(['category' => $category->id, 'tag' => $tag->id])
        ->test(Index::class)
        ->assertSet('categoryFilter', $category->id)
        ->assertSet('tagFilter', $tag->id);
});

test('an unknown, malformed or forged filter value degrades to no filter and never a 500', function (string $property, string $forged) {
    BlogPost::factory()->count(3)->create();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $component = Livewire::test(Index::class)->set($property, $forged);

    expect($component->get('posts')->total())->toBe(3);
    $component->assertSet($property, '');
})->with([
    'category: unknown uuid' => ['categoryFilter', '0198a3a0-0000-7000-8000-000000000000'],
    'category: not a uuid' => ['categoryFilter', 'not-a-uuid'],
    'category: sql-ish' => ['categoryFilter', "' OR 1=1 --"],
    'tag: unknown uuid' => ['tagFilter', '0198a3a0-0000-7000-8000-000000000000'],
    'tag: not a uuid' => ['tagFilter', 'not-a-uuid'],
]);

test('a forged filter arriving through the URL, even as an array, renders the full list rather than a 500', function (array $query) {
    BlogPost::factory()->count(2)->create();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $this->get(route('blog-posts.index', $query))->assertOk();
})->with([
    'category array' => [['category' => ['a', 'b']]],
    'tag array' => [['tag' => ['a']]],
    'category unknown' => [['category' => 'nope']],
    'tag unknown' => [['tag' => '0198a3a0-0000-7000-8000-000000000000']],
]);

test('a filter naming a trashed post\'s category still degrades cleanly and never surfaces the trashed post', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['title' => 'Trashed', 'blog_category_id' => $category->id])->delete();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    expect(blogPostsIndexTitles(Livewire::test(Index::class)->set('categoryFilter', $category->id)))->toBe([]);
});

// =====================================================================
// Delete: confirm -> soft delete, with its cancel path
// =====================================================================

test('confirmDelete() names the target from a freshly read row and opens the modal', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $this->actingAs(blogPostsIndexActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingBlogPostId', $post->id)
        ->assertSet('deletingBlogPostTitle', 'Botas de invierno');
});

test('deleting a post soft-deletes it, closes the modal and removes it from the list', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $keeper = BlogPost::factory()->create(['title' => 'Keeper']);
    $this->actingAs(blogPostsIndexActor());

    $component = Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->call('deleteBlogPost')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingBlogPostId', null)
        ->assertSet('deletingBlogPostTitle', '');

    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
    $this->assertNotSoftDeleted('blog_posts', ['id' => $keeper->id]);
    expect(blogPostsIndexTitles($component))->toBe(['Keeper']);
});

test('cancelling the delete confirmation leaves the post untouched and clears the modal state', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->call('closeDeleteModal')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingBlogPostId', null)
        ->assertSet('deletingBlogPostTitle', '')
        ->assertHasNoErrors();

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('closeDeleteModal() resets the whole error bag, so a stale message cannot leak into the next attempt', function () {
    // The error bag is dehydrated only for keys the component declares, and this component declares
    // no validated property -- so the reset is pinned at the source, the only place it is observable.
    $source = file_get_contents((new ReflectionClass(Index::class))->getFileName());
    preg_match('/function closeDeleteModal\(\): void\s*\{(.*?)\n    \}/s', $source, $matches);

    expect($matches[1] ?? '')->toContain('resetErrorBag()');
});

test('deleteBlogPost() with nothing confirmed does nothing and does not throw', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor());

    Livewire::test(Index::class)->call('deleteBlogPost')->assertHasNoErrors();

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('confirmDelete() with an unknown, malformed or already-trashed id fails cleanly with ModelNotFoundException', function (string $id) {
    $this->withoutExceptionHandling();
    $this->actingAs(blogPostsIndexActor());

    expect(fn () => Livewire::test(Index::class)->call('confirmDelete', $id))->toThrow(ModelNotFoundException::class);
})->with([
    'unknown uuid' => '0198a3a0-0000-7000-8000-000000000000',
    'not a uuid' => 'not-a-uuid',
    'empty' => '',
]);

test('confirmDelete() on a post that is already trashed fails closed', function () {
    $this->withoutExceptionHandling();
    $post = BlogPost::factory()->create();
    $post->delete();
    $this->actingAs(blogPostsIndexActor());

    expect(fn () => Livewire::test(Index::class)->call('confirmDelete', $post->id))->toThrow(ModelNotFoundException::class);
});

test('a client cannot forge the delete target: deletingBlogPostId, deletingBlogPostTitle and the modal flag are locked or inert', function () {
    $victim = BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor());

    expect(fn () => Livewire::test(Index::class)->set('deletingBlogPostId', $victim->id))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => Livewire::test(Index::class)->set('deletingBlogPostTitle', 'forged'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $this->assertNotSoftDeleted('blog_posts', ['id' => $victim->id]);
});

test('deleteBlogPost() re-authorizes at click time: an actor who lost blog.delete after opening the modal is refused and the post survives', function () {
    $this->withoutExceptionHandling();
    $post = BlogPost::factory()->create();
    $actor = blogPostsIndexActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('confirmDelete', $post->id);
    blogPostsIndexRevoke($actor, 'blog.delete');

    expect(fn () => $component->call('deleteBlogPost'))->toThrow(AuthorizationException::class);

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('deleteBlogPost() fails closed when the target was deleted by someone else after the modal opened', function () {
    $this->withoutExceptionHandling();
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor());

    $component = Livewire::test(Index::class)->call('confirmDelete', $post->id);
    $post->delete();

    expect(fn () => $component->call('deleteBlogPost'))->toThrow(ModelNotFoundException::class);
});

test('confirmDelete() is refused without blog.delete, before anything opens', function () {
    $this->withoutExceptionHandling();
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor(['blog.view', 'blog.edit']));

    $component = Livewire::test(Index::class);

    expect(fn () => $component->call('confirmDelete', $post->id))->toThrow(AuthorizationException::class);
    $component->assertSet('showDeleteModal', false)->assertSet('deletingBlogPostId', null);
});

// =====================================================================
// Refusal logging (target_type: 'blog_post'), asserted on the context array
// =====================================================================

test('every refusal this component raises writes exactly one warning with target_type blog_post, the actor, the ability and the target', function (string $ability, string $revoke, Closure $act) {
    $post = BlogPost::factory()->create();
    $trashed = BlogPost::factory()->create();
    $trashed->delete();
    $actor = blogPostsIndexActor();
    $this->actingAs($actor);

    $context = blogPostsIndexRefusedContext(function () use ($actor, $revoke, $act, $post, $trashed): void {
        $component = Livewire::test(Index::class);
        $act($component, $post, $trashed, 'arrange');
        blogPostsIndexRevoke($actor, $revoke);

        try {
            $act($component, $post, $trashed, 'refuse');
        } catch (AuthorizationException) {
            // expected -- the log line is what is under test
        }
    });

    expect($context['actor_id'])->toBe($actor->id)
        ->and($context['ability'])->toBe($ability)
        ->and($context['target_type'])->toBe('blog_post')
        ->and(array_keys($context))->toEqualCanonicalizing(['actor_id', 'ability', 'target_type', 'target_id']);
})->with([
    'confirmDelete' => ['delete', 'blog.delete', fn ($c, $p, $t, $phase) => $phase === 'refuse' ? $c->call('confirmDelete', $p->id) : null],
    'deleteBlogPost' => ['delete', 'blog.delete', fn ($c, $p, $t, $phase) => $phase === 'arrange' ? $c->call('confirmDelete', $p->id) : $c->call('deleteBlogPost')],
    'restoreBlogPost' => ['restore', 'blog.edit', fn ($c, $p, $t, $phase) => $phase === 'refuse' ? $c->call('restoreBlogPost', $t->id) : null],
]);

test('the delete and restore refusals name the target post id', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsIndexActor(['blog.view']));

    $context = blogPostsIndexRefusedContext(function () use ($post): void {
        try {
            Livewire::test(Index::class)->call('confirmDelete', $post->id);
        } catch (AuthorizationException) {
            //
        }
    });

    expect($context['target_id'])->toBe($post->id);
});

test('a permitted delete and a permitted restore write no warning at all', function () {
    $post = BlogPost::factory()->create();
    $trashed = BlogPost::factory()->create();
    $trashed->delete();
    $this->actingAs(blogPostsIndexActor());

    Log::spy();

    Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->call('deleteBlogPost')
        ->call('restoreBlogPost', $trashed->id);

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// Scope fences
// =====================================================================

test('the list component neither writes an action nor reaches the tag machinery', function () {
    // Layer-1 half of the fence pinned structurally in tests/Unit/ArchitectureTest.php: the list
    // calls only DeleteBlogPost / RestoreBlogPost, and there is no force-delete anywhere.
    $source = file_get_contents((new ReflectionClass(Index::class))->getFileName());
    $code = collect(token_get_all($source))
        ->reject(fn ($token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
        ->implode('');

    expect($code)->not->toContain('forceDelete')
        ->and($code)->not->toContain('SyncBlogPostTags')
        ->and($code)->not->toContain('FindOrCreateBlogTag')
        ->and(glob(app_path('Actions/Blog/*ForceDelete*')))->toBeEmpty();
});
