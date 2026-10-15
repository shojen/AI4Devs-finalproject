<?php

use App\Actions\Blog\RenameBlogTag;
use App\Actions\Blog\TranslateBlogTagNameUniqueViolation;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0059 / 0074 (D-7): a rename writes the DEFAULT language's blog_tag_translations row, so every
// test needs a default store language and every read goes through the translation row.
// D-12: every test runs actingAs() an actor holding blog.edit,
// or the call throws AuthorizationException before validation ever runs.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('blog.edit');
    $this->actingAs($this->actor);

    $this->defaultLanguage = StoreLanguage::factory()->default()->create();
});

/**
 * Whether any UPDATE on blog_tag_translations was attempted while $callback ran -- what tells a pre-flight
 * validation refusal apart from the unique index refusing a rename that validation let through.
 */
function blogTagUpdateAttempted(Closure $callback): bool
{
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return $queries->contains(fn (string $sql): bool => str_starts_with($sql, 'update `blog_tag_translations`'));
}

function blogTagRenameOutcome(BlogTag $tag, string $name): ?Throwable
{
    try {
        app(RenameBlogTag::class)($tag, $name);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('renaming to a free name updates the row and its normalized_name', function () {
    $tag = BlogTag::factory()->named('running')->create();

    app(RenameBlogTag::class)($tag, 'Trail Running');

    $fresh = $tag->fresh();

    expect($fresh->translated('name'))->toBe('Trail Running')
        ->and(BlogTagTranslation::where('blog_tag_id', $tag->id)->value('normalized_name'))->toBe('trail running')
        ->and(BlogTagTranslation::where('name', 'running')->exists())->toBeFalse()
        ->and(BlogTag::count())->toBe(1);
});

test('renaming onto another tag\'s name is refused and the target keeps its name', function () {
    BlogTag::factory()->named('running')->create();
    $target = BlogTag::factory()->named('invierno')->create();

    $caught = null;
    $attempted = blogTagUpdateAttempted(function () use (&$caught, $target) {
        $caught = blogTagRenameOutcome($target, 'running');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($attempted)->toBeFalse()
        ->and($target->fresh()->translated('name'))->toBe('invierno')
        ->and(BlogTagTranslation::where('blog_tag_id', $target->id)->value('normalized_name'))->toBe('invierno');
});

test('renaming onto a case-only, accent-only or whitespace variant of another tag is refused', function () {
    BlogTag::factory()->named('Niño trail')->create();
    $target = BlogTag::factory()->named('invierno')->create();

    // Refused by validation, never by the unique index: no UPDATE may even be attempted. Without this
    // probe the 23000 catch would turn an index refusal into the same ValidationException, and these
    // cases would stay green against a uniqueness rule that skipped the normaliser (R-8).
    foreach (['NIÑO TRAIL', 'Nino trail', 'nino  trail'] as $variant) {
        $caught = null;
        $attempted = blogTagUpdateAttempted(function () use (&$caught, $target, $variant) {
            $caught = blogTagRenameOutcome($target, $variant);
        });

        expect($caught)->toBeInstanceOf(ValidationException::class)
            ->and($attempted)->toBeFalse();
    }

    expect($target->fresh()->translated('name'))->toBe('invierno');
});

// R-1, in three parts so a rule that rejects everything cannot pass the first trivially.
test('renaming a tag to its own current name is accepted', function () {
    $tag = BlogTag::factory()->named('running')->create();

    expect(blogTagRenameOutcome($tag, 'running'))->toBeNull();
});

test('a no-op rename leaves the row genuinely unchanged', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $before = DB::table('blog_tag_translations')->where('blog_tag_id', $tag->id)->first();

    app(RenameBlogTag::class)($tag, 'running');

    expect(DB::table('blog_tag_translations')->where('blog_tag_id', $tag->id)->first())->toEqual($before)
        ->and(BlogTag::count())->toBe(1)
        ->and(BlogTagTranslation::count())->toBe(1);
});

test('a genuinely free name is still accepted as the control for the no-op case', function () {
    BlogTag::factory()->named('invierno')->create();
    $tag = BlogTag::factory()->named('running')->create();

    expect(blogTagRenameOutcome($tag, 'trail running'))->toBeNull()
        ->and($tag->fresh()->translated('name'))->toBe('trail running');
});

// The ->ignore() branch has to survive the NORMALISED comparison: "running" -> "Running" is the one
// case where this table's uniqueness column differs from a raw-name index.
test('renaming to a case variant of its own current name is accepted and updates the stored name', function () {
    $tag = BlogTag::factory()->named('running')->create();

    app(RenameBlogTag::class)($tag, 'Running');

    expect($tag->fresh()->translated('name'))->toBe('Running')
        ->and(BlogTagTranslation::where('blog_tag_id', $tag->id)->value('normalized_name'))->toBe('running');
});

// R-7: the full validation depth is re-asserted on the rename path, not assumed symmetric with create.
test('renaming to a blank or whitespace-only name is refused', function (string $invalid) {
    $tag = BlogTag::factory()->named('running')->create();

    $caught = blogTagRenameOutcome($tag, $invalid);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($tag->fresh()->translated('name'))->toBe('running');
})->with(['blank' => [''], 'whitespace only' => ['   ']]);

test('a rename trims surrounding whitespace before storing', function () {
    $tag = BlogTag::factory()->named('running')->create();

    app(RenameBlogTag::class)($tag, '  invierno  ');

    expect($tag->fresh()->translated('name'))->toBe('invierno');
});

test('a rename trims surrounding whitespace before validation, so it never counts toward the maximum', function () {
    $tag = BlogTag::factory()->named('running')->create();

    app(RenameBlogTag::class)($tag, '  '.str_repeat('a', 100).'  ');

    expect($tag->fresh()->translated('name'))->toBe(str_repeat('a', 100));
});

test('a rename cannot dodge the duplicate check with a non-breaking space', function () {
    BlogTag::factory()->named('running')->create();
    $target = BlogTag::factory()->named('invierno')->create();

    expect(blogTagRenameOutcome($target, "\u{00A0}running"))->toBeInstanceOf(ValidationException::class)
        ->and($target->fresh()->translated('name'))->toBe('invierno');
});

test('a rename accepts exactly 100 characters and refuses 101', function () {
    $tag = BlogTag::factory()->named('running')->create();

    expect(blogTagRenameOutcome($tag, str_repeat('a', 100)))->toBeNull();

    $caught = blogTagRenameOutcome($tag, str_repeat('b', 101));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($tag->fresh()->translated('name'))->toBe(str_repeat('a', 100));
});

test('a max-length accented name round-trips through rename', function () {
    $tag = BlogTag::factory()->named('running')->create();

    app(RenameBlogTag::class)($tag, str_repeat('ñ', 100));

    expect($tag->fresh()->translated('name'))->toBe(str_repeat('ñ', 100))
        ->and(BlogTagTranslation::where('blog_tag_id', $tag->id)->value('normalized_name'))->toBe(str_repeat('n', 100));
});

test('a rename whose folded form no longer fits normalized_name is refused', function () {
    $tag = BlogTag::factory()->named('running')->create();

    expect(blogTagRenameOutcome($tag, str_repeat("\u{104C}", 51)))->toBeNull();

    $caught = blogTagRenameOutcome($tag, str_repeat("\u{104C}", 52));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTagTranslation::where('blog_tag_id', $tag->id)->value('normalized_name'))->toHaveLength(255);
});

// The caller's instance is untrusted (docs/security/model-instance-trust.md): the action re-reads the
// row. With the stale instance still saying "running" while the row was renamed behind its back, a
// rename "back" to "running" would otherwise see no dirty attribute, write nothing, and report success.
test('renaming through a stale instance writes to the real row instead of silently doing nothing', function () {
    $stale = BlogTag::factory()->named('running')->create();

    DB::table('blog_tag_translations')->where('blog_tag_id', $stale->id)->update(['name' => 'otra', 'normalized_name' => 'otra']);

    app(RenameBlogTag::class)($stale, 'running');

    expect(DB::table('blog_tag_translations')->where('blog_tag_id', $stale->id)->value('name'))->toBe('running');
});

test('an attribute left dirty on the caller\'s instance is not persisted by a rename', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $originalCreatedAt = DB::table('blog_tags')->where('id', $tag->id)->value('created_at');

    $tag->created_at = now()->subYears(5);

    app(RenameBlogTag::class)($tag, 'invierno');

    expect(DB::table('blog_tags')->where('id', $tag->id)->value('created_at'))->toBe($originalCreatedAt);
});

test('renaming a tag whose row is already gone fails cleanly instead of reporting success', function () {
    $tag = BlogTag::factory()->named('running')->create();
    DB::table('blog_tags')->where('id', $tag->id)->delete();

    expect(fn () => app(RenameBlogTag::class)($tag, 'invierno'))->toThrow(ModelNotFoundException::class);
});

test('a rename that races past validation is refused by the unique index as a ValidationException', function () {
    $target = BlogTag::factory()->named('invierno')->create();

    BlogTagTranslation::updating(function (BlogTagTranslation $incoming): void {
        $parentId = (string) Str::uuid7();

        DB::table('blog_tags')->insert(['id' => $parentId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('blog_tag_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_tag_id' => $parentId,
            'store_language_id' => $incoming->store_language_id,
            'name' => 'RUNNING',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = blogTagRenameOutcome($target, 'running');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($target->fresh()->translated('name'))->toBe('invierno');
});

test('a rename with no default store language configured refuses loudly and changes nothing', function () {
    $tag = BlogTag::factory()->named('running')->create();

    DB::table('blog_tag_translations')->delete();
    DB::table('store_languages')->delete();
    StoreLanguage::flushDefaultStoreLanguage();

    expect(blogTagRenameOutcome($tag, 'invierno'))->toBeInstanceOf(RuntimeException::class)
        ->and(BlogTagTranslation::count())->toBe(0);
});

// D-7: only the default language's row is the rename's business; another language's name for the SAME
// tag is authored elsewhere and must come through untouched.
test('a rename touches only the default language row and leaves other languages untouched', function () {
    $other = StoreLanguage::factory()->create();
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($other)->create(['blog_tag_id' => $tag->id, 'name' => 'correr']);

    app(RenameBlogTag::class)($tag, 'Trail');

    expect($tag->fresh()->translated('name'))->toBe('Trail')
        ->and($tag->fresh()->translated('name', $other->id))->toBe('correr')
        ->and(BlogTagTranslation::where('blog_tag_id', $tag->id)->count())->toBe(2);
});

test('the same string already used in another language does not block a rename', function () {
    $other = StoreLanguage::factory()->create();
    $elsewhere = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($other)->create(['blog_tag_id' => $elsewhere->id, 'name' => 'Trail']);
    $tag = BlogTag::factory()->named('running')->create();

    expect(blogTagRenameOutcome($tag, 'Trail'))->toBeNull()
        ->and($tag->fresh()->translated('name'))->toBe('Trail');
});

// A name exclusion keyed by blog_tag_id: a wrong id (the other tag's) would let a rename onto the
// target's own name through, or refuse the tag's own name.
test('with two tags present, renaming one onto its own name passes and onto the other\'s is refused', function () {
    $first = BlogTag::factory()->named('running')->create();
    $second = BlogTag::factory()->named('invierno')->create();

    expect(blogTagRenameOutcome($first, 'RUNNING'))->toBeNull()
        ->and(blogTagRenameOutcome($first, 'invierno'))->toBeInstanceOf(ValidationException::class)
        ->and(blogTagRenameOutcome($second, 'Running'))->toBeInstanceOf(ValidationException::class)
        ->and($second->fresh()->translated('name'))->toBe('invierno');
});

test('a foreign key violation is not misreported as a duplicate name', function () {
    $violation = new QueryException(
        'mysql',
        'insert into blog_tag_translations ...',
        [],
        new PDOException('Cannot add or update a child row', 1452),
    );
    $violation->errorInfo = ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'];

    expect(fn () => app(TranslateBlogTagNameUniqueViolation::class)($violation))->toThrow(QueryException::class);
});

test('a 1062 on the per-language normalized_name index becomes a validation error on name', function () {
    $collision = new QueryException(
        'mysql',
        'insert into blog_tag_translations ...',
        [],
        new PDOException('dup', 1062),
    );
    $collision->errorInfo = ['23000', 1062, "Duplicate entry 'x-running' for key 'blog_tag_translations.blog_tag_translations_language_normalized_name_unique'"];

    $exception = app(TranslateBlogTagNameUniqueViolation::class)($collision);

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('name');
});

test('a 1062 on the per-tag-per-language index is rethrown rather than reported as a taken name', function () {
    $collision = new QueryException(
        'mysql',
        'insert into blog_tag_translations ...',
        [],
        new PDOException('dup', 1062),
    );
    $collision->errorInfo = ['23000', 1062, "Duplicate entry 'a-b' for key 'blog_tag_translations.blog_tag_translations_tag_language_unique'"];

    expect(fn () => app(TranslateBlogTagNameUniqueViolation::class)($collision))->toThrow(QueryException::class);
});
