<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0061 (D-6), Phase 3 (TDD "red" step). Its own file, deliberately: the case density here would
// bury the invariant inside the create/update files. EVERY case freezes the clock -- a test computing
// `now()->addSecond()` and trusting wall-clock timing is flaky by construction on a slow CI run -- and
// the `>` vs `>=` boundary is asserted from BOTH sides (docs/security/step-up-authentication.md).
beforeEach(function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.create', 'blog.edit', 'blog.view']);
    $this->actingAs($this->actor);

    $this->category = BlogCategory::factory()->create(['name' => 'Guías']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createDatedPost(BlogCategory $category, array $overrides = []): BlogPost
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
 * @param  array<string, mixed>  $overrides
 */
function updateDatedPost(BlogPost $post, array $overrides = []): BlogPost
{
    return app(UpdateBlogPost::class)($post, ...array_merge([
        'title' => $post->title,
        'body' => $post->body,
        'blogCategoryId' => $post->blog_category_id,
        'status' => $post->status->value,
        'publishedAt' => $post->published_at?->toDateTimeString(),
        'tagNames' => [],
    ], $overrides));
}

/**
 * @param  callable(): mixed  $call
 * @return list<string>
 */
function datedPostErrorKeys(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

test('a scheduled post with a date that has already passed is refused', function () {
    $keys = datedPostErrorKeys(fn () => createDatedPost($this->category, [
        'status' => 'scheduled',
        'publishedAt' => now()->subDay()->toDateTimeString(),
    ]));

    expect($keys)->toContain('published_at');
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

// The boundary, from BOTH sides: `after:now` is strictly `>`, so a date equal to the current instant is
// refused while the very next second is accepted. Either half alone cannot tell `>` from `>=`.
test('a scheduled post dated exactly now is refused', function () {
    $keys = datedPostErrorKeys(fn () => createDatedPost($this->category, [
        'status' => 'scheduled',
        'publishedAt' => now()->toDateTimeString(),
    ]));

    expect($keys)->toContain('published_at');
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

test('a scheduled post dated one second from now is accepted', function () {
    $post = createDatedPost($this->category, [
        'status' => 'scheduled',
        'publishedAt' => now()->addSecond()->toDateTimeString(),
    ]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Scheduled)
        ->and($post->fresh()->published_at->toDateTimeString())->toBe('2026-06-15 12:00:01');
});

test('a scheduled post with a future date is accepted and the date is persisted', function () {
    $post = createDatedPost($this->category, [
        'status' => 'scheduled',
        'publishedAt' => '2026-07-01 09:30:00',
    ]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Scheduled)
        ->and($post->fresh()->published_at->toDateTimeString())->toBe('2026-07-01 09:30:00');
});

test('a scheduled post without a date is refused', function () {
    $keys = datedPostErrorKeys(fn () => createDatedPost($this->category, [
        'status' => 'scheduled',
        'publishedAt' => null,
    ]));

    expect($keys)->toContain('published_at');
    expect(BlogPost::withTrashed()->count())->toBe(0);
});

test('a date that is not a date is refused', function () {
    $keys = datedPostErrorKeys(fn () => createDatedPost($this->category, [
        'status' => 'published',
        'publishedAt' => 'next tuesday-ish',
    ]));

    expect($keys)->toContain('published_at');
});

test('publishing without a date stamps the current moment', function () {
    $post = createDatedPost($this->category, ['status' => 'published', 'publishedAt' => null]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Published)
        ->and($post->fresh()->published_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
});

// The normal case for backdating an already-published post -- and the one a blanket `after:now` would
// wrongly refuse.
test('publishing with a past date is accepted and the supplied date is persisted verbatim', function () {
    $post = createDatedPost($this->category, [
        'status' => 'published',
        'publishedAt' => '2024-03-10 08:15:00',
    ]);

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2024-03-10 08:15:00');
});

// R-2: a stale future date left on a Draft looks fine in every UI and corrupts the invariant the moment
// the row is later re-promoted.
test('a draft persists a null publication date whatever date is submitted', function (string $submitted) {
    $post = createDatedPost($this->category, ['status' => 'draft', 'publishedAt' => $submitted]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->published_at)->toBeNull();
})->with([
    'a future date' => ['2026-07-01 09:30:00'],
    'a past date' => ['2024-03-10 08:15:00'],
]);

test('returning a scheduled post to draft clears its publication date', function (?string $submitted) {
    $post = BlogPost::factory()->scheduled()->create();
    expect($post->fresh()->published_at)->not->toBeNull();

    updateDatedPost($post, ['status' => 'draft', 'publishedAt' => $submitted]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->published_at)->toBeNull();
})->with([
    'no date resubmitted' => [null],
    'the stored future date resubmitted' => ['2026-06-16 12:00:00'],
]);

test('moving a draft to scheduled with no date supplied and none stored is refused on the update path', function () {
    $post = BlogPost::factory()->draft()->create();

    $keys = datedPostErrorKeys(fn () => updateDatedPost($post, ['status' => 'scheduled', 'publishedAt' => null]));

    expect($keys)->toContain('published_at');
    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->published_at)->toBeNull();
});

test('moving a draft to scheduled with a past date is refused, and with a future date is accepted', function () {
    $post = BlogPost::factory()->draft()->create();

    $keys = datedPostErrorKeys(fn () => updateDatedPost($post, [
        'status' => 'scheduled',
        'publishedAt' => now()->subMinute()->toDateTimeString(),
    ]));

    expect($keys)->toContain('published_at');
    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft);

    updateDatedPost($post, ['status' => 'scheduled', 'publishedAt' => '2026-07-01 09:30:00']);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Scheduled)
        ->and($post->fresh()->published_at->toDateTimeString())->toBe('2026-07-01 09:30:00');
});

test('moving a draft to published without a date stamps the current moment', function () {
    $post = BlogPost::factory()->draft()->create();

    updateDatedPost($post, ['status' => 'published', 'publishedAt' => null]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Published)
        ->and($post->fresh()->published_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
});

test('re-saving an already published post keeps the date it went live', function () {
    $post = BlogPost::factory()->published()->create(['published_at' => Carbon::parse('2026-06-10 10:00:00')]);

    updateDatedPost($post, ['title' => 'Botas de invierno 2026', 'publishedAt' => null]);

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2026-06-10 10:00:00');
});

// The single most important case in this file, and the one a naive implementation fails: an editor
// retitling a Scheduled post whose date has already passed (the scheduler has not flipped it yet)
// resubmits the stored date, and an unconditional `after:now` would refuse every edit. It only appears
// once real time has passed the stored date -- which is why the clock is moved past it here.
test('retitling a scheduled post whose date has already passed is still accepted', function () {
    $post = BlogPost::factory()->scheduled()->create(['published_at' => Carbon::parse('2026-06-16 12:00:00')]);

    Carbon::setTestNow('2026-06-20 12:00:00');
    $overdue = $post->fresh();

    updateDatedPost($overdue, [
        'title' => 'Botas de invierno 2026',
        'status' => 'scheduled',
        'publishedAt' => $overdue->published_at->toDateTimeString(),
    ]);

    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Botas de invierno 2026')
        ->and($fresh->status)->toBe(BlogPostStatus::Scheduled)
        ->and($fresh->published_at->toDateTimeString())->toBe('2026-06-16 12:00:00');
});

// The control for the case above: the exception is for an UNCHANGED date only. Moving an overdue
// scheduled post to a different past date is still refused, and to a future date is accepted.
test('an overdue scheduled post cannot be moved to another past date but can be moved to a future one', function () {
    $post = BlogPost::factory()->scheduled()->create(['published_at' => Carbon::parse('2026-06-16 12:00:00')]);

    Carbon::setTestNow('2026-06-20 12:00:00');
    $overdue = $post->fresh();

    $keys = datedPostErrorKeys(fn () => updateDatedPost($overdue, [
        'status' => 'scheduled',
        'publishedAt' => '2026-06-17 12:00:00',
    ]));

    expect($keys)->toContain('published_at');
    expect($post->fresh()->published_at->toDateTimeString())->toBe('2026-06-16 12:00:00');

    updateDatedPost($overdue, ['status' => 'scheduled', 'publishedAt' => '2026-06-25 12:00:00']);

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2026-06-25 12:00:00');
});

test('an overdue scheduled post can still be returned to draft', function () {
    $post = BlogPost::factory()->scheduled()->create(['published_at' => Carbon::parse('2026-06-16 12:00:00')]);

    Carbon::setTestNow('2026-06-20 12:00:00');

    updateDatedPost($post->fresh(), ['status' => 'draft', 'publishedAt' => null]);

    expect($post->fresh()->status)->toBe(BlogPostStatus::Draft)
        ->and($post->fresh()->published_at)->toBeNull();
});

test('the persisted publication date is what the scheduler will read, in the database column', function () {
    $post = createDatedPost($this->category, ['status' => 'scheduled', 'publishedAt' => '2026-07-01 09:30:00']);

    expect(DB::table('blog_posts')->where('id', $post->id)->value('published_at'))->toBe('2026-07-01 09:30:00');
});

// published_at is a MySQL TIMESTAMP (1970-01-01 .. 2038-01-19 03:14:07 UTC). A date outside it passed the
// `date` rule and then failed the INSERT with a raw 1292 error, so the bounds are validation, asserted
// from both sides.
test('a Scheduled date past the TIMESTAMP ceiling is a validation error, not a database error', function () {
    expect(fn () => createDatedPost($this->category, ['status' => 'scheduled', 'publishedAt' => '2050-01-01 00:00:00']))
        ->toThrow(ValidationException::class);

    expect(BlogPost::count())->toBe(0);

    $post = createDatedPost($this->category, ['status' => 'scheduled', 'publishedAt' => '2037-12-31 23:59:59']);

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2037-12-31 23:59:59');
});

test('a Published date outside the TIMESTAMP range is a validation error on both edges', function (string $date) {
    expect(fn () => createDatedPost($this->category, ['status' => 'published', 'publishedAt' => $date]))
        ->toThrow(ValidationException::class);

    expect(BlogPost::count())->toBe(0);
})->with([
    'past the ceiling' => '2040-06-01 00:00:00',
    'before the epoch' => '1960-06-01 00:00:00',
]);

test('a Published date just inside the TIMESTAMP range is accepted', function () {
    $post = createDatedPost($this->category, ['status' => 'published', 'publishedAt' => '1970-01-02 00:00:00']);

    expect($post->fresh()->published_at->toDateTimeString())->toBe('1970-01-02 00:00:00');
});

test('the same ceiling applies when updating', function () {
    $post = createDatedPost($this->category, ['status' => 'scheduled', 'publishedAt' => '2026-07-01 00:00:00']);

    expect(fn () => updateDatedPost($post, ['publishedAt' => '2050-01-01 00:00:00']))
        ->toThrow(ValidationException::class);

    expect($post->fresh()->published_at->toDateTimeString())->toBe('2026-07-01 00:00:00');
});
