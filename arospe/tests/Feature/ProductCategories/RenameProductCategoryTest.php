<?php

use App\Actions\ProductCategories\CreateProductCategory;
use App\Actions\ProductCategories\RenameProductCategory;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0023, Phase 3 (TDD "red" step) -- see CreateProductCategoryTest.php's file banner; the
// same applies here.
// RenameProductCategory::__invoke(ProductCategory $productCategory, string $name): ProductCategory
// does not exist yet.
//
// Story 0025: both CreateProductCategory (used here only to set up fixtures) and
// RenameProductCategory now authorize themselves as their own first statement, so the actor below
// needs both products.create and products.edit -- see CreateProductCategoryTest.php's identical
// fix and DeleteProductCategoryTest.php's original one (Phase 2 review finding B-2).
//
// Story 0070 (D-12, D-15): RenameProductCategory now writes the given name into the store
// DEFAULT language's product_category_translations row rather than a product_categories.name
// column -- every read below goes through ProductCategoryTranslation / ->translated('name')
// instead, and every test needs a default store language to write into.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['products.create', 'products.edit']);
    $this->actingAs($this->actor);

    $this->defaultLanguage = StoreLanguage::factory()->default()->create();
});

test('renaming to a free name updates the row and leaves the old name unused', function () {
    $category = app(CreateProductCategory::class)('Footwear');

    $renamed = app(RenameProductCategory::class)($category, 'Running shoes');

    expect($renamed->fresh()->translated('name'))->toBe('Running shoes')
        ->and(ProductCategoryTranslation::where('name', 'Footwear')->exists())->toBeFalse();
});

test("renaming onto another category's name is refused and the target keeps its original name", function () {
    app(CreateProductCategory::class)('Footwear');
    $apparel = app(CreateProductCategory::class)('Apparel');

    $caught = null;

    try {
        app(RenameProductCategory::class)($apparel, 'Footwear');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($apparel->fresh()->translated('name'))->toBe('Apparel');
});

// R-1: the single most likely bug in this story -- the Rule::unique()->ignore() trap, and exactly
// why ProfileValidationRules::emailRules() takes a nullable id. Written as THREE tests, not one,
// so a rule that rejects everything cannot pass the first trivially.

test('saving a category under its own current name (a no-op rename) succeeds', function () {
    $category = app(CreateProductCategory::class)('Footwear');

    $result = app(RenameProductCategory::class)($category, 'Footwear');

    expect($result)->not->toBeNull();
});

test('after a no-op rename to the identical name, the row is genuinely unchanged', function () {
    $category = app(CreateProductCategory::class)('Footwear');

    app(RenameProductCategory::class)($category, 'Footwear');

    expect(ProductCategoryTranslation::where('name', 'Footwear')->count())->toBe(1)
        ->and($category->fresh()->id)->toBe($category->id)
        ->and($category->fresh()->translated('name'))->toBe('Footwear');
});

test('a genuinely free name is still accepted when renaming, as the control for the no-op case above', function () {
    $category = app(CreateProductCategory::class)('Footwear');

    $renamed = app(RenameProductCategory::class)($category, 'Boots');

    expect($renamed->fresh()->translated('name'))->toBe('Boots');
});

// R-7: nameRules() reused asymmetrically -- a nullable-id rule helper whose $id is threaded
// through on one call path but not the other fails silently in only one direction. The full
// validation depth is re-asserted here independently on the RENAME path, not assumed symmetric
// with create.

test('renaming to a blank name is refused and the category keeps its original name', function () {
    $category = app(CreateProductCategory::class)('Footwear');

    $caught = null;

    try {
        app(RenameProductCategory::class)($category, '');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('name');

    expect($category->fresh()->translated('name'))->toBe('Footwear');
});

test('renaming to a whitespace-only name is refused and the category keeps its original name', function () {
    $category = app(CreateProductCategory::class)('Footwear');

    $caught = null;

    try {
        app(RenameProductCategory::class)($category, '   ');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($category->fresh()->translated('name'))->toBe('Footwear');
});

test('renaming to a name of exactly the maximum length (255) is accepted', function () {
    $category = app(CreateProductCategory::class)('Footwear');
    $name = str_repeat('b', 255);

    $renamed = app(RenameProductCategory::class)($category, $name);

    expect($renamed->fresh()->translated('name'))->toBe($name);
});

test('renaming to a name one character over the maximum length (256) is refused', function () {
    $category = app(CreateProductCategory::class)('Footwear');
    $name = str_repeat('b', 256);

    $caught = null;

    try {
        app(RenameProductCategory::class)($category, $name);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($category->fresh()->translated('name'))->toBe('Footwear');
});

// R-2/D-4/D-7, rename path: mirrors CreateProductCategoryTest's identical race test, driving the
// collision through the real unique index rather than a hand-written assertion about the catch
// block -- proves RenameProductCategory's own QueryException catch independently of
// CreateProductCategory's, rather than assuming the two actions share the same behaviour.
test('a duplicate that bypasses validation via a simulated race surfaces as a ValidationException on name when renaming, not a 500', function () {
    $category = app(CreateProductCategory::class)('Footwear');
    $racedName = 'Race Condition Rename';
    $raced = false;

    DB::listen(function ($query) use (&$raced, $racedName): void {
        if ($raced || ! str_contains($query->sql, 'product_category_translations')) {
            return;
        }

        $raced = true;

        $racerCategoryId = (string) Str::uuid7();

        DB::table('product_categories')->insert([
            'id' => $racerCategoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_category_translations')->insert([
            'id' => (string) Str::uuid7(),
            'product_category_id' => $racerCategoryId,
            'store_language_id' => $this->defaultLanguage->id,
            'name' => $racedName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = null;

    try {
        app(RenameProductCategory::class)($category, $racedName);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($raced)->toBeTrue()
        ->and($caught)->toBeInstanceOf(ValidationException::class);

    expect(ProductCategoryTranslation::where('name', $racedName)->count())->toBeLessThan(2);
});

// Phase 5 review round 1, finding 3(b): RenameProductCategory only ever writes the DEFAULT
// language's row (D-12, D-15) -- untested was whether it leaves an existing NON-default
// translation, seeded before the rename, byte-for-byte untouched rather than overwriting or
// deleting it.
test('renaming a category in the default language leaves an existing translation in another language byte-for-byte untouched', function () {
    $french = StoreLanguage::factory()->create();
    $category = app(CreateProductCategory::class)('Footwear');

    $frenchTranslation = ProductCategoryTranslation::factory()
        ->forLanguage($french)
        ->create([
            'product_category_id' => $category->id,
            'name' => 'Chaussures',
        ]);

    app(RenameProductCategory::class)($category, 'Running shoes');

    expect($frenchTranslation->fresh()->name)->toBe('Chaussures')
        ->and($category->fresh()->translated('name', $french->id))->toBe('Chaussures')
        ->and($category->fresh()->translated('name'))->toBe('Running shoes');
});
