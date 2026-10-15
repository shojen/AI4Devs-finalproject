<?php

use App\Actions\Blog\TranslateBlogTagNameUniqueViolation;
use App\Actions\Translations\SetTranslation;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0074 (D-1, D-4, D-6, D-10): the derivation hook on the child model, its composition with
// SetTranslation's write path, the table's schema, and the translated() fallback BlogTag inherits.

test('the saving hook derives normalized_name on insert, exercised through the model directly', function () {
    $translation = BlogTagTranslation::factory()->create(['name' => 'Niño']);

    expect($translation->fresh()->normalized_name)->toBe('nino');
});

test('the saving hook re-derives normalized_name on a rename', function () {
    $translation = BlogTagTranslation::factory()->create(['name' => 'Niño']);

    $translation->update(['name' => 'Trail  Running']);

    expect($translation->fresh()->name)->toBe('Trail  Running')
        ->and($translation->fresh()->normalized_name)->toBe('trail running');
});

test('saving without touching name does not rewrite normalized_name', function () {
    $translation = BlogTagTranslation::factory()->create(['name' => 'running']);

    // A sentinel no fold could ever produce: if the hook re-derived it on this unrelated save, the
    // value would flip back to 'running'.
    DB::table('blog_tag_translations')->where('id', $translation->id)->update(['normalized_name' => 'sentinel']);

    $fresh = $translation->fresh();
    $fresh->updated_at = now()->addMinute();
    $fresh->save();

    expect($fresh->fresh()->normalized_name)->toBe('sentinel');
});

test('a forged normalized_name is replaced by the derived one, on mass assignment and on direct assignment', function () {
    $tag = BlogTag::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();

    $forged = new BlogTagTranslation(['name' => 'running', 'normalized_name' => 'hijacked']);
    $forged->forceFill(['blog_tag_id' => $tag->id, 'store_language_id' => $language->id]);
    $forged->save();

    expect($forged->fresh()->normalized_name)->toBe('running');

    $forged->normalized_name = 'hijacked';
    $forged->save();

    expect($forged->fresh()->normalized_name)->toBe('running');
});

test('blog_tag_id and store_language_id are not mass-assignable', function () {
    $tag = BlogTag::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();
    $victimTag = BlogTag::factory()->withoutTranslations()->create();
    $otherLanguage = StoreLanguage::factory()->create();

    $translation = new BlogTagTranslation([
        'name' => 'running',
        'blog_tag_id' => $victimTag->id,
        'store_language_id' => $otherLanguage->id,
    ]);

    expect($translation->blog_tag_id)->toBeNull()
        ->and($translation->store_language_id)->toBeNull()
        ->and($translation->name)->toBe('running');

    $translation->forceFill(['blog_tag_id' => $tag->id, 'store_language_id' => $language->id])->save();

    expect($translation->fresh()->blog_tag_id)->toBe($tag->id)
        ->and($translation->fresh()->store_language_id)->toBe($language->id);
});

test('SetTranslation twice on one pair keeps one row and refreshes name and normalized_name together', function () {
    $tag = BlogTag::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();

    app(SetTranslation::class)($tag, $language, ['name' => 'running']);
    app(SetTranslation::class)($tag, $language, ['name' => 'Invierno']);

    $rows = BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Invierno')
        ->and($rows->first()->normalized_name)->toBe('invierno');
});

test('a factory-created translation receives a uuidv7 primary key', function () {
    expect(Str::isUuid(BlogTagTranslation::factory()->create()->id, 7))->toBeTrue();
});

test('the blogTag and storeLanguage relations resolve to the owning rows', function () {
    $language = StoreLanguage::factory()->default()->create();
    $tag = BlogTag::factory()->withoutTranslations()->create();
    $translation = BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $tag->id]);

    expect($translation->blogTag())->toBeInstanceOf(BelongsTo::class)
        ->and($translation->storeLanguage())->toBeInstanceOf(BelongsTo::class)
        ->and($translation->blogTag->is($tag))->toBeTrue()
        ->and($translation->storeLanguage->is($language))->toBeTrue()
        ->and($tag->translations->pluck('id')->all())->toBe([$translation->id]);
});

