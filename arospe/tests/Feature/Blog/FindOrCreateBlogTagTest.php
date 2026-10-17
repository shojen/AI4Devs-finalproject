<?php

use App\Actions\Blog\FindOrCreateBlogTag;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0059 / 0074 (D-7, D-8): the lookup and the insert are scoped to the store DEFAULT language's
// blog_tag_translations rows, so every test needs a default store language.
// Story 0059. The story's highest-value file -- no 0023/0058 precedent exists, because neither has a find-or-create.
//
// D-11/D-12: the action authorizes itself before it reads anything, so every test runs actingAs() an
// actor holding both blog.view and blog.create. The conditional-ability matrix has its own file.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.view', 'blog.create']);
    $this->actingAs($this->actor);

    $this->defaultLanguage = StoreLanguage::factory()->default()->create();
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
    $existing = BlogTag::factory()->named('running')->create();

    $resolved = app(FindOrCreateBlogTag::class)('Running');

    expect($resolved->id)->toBe($existing->id)
        ->and($resolved->translated('name'))->toBe('running')
        ->and(BlogTag::count())->toBe(1);
});

test('an accent-only variant resolves to the existing tag', function () {
    $existing = BlogTag::factory()->named('Niño')->create();

    $resolved = app(FindOrCreateBlogTag::class)('Nino');

    expect($resolved->id)->toBe($existing->id)
        ->and($resolved->translated('name'))->toBe('Niño')
        ->and(BlogTag::count())->toBe(1);
});

// R-8, BLOCKING: utf8mb4_unicode_ci folds case and accents by itself, so the two tests above would
// stay green against an implementation that skips NormalizeForSearch entirely. No collation folds
// INTERNAL whitespace, so the double-space test below is the one that proves the normaliser is in the
// path. The padded-name test only proves the trim (trimName() also handles it), and is kept as the
// companion case.
test('a whitespace-padded name resolves to the existing tag', function () {
    $existing = BlogTag::factory()->named('running')->create();

    $resolved = app(FindOrCreateBlogTag::class)('  running  ');

    expect($resolved->id)->toBe($existing->id)
        ->and(BlogTag::count())->toBe(1);
});

test('a name with a collapsed internal whitespace run resolves to the existing tag', function () {
    $existing = BlogTag::factory()->named('trail running')->create();

    $resolved = app(FindOrCreateBlogTag::class)('trail  running');

    expect($resolved->id)->toBe($existing->id)
        ->and(BlogTag::count())->toBe(1);
});

// The negative control: without it, every reuse assertion above could pass against an implementation
// that returns some arbitrary row regardless of input.
test('an unmatched name creates exactly one new tag and returns it', function () {
    BlogTag::factory()->named('running')->create();

    $resolved = app(FindOrCreateBlogTag::class)('invierno');

    expect($resolved)->toBeInstanceOf(BlogTag::class)
        ->and($resolved->fresh()->translated('name'))->toBe('invierno')
        ->and(BlogTagTranslation::where('blog_tag_id', $resolved->id)->value('normalized_name'))->toBe('invierno')
        ->and(BlogTag::count())->toBe(2);
});

test('the name stored for a newly created tag is the trimmed input', function () {
    $resolved = app(FindOrCreateBlogTag::class)('  Trail  Running  ');

    expect($resolved->fresh()->translated('name'))->toBe('Trail  Running')
        ->and(BlogTagTranslation::where('blog_tag_id', $resolved->id)->value('normalized_name'))->toBe('trail running');
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
        ->and($queries->contains(fn (string $sql): bool => str_contains($sql, '`blog_tag')))->toBeFalse();
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
    expect(app(FindOrCreateBlogTag::class)(str_repeat('a', 100))->fresh()->translated('name'))->toHaveLength(100);

    $accented = app(FindOrCreateBlogTag::class)(str_repeat('ñ', 100));

    expect(BlogTagTranslation::where('blog_tag_id', $accented->id)->value('normalized_name'))->toBe(str_repeat('n', 100));
});

test('a name whose folded form no longer fits normalized_name is refused, not truncated', function () {
    $stored = app(FindOrCreateBlogTag::class)(str_repeat("\u{104C}", 51));

    expect(BlogTagTranslation::where('blog_tag_id', $stored->id)->value('normalized_name'))->toHaveLength(255);

    $caught = blogTagResolveOutcome(str_repeat("\u{104C}", 52));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(1);
});

// D-9: the format rules must not carry uniqueness, or the action would refuse its own primary use
// case. An existing name is a HIT here, never a validation error.
test('an existing name is never refused as a duplicate', function () {
    BlogTag::factory()->named('running')->create();

    expect(blogTagResolveOutcome('running'))->toBeNull()
        ->and(blogTagResolveOutcome('RUNNING'))->toBeNull()
        ->and(BlogTag::count())->toBe(1);
});

