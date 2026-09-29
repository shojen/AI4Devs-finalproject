<?php

use App\Actions\Blog\CreateBlogPost;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0061, Phase 3 (TDD "red" step). The actions authorize THEMSELVES before they validate (D-13),
// so a direct call with no authenticated actor throws AuthorizationException, not ValidationException.
// Every negative-validation test below therefore runs actingAs() an actor holding blog.create --
// otherwise it would pass for entirely the wrong reason.
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.create', 'blog.view']);
    $this->actingAs($this->actor);

    $this->category = BlogCategory::factory()->create(['name' => 'Guías']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Calls CreateBlogPost with valid defaults, any of which a test overrides by parameter name.
 *
 * @param  array<string, mixed>  $overrides
 */
function createBlogPostWith(BlogCategory $category, array $overrides = []): BlogPost
{
    return app(CreateBlogPost::class)(...array_merge([
        'title' => 'Botas de invierno',
        'body' => '<p>Contenido</p>',
        'blogCategoryId' => $category->id,
        'status' => 'draft',
        'publishedAt' => null,
        'tagNames' => [],
    ], $overrides));
}

/**
 * The validation error keys a create raises, or [] when it did not raise any -- so a missing refusal
 * fails the caller's `toContain` with a readable diff rather than an uncaught exception.
 *
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function createBlogPostErrorKeys(BlogCategory $category, array $overrides = []): array
{
    try {
        createBlogPostWith($category, $overrides);
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

test('a valid create persists title, body, category and status and returns the model', function () {
    $post = createBlogPostWith($this->category, [
        'title' => 'Botas de invierno',
        'body' => '<p>Contenido</p>',
        'status' => 'draft',
    ]);

    $fresh = BlogPost::findOrFail($post->id);

    expect($post)->toBeInstanceOf(BlogPost::class)
        ->and($fresh->title)->toBe('Botas de invierno')
        ->and($fresh->body)->toBe('<p>Contenido</p>')
        ->and($fresh->blog_category_id)->toBe($this->category->id)
        ->and($fresh->status)->toBe(BlogPostStatus::Draft)
        ->and($fresh->slug)->toBe('botas-de-invierno');
});

test('omitting the status persists Draft, never Published', function () {
    $post = createBlogPostWith($this->category, ['status' => null]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->published_at)->toBeNull();
    $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'status' => 'draft']);
});

test('a post can be created already published or scheduled with its status persisted', function (string $status, ?string $publishedAt) {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $post = createBlogPostWith($this->category, [
        'status' => $status,
        'publishedAt' => $publishedAt,
    ]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::from($status));
})->with([
    'published' => ['published', null],
    'scheduled' => ['scheduled', '2026-07-01 09:00:00'],
]);

test('a blank title is refused and no post is added', function (string $title) {
    expect(createBlogPostErrorKeys($this->category, ['title' => $title]))->toContain('title');
    expect(BlogPost::withTrashed()->count())->toBe(0);
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
]);

// R-4: the boundary is derived from the constant the migration's string('title', 255) mirrors, and
// asserted as a PAIR so an off-by-one in either direction fails.
test('a title exactly at the maximum length is accepted and one character over is refused', function () {
    $atMaximum = str_repeat('a', BlogPost::TITLE_MAX_LENGTH);

    expect(createBlogPostWith($this->category, ['title' => $atMaximum])->fresh()->title)->toBe($atMaximum);

    expect(createBlogPostErrorKeys($this->category, ['title' => $atMaximum.'a']))->toContain('title');
    expect(BlogPost::count())->toBe(1);
});

test('a post without a category is refused and no post is added', function () {
    expect(createBlogPostErrorKeys($this->category, ['blogCategoryId' => null]))->toContain('blog_category_id');
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

// Rule::exists() makes this a form error; without it the FK would raise a raw QueryException (a 500).
test('a category that is not in the catalog is refused as a validation error, not a QueryException', function (string $blogCategoryId) {
    expect(createBlogPostErrorKeys($this->category, ['blogCategoryId' => $blogCategoryId]))->toContain('blog_category_id');
    expect(BlogPost::withTrashed()->count())->toBe(0);
})->with([
    'well-formed uuid that does not exist' => ['0198f3e2-7c1d-7a3b-9d2e-4f5a6b7c8d9e'],
    'malformed uuid' => ['not-a-uuid'],
]);

// Task 0015's finding F8: an invalid backing value must be a ValidationException, never the \ValueError
// the enum cast would throw. `status` is a string parameter precisely so that this holds.
test('an unrecognised status is refused as a validation error, never a ValueError, and no fallback is applied', function (string $status) {
    expect(createBlogPostErrorKeys($this->category, ['status' => $status]))->toContain('status');
    expect(BlogPost::withTrashed()->count())->toBe(0);
})->with([
    'unknown word' => ['archived'],
    'wrong case' => ['PUBLISHED'],
    'numeric' => ['1'],
]);

test('the body is stored sanitized and an image from the shared gallery survives intact', function () {
    $post = createBlogPostWith($this->category, [
        'body' => '<p>Antes</p><img src="https://cdn.example.com/media/bota.jpg" alt="Bota"><p>Después</p>',
    ]);

    $body = $post->fresh()->body;

    expect($body)->toContain('<img')
        ->and($body)->toContain('src="https://cdn.example.com/media/bota.jpg"')
        ->and($body)->toContain('alt="Bota"')
        ->and($body)->toContain('Antes')
        ->and($body)->toContain('Después');
});

test('a script tag, an event handler and a javascript URI never reach the column', function () {
    $post = createBlogPostWith($this->category, [
        'body' => '<p onclick="steal()">Hola</p><script>steal()</script><a href="javascript:steal()">enlace</a>',
    ]);

    $body = $post->fresh()->body;

    expect($body)->not->toContain('<script')
        ->and($body)->not->toContain('onclick')
        ->and($body)->not->toContain('javascript:')
        ->and($body)->toContain('Hola');
});

test('a draft may be saved without a body, and the persisted value is null', function (?string $body) {
    $post = createBlogPostWith($this->category, ['status' => 'draft', 'body' => $body]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->body)->toBeNull();
})->with([
    'null body' => [null],
    'empty body' => [''],
]);

// Two cases, not one: a `required_if:status,published`-shaped rule passes the first and silently allows
// a bodiless scheduled post, which would then go live empty when the scheduler flips it.
test('a published or scheduled post without a body is refused', function (string $status, ?string $body) {
    Carbon::setTestNow('2026-06-15 12:00:00');

    expect(createBlogPostErrorKeys($this->category, [
        'status' => $status,
        'body' => $body,
        'publishedAt' => $status === 'scheduled' ? '2026-07-01 09:00:00' : null,
    ]))->toContain('body');
    expect(BlogPost::withTrashed()->count())->toBe(0);
})->with([
    'published, null body' => ['published', null],
    'published, empty body' => ['published', ''],
    'scheduled, null body' => ['scheduled', null],
    'scheduled, empty body' => ['scheduled', ''],
]);

// `required` treats '   ' as present unless the value is trimmed BEFORE validation.
test('a whitespace-only body on a published post is refused', function () {
    expect(createBlogPostErrorKeys($this->category, ['status' => 'published', 'body' => "  \n  "]))->toContain('body');
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

// OQ-2 (resolved): a slug collision is refused with a validation error keyed on `title`, and the check
// must see TRASHED rows too -- a trashed post keeps its slug reserved (D-7b).
test('a title whose slug is already taken by a live post is refused on title', function (string $title) {
    BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect(createBlogPostErrorKeys($this->category, ['title' => $title]))->toContain('title');
    expect(BlogPost::count())->toBe(1);
})->with([
    'the identical title' => ['Botas de invierno'],
    'a different title with the same slug' => ['botas de invierno!!'],
]);

test('a title whose slug is held by a trashed post is refused, and the trashed post keeps its slug', function () {
    $trashed = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $trashed->delete();

    expect(createBlogPostErrorKeys($this->category, ['title' => 'Botas de invierno']))->toContain('title');

    expect(BlogPost::withTrashed()->count())->toBe(1)
        ->and(BlogPost::withTrashed()->find($trashed->id)->slug)->toBe('botas-de-invierno');
});

test('a different title still saves alongside a trashed post, as the positive control', function () {
    $trashed = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $trashed->delete();

    $post = createBlogPostWith($this->category, ['title' => 'Botas de verano']);

    expect($post->fresh()->slug)->toBe('botas-de-verano')
        ->and(BlogPost::withTrashed()->count())->toBe(2);
});

test('a title that slugifies to nothing is refused on title rather than saved with an empty slug', function () {
    expect(createBlogPostErrorKeys($this->category, ['title' => '!!!']))->toContain('title');
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

// Review finding: symfony/html-sanitizer TRUNCATES past max_input_length, so without a check an
// oversized body would be silently cut off (D-4b: the validation rule is the binding limit).
test('a body larger than the sanitizer limit is refused rather than silently truncated', function () {
    $max = (int) config('html-sanitizer.max_input_length');

    expect(createBlogPostErrorKeys($this->category, ['status' => 'published', 'body' => '<p>'.str_repeat('a', $max).'</p>']))
        ->toBe(['body'])
        ->and(BlogPost::count())->toBe(0);
});

// Story 0061b. "No body" is judged on what a reader would SEE, after sanitizing -- not on the string -- so an
// editor that emptied its field (`<p><br></p>`) is the same case as `''`.
test('a published or scheduled post whose body renders nothing is refused', function (string $status, string $body) {
    Carbon::setTestNow('2026-06-15 12:00:00');

    expect(createBlogPostErrorKeys($this->category, [
        'status' => $status,
        'body' => $body,
        'publishedAt' => $status === 'scheduled' ? '2026-07-01 09:00:00' : null,
    ]))->toContain('body');
    expect(BlogPost::withTrashed()->count())->toBe(0);
})->with(function () {
    $bodies = [
        'line break in a paragraph' => '<p><br></p>',
        'bare line break' => '<br>',
        'empty paragraph' => '<p></p>',
        'non-breaking space' => '<p>&nbsp;</p>',
        'spaces only' => '<p>   </p>',
        'hidden empty div' => '<div style="display:none"></div>',
        'empty list' => '<ul><li></li></ul>',
        'empty heading' => '<h2></h2>',
        'empty link' => '<a href="https://example.com"></a>',
        'zero-width space' => '<p>&#8203;</p>',
        'html comment' => '<!-- just a comment -->',
        'only a script the sanitizer drops' => '<script>alert(1)</script>',
        'image with an empty src' => '<img src="" alt="Bota">',
    ];

    foreach (['published', 'scheduled'] as $status) {
        foreach ($bodies as $label => $body) {
            yield "{$status}, {$label}" => [$status, $body];
        }
    }
});

test('a draft whose body renders nothing is saved with no body', function (string $body) {
    $post = createBlogPostWith($this->category, ['status' => 'draft', 'body' => $body]);

    expect($post->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->body)->toBeNull();
})->with([
    'line break in a paragraph' => ['<p><br></p>'],
    'non-breaking space' => ['<p>&nbsp;</p>'],
    'empty list' => ['<ul><li></li></ul>'],
    'zero-width space' => ['<p>&#8203;</p>'],
    'html comment' => ['<!-- just a comment -->'],
]);

test('a published post with visible text is saved', function () {
    $post = createBlogPostWith($this->category, ['status' => 'published', 'body' => '<p>Botas de invierno</p>']);

    expect($post->fresh()->body)->toBe('<p>Botas de invierno</p>')
        ->and($post->status)->toBe(BlogPostStatus::Published);
});

test('a published post whose body is only an image from the shared gallery is saved', function () {
    $post = createBlogPostWith($this->category, [
        'status' => 'published',
        'body' => '<img src="https://cdn.example.com/media/bota.jpg" alt="Bota">',
    ]);

    expect($post->fresh()->body)->toContain('src="https://cdn.example.com/media/bota.jpg"');
});

// Pinned on purpose: the sanitizer drops `style` and unwraps `<div>`, so the text survives and is judged as what
// REMAINS. If the allow-list ever starts keeping `style`, this fails and forces the visibility rule to be revisited.
test('text hidden by markup the sanitizer removes is judged as what remains', function () {
    $post = createBlogPostWith($this->category, [
        'status' => 'published',
        'body' => '<div style="display:none">hola</div>',
    ]);

    $body = $post->fresh()->body;

    expect($body)->toContain('hola')
        ->and($body)->not->toContain('style')
        ->and($body)->not->toContain('display');
});
