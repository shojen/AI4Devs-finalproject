<?php

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0058, Phase 3 (TDD "red" step): App\Models\BlogCategory, its factory and the
// blog_categories migration do not exist yet -- every test below is expected to fail until they do.

test('a factory-created blog category receives a uuidv7 string primary key', function () {
    $category = BlogCategory::factory()->create();

    expect($category->id)->toBeString()
        ->and(Str::isUuid($category->id, 7))->toBeTrue();
});

test('two blog categories created in immediate succession sort lexicographically in creation order', function () {
    $first = BlogCategory::factory()->create();
    $second = BlogCategory::factory()->create();

    expect(strcmp($first->id, $second->id))->toBeLessThan(0);
});

test('creating and re-fetching a blog category persists name and populates both timestamps', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    $fresh = $category->fresh();

    expect($fresh->name)->toBe('Guías')
        ->and($fresh->created_at)->not->toBeNull()
        ->and($fresh->updated_at)->not->toBeNull();
});

test('name is mass-assignable but normalized_name is not, so a forged value is replaced by the derived one', function () {
    $category = BlogCategory::create(['name' => 'Guías', 'normalized_name' => 'hijacked']);

    expect($category->fresh()->normalized_name)->toBe('guias');
});

test('the saving hook derives normalized_name on insert and re-derives it on a rename', function () {
    $category = BlogCategory::create(['name' => 'Guías']);

    expect($category->fresh()->normalized_name)->toBe('guias');

    $category->update(['name' => 'Guías de compra']);

    expect($category->fresh()->normalized_name)->toBe('guias de compra');
});

test('saving a blog category without touching name does not rewrite normalized_name', function () {
    $category = BlogCategory::create(['name' => 'Guías']);

    // A sentinel no fold could ever produce: if the hook re-derived it on this unrelated save,
    // the value would flip back to 'guias'.
    DB::table('blog_categories')->where('id', $category->id)->update(['normalized_name' => 'sentinel']);

    $fresh = $category->fresh();
    $fresh->updated_at = now()->addMinute();
    $fresh->save();

    expect($fresh->fresh()->normalized_name)->toBe('sentinel');
});

test('a directly assigned normalized_name is overwritten by the derived one on save', function () {
    $category = BlogCategory::create(['name' => 'Guías']);

    $category->normalized_name = 'hijacked';
    $category->save();

    expect($category->fresh()->normalized_name)->toBe('guias');
});

test('a name whose fold differs from it stores both columns correctly', function () {
    $category = BlogCategory::create(['name' => 'Guías  De   Compra']);

    $fresh = $category->fresh();

    expect($fresh->name)->toBe('Guías  De   Compra')
        ->and($fresh->normalized_name)->toBe('guias de compra');
});

test('blog category does not use SoftDeletes', function () {
    expect(class_uses_recursive(BlogCategory::class))->not->toContain(SoftDeletes::class);
});

test('blog_categories carries exactly the specified columns and no unique index on name', function () {
    $columns = Schema::getColumnListing('blog_categories');
    sort($columns);

    expect($columns)->toBe(['created_at', 'id', 'name', 'normalized_name', 'updated_at']);

    $indexes = collect(Schema::getIndexes('blog_categories'))->keyBy('name');

    expect($indexes->keys()->sort()->values()->all())->toBe(['blog_categories_normalized_name_unique', 'primary'])
        ->and($indexes['blog_categories_normalized_name_unique']['columns'])->toBe(['normalized_name'])
        ->and($indexes['blog_categories_normalized_name_unique']['unique'])->toBeTrue();
});
