<?php

use App\Actions\Blog\UpdateBlogPost;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0061, Phase 3 (TDD "red" step). As in CreateBlogPostTest, every validation test runs actingAs()
// an actor holding blog.edit, because the action authorizes before it validates (D-13).
//
// The full validation depth is re-asserted HERE, independently of create (R-5): a status-parameterised
// rule threaded through one call path but not the other fails silently in exactly one direction.
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.edit', 'blog.view']);
    $this->actingAs($this->actor);

    $this->category = BlogCategory::factory()->create(['name' => 'Guías']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Calls UpdateBlogPost with the post's own current values as defaults, so a test overrides only the
 * field it is about, by parameter name.
 *
 * @param  array<string, mixed>  $overrides
 */
function updateBlogPostWith(BlogPost $post, array $overrides = []): BlogPost
{
    return app(UpdateBlogPost::class)($post, ...array_merge([
        'title' => $post->title,
        'body' => $post->body,
        'blogCategoryId' => $post->blog_category_id,
        'status' => $post->status->value,
        'publishedAt' => $post->published_at?->toDateTimeString(),
        'tagNames' => $post->tags()->pluck('name')->all(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function updateBlogPostErrorKeys(BlogPost $post, array $overrides = []): array
{
    try {
        updateBlogPostWith($post, $overrides);
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

test('a valid update persists every changed field', function () {
    $post = BlogPost::factory()->create([
        'title' => 'Botas de invierno',
        'body' => '<p>Original</p>',
        'blog_category_id' => $this->category->id,
    ]);
    $other = BlogCategory::factory()->create(['name' => 'Novedades']);

    updateBlogPostWith($post, [
        'title' => 'Botas de invierno 2026',
        'body' => '<p>Nuevo cuerpo</p>',
        'blogCategoryId' => $other->id,
        'status' => 'published',
    ]);

    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Botas de invierno 2026')
        ->and($fresh->body)->toBe('<p>Nuevo cuerpo</p>')
        ->and($fresh->blog_category_id)->toBe($other->id)
        ->and($fresh->status)->toBe(BlogPostStatus::Published);
});

test('changing the category to one that is not in the catalog is refused as a validation error', function (string $blogCategoryId) {
    $post = BlogPost::factory()->create(['blog_category_id' => $this->category->id]);

    expect(updateBlogPostErrorKeys($post, ['blogCategoryId' => $blogCategoryId]))->toContain('blog_category_id');
    expect($post->fresh()->blog_category_id)->toBe($this->category->id);
})->with([
    'well-formed uuid that does not exist' => ['0198f3e2-7c1d-7a3b-9d2e-4f5a6b7c8d9e'],
    'malformed uuid' => ['not-a-uuid'],
]);

test('removing the category is refused', function () {
    $post = BlogPost::factory()->create(['blog_category_id' => $this->category->id]);

    expect(updateBlogPostErrorKeys($post, ['blogCategoryId' => null]))->toContain('blog_category_id');
    expect($post->fresh()->blog_category_id)->toBe($this->category->id);
});

test('a blank title is refused on the update path and the row is left as it was', function (string $title) {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect(updateBlogPostErrorKeys($post, ['title' => $title]))->toContain('title');
    expect($post->fresh()->title)->toBe('Botas de invierno');
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
]);

test('a title exactly at the maximum length is accepted on update and one character over is refused', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $atMaximum = str_repeat('a', BlogPost::TITLE_MAX_LENGTH);

    updateBlogPostWith($post, ['title' => $atMaximum]);

    expect($post->fresh()->title)->toBe($atMaximum);

    expect(updateBlogPostErrorKeys($post, ['title' => $atMaximum.'a']))->toContain('title');
    expect($post->fresh()->title)->toBe($atMaximum);
});

test('an unrecognised status is refused on the update path as a validation error, never a ValueError', function (string $status) {
    $post = BlogPost::factory()->create();

    expect(updateBlogPostErrorKeys($post, ['status' => $status]))->toContain('status');
    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft);
})->with([
    'unknown word' => ['archived'],
    'wrong case' => ['PUBLISHED'],
]);

test('re-saving with no changes at all succeeds and leaves the row genuinely unchanged', function () {
    $post = BlogPost::factory()->create([
        'title' => 'Botas de invierno',
        'body' => '<p>Hello</p>',
        'blog_category_id' => $this->category->id,
    ]);
    $before = $post->fresh()->getAttributes();

    updateBlogPostWith($post);

    expect($post->fresh()->getAttributes())->toEqual($before);
});

test('retitling re-derives the slug at the action call site', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    updateBlogPostWith($post, ['title' => 'Botas de invierno 2026']);

    expect($post->fresh()->slug)->toBe('botas-de-invierno-2026');
});

test('an edit that leaves the title alone does not rewrite the slug', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    updateBlogPostWith($post, ['body' => '<p>Otro cuerpo</p>']);

    expect($post->fresh()->slug)->toBe('botas-de-invierno');
});

// OQ-2: the collision check must exclude the post's OWN row, or saving a post under its own unchanged
// title (which is every save) would refuse itself -- and must include everyone else's, trashed included.
test('a post saved under its own unchanged title does not collide with its own slug', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect(updateBlogPostErrorKeys($post, ['body' => '<p>Otro cuerpo</p>']))->toBe([]);
});

test('retitling to a title whose slug belongs to another post is refused on title', function () {
    BlogPost::factory()->create(['title' => 'Botas de verano']);
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect(updateBlogPostErrorKeys($post, ['title' => 'Botas de verano']))->toContain('title');
    expect($post->fresh()->title)->toBe('Botas de invierno')
        ->and($post->fresh()->slug)->toBe('botas-de-invierno');
});

test('retitling to a title whose slug belongs to a trashed post is refused on title', function () {
    $trashed = BlogPost::factory()->create(['title' => 'Botas de verano']);
    $trashed->delete();
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect(updateBlogPostErrorKeys($post, ['title' => 'Botas de verano']))->toContain('title');
    expect($post->fresh()->slug)->toBe('botas-de-invierno');
});

test('the body is sanitized on the update path too, keeping gallery images', function () {
    $post = BlogPost::factory()->create();

    updateBlogPostWith($post, [
        'body' => '<p onclick="steal()">Hola</p><script>steal()</script><img src="https://cdn.example.com/media/bota.jpg" alt="Bota">',
    ]);

    $body = $post->fresh()->body;

    expect($body)->not->toContain('<script')
        ->and($body)->not->toContain('onclick')
        ->and($body)->toContain('<img')
        ->and($body)->toContain('src="https://cdn.example.com/media/bota.jpg"');
});

test('a whitespace-only body is refused when the post is published', function () {
    $post = BlogPost::factory()->create();

    expect(updateBlogPostErrorKeys($post, ['status' => 'published', 'body' => "  \n "]))->toContain('body');
    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft);
});

// Two assertions: a rule that throws AFTER writing would pass a throw-only test. This is the transition
// the create-path body tests cannot reach, and the one where a bodiless post would go public.
test('promoting a bodiless draft to published is refused and the post is still a draft afterwards', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $post = BlogPost::factory()->create(['body' => null]);

    expect(updateBlogPostErrorKeys($post, ['status' => 'published']))->toContain('body');

    $fresh = $post->fresh();

    expect($fresh->status)->toBe(BlogPostStatus::Draft)
        ->and($fresh->published_at)->toBeNull()
        ->and($fresh->body)->toBeNull();
});

test('the same promotion succeeds once a body is supplied in the same save', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $post = BlogPost::factory()->create(['body' => null]);

    updateBlogPostWith($post, ['status' => 'published', 'body' => '<p>Ya escrito</p>']);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Published)
        ->and($post->fresh()->body)->toBe('<p>Ya escrito</p>');
});

