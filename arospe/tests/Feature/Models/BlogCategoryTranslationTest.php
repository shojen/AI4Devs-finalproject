<?php

use App\Actions\Translations\SetTranslation;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0072 (D-1, D-3, D-10, D-13): the derivation hook that moved onto the child model, its
// composition with SetTranslation's write path, and the table's schema.

test('the saving hook derives normalized_name on insert, exercised through the model directly', function () {
    $translation = BlogCategoryTranslation::factory()->create(['name' => 'Guías']);

    expect($translation->fresh()->normalized_name)->toBe('guias');
});

test('the saving hook re-derives normalized_name on a rename', function () {
    $translation = BlogCategoryTranslation::factory()->create(['name' => 'Guías']);

    $translation->update(['name' => 'Guías de compra']);

    expect($translation->fresh()->name)->toBe('Guías de compra')
        ->and($translation->fresh()->normalized_name)->toBe('guias de compra');
});

test('saving without touching name does not rewrite normalized_name', function () {
    $translation = BlogCategoryTranslation::factory()->create(['name' => 'Guías']);

    // A sentinel no fold could ever produce: if the hook re-derived it on this unrelated save, it
    // would flip back to 'guias'.
    DB::table('blog_category_translations')->where('id', $translation->id)->update(['normalized_name' => 'sentinel']);

    $fresh = $translation->fresh();
    $fresh->updated_at = now()->addMinute();
    $fresh->save();

    expect($fresh->fresh()->normalized_name)->toBe('sentinel');
});

test('a forged normalized_name is replaced by the derived one, on mass assignment and on direct assignment', function () {
    $category = BlogCategory::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();

    $forged = new BlogCategoryTranslation(['name' => 'Guías', 'normalized_name' => 'hijacked']);
    $forged->forceFill(['blog_category_id' => $category->id, 'store_language_id' => $language->id]);
    $forged->save();

    expect($forged->fresh()->normalized_name)->toBe('guias');

    $forged->normalized_name = 'hijacked';
    $forged->save();

    expect($forged->fresh()->normalized_name)->toBe('guias');
});

test('SetTranslation twice on one pair keeps one row and refreshes name and normalized_name together', function () {
    $category = BlogCategory::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();

    app(SetTranslation::class)($category, $language, ['name' => 'Guías']);
    app(SetTranslation::class)($category, $language, ['name' => 'Novedades']);

    $rows = BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Novedades')
        ->and($rows->first()->normalized_name)->toBe('novedades');
});

test('SetTranslation writes name and normalized_name in a single statement', function () {
    $category = BlogCategory::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();

    DB::enableQueryLog();
    app(SetTranslation::class)($category, $language, ['name' => 'Guías']);
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(
        fn (string $sql): bool => preg_match('/^(insert|update) /i', $sql) === 1,
    );
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($writes)->toHaveCount(1);

    DB::enableQueryLog();
    app(SetTranslation::class)($category, $language, ['name' => 'Novedades']);
    $updates = collect(DB::getQueryLog())->pluck('query')->filter(
        fn (string $sql): bool => preg_match('/^(insert|update) /i', $sql) === 1,
    );
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($updates)->toHaveCount(1);
});

test('the translation model does not use SoftDeletes', function () {
    expect(class_uses_recursive(BlogCategoryTranslation::class))->not->toContain(SoftDeletes::class);
});

test('a factory-created translation receives a uuidv7 primary key', function () {
    expect(Str::isUuid(BlogCategoryTranslation::factory()->create()->id, 7))->toBeTrue();
});

test('blog_category_translations carries exactly the specified columns', function () {
    $columns = Schema::getColumnListing('blog_category_translations');
    sort($columns);

    expect($columns)->toBe(['blog_category_id', 'created_at', 'id', 'name', 'normalized_name', 'store_language_id', 'updated_at']);
});

// D-10: three indexes, not four -- each composite unique serves as the FK index for its leftmost
// column. normalized_name carries the per-language unique; there is deliberately no unique on name.
test('blog_category_translations has exactly the primary key and the two composite unique indexes', function () {
    $indexes = collect(Schema::getIndexes('blog_category_translations'))->keyBy('name');

    expect($indexes->keys()->sort()->values()->all())->toBe([
        'blog_category_translations_category_language_unique',
        'blog_category_translations_language_normalized_name_unique',
        'primary',
    ])
        ->and($indexes['blog_category_translations_category_language_unique']['columns'])->toBe(['blog_category_id', 'store_language_id'])
        ->and($indexes['blog_category_translations_category_language_unique']['unique'])->toBeTrue()
        ->and($indexes['blog_category_translations_language_normalized_name_unique']['columns'])->toBe(['store_language_id', 'normalized_name'])
        ->and($indexes['blog_category_translations_language_normalized_name_unique']['unique'])->toBeTrue();
});

test('the unique index folds accents within one language even when the validation rule is bypassed', function () {
    $language = StoreLanguage::factory()->default()->create();
    $first = BlogCategory::factory()->withoutTranslations()->create();
    $second = BlogCategory::factory()->withoutTranslations()->create();
    BlogCategoryTranslation::factory()->forLanguage($language)->create(['blog_category_id' => $first->id, 'name' => 'Guías']);

    expect(fn () => BlogCategoryTranslation::factory()->forLanguage($language)->create(['blog_category_id' => $second->id, 'name' => 'Guias']))
        ->toThrow(QueryException::class);
});

// D-13: the first assertion anywhere that the entity-side FK really cascades.
test('deleting a category removes every one of its translations', function () {
    $category = BlogCategory::factory()->withoutTranslations()->create();

    foreach (StoreLanguage::factory()->count(3)->create() as $language) {
        BlogCategoryTranslation::factory()->forLanguage($language)->create(['blog_category_id' => $category->id]);
    }

    expect(DB::table('blog_category_translations')->where('blog_category_id', $category->id)->count())->toBe(3);

    $category->delete();

    expect(DB::table('blog_category_translations')->where('blog_category_id', $category->id)->count())->toBe(0);
});

test('the store language foreign key restricts deleting a language that still holds a translation', function () {
    $translation = BlogCategoryTranslation::factory()->create();

    expect(fn () => DB::table('store_languages')->where('id', $translation->store_language_id)->delete())
        ->toThrow(QueryException::class);
});
