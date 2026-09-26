<?php

// Story 0063 (layer 1) -- the RENDERED half of App\Livewire\BlogPosts\Index (resources/views/
// livewire/blog-posts.blade.php). Component logic, ordering, filtering and authorization live in
// BlogPostsIndexTest.php and the executed SQL in BlogPostsIndexQueryTest.php; nothing here
// duplicates them. Every test asserts against the rendered HTML, which those files never do.
//
// Three page-global assertions are unsafe on this screen specifically, because a blog list
// realistically has many rows sharing a status and overlapping dates (D-20): a status word, a date
// fragment and a tag name (which appears both as a filter option and in a row). Each is asserted
// through its row-scoped data-test hook, with a decoy row of a DIFFERENT status/date in the fixture
// so a hook resolving to the wrong row fails.

use App\Enums\BlogPostStatus;
use App\Livewire\BlogPosts\Index;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogPostsRenderingActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Does the tag carrying `data-test="$hook"` also carry `disabled="disabled"`? Matches the exact
 * attribute, never a bare `disabled` substring: Flux's compiled class list carries the literal
 * `disabled:opacity-75` on the ENABLED branch too (D-20).
 */
function blogPostsControlIsDisabled(string $html, string $hook): bool
{
    return preg_match(
        '/<[a-z0-9-]+(?=[^>]*\bdata-test="'.preg_quote($hook, '/').'")(?=[^>]*\sdisabled="disabled")[^>]*>/is',
        $html,
    ) === 1;
}

function blogPostsControlExists(string $html, string $hook): bool
{
    return str_contains($html, 'data-test="'.$hook.'"');
}

/**
 * The opening tag and the trimmed, tag-stripped text of the one element carrying a data-test hook.
 *
 * @return array{open: string, text: string}|null
 */
function blogPostsRenderedElement(string $html, string $hook): ?array
{
    $pattern = '/<([a-z0-9-]+)\b[^>]*\bdata-test="'.preg_quote($hook, '/').'"[^>]*>(.*?)<\/\1>/is';

    if (preg_match($pattern, $html, $matches) !== 1) {
        return null;
    }

    preg_match('/^<[^>]*>/', $matches[0], $open);

    return [
        'open' => $open[0],
        'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($matches[2]), ENT_QUOTES))),
    ];
}

/**
 * Every Livewire method a `wire:click` in the markup can call.
 *
 * @return list<string>
 */
function blogPostsClickedMethods(string $html): array
{
    preg_match_all('/wire:click="(\w+)/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

// =====================================================================
// Row content
// =====================================================================

test('each status renders its own label and exact colour class through its row-scoped hook', function (string $state, BlogPostStatus $status, string $colourClass) {
    $post = BlogPost::factory()->{$state}()->create();
    // Decoys of the OTHER two statuses, so a hook resolving to the wrong row (or a page-global
    // match) cannot pass.
    collect(['draft', 'published', 'scheduled'])->reject(fn (string $other) => $other === $state)
        ->each(fn (string $other) => BlogPost::factory()->{$other}()->create());
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $badge = blogPostsRenderedElement(Livewire::test(Index::class)->html(), 'status-badge-blog-post-'.$post->id);

    expect($badge)->not->toBeNull()
        ->and($badge['text'])->toBe($status->label())
        ->and($badge['open'])->toContain($colourClass);

    // Never one of the other two statuses' colours. (Flux's badge falls back to zinc for an unknown
    // colour, so the Draft class alone cannot tell "zinc" from "no colour matched"; the exact text
    // class of each of the three does distinguish the three from one another.)
    foreach (array_diff(['text-zinc-700', 'text-amber-700', 'text-lime-800'], [$colourClass]) as $other) {
        expect($badge['open'])->not->toContain($other);
    }
})->with([
    'draft is zinc' => ['draft', BlogPostStatus::Draft, 'text-zinc-700'],
    'scheduled is amber' => ['scheduled', BlogPostStatus::Scheduled, 'text-amber-700'],
    'published is lime' => ['published', BlogPostStatus::Published, 'text-lime-800'],
]);

test('the status badge speaks Spanish under the es locale', function () {
    $post = BlogPost::factory()->published()->create();
    $this->actingAs(blogPostsRenderingActor(['blog.view']));
    App::setLocale('es');

    try {
        $badge = blogPostsRenderedElement(Livewire::test(Index::class)->html(), 'status-badge-blog-post-'.$post->id);
    } finally {
        App::setLocale('en');
    }

    expect($badge['text'])->toBe('Publicado');
});

test('the date cell renders the row\'s own date through its hook, beside a decoy row with another date', function () {
    $published = BlogPost::factory()->create(['status' => 'published', 'published_at' => '2026-03-15 10:30:00']);
    $draft = BlogPost::factory()->create(['created_at' => '2026-02-01 09:15:00']);
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsRenderedElement($html, 'date-blog-post-'.$published->id)['text'])->toBe('15/03/2026 10:30')
        ->and(blogPostsRenderedElement($html, 'date-blog-post-'.$draft->id)['text'])->toBe('01/02/2026 09:15');
});

test('each row renders its title, its category name and its tags through row-scoped hooks', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $decoyCategory = BlogCategory::factory()->create(['name' => 'Novedades']);
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno', 'blog_category_id' => $category->id]);
    $post->tags()->attach([
        BlogTag::factory()->create(['name' => 'running'])->id,
        BlogTag::factory()->create(['name' => 'invierno'])->id,
    ]);
    $decoy = BlogPost::factory()->create(['title' => 'Otra entrada', 'blog_category_id' => $decoyCategory->id]);
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsRenderedElement($html, 'title-blog-post-'.$post->id)['text'])->toBe('Botas de invierno')
        ->and(blogPostsRenderedElement($html, 'category-blog-post-'.$post->id)['text'])->toBe('Guías')
        ->and(blogPostsRenderedElement($html, 'category-blog-post-'.$decoy->id)['text'])->toBe('Novedades')
        ->and(blogPostsRenderedElement($html, 'tags-blog-post-'.$post->id)['text'])->toContain('running')->toContain('invierno')
        ->and(blogPostsControlExists($html, 'tags-blog-post-'.$decoy->id))->toBeFalse();
});

