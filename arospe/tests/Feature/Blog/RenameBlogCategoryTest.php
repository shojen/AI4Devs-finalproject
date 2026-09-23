<?php

use App\Actions\Blog\RenameBlogCategory;
use App\Models\BlogCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0058, Phase 3 (TDD "red" step). D-13: every test runs actingAs() an actor holding
// blog.edit, or the call throws AuthorizationException before validation ever runs.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('blog.edit');
    $this->actingAs($this->actor);
});

function blogCategoryRenameOutcome(BlogCategory $category, string $name): ?Throwable
{
    try {
        app(RenameBlogCategory::class)($category, $name);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('renaming to a free name updates the row and its normalized_name', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    app(RenameBlogCategory::class)($category, 'Guías de compra');

    $fresh = $category->fresh();

    expect($fresh->name)->toBe('Guías de compra')
        ->and($fresh->normalized_name)->toBe('guias de compra')
        ->and(BlogCategory::where('name', 'Guías')->exists())->toBeFalse();
});

test('renaming onto another category\'s name is refused and the target keeps its name', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $target = BlogCategory::factory()->create(['name' => 'Novedades']);

    $caught = blogCategoryRenameOutcome($target, 'Guías');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($target->fresh()->name)->toBe('Novedades')
        ->and($target->fresh()->normalized_name)->toBe('novedades');
});

test('renaming onto a case-only or accent-only variant of another category is refused', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $target = BlogCategory::factory()->create(['name' => 'Novedades']);

    expect(blogCategoryRenameOutcome($target, 'GUÍAS'))->toBeInstanceOf(ValidationException::class)
        ->and(blogCategoryRenameOutcome($target, 'Guias'))->toBeInstanceOf(ValidationException::class)
        ->and($target->fresh()->name)->toBe('Novedades');
});

// R-1, in three parts so a rule that rejects everything cannot pass the first trivially.
test('renaming a category to its own current name is accepted', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    expect(blogCategoryRenameOutcome($category, 'Guías'))->toBeNull();
});

test('a no-op rename leaves the row genuinely unchanged', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $before = DB::table('blog_categories')->where('id', $category->id)->first();

    app(RenameBlogCategory::class)($category, 'Guías');

    expect(DB::table('blog_categories')->where('id', $category->id)->first())->toEqual($before)
        ->and(BlogCategory::count())->toBe(1);
});

test('renaming to its own name in a different case or accent form is accepted and re-derives the display name', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    app(RenameBlogCategory::class)($category, 'GUIAS');

    expect($category->fresh()->name)->toBe('GUIAS')
        ->and($category->fresh()->normalized_name)->toBe('guias');
});

test('a genuinely free name is still accepted as the control for the no-op case', function () {
    BlogCategory::factory()->create(['name' => 'Novedades']);
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    expect(blogCategoryRenameOutcome($category, 'Tutoriales'))->toBeNull()
        ->and($category->fresh()->name)->toBe('Tutoriales');
});

// R-6: the full validation depth is re-asserted on the rename path, not assumed symmetric with create.
test('renaming to a blank or whitespace-only name is refused', function (string $invalid) {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    $caught = blogCategoryRenameOutcome($category, $invalid);

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($category->fresh()->name)->toBe('Guías');
})->with(['blank' => [''], 'whitespace only' => ['   ']]);

test('a rename trims surrounding whitespace before storing', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    app(RenameBlogCategory::class)($category, '  Tutoriales  ');

    expect($category->fresh()->name)->toBe('Tutoriales');
});

test('a rename accepts exactly 255 characters and refuses 256', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    expect(blogCategoryRenameOutcome($category, str_repeat('a', 255)))->toBeNull();

    $caught = blogCategoryRenameOutcome($category, str_repeat('b', 256));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($category->fresh()->name)->toBe(str_repeat('a', 255));
});

test('a rename whose folded form no longer fits normalized_name is refused', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    $caught = blogCategoryRenameOutcome($category, str_repeat('ß', 128));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($category->fresh()->name)->toBe('Guías');
});

test('a rename that races past validation is refused by the unique index as a ValidationException', function () {
    $target = BlogCategory::factory()->create(['name' => 'Novedades']);

    BlogCategory::updating(function (BlogCategory $incoming): void {
        DB::table('blog_categories')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'GUÍAS',
            'normalized_name' => 'guias',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = blogCategoryRenameOutcome($target, 'Guías');

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name')
        ->and($target->fresh()->name)->toBe('Novedades');
});
