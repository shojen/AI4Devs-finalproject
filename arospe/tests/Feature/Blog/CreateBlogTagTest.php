<?php

use App\Actions\Blog\CreateBlogTag;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0059. D-12: CreateBlogTag authorizes itself BEFORE it validates, so every test runs actingAs() an actor
// holding blog.create -- without one, each negative-validation test below would throw
// AuthorizationException and pass (or fail) for entirely the wrong reason.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('blog.create');
    $this->actingAs($this->actor);
});

function blogTagCreateOutcome(string $name): ?Throwable
{
    try {
        app(CreateBlogTag::class)($name);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * Whether any INSERT into blog_tags was attempted while $callback ran -- what tells a pre-flight
 * validation refusal apart from the unique index refusing a row that validation let through.
 */
function blogTagInsertAttempted(Closure $callback): bool
{
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return $queries->contains(fn (string $sql): bool => str_starts_with($sql, 'insert into `blog_tags`'));
}

test('creating with a valid name persists exactly one row, timestamps and the derived normalized_name', function () {
    $tag = app(CreateBlogTag::class)('Niño');

    expect(BlogTag::where('name', 'Niño')->count())->toBe(1);

    $fresh = $tag->fresh();

    expect($fresh->normalized_name)->toBe('nino')
        ->and($fresh->created_at)->not->toBeNull()
        ->and($fresh->updated_at)->not->toBeNull();
});

test('creating with a blank name throws ValidationException on name and writes no row', function () {
    $caught = blogTagCreateOutcome('');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(0);
});

test('creating with a whitespace-only name is refused and writes no row', function () {
    $caught = blogTagCreateOutcome('   ');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(0);
});

// R-2. Laravel's own `required` already refuses '   ' (ConvertEmptyStringsToNull aside), so the test
// above cannot prove the trim runs first. This one can: padded to 104 characters, the name only
// passes `max:100` if it is trimmed BEFORE validation.
test('surrounding whitespace is trimmed before validation, so it never counts toward the maximum', function () {
    $tag = app(CreateBlogTag::class)('  '.str_repeat('a', 100).'  ');

    expect($tag->fresh()->name)->toBe(str_repeat('a', 100));
});

// PHP's trim() leaves these in place and the shared normaliser then folds them to a plain space, so
// without a Unicode-aware trim "\u{00A0}running" folds to " running" and slips past the duplicate check.
test('a non-breaking or zero-width space around a name is stripped, so it cannot dodge the duplicate check', function (string $padded) {
    BlogTag::factory()->create(['name' => 'running']);

    $caught = blogTagCreateOutcome($padded);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(1);
})->with([
    'leading NBSP' => ["\u{00A0}running"],
    'trailing NBSP' => ["running\u{00A0}"],
    'trailing zero-width space' => ["running\u{200B}"],
]);

test('a name that is only invisible characters, or that folds to nothing, is refused', function (string $invisible) {
    $caught = blogTagCreateOutcome($invisible);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(0);
})->with([
    'zero-width space' => ["\u{200B}"],
    'non-breaking spaces' => ["\u{00A0}\u{00A0}"],
    'a symbol the fold drops' => ['™'],
]);

// 101 x U+104C breaks BOTH `max:100` and the folded-length bound (505 > 255), so without `bail` it
// would report two errors; a plain ASCII overflow only breaks the first and could not detect its loss.
test('a name over the maximum reports the length error exactly once', function () {
    $caught = blogTagCreateOutcome(str_repeat("\u{104C}", 101));

    expect($caught->errors()['name'])->toHaveCount(1);
});

// The trim runs on the raw input, before `max:` can refuse it, so it must stay linear: the plain-`+`
// regex measured 34 s for 50,000 interior spaces with PCRE's JIT off. 5 s is a generous ceiling for a
// linear one and a hard fail for a quadratic one.
test('a very long run of interior whitespace is refused quickly', function () {
    $start = microtime(true);
    $caught = blogTagCreateOutcome('a'.str_repeat(' ', 50000).'a');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(microtime(true) - $start)->toBeLessThan(5.0);
});

test('a name that is not valid UTF-8 is refused instead of being stored or aliased', function () {
    $caught = blogTagCreateOutcome("abc\xFF");

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(0);
});

test('a name with leading and trailing whitespace is stored trimmed', function () {
    $tag = app(CreateBlogTag::class)('  running  ');

    expect($tag->fresh()->name)->toBe('running')
        ->and(BlogTag::count())->toBe(1);
});

// R-4: the boundary pair on the raw name.
test('a name of exactly 100 characters is accepted and one character more is refused', function () {
    $accepted = app(CreateBlogTag::class)(str_repeat('a', 100));

    expect($accepted->fresh()->name)->toHaveLength(100);

    $caught = blogTagCreateOutcome(str_repeat('b', 101));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(1);
});

// R-4, the half an ASCII-only boundary test cannot reach: a max-length ACCENTED name must round-trip
// without a 22001. Accents fold 1:1, so this is the ordinary Spanish-language worst case.
test('a max-length accented name round-trips through create', function () {
    $tag = app(CreateBlogTag::class)(str_repeat('ñ', 100));

    expect($tag->fresh()->name)->toBe(str_repeat('ñ', 100))
        ->and($tag->fresh()->normalized_name)->toBe(str_repeat('n', 100));
});

// R-4: Str::ascii() transliterates rather than mapping 1:1 -- measured at up to 5 characters for a
// single code point (U+104C -> "hnaik"). A name inside `max:100` can therefore fold to more than the
// 255 characters normalized_name holds; that must be a validation error, never a raw 22001 or a
// truncated key.
test('a name whose folded form no longer fits normalized_name is refused, at the exact boundary', function () {
    // 51 x U+104C folds to exactly 255: accepted.
    $accepted = app(CreateBlogTag::class)(str_repeat("\u{104C}", 51));

    expect($accepted->fresh()->normalized_name)->toHaveLength(255);

    // 52 x U+104C folds to 260 although the visible name is only 52 characters.
    $caught = blogTagCreateOutcome(str_repeat("\u{104C}", 52));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(1);

    $caught = blogTagCreateOutcome(str_repeat("\u{104C}", 100));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(BlogTag::count())->toBe(1);
});

test('creating a duplicate name is refused by validation and never reaches the insert', function () {
    BlogTag::factory()->create(['name' => 'running']);

    $caught = null;
    $inserted = blogTagInsertAttempted(function () use (&$caught) {
        $caught = blogTagCreateOutcome('running');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogTag::where('name', 'running')->count())->toBe(1);
});

test('a case-only duplicate is refused by validation, not by the unique index', function () {
    BlogTag::factory()->create(['name' => 'running']);

    $caught = null;
    $inserted = blogTagInsertAttempted(function () use (&$caught) {
        $caught = blogTagCreateOutcome('Running');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogTag::count())->toBe(1);
});

test('an accent-only duplicate is refused by validation, not by the unique index', function () {
    BlogTag::factory()->create(['name' => 'Niño']);

    $caught = null;
    $inserted = blogTagInsertAttempted(function () use (&$caught) {
        $caught = blogTagCreateOutcome('Nino');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogTag::count())->toBe(1);
});

// R-8: no collation folds internal whitespace, so this is the duplicate case only an explicit
// normaliser can catch -- it proves the fold is in the validation path, not MySQL's collation.
test('a duplicate that differs only by surrounding or internal whitespace is refused by validation', function (string $variant) {
    BlogTag::factory()->create(['name' => 'trail running']);

    $caught = null;
    $inserted = blogTagInsertAttempted(function () use (&$caught, $variant) {
        $caught = blogTagCreateOutcome($variant);
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogTag::count())->toBe(1);
})->with([
    'double internal space' => ['trail  running'],
    'padded' => ['  trail running  '],
]);

// A pre-flight check is not a race guard. A competing request that commits between the validation
// pass and the INSERT is simulated by a real row landing in the `creating` hook, so the collision is
// driven through the REAL unique index on normalized_name -- no mocked exception. The outcome must be
// a clean ValidationException on `name`, never a 500.
test('a duplicate that races past validation is refused by the unique index as a ValidationException', function () {
    BlogTag::creating(function (BlogTag $incoming): void {
        DB::table('blog_tags')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'RUNNING',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = blogTagCreateOutcome('running');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogTag::count())->toBe(1)
        ->and(BlogTag::first()->name)->toBe('RUNNING');
});