test('a title carrying markup is escaped, never rendered', function () {
    $post = BlogPost::factory()->create(['title' => '<script>alert(1)</script> Botas']);
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and(blogPostsRenderedElement($html, 'title-blog-post-'.$post->id)['text'])->toBe('<script>alert(1)</script> Botas');
});

// =====================================================================
// Row actions: the hook on BOTH branches, disabled matched exactly
// =====================================================================

test('the edit and delete actions render enabled, hooked, when the actor may edit and delete', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'edit-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsControlIsDisabled($html, 'edit-blog-post-'.$post->id))->toBeFalse()
        ->and($html)->toContain(route('blog-posts.edit', $post))
        ->and(blogPostsControlExists($html, 'delete-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsControlIsDisabled($html, 'delete-blog-post-'.$post->id))->toBeFalse()
        ->and($html)->toContain('confirmDelete(');
});

test('the edit and delete actions keep their hook and render disabled, with a reason, when the actor may not use them', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'edit-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsControlIsDisabled($html, 'edit-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsControlExists($html, 'delete-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsControlIsDisabled($html, 'delete-blog-post-'.$post->id))->toBeTrue()
        ->and($html)->not->toContain(route('blog-posts.edit', $post))
        ->and($html)->not->toContain('confirmDelete(')
        ->and($html)->toContain(__('blog-posts.index.action_not_allowed'));
});

test('edit and delete are gated independently: an actor with only blog.edit sees edit enabled and delete disabled', function () {
    $post = BlogPost::factory()->create();
    $this->actingAs(blogPostsRenderingActor(['blog.view', 'blog.edit']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlIsDisabled($html, 'edit-blog-post-'.$post->id))->toBeFalse()
        ->and(blogPostsControlIsDisabled($html, 'delete-blog-post-'.$post->id))->toBeTrue();
});

test('the "new post" action links to the create route when allowed and is disabled, hooked, when not', function () {
    $this->actingAs(blogPostsRenderingActor());
    $enabled = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($enabled, 'create-blog-post-button'))->toBeTrue()
        ->and(blogPostsControlIsDisabled($enabled, 'create-blog-post-button'))->toBeFalse()
        ->and($enabled)->toContain(route('blog-posts.create'));

    $this->actingAs(blogPostsRenderingActor(['blog.view']));
    $disabled = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($disabled, 'create-blog-post-button'))->toBeTrue()
        ->and(blogPostsControlIsDisabled($disabled, 'create-blog-post-button'))->toBeTrue()
        ->and($disabled)->not->toContain(route('blog-posts.create'));
});

// =====================================================================
// Empty states, filters, pagination
// =====================================================================

test('the empty state renders when no post exists, and does not render when one does', function () {
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $empty = Livewire::test(Index::class)->html();
    expect(blogPostsControlExists($empty, 'blog-posts-empty-state'))->toBeTrue()
        ->and($empty)->toContain(__('blog-posts.index.empty'));

    BlogPost::factory()->create();
    $populated = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($populated, 'blog-posts-empty-state'))->toBeFalse()
        ->and($populated)->not->toContain(__('blog-posts.index.empty'));
});