test('the translation model does not use SoftDeletes', function () {
    expect(class_uses_recursive(BlogTagTranslation::class))->not->toContain(SoftDeletes::class);
});

test('blog_tag_translations carries exactly the specified columns', function () {
    $columns = Schema::getColumnListing('blog_tag_translations');
    sort($columns);

    expect($columns)->toBe(['blog_tag_id', 'created_at', 'id', 'name', 'normalized_name', 'store_language_id', 'updated_at']);
});

// D-10: three indexes, not four -- each composite unique serves as the FK index for its leftmost
// column. normalized_name carries the per-language unique; there is deliberately no unique on name.
test('blog_tag_translations has exactly the primary key and the two composite unique indexes', function () {
    $indexes = collect(Schema::getIndexes('blog_tag_translations'))->keyBy('name');

    expect($indexes->keys()->sort()->values()->all())->toBe([
        'blog_tag_translations_language_normalized_name_unique',
        'blog_tag_translations_tag_language_unique',
        'primary',
    ])
        ->and($indexes['blog_tag_translations_tag_language_unique']['columns'])->toBe(['blog_tag_id', 'store_language_id'])
        ->and($indexes['blog_tag_translations_tag_language_unique']['unique'])->toBeTrue()
        ->and($indexes['blog_tag_translations_language_normalized_name_unique']['columns'])->toBe(['store_language_id', 'normalized_name'])
        ->and($indexes['blog_tag_translations_language_normalized_name_unique']['unique'])->toBeTrue();
});

test('the unique index folds accents within one language even when the validation rule is bypassed', function () {
    $language = StoreLanguage::factory()->default()->create();
    $first = BlogTag::factory()->withoutTranslations()->create();
    $second = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $first->id, 'name' => 'Niño']);

    expect(fn () => BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $second->id, 'name' => 'Nino']))
        ->toThrow(QueryException::class);
});

// D-6: per language, not global -- the whole point of moving the unique index off blog_tags.
test('the identical string is accepted in two different languages', function () {
    $english = StoreLanguage::factory()->default()->create();
    $spanish = StoreLanguage::factory()->create();
    $first = BlogTag::factory()->withoutTranslations()->create();
    $second = BlogTag::factory()->withoutTranslations()->create();

    BlogTagTranslation::factory()->forLanguage($english)->create(['blog_tag_id' => $first->id, 'name' => 'running']);
    BlogTagTranslation::factory()->forLanguage($spanish)->create(['blog_tag_id' => $second->id, 'name' => 'running']);

    expect(BlogTagTranslation::where('normalized_name', 'running')->count())->toBe(2);
});

test('one tag cannot hold two translations in the same language', function () {
    $language = StoreLanguage::factory()->default()->create();
    $tag = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $tag->id, 'name' => 'running']);

    expect(fn () => BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $tag->id, 'name' => 'invierno']))
        ->toThrow(QueryException::class);
});

test('deleting a tag removes every one of its translations', function () {
    $tag = BlogTag::factory()->withoutTranslations()->create();

    foreach (StoreLanguage::factory()->count(3)->create() as $language) {
        BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $tag->id]);
    }

    expect(DB::table('blog_tag_translations')->where('blog_tag_id', $tag->id)->count())->toBe(3);

    $tag->delete();

    expect(DB::table('blog_tag_translations')->where('blog_tag_id', $tag->id)->count())->toBe(0);
});

test('the store language foreign key restricts deleting a language that still holds a translation', function () {
    $translation = BlogTagTranslation::factory()->create();

    expect(fn () => DB::table('store_languages')->where('id', $translation->store_language_id)->delete())
        ->toThrow(QueryException::class);
});

