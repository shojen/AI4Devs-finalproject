<?php

use App\Actions\Blog\RenameBlogTag;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0059, Phase 3 (TDD "red" step). D-12: every test runs actingAs() an actor holding blog.edit,
// or the call throws AuthorizationException before validation ever runs.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('blog.edit');
    $this->actingAs($this->actor);
});

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
    $tag = BlogTag::factory()->create(['name' => 'running']);

    app(RenameBlogTag::class)($tag, 'Trail Running');

    $fresh = $tag->fresh();

    expect($fresh->name)->toBe('Trail Running')
        ->and($fresh->normalized_name)->toBe('trail running')
        ->and(BlogTag::where('name', 'running')->exists())->toBeFalse()
        ->and(BlogTag::count())->toBe(1);
});

test('renaming onto another tag\'s name is refused and the target keeps its name', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $target = BlogTag::factory()->create(['name' => 'invierno']);

    $caught = blogTagRenameOutcome($target, 'running');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($target->fresh()->name)->toBe('invierno')
        ->and($target->fresh()->normalized_name)->toBe('invierno');
});

test('renaming onto a case-only, accent-only or whitespace variant of another tag is refused', function () {
    BlogTag::factory()->create(['name' => 'Niño trail']);
    $target = BlogTag::factory()->create(['name' => 'invierno']);

    expect(blogTagRenameOutcome($target, 'NIÑO TRAIL'))->toBeInstanceOf(ValidationException::class)
        ->and(blogTagRenameOutcome($target, 'Nino trail'))->toBeInstanceOf(ValidationException::class)
        ->and(blogTagRenameOutcome($target, 'nino  trail'))->toBeInstanceOf(ValidationException::class)
        ->and($target->fresh()->name)->toBe('invierno');
});

// R-1, in three parts so a rule that rejects everything cannot pass the first trivially.
test('renaming a tag to its own current name is accepted', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    expect(blogTagRenameOutcome($tag, 'running'))->toBeNull();
});

test('a no-op rename leaves the row genuinely unchanged', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $before = DB::table('blog_tags')->where('id', $tag->id)->first();

    app(RenameBlogTag::class)($tag, 'running');

    expect(DB::table('blog_tags')->where('id', $tag->id)->first())->toEqual($before)
        ->and(BlogTag::count())->toBe(1);
});

test('a genuinely free name is still accepted as the control for the no-op case', function () {
    BlogTag::factory()->create(['name' => 'invierno']);
    $tag = BlogTag::factory()->create(['name' => 'running']);

    expect(blogTagRenameOutcome($tag, 'trail running'))->toBeNull()
        ->and($tag->fresh()->name)->toBe('trail running');
});

// The ->ignore() branch has to survive the NORMALISED comparison: "running" -> "Running" is the one
// case where this table's uniqueness column differs from a raw-name index.
test('renaming to a case variant of its own current name is accepted and updates the stored name', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    app(RenameBlogTag::class)($tag, 'Running');

    expect($tag->fresh()->name)->toBe('Running')
        ->and($tag->fresh()->normalized_name)->toBe('running');
});

// R-7: the full validation depth is re-asserted on the rename path, not assumed symmetric with create.
test('renaming to a blank or whitespace-only name is refused', function (string $invalid) {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    $caught = blogTagRenameOutcome($tag, $invalid);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($tag->fresh()->name)->toBe('running');
})->with(['blank' => [''], 'whitespace only' => ['   ']]);

test('a rename trims surrounding whitespace before storing', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    app(RenameBlogTag::class)($tag, '  invierno  ');

    expect($tag->fresh()->name)->toBe('invierno');
});

test('a rename trims surrounding whitespace before validation, so it never counts toward the maximum', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    app(RenameBlogTag::class)($tag, '  '.str_repeat('a', 100).'  ');

    expect($tag->fresh()->name)->toBe(str_repeat('a', 100));
});

test('a rename cannot dodge the duplicate check with a non-breaking space', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $target = BlogTag::factory()->create(['name' => 'invierno']);

    expect(blogTagRenameOutcome($target, "\u{00A0}running"))->toBeInstanceOf(ValidationException::class)
        ->and($target->fresh()->name)->toBe('invierno');
});

test('a rename accepts exactly 100 characters and refuses 101', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    expect(blogTagRenameOutcome($tag, str_repeat('a', 100)))->toBeNull();

    $caught = blogTagRenameOutcome($tag, str_repeat('b', 101));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($tag->fresh()->name)->toBe(str_repeat('a', 100));
});

test('a max-length accented name round-trips through rename', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    app(RenameBlogTag::class)($tag, str_repeat('ñ', 100));

    expect($tag->fresh()->name)->toBe(str_repeat('ñ', 100))
        ->and($tag->fresh()->normalized_name)->toBe(str_repeat('n', 100));
});

test('a rename whose folded form no longer fits normalized_name is refused', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    expect(blogTagRenameOutcome($tag, str_repeat("\u{104C}", 51)))->toBeNull();

    $caught = blogTagRenameOutcome($tag, str_repeat("\u{104C}", 52));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($tag->fresh()->normalized_name)->toHaveLength(255);
});

// The caller's instance is untrusted (docs/security/model-instance-trust.md): the action re-reads the
// row. With the stale instance still saying "running" while the row was renamed behind its back, a
// rename "back" to "running" would otherwise see no dirty attribute, write nothing, and report success.
test('renaming through a stale instance writes to the real row instead of silently doing nothing', function () {
    $stale = BlogTag::factory()->create(['name' => 'running']);

    DB::table('blog_tags')->where('id', $stale->id)->update(['name' => 'otra', 'normalized_name' => 'otra']);

    app(RenameBlogTag::class)($stale, 'running');

    expect(DB::table('blog_tags')->where('id', $stale->id)->value('name'))->toBe('running');
});

test('an attribute left dirty on the caller\'s instance is not persisted by a rename', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $originalCreatedAt = DB::table('blog_tags')->where('id', $tag->id)->value('created_at');

    $tag->created_at = now()->subYears(5);

    app(RenameBlogTag::class)($tag, 'invierno');

    expect(DB::table('blog_tags')->where('id', $tag->id)->value('created_at'))->toBe($originalCreatedAt);
});

test('renaming a tag whose row is already gone fails cleanly instead of reporting success', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    DB::table('blog_tags')->where('id', $tag->id)->delete();

    expect(fn () => app(RenameBlogTag::class)($tag, 'invierno'))->toThrow(ModelNotFoundException::class);
});

test('a rename that races past validation is refused by the unique index as a ValidationException', function () {
    $target = BlogTag::factory()->create(['name' => 'invierno']);

    BlogTag::updating(function (BlogTag $incoming): void {
        DB::table('blog_tags')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'RUNNING',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = blogTagRenameOutcome($target, 'running');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($target->fresh()->name)->toBe('invierno');
});