test('a filter that matches nothing says so, instead of claiming that no post exists', function () {
    $empty = BlogCategory::factory()->create(['name' => 'Vacía']);
    BlogPost::factory()->create();
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->set('categoryFilter', $empty->id)->html();

    expect(blogPostsControlExists($html, 'blog-posts-empty-state'))->toBeTrue()
        ->and($html)->toContain(__('blog-posts.index.empty_filtered'))
        ->and($html)->not->toContain(__('blog-posts.index.empty'));
});

test('both filters render as live selects offering an all option plus every category and tag', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    BlogCategory::factory()->create(['name' => 'Novedades']);
    BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'category-filter'))->toBeTrue()
        ->and(blogPostsControlExists($html, 'tag-filter'))->toBeTrue()
        ->and($html)->toContain('wire:model.live="categoryFilter"')
        ->and($html)->toContain('wire:model.live="tagFilter"')
        ->and($html)->toContain(__('blog-posts.index.filter_all_categories'))
        ->and($html)->toContain(__('blog-posts.index.filter_all_tags'))
        ->and($html)->toContain('Guías')->toContain('Novedades')->toContain('running');
});

test('pagination links render once the list outgrows a page', function () {
    BlogPost::factory()->count(26)->create();
    $this->actingAs(blogPostsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('gotoPage(2');
});

// =====================================================================
// Delete modal
// =====================================================================

test('the delete modal renders nothing until opened, then names the post and offers one Cancel', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $this->actingAs(blogPostsRenderingActor());

    $closed = Livewire::test(Index::class)->html();
    expect(blogPostsControlExists($closed, 'confirm-delete-blog-post'))->toBeFalse()
        ->and($closed)->not->toContain(__('blog-posts.index.cancel'));

    $open = Livewire::test(Index::class)->call('confirmDelete', $post->id)->html();

    expect(blogPostsControlExists($open, 'confirm-delete-blog-post'))->toBeTrue()
        ->and($open)->toContain('Botas de invierno')
        ->and(substr_count($open, __('blog-posts.index.cancel')))->toBe(1);
});

// =====================================================================
// The trashed section (D-12 / D-13)
// =====================================================================

test('with no trashed post the section is absent entirely, not an empty shell', function () {
    BlogPost::factory()->create();
    $this->actingAs(blogPostsRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'trashed-posts-toggle'))->toBeFalse()
        ->and(blogPostsControlExists($html, 'trashed-posts-section'))->toBeFalse()
        ->and(blogPostsControlExists($html, 'trashed-posts-count'))->toBeFalse()
        ->and($html)->not->toContain(__('blog-posts.index.trashed_heading'));
});

test('with trashed posts the section renders closed on first paint and its count matches the trashed rows', function () {
    BlogPost::factory()->create();
    $trashed = BlogPost::factory()->count(3)->create();
    $trashed->each->delete();
    $this->actingAs(blogPostsRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'trashed-posts-toggle'))->toBeTrue()
        ->and(blogPostsRenderedElement($html, 'trashed-posts-count')['text'])->toBe('3')
        // Closed on first paint: the Alpine flag starts false and the panel is hidden server-side, so
        // there is no flash of open content before Alpine boots.
        ->and(blogPostsRenderedElement($html, 'trashed-posts-section')['open'])->toContain('open: false')
        ->and(blogPostsRenderedElement($html, 'trashed-posts-list')['open'])->toContain('x-show="open"')->toContain('display: none');

    foreach ($trashed as $post) {
        expect(blogPostsControlExists($html, 'trashed-blog-post-'.$post->id))->toBeTrue();
    }
});

test('a trashed row shows its title and category, and offers no edit link (its edit URL is a 404)', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno', 'blog_category_id' => $category->id]);
    $post->delete();
    $this->actingAs(blogPostsRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsRenderedElement($html, 'trashed-blog-post-'.$post->id)['text'])->toContain('Botas de invierno')->toContain('Guías')
        ->and(blogPostsControlExists($html, 'edit-blog-post-'.$post->id))->toBeFalse()
        ->and($html)->not->toContain(route('blog-posts.edit', $post->id));
});