test('scheduling a bodiless draft is refused as well', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $post = BlogPost::factory()->create(['body' => null]);

    expect(updateBlogPostErrorKeys($post, ['status' => 'scheduled', 'publishedAt' => '2026-07-01 09:00:00']))->toContain('body');
    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft);
});

// D-19a's second effect: refresh() is the action's first statement, and it discards every attribute the
// caller dirtied. Without it save() writes the whole dirty set (docs/security/model-instance-trust.md),
// so a column the action does not own would persist. A refactor moving the refresh() line would reopen
// this silently -- this test is what stops that.
test('a dirtied caller instance cannot smuggle a column the action does not own through the update', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $originalCreatedAt = $post->fresh()->created_at->toDateTimeString();

    $post->slug = 'hijacked';
    $post->created_at = Carbon::parse('2001-01-01 00:00:00');

    updateBlogPostWith($post, ['body' => '<p>Editado</p>']);

    $fresh = $post->fresh();

    expect($fresh->slug)->toBe('botas-de-invierno')
        ->and($fresh->created_at->toDateTimeString())->toBe($originalCreatedAt)
        ->and($fresh->body)->toBe('<p>Editado</p>');
});

test('a status the caller dirtied on the instance is discarded in favour of the submitted one', function () {
    $post = BlogPost::factory()->create();

    $post->status = BlogPostStatus::Published;
    $post->published_at = Carbon::parse('2020-01-01 00:00:00');

    updateBlogPostWith($post, ['status' => 'draft', 'publishedAt' => null]);

    $fresh = $post->fresh();

    expect($fresh->status)->toBe(BlogPostStatus::Draft)
        ->and($fresh->published_at)->toBeNull();
});

test('tag names omitted from an update are detached and the tag rows survive', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $post = BlogPost::factory()->create();
    $post->tags()->attach($tag->id);

    updateBlogPostWith($post, ['tagNames' => []]);

    $this->assertDatabaseMissing('blog_post_tag', ['blog_post_id' => $post->id]);
    $this->assertDatabaseHas('blog_tags', ['id' => $tag->id]);
});

// Review finding: refresh() neither fails on a non-persisted instance nor skips a trashed one.
test('updating a trashed post is refused and leaves it trashed and unchanged', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $post->delete();

    expect(fn () => updateBlogPostWith($post, ['title' => 'Otro título']))
        ->toThrow(ModelNotFoundException::class);

    $this->assertSoftDeleted('blog_posts', ['id' => $post->id, 'title' => 'Botas de invierno']);
});

test('updating a never-persisted instance cannot create a post with only blog.edit', function () {
    $ghost = new BlogPost;

    expect(fn () => app(UpdateBlogPost::class)(
        $ghost,
        'Fantasma',
        '<p>Cuerpo</p>',
        $this->category->id,
        'draft',
        null,
        [],
    ))->toThrow(ModelNotFoundException::class);

    expect(BlogPost::withTrashed()->count())->toBe(0);
});
