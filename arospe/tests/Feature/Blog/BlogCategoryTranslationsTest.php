<?php

use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Actions\Blog\RenameBlogCategory;
use App\Actions\Blog\TranslateBlogCategoryNameUniqueViolation;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0072: per-store-language blog category names -- resolution and fallback wiring, uniqueness
// scoped per language, authorization of the rewritten actions, query shape and the registry.
//
// D-13 / R-8: every validation test runs actingAs() an actor holding the relevant blog.*
// permission, or it would throw AuthorizationException and pass for the wrong reason.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($this->actor);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
});

/**
 * Validate a name in a given language the way the actions do, returning the first-class result.
 */
function blogCategoryNameIsValid(string $name, string $storeLanguageId, ?string $blogCategoryId = null): bool
{
    $harness = new class
    {
        use BlogCategoryValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function rules(string $storeLanguageId, ?string $blogCategoryId): array
        {
            return $this->blogCategoryRules(app(NormalizeForSearch::class), $storeLanguageId, $blogCategoryId);
        }
    };

    return ! Validator::make(['name' => $name], $harness->rules($storeLanguageId, $blogCategoryId))->fails();
}

// --- Resolution and fallback: one wiring proof, not a re-spec of HasTranslations ---

test('a category resolves the requested language, falls back to the default, and returns null when neither exists', function () {
    $category = BlogCategory::factory()->named('Guías')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $category->id, 'name' => 'Guides']);

    $fresh = BlogCategory::query()->findOrFail($category->id);
    expect($fresh->translated('name', $this->french->id))->toBe('Guides');

    $german = StoreLanguage::factory()->create();
    expect($fresh->translated('name', $german->id))->toBe('Guías');

    $bare = BlogCategory::factory()->withoutTranslations()->create();
    expect($bare->translated('name', $this->french->id))->toBeNull();
});

test('a translation in a language since made inactive is still readable', function () {
    $category = BlogCategory::factory()->named('Guías')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $category->id, 'name' => 'Guides']);

    $this->french->forceFill(['is_active' => false])->save();

    expect(BlogCategory::query()->findOrFail($category->id)->translated('name', $this->french->id))->toBe('Guides');
});

// --- Create and rename write the default language ---

test('creating a category stores exactly one translation, in the default store language', function () {
    $category = app(CreateBlogCategory::class)('Guías');

    $rows = BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->store_language_id)->toBe($this->spanish->id)
        ->and($rows->first()->name)->toBe('Guías');
});

test('creating a category without a default store language refuses legibly and writes nothing', function () {
    DB::table('store_languages')->delete();
    StoreLanguage::flushDefaultStoreLanguage();

    expect(fn () => app(CreateBlogCategory::class)('Guías'))->toThrow(RuntimeException::class);
    expect(BlogCategory::count())->toBe(0);
});

test('renaming leaves another language\'s translation untouched', function () {
    $category = BlogCategory::factory()->named('Guías')->create();
    $french = BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $category->id, 'name' => 'Guides']);

    app(RenameBlogCategory::class)($category, 'Novedades');

    expect($french->fresh()->name)->toBe('Guides')
        ->and($french->fresh()->normalized_name)->toBe('guides')
        ->and($category->fresh()->translated('name'))->toBe('Novedades');
});

// --- Uniqueness, scoped per language ---

test('two categories cannot share a name within one language', function () {
    BlogCategory::factory()->named('Guías')->create();
    $other = BlogCategory::factory()->named('Otra')->create();

    expect(blogCategoryNameIsValid('Guías', $this->spanish->id, $other->id))->toBeFalse();
});

test('a byte-identical name in two different languages is permitted', function () {
    $first = BlogCategory::factory()->named('Guías')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $first->id, 'name' => 'Guías']);
    $other = BlogCategory::factory()->named('Otra')->create();

    expect(blogCategoryNameIsValid('Guías', $this->spanish->id, $other->id))->toBeFalse()
        ->and(blogCategoryNameIsValid('Guías', $this->french->id, $other->id))->toBeFalse();

    $third = BlogCategory::factory()->named('Tercera')->create();
    $german = StoreLanguage::factory()->create();

    expect(blogCategoryNameIsValid('Guías', $german->id, $third->id))->toBeTrue();
});

test('an accent-folded pair collides within one language but not across languages', function () {
    $first = BlogCategory::factory()->named('Guías')->create();
    $other = BlogCategory::factory()->named('Otra')->create();

    expect(blogCategoryNameIsValid('Guias', $this->spanish->id, $other->id))->toBeFalse()
        ->and(blogCategoryNameIsValid('GUÍAS', $this->spanish->id, $other->id))->toBeFalse()
        ->and(blogCategoryNameIsValid('Guias', $this->french->id, $other->id))->toBeTrue();

    // The composite index shape itself, not only the PHP rule: the same fold across two
    // languages inserts fine, the same fold within one language does not.
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $first->id, 'name' => 'Guias']);
    expect(BlogCategoryTranslation::query()->where('normalized_name', 'guias')->count())->toBe(2);
});