test('restoring resolves the trashed row, brings it back to the list with its category and tags, and empties the section', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno', 'blog_category_id' => $category->id]);
    $post->tags()->attach(BlogTag::factory()->create(['name' => 'running'])->id);
    $post->delete();
    $this->actingAs(blogPostsRenderingActor());

    $component = Livewire::test(Index::class);
    expect(blogPostsControlExists($component->html(), 'trashed-blog-post-'.$post->id))->toBeTrue();

    $html = $component->call('restoreBlogPost', $post->id)->html();

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
    expect(blogPostsRenderedElement($html, 'title-blog-post-'.$post->id)['text'])->toBe('Botas de invierno')
        ->and(blogPostsRenderedElement($html, 'category-blog-post-'.$post->id)['text'])->toBe('Guías')
        ->and(blogPostsRenderedElement($html, 'tags-blog-post-'.$post->id)['text'])->toContain('running')
        ->and(blogPostsControlExists($html, 'trashed-blog-post-'.$post->id))->toBeFalse()
        ->and(blogPostsControlExists($html, 'trashed-posts-section'))->toBeFalse();
});

test('restoring one of two trashed posts leaves the other listed and the count at one', function () {
    $restored = BlogPost::factory()->create(['title' => 'Restored']);
    $left = BlogPost::factory()->create(['title' => 'Left behind']);
    $restored->delete();
    $left->delete();
    $this->actingAs(blogPostsRenderingActor());

    $html = Livewire::test(Index::class)->call('restoreBlogPost', $restored->id)->html();

    expect(blogPostsControlExists($html, 'trashed-blog-post-'.$left->id))->toBeTrue()
        ->and(blogPostsRenderedElement($html, 'trashed-posts-count')['text'])->toBe('1');
});

test('a deleted post lands in the trashed section in the same request', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $this->actingAs(blogPostsRenderingActor());

    $html = Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->call('deleteBlogPost')
        ->html();

    expect(blogPostsControlExists($html, 'title-blog-post-'.$post->id))->toBeFalse()
        ->and(blogPostsControlExists($html, 'trashed-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsRenderedElement($html, 'trashed-posts-count')['text'])->toBe('1');
});

test('the restore control is enabled for an actor holding blog.edit', function () {
    $post = BlogPost::factory()->create();
    $post->delete();
    $this->actingAs(blogPostsRenderingActor(['blog.view', 'blog.edit']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'restore-blog-post-'.$post->id))->toBeTrue()
        ->and(blogPostsControlIsDisabled($html, 'restore-blog-post-'.$post->id))->toBeFalse()
        ->and($html)->toContain('restoreBlogPost(');
});

test('the restore control is disabled, hooked, for an actor holding blog.delete but NOT blog.edit -- the partial grant', function () {
    // delete and restore gate on DIFFERENT permissions (0061 D-20): this actor sees delete enabled
    // and restore disabled. Neither the seeded Administrator nor a full blog.* role reaches it.
    $live = BlogPost::factory()->create();
    $trashed = BlogPost::factory()->create();
    $trashed->delete();
    $this->actingAs(blogPostsRenderingActor(['blog.view', 'blog.delete']));

    $html = Livewire::test(Index::class)->html();

    expect(blogPostsControlExists($html, 'restore-blog-post-'.$trashed->id))->toBeTrue()
        ->and(blogPostsControlIsDisabled($html, 'restore-blog-post-'.$trashed->id))->toBeTrue()
        ->and($html)->not->toContain('restoreBlogPost(')
        ->and(blogPostsControlIsDisabled($html, 'delete-blog-post-'.$live->id))->toBeFalse();
});

test('a forged restore of a post the actor may not restore is refused, and the component writes nothing', function () {
    $this->withoutExceptionHandling();
    $post = BlogPost::factory()->create();
    $post->delete();
    $this->actingAs(blogPostsRenderingActor(['blog.view', 'blog.delete']));

    $component = Livewire::test(Index::class);

    expect(fn () => $component->call('restoreBlogPost', $post->id))->toThrow(AuthorizationException::class);

    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('restoreBlogPost() with an unknown id fails cleanly with ModelNotFoundException', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(blogPostsRenderingActor());

    expect(fn () => Livewire::test(Index::class)->call('restoreBlogPost', '0198a3a0-0000-7000-8000-000000000000'))
        ->toThrow(ModelNotFoundException::class);
});

test('no force-delete control exists anywhere in the markup, and the only clickable methods are the expected ones', function () {
    $live = BlogPost::factory()->create();
    BlogPost::factory()->create()->delete();
    $this->actingAs(blogPostsRenderingActor());

    $component = Livewire::test(Index::class)->call('confirmDelete', $live->id);
    $html = $component->html();

    expect(strtolower($html))->not->toContain('force')
        ->and(strtolower($html))->not->toContain('purge')
        ->and(strtolower($html))->not->toContain('delete permanently')
        ->and(array_diff(blogPostsClickedMethods($html), ['confirmDelete', 'restoreBlogPost', 'closeDeleteModal', 'deleteBlogPost', 'gotoPage', 'nextPage', 'previousPage']))->toBeEmpty();
});
