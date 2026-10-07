<?php

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0072 (D-2): blog_categories no longer carries `name` / `normalized_name`; the name lives in
// blog_category_translations (see BlogCategoryTranslationTest).

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

test('creating and re-fetching a blog category populates both timestamps and resolves its default-language name', function () {
    $category = BlogCategory::factory()->named('Guías')->create();

    $fresh = $category->fresh();

    expect($fresh->translated('name'))->toBe('Guías')
        ->and($fresh->created_at)->not->toBeNull()
        ->and($fresh->updated_at)->not->toBeNull();
});

test('the parent row has no mass-assignable column left', function () {
    expect((new BlogCategory)->getFillable())->toBe([]);
});

test('blog category does not use SoftDeletes', function () {
    expect(class_uses_recursive(BlogCategory::class))->not->toContain(SoftDeletes::class);
});

test('blog_categories carries only its key and timestamps, and no name or normalized_name column', function () {
    $columns = Schema::getColumnListing('blog_categories');
    sort($columns);

    expect($columns)->toBe(['created_at', 'id', 'updated_at']);

    expect(collect(Schema::getIndexes('blog_categories'))->pluck('name')->all())->toBe(['primary']);
});
