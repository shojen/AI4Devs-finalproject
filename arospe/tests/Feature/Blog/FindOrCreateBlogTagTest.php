<?php

use App\Actions\Blog\FindOrCreateBlogTag;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0059, Phase 3 (TDD "red" step): FindOrCreateBlogTag does not exist yet. The story's
// highest-value file -- no 0023/0058 precedent exists, because neither has a find-or-create.
//
// D-11/D-12: the action authorizes itself before it reads anything, so every test runs actingAs() an
// actor holding both blog.view and blog.create. The conditional-ability matrix has its own file.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.view', 'blog.create']);
    $this->actingAs($this->actor);
});

function blogTagResolveOutcome(string $name): ?Throwable
{
    try {
        app(FindOrCreateBlogTag::class)($name);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('calling twice with byte-identical input returns the same row both times and leaves one row', function () {
    $first = app(FindOrCreateBlogTag::class)('running');
    $second = app(FindOrCreateBlogTag::class)('running');

    expect($second->id)->toBe($first->id)
        ->and(BlogTag::count())->toBe(1);
});

test('a case-only variant resolves to the existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);

    $resolved = app(FindOrCreateBlogTag::class)('Running');

    expect($resolved->id)->toBe($existing->id)
        ->and($resolved->name)->toBe('running')
        ->and(BlogTag::count())->toBe(1);
});

test('an accent-only variant resolves to the existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'Niño']);

    $resolved = app(FindOrCreateBlogTag::class)('Nino');

    expect($resolved->id)->toBe($existing->id)
        ->and($resolved->name)->toBe('Niño')
        ->and(BlogTag::count())->toBe(1);
});

// R-8, BLOCKING: utf8mb4_unicode_ci folds case and accents by itself, so the two tests above would
// stay green against an implementation that skips NormalizeForSearch entirely. No collation folds
// whitespace, so this test and the next are the only ones that prove the normaliser is in the path.
test('a whitespace-padded name resolves to the existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);

    $resolved = app(FindOrCreateBlogTag::class)('  running  ');

    expect($resolved->id)->toBe($existing->id)
        ->and(BlogTag::count())->toBe(1);
});

test('a name with a collapsed internal whitespace run resolves to the existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'trail running']);

    $resolved = app(FindOrCreateBlogTag::class)('trail  running');

    expect($resolved->id)->toBe($existing->id)
        ->and(BlogTag::count())->toBe(1);
});

// The negative control: without it, every reuse assertion above could pass against an implementation
// that returns some arbitrary row regardless of input.
test('an unmatched name creates exactly one new tag and returns it', function () {
    BlogTag::factory()->create(['name' => 'running']);

    $resolved = app(FindOrCreateBlogTag::class)('invierno');

    expect($resolved)->toBeInstanceOf(BlogTag::class)
        ->and($resolved->fresh()->name)->toBe('invierno')
        ->and($resolved->fresh()->normalized_name)->toBe('invierno')
        ->and(BlogTag::count())->toBe(2);
});

test('the name stored for a newly created tag is the trimmed input', function () {
    $resolved = app(FindOrCreateBlogTag::class)('  Trail  Running  ');

    expect($resolved->fresh()->name)->toBe('Trail  Running')
        ->and($resolved->fresh()->normalized_name)->toBe('trail running');
});

// D-10: Eloquent already tracks this on the instance, so no return shape is invented for it.
test('wasRecentlyCreated is true on the create path and false on the reuse path', function () {
    $created = app(FindOrCreateBlogTag::class)('running');
    $reused = app(FindOrCreateBlogTag::class)('Running');

    expect($created->wasRecentlyCreated)->toBeTrue()
        ->and($reused->wasRecentlyCreated)->toBeFalse();
});

// R-2, sharper than in CreateBlogTag: two different whitespace-only inputs both fold to '' and a
// lookup-first implementation would resolve the second to the first's empty-named row -- a false
// success. Validation must refuse BEFORE any lookup or insert, leaving no row of any kind.
test('blank and whitespace-only input is refused before any lookup or insert', function (string $invalid) {
    DB::enableQueryLog();
    $caught = blogTagResolveOutcome($invalid);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(0)
        ->and($queries->contains(fn (string $sql): bool => str_contains($sql, '`blog_tags`')))->toBeFalse();
})->with([
    'blank' => [''],
    'spaces' => ['   '],
    'non-breaking spaces' => ["\u{00A0}\u{00A0}"],
    'zero-width space' => ["\u{200B}"],
]);

test('over-length input is refused and creates nothing', function () {
    $caught = blogTagResolveOutcome(str_repeat('a', 101));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(0);
});

test('a name of exactly 100 characters is resolved and a max-length accented name is stored without a 22001', function () {
    expect(app(FindOrCreateBlogTag::class)(str_repeat('a', 100))->fresh()->name)->toHaveLength(100);

    $accented = app(FindOrCreateBlogTag::class)(str_repeat('ñ', 100));

    expect($accented->fresh()->normalized_name)->toBe(str_repeat('n', 100));
});

test('a name whose folded form no longer fits normalized_name is refused, not truncated', function () {
    expect(app(FindOrCreateBlogTag::class)(str_repeat("\u{104C}", 51))->fresh()->normalized_name)->toHaveLength(255);

    $caught = blogTagResolveOutcome(str_repeat("\u{104C}", 52));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(1);
});

// D-9: the format rules must not carry uniqueness, or the action would refuse its own primary use
// case. An existing name is a HIT here, never a validation error.
test('an existing name is never refused as a duplicate', function () {
    BlogTag::factory()->create(['name' => 'running']);

    expect(blogTagResolveOutcome('running'))->toBeNull()
        ->and(blogTagResolveOutcome('RUNNING'))->toBeNull()
        ->and(BlogTag::count())->toBe(1);
});

// D-10. A genuinely simultaneous two-connection race is unreachable under RefreshDatabase's single
// transaction, so the MECHANISM is simulated -- a competing row lands in the `creating` hook, after
// the lookup missed and before the insert -- and the OUTCOME is asserted: the winning row comes back,
// nothing throws, and no second row exists. The collision is driven through the real unique index.
test('a lost insert race resolves to the winning row instead of throwing', function () {
    $winnerId = (string) Str::uuid7();

    BlogTag::creating(function (BlogTag $incoming) use ($winnerId): void {
        DB::table('blog_tags')->insert([
            'id' => $winnerId,
            'name' => 'RUNNING',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $resolved = app(FindOrCreateBlogTag::class)('running');

    expect($resolved->id)->toBe($winnerId)
        ->and($resolved->name)->toBe('RUNNING')
        ->and($resolved->wasRecentlyCreated)->toBeFalse()
        ->and(BlogTag::count())->toBe(1);
});

test('two clearly different names never collide', function () {
    $running = app(FindOrCreateBlogTag::class)('running');
    $winter = app(FindOrCreateBlogTag::class)('invierno');
    $trail = app(FindOrCreateBlogTag::class)('trail running');

    expect(collect([$running->id, $winter->id, $trail->id])->unique())->toHaveCount(3)
        ->and(BlogTag::count())->toBe(3);
});
