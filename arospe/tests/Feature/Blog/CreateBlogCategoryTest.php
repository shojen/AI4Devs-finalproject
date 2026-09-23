<?php

use App\Actions\Blog\CreateBlogCategory;
use App\Models\BlogCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0058, Phase 3 (TDD "red" step): the action, model, factory, trait and migration do not exist yet.
//
// D-13: CreateBlogCategory authorizes itself BEFORE it validates, so every test runs actingAs() an
// actor holding blog.create -- without one, each negative-validation test below would throw
// AuthorizationException and pass (or fail) for entirely the wrong reason.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('blog.create');
    $this->actingAs($this->actor);
});

/**
 * Run the action and return the ValidationException it threw, or fail the calling test.
 */
function blogCategoryCreateOutcome(string $name): ?Throwable
{
    try {
        app(CreateBlogCategory::class)($name);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * Whether any INSERT into blog_categories was attempted while $callback ran -- what tells a
 * pre-flight validation refusal apart from the unique index refusing a row that validation let through.
 */
function blogCategoryInsertAttempted(Closure $callback): bool
{
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return $queries->contains(fn (string $sql): bool => str_starts_with($sql, 'insert into `blog_categories`'));
}

test('creating with a valid name persists exactly one row and populates timestamps', function () {
    $category = app(CreateBlogCategory::class)('Guías');

    expect(BlogCategory::where('name', 'Guías')->count())->toBe(1);

    $fresh = $category->fresh();

    expect($fresh->created_at)->not->toBeNull()
        ->and($fresh->updated_at)->not->toBeNull();
});

test('creating with a blank name throws ValidationException on name and writes no row', function () {
    $caught = blogCategoryCreateOutcome('');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(0);
});

test('creating with a whitespace-only name is refused and writes no row', function () {
    $caught = blogCategoryCreateOutcome('   ');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(0);
});

// Laravel's own `required` already refuses '   ', so the test above cannot prove the trim runs
// first. This one can: padded to 261 characters, the name only passes `max:255` if it is trimmed
// BEFORE validation.
test('surrounding whitespace is trimmed before validation, so it never counts toward the maximum', function () {
    $category = app(CreateBlogCategory::class)('  '.str_repeat('a', 255).'  ');

    expect($category->fresh()->name)->toBe(str_repeat('a', 255));
});

// PHP's trim() leaves these in place and the shared normaliser then folds them to a plain space, so
// without a Unicode-aware trim "\u{00A0}Guías" folds to " guias" and slips past the duplicate check.
test('a non-breaking or zero-width space around a name is stripped, so it cannot dodge the duplicate check', function (string $padded) {
    BlogCategory::factory()->create(['name' => 'Guías']);

    $caught = blogCategoryCreateOutcome($padded);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(1);
})->with([
    'leading NBSP' => ["\u{00A0}Guías"],
    'trailing NBSP' => ["Guías\u{00A0}"],
    'trailing zero-width space' => ["Guías\u{200B}"],
]);

test('a name that is only invisible characters, or that folds to nothing, is refused', function (string $invisible) {
    $caught = blogCategoryCreateOutcome($invisible);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(0);
})->with([
    'zero-width space' => ["\u{200B}"],
    'non-breaking spaces' => ["\u{00A0}\u{00A0}"],
    'a symbol the fold drops' => ['™'],
]);

test('a name over the maximum reports the length error exactly once', function () {
    $caught = blogCategoryCreateOutcome(str_repeat('b', 256));

    expect($caught->errors()['name'])->toHaveCount(1);
});

test('a name with leading and trailing whitespace is stored trimmed', function () {
    $category = app(CreateBlogCategory::class)('  Guías  ');

    expect($category->fresh()->name)->toBe('Guías')
        ->and(BlogCategory::count())->toBe(1);
});

test('a name of exactly 255 characters is accepted and one character more is refused', function () {
    $accepted = app(CreateBlogCategory::class)(str_repeat('a', 255));

    expect($accepted->fresh()->name)->toHaveLength(255);

    $caught = blogCategoryCreateOutcome(str_repeat('b', 256));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(1);
});

// R-4: Str::ascii() transliterates rather than mapping 1:1 (ß -> ss, up to 5x per character for some
// scripts), so a name inside `max:255` can still fold to a normalized_name that no longer fits its
// 255-character column. The fold must be refused as a validation error, never truncated or a raw 22001.
test('a name whose folded form no longer fits normalized_name is refused, at the exact boundary', function () {
    // 127 x "ß" (-> 254) + "a" folds to exactly 255: accepted.
    $accepted = app(CreateBlogCategory::class)(str_repeat('ß', 127).'a');

    expect($accepted->fresh()->normalized_name)->toHaveLength(255);

    // 128 x "ß" folds to 256: refused although the visible name is only 128 characters.
    $caught = blogCategoryCreateOutcome(str_repeat('ß', 128));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(1);

    $caught = blogCategoryCreateOutcome(str_repeat('ß', 255));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(BlogCategory::count())->toBe(1);
});

test('creating a duplicate name is refused by validation and never reaches the insert', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);

    $caught = null;
    $inserted = blogCategoryInsertAttempted(function () use (&$caught) {
        $caught = blogCategoryCreateOutcome('Guías');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogCategory::where('name', 'Guías')->count())->toBe(1);
});

test('a case-only duplicate is refused by validation, not by the unique index', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);

    $caught = null;
    $inserted = blogCategoryInsertAttempted(function () use (&$caught) {
        $caught = blogCategoryCreateOutcome('guías');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogCategory::count())->toBe(1);
});

test('an accent-only duplicate is refused by validation, not by the unique index', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);

    $caught = null;
    $inserted = blogCategoryInsertAttempted(function () use (&$caught) {
        $caught = blogCategoryCreateOutcome('Guias');
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($inserted)->toBeFalse()
        ->and(BlogCategory::count())->toBe(1);
});

// Rule::unique()-style pre-flight checks are not race guards. A competing request that commits
// between the validation pass and the INSERT is simulated by a real row landing in the `creating`
// hook, so the collision is driven through the REAL unique index on normalized_name -- no mocked
// exception. The outcome must be a clean ValidationException on `name`, never a 500.
test('a duplicate that races past validation is refused by the unique index as a ValidationException', function () {
    BlogCategory::creating(function (BlogCategory $incoming): void {
        DB::table('blog_categories')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'GUÍAS',
            'normalized_name' => 'guias',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = blogCategoryCreateOutcome('Guías');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and(BlogCategory::count())->toBe(1)
        ->and(BlogCategory::first()->name)->toBe('GUÍAS');
});