// D-10. A genuinely simultaneous two-connection race is unreachable under RefreshDatabase's single
// transaction, so the MECHANISM is simulated -- a competing parent + translation row is committed
// after the lookup missed and before the action's transaction opens (beforeStartingTransaction runs
// before the savepoint, so it survives the rollback a `creating` hook would be swept up in) -- and the
// OUTCOME is asserted: the winning row comes back, nothing throws, and no second row exists. The
// collision is driven through the real (store_language_id, normalized_name) unique index.
//
// D-5, the orphan: the action inserts the parent blog_tags row BEFORE the translation that collides.
// Without the surrounding transaction that parent would stay behind with no translation, so the
// blog_tags count -- not just the translation count -- is the assertion that can fail.
test('a lost insert race resolves to the winning row and leaves no orphaned parent row', function () {
    $winnerId = (string) Str::uuid7();
    $raced = false;

    DB::connection()->beforeStartingTransaction(function () use ($winnerId, &$raced): void {
        if ($raced) {
            return;
        }

        $raced = true;

        DB::table('blog_tags')->insert(['id' => $winnerId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('blog_tag_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_tag_id' => $winnerId,
            'store_language_id' => $this->defaultLanguage->id,
            'name' => 'RUNNING',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $resolved = app(FindOrCreateBlogTag::class)('running');

    expect($raced)->toBeTrue()
        ->and($resolved->id)->toBe($winnerId)
        ->and($resolved->translated('name'))->toBe('RUNNING')
        ->and($resolved->wasRecentlyCreated)->toBeFalse()
        ->and(BlogTag::count())->toBe(1)
        ->and(BlogTagTranslation::count())->toBe(1);
});

test('a translation row already holding the folded name in the default language is returned and no tag is added', function () {
    $parentId = (string) Str::uuid7();

    DB::table('blog_tags')->insert(['id' => $parentId, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('blog_tag_translations')->insert([
        'id' => (string) Str::uuid7(),
        'blog_tag_id' => $parentId,
        'store_language_id' => $this->defaultLanguage->id,
        'name' => 'RUNNING',
        'normalized_name' => 'running',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $before = DB::table('blog_tags')->count();
    $resolved = app(FindOrCreateBlogTag::class)('running');

    expect($resolved->id)->toBe($parentId)
        ->and(DB::table('blog_tags')->count())->toBe($before);
});

// D-8 (amended): the lookup is scoped to the default language; the single exception is a tag that
// holds NO default-language name at all, which is reachable by the name it does have.
test('a name held only in another language by a tag that also has a default name does not resolve to that tag', function () {
    $other = StoreLanguage::factory()->create();
    $foreign = BlogTag::factory()->named('trail')->create();
    BlogTagTranslation::factory()->forLanguage($other)->create(['blog_tag_id' => $foreign->id, 'name' => 'running']);

    $resolved = app(FindOrCreateBlogTag::class)('running');

    expect($resolved->id)->not->toBe($foreign->id)
        ->and($resolved->wasRecentlyCreated)->toBeTrue()
        ->and(BlogTag::count())->toBe(2)
        ->and(BlogTagTranslation::where('blog_tag_id', $resolved->id)->sole()->store_language_id)->toBe($this->defaultLanguage->id);
});

test('a tag with no default-language name is reused by the name it holds elsewhere, without gaining a default row', function () {
    $other = StoreLanguage::factory()->create();
    $orphaned = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($other)->create(['blog_tag_id' => $orphaned->id, 'name' => 'running']);

    $resolved = app(FindOrCreateBlogTag::class)('Running');

    expect($resolved->id)->toBe($orphaned->id)
        ->and($resolved->wasRecentlyCreated)->toBeFalse()
        ->and(BlogTag::count())->toBe(1)
        ->and(BlogTagTranslation::count())->toBe(1);
});

test('a created tag carries exactly one translation, in the default language', function () {
    StoreLanguage::factory()->create();

    $resolved = app(FindOrCreateBlogTag::class)('running');

    $rows = BlogTagTranslation::where('blog_tag_id', $resolved->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->store_language_id)->toBe($this->defaultLanguage->id);
});

test('resolving with no default store language configured refuses loudly and writes nothing', function () {
    DB::table('store_languages')->delete();
    StoreLanguage::flushDefaultStoreLanguage();

    expect(blogTagResolveOutcome('running'))->toBeInstanceOf(RuntimeException::class)
        ->and(BlogTag::count())->toBe(0);
});

// After the default flips, the formerly-default rows are no longer a default-language hit, but a tag
// left with no name in the new default is still reused through the bounded fallback.
test('after the default language changes, a tag left without a default name is still reused', function () {
    $existing = BlogTag::factory()->named('running')->create();
    $newDefault = StoreLanguage::factory()->create();

    DB::table('store_languages')->update(['is_default' => false]);
    DB::table('store_languages')->where('id', $newDefault->id)->update(['is_default' => true]);
    StoreLanguage::flushDefaultStoreLanguage();

    $resolved = app(FindOrCreateBlogTag::class)('running');

    expect($resolved->id)->toBe($existing->id)
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