test('a real foreign key violation on a translation insert is not reported as a taken name', function () {
    $language = StoreLanguage::factory()->default()->create();
    $caught = null;

    try {
        DB::table('blog_tag_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_tag_id' => (string) Str::uuid7(),
            'store_language_id' => $language->id,
            'name' => 'running',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and(fn () => app(TranslateBlogTagNameUniqueViolation::class)($caught))->toThrow(QueryException::class);
});

test('a real duplicate normalized_name in one language is translated to a validation error on name', function () {
    $language = StoreLanguage::factory()->default()->create();
    BlogTag::factory()->named('running')->create();
    $second = BlogTag::factory()->withoutTranslations()->create();
    $caught = null;

    try {
        DB::table('blog_tag_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_tag_id' => $second->id,
            'store_language_id' => $language->id,
            'name' => 'RUNNING',
            'normalized_name' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and(app(TranslateBlogTagNameUniqueViolation::class)($caught)->errors())->toHaveKey('name');
});

// HasTranslations fallback as BlogTag consumes it: requested language, then the default, then null.
test('translated() returns the requested language, falls back to the default, then to null', function () {
    $default = StoreLanguage::factory()->default()->create();
    $spanish = StoreLanguage::factory()->create();
    $french = StoreLanguage::factory()->create();
    $tag = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($default)->create(['blog_tag_id' => $tag->id, 'name' => 'running']);
    BlogTagTranslation::factory()->forLanguage($spanish)->create(['blog_tag_id' => $tag->id, 'name' => 'correr']);

    $fresh = $tag->fresh();

    expect($fresh->translated('name', $spanish->id))->toBe('correr')
        ->and($fresh->translated('name', $french->id))->toBe('running')
        ->and($fresh->translated('name'))->toBe('running');

    $bare = BlogTag::factory()->withoutTranslations()->create();

    expect($bare->fresh()->translated('name', $spanish->id))->toBeNull();
});

test('translated() still resolves a name in an inactive language', function () {
    $default = StoreLanguage::factory()->default()->create();
    $inactive = StoreLanguage::factory()->create(['is_active' => false]);
    $tag = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($default)->create(['blog_tag_id' => $tag->id, 'name' => 'running']);
    BlogTagTranslation::factory()->forLanguage($inactive)->create(['blog_tag_id' => $tag->id, 'name' => 'correr']);

    expect($tag->fresh()->translated('name', $inactive->id))->toBe('correr');
});

test('translated() follows the default language when the default changes', function () {
    $first = StoreLanguage::factory()->default()->create();
    $second = StoreLanguage::factory()->create();
    $third = StoreLanguage::factory()->create();
    $tag = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($first)->create(['blog_tag_id' => $tag->id, 'name' => 'running']);
    BlogTagTranslation::factory()->forLanguage($second)->create(['blog_tag_id' => $tag->id, 'name' => 'correr']);

    expect($tag->fresh()->translated('name', $third->id))->toBe('running');

    DB::table('store_languages')->update(['is_default' => false]);
    DB::table('store_languages')->where('id', $second->id)->update(['is_default' => true]);
    StoreLanguage::flushDefaultStoreLanguage();

    expect($tag->fresh()->translated('name', $third->id))->toBe('correr')
        ->and($tag->fresh()->translated('name'))->toBe('correr');
});

test('withTranslationsFor() eager loads only the requested and default language rows', function () {
    $default = StoreLanguage::factory()->default()->create();
    $spanish = StoreLanguage::factory()->create();
    $french = StoreLanguage::factory()->create();
    $tag = BlogTag::factory()->withoutTranslations()->create();

    foreach ([$default, $spanish, $french] as $language) {
        BlogTagTranslation::factory()->forLanguage($language)->create(['blog_tag_id' => $tag->id]);
    }

    $loaded = BlogTag::query()->withTranslationsFor($spanish->id)->sole();

    expect($loaded->translations->pluck('store_language_id')->sort()->values()->all())
        ->toBe(collect([$default->id, $spanish->id])->sort()->values()->all());
});

// D-9: a language's usage count must see blog tag names, or removing the language would orphan them
// (or hit the RESTRICT FK as a 500 instead of a refusal).
test('translationUsageCount() includes blog tag translations', function () {
    $language = StoreLanguage::factory()->default()->create();

    expect(StoreLanguage::translationUsageCount($language->id))->toBe(0);

    BlogTagTranslation::factory()->forLanguage($language)->create();
    BlogTagTranslation::factory()->forLanguage($language)->create();

    expect(StoreLanguage::translationUsageCount($language->id))->toBe(2);
});