// D-4: the ignore targets the FK column, not the translation's primary key.
test('a category keeps its own name when re-saved in the same language', function () {
    $category = BlogCategory::factory()->named('Guías')->create();
    $before = DB::table('blog_category_translations')->where('blog_category_id', $category->id)->first();

    expect(blogCategoryNameIsValid('Guías', $this->spanish->id, $category->id))->toBeTrue();

    app(RenameBlogCategory::class)($category, 'Guías');

    expect(DB::table('blog_category_translations')->where('blog_category_id', $category->id)->first())->toEqual($before)
        ->and(blogCategoryNameIsValid('Libre', $this->spanish->id, $category->id))->toBeTrue();
});

test('blank and whitespace-only names are refused in every language', function (string $blank) {
    $category = BlogCategory::factory()->named('Guías')->create();

    expect(blogCategoryNameIsValid($blank, $this->spanish->id, $category->id))->toBeFalse()
        ->and(blogCategoryNameIsValid($blank, $this->french->id, $category->id))->toBeFalse();
})->with(['blank' => [''], 'whitespace' => ['   ']]);

// R-11: the unique-violation translator must not misreport any other 23000 as "name taken".
test('a foreign-key violation is not misattributed as a duplicate name', function () {
    $category = BlogCategory::factory()->withoutTranslations()->create();

    try {
        DB::table('blog_category_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_category_id' => $category->id,
            'store_language_id' => (string) Str::uuid7(),
            'name' => 'Guías',
            'normalized_name' => 'guias',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        expect(fn () => app(TranslateBlogCategoryNameUniqueViolation::class)($e))->toThrow(QueryException::class);

        return;
    }

    $this->fail('Expected the foreign key to refuse a nonexistent store_language_id.');
});

test('a genuine name collision on the unique index translates to a validation error', function () {
    BlogCategory::factory()->named('Guías')->create();
    $other = BlogCategory::factory()->withoutTranslations()->create();

    try {
        DB::table('blog_category_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_category_id' => $other->id,
            'store_language_id' => $this->spanish->id,
            'name' => 'Guias',
            'normalized_name' => 'guias',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        expect(app(TranslateBlogCategoryNameUniqueViolation::class)($e))->toBeInstanceOf(ValidationException::class);

        return;
    }

    $this->fail('Expected the unique index to refuse the duplicate.');
});

// --- Deletion ---

test('deleting a translated category leaves no translation rows and frees the name in that language', function () {
    $category = BlogCategory::factory()->named('Guías')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $category->id]);
    BlogCategoryTranslation::factory()->forLanguage(StoreLanguage::factory()->create())->create(['blog_category_id' => $category->id]);

    app(DeleteBlogCategory::class)($category);

    expect(DB::table('blog_category_translations')->where('blog_category_id', $category->id)->count())->toBe(0);

    app(CreateBlogCategory::class)('Guías');

    expect(BlogCategoryTranslation::query()->where('name', 'Guías')->count())->toBe(1);
});

// --- Query shape ---

test('rendering N categories through withTranslationsFor issues a bounded number of queries', function () {
    $queriesFor = function (int $count, bool $eager): int {
        BlogCategory::query()->delete();
        BlogCategory::factory()->count($count)->create();
        StoreLanguage::defaultStoreLanguage();

        DB::enableQueryLog();
        $query = BlogCategory::query();
        $eager && $query->withTranslationsFor();
        $query->get()->each(fn (BlogCategory $category) => $category->translated('name'));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    };

    expect($queriesFor(5, true))->toBe($queriesFor(1, true))
        ->and($queriesFor(5, false))->toBeGreaterThan($queriesFor(1, false));
});

test('translated() reads the already-loaded relation and issues no additional query', function () {
    BlogCategory::factory()->named('Guías')->create();
    $category = BlogCategory::query()->withTranslationsFor()->firstOrFail();
    StoreLanguage::defaultStoreLanguage();

    DB::enableQueryLog();
    $category->translated('name');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($queries)->toBe(0);
});

// --- The registry ---

test('translationUsageCount() includes blog category translations', function () {
    $category = BlogCategory::factory()->named('Guías')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create(['blog_category_id' => $category->id]);

    expect(StoreLanguage::translationUsageCount($this->french->id))->toBe(1)
        ->and(StoreLanguage::translationUsageCount($this->spanish->id))->toBe(1);
});

// --- Authorization ---

test('an actor without blog.edit cannot rename, and one without blog.create cannot create', function () {
    $category = BlogCategory::factory()->named('Guías')->create();

    $editorOnly = User::factory()->create();
    $editorOnly->givePermissionTo('blog.edit');
    $this->actingAs($editorOnly);

    expect(fn () => app(CreateBlogCategory::class)('Nueva'))->toThrow(AuthorizationException::class);

    $creatorOnly = User::factory()->create();
    $creatorOnly->givePermissionTo('blog.create');
    $this->actingAs($creatorOnly);

    expect(fn () => app(RenameBlogCategory::class)($category, 'Nueva'))->toThrow(AuthorizationException::class)
        ->and($category->fresh()->translated('name'))->toBe('Guías');
});

test('authoring a translation needs no store-languages permission', function () {
    $category = BlogCategory::factory()->named('Guías')->create();

    $editor = User::factory()->create();
    $editor->givePermissionTo('blog.edit');
    $this->actingAs($editor);

    expect($editor->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'store-languages.')))->toBeEmpty();

    app(RenameBlogCategory::class)($category, 'Novedades');
    app(SetTranslation::class)($category, $this->french, ['name' => 'Nouveautés']);

    expect($category->fresh()->translated('name', $this->french->id))->toBe('Nouveautés');
});
