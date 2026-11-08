<?php

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0078 (D-2, D-3, D-5, D-6, D-7): the blog_post_translations table, the pair of migrations that
// create it and drop blog_posts.title/slug/body, and the drop migration's guard. RefreshDatabase has
// already run both migrations before every test body.

/**
 * @return array<string, array<string, mixed>> the table's columns keyed by name
 */
function bp78Columns(string $table): array
{
    $columns = [];

    foreach (Schema::getColumns($table) as $column) {
        $columns[$column['name']] = $column;
    }

    return $columns;
}

function bp78MigrationPath(string $suffix): string
{
    $matches = glob(database_path('migrations/*_'.$suffix.'.php'));

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

test('blog_post_translations has the declared columns, types and nullability and no deleted_at, normalized_slug or meta column', function () {
    $columns = bp78Columns('blog_post_translations');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'blog_post_id', 'store_language_id', 'title', 'slug', 'body', 'created_at', 'updated_at',
    ])
        ->and($columns['id']['type'])->toBe('char(36)')
        ->and($columns['blog_post_id']['type'])->toBe('char(36)')
        ->and($columns['blog_post_id']['nullable'])->toBeFalse()
        ->and($columns['store_language_id']['type'])->toBe('char(36)')
        ->and($columns['store_language_id']['nullable'])->toBeFalse()
        ->and($columns['title']['type'])->toBe('varchar(255)')
        ->and($columns['title']['nullable'])->toBeFalse()
        ->and($columns['slug']['type'])->toBe('varchar(255)')
        ->and($columns['slug']['nullable'])->toBeFalse()
        ->and($columns['body']['type'])->toBe('mediumtext')
        ->and($columns['body']['nullable'])->toBeTrue();

    expect(Schema::hasColumn('blog_post_translations', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('blog_post_translations', 'normalized_slug'))->toBeFalse()
        ->and(Schema::hasColumn('blog_post_translations', 'meta_title'))->toBeFalse()
        ->and(Schema::hasColumn('blog_post_translations', 'meta_description'))->toBeFalse();
});

test('blog_posts no longer carries title, slug or body but keeps its parent columns', function () {
    expect(Schema::hasColumn('blog_posts', 'title'))->toBeFalse()
        ->and(Schema::hasColumn('blog_posts', 'slug'))->toBeFalse()
        ->and(Schema::hasColumn('blog_posts', 'body'))->toBeFalse()
        ->and(Schema::hasColumn('blog_posts', 'blog_category_id'))->toBeTrue()
        ->and(Schema::hasColumn('blog_posts', 'status'))->toBeTrue()
        ->and(Schema::hasColumn('blog_posts', 'published_at'))->toBeTrue()
        ->and(Schema::hasColumn('blog_posts', 'created_by'))->toBeTrue()
        ->and(Schema::hasColumn('blog_posts', 'deleted_at'))->toBeTrue();
});

test('blog_post_translations carries exactly three indexes, with the two unique ones named and column-listed', function () {
    $indexes = collect(Schema::getIndexes('blog_post_translations'))->keyBy('name');

    expect($indexes)->toHaveCount(3);

    $primary = $indexes->first(fn (array $index): bool => $index['primary']);
    $pair = $indexes->get('blog_post_translations_post_language_unique');
    $slug = $indexes->get('blog_post_translations_language_slug_unique');

    expect($primary['columns'])->toBe(['id'])
        ->and($pair)->not->toBeNull()
        ->and($pair['unique'])->toBeTrue()
        ->and($pair['columns'])->toBe(['blog_post_id', 'store_language_id'])
        ->and($slug)->not->toBeNull()
        ->and($slug['unique'])->toBeTrue()
        ->and($slug['columns'])->toBe(['store_language_id', 'slug']);
});

test('the language-slug unique index name is exposed as a constant on the model', function () {
    expect(BlogPostTranslation::LANGUAGE_SLUG_UNIQUE_INDEX)->toBe('blog_post_translations_language_slug_unique');
});

test('the foreign keys cascade from blog_posts and restrict from store_languages', function () {
    $foreignKeys = collect(Schema::getForeignKeys('blog_post_translations'))->keyBy(fn (array $fk): string => $fk['columns'][0]);

    expect($foreignKeys->keys()->all())->toEqualCanonicalizing(['blog_post_id', 'store_language_id'])
        ->and($foreignKeys['blog_post_id']['foreign_table'])->toBe('blog_posts')
        ->and(strtolower($foreignKeys['blog_post_id']['on_delete']))->toBe('cascade')
        ->and($foreignKeys['store_language_id']['foreign_table'])->toBe('store_languages')
        ->and(strtolower($foreignKeys['store_language_id']['on_delete']))->toBe('restrict');
});

test('blog_posts keeps its deleted_at, status, published_at index and no longer has a slug unique index', function () {
    $indexes = collect(Schema::getIndexes('blog_posts'));

    expect($indexes->contains(fn (array $index): bool => $index['columns'] === ['deleted_at', 'status', 'published_at']))->toBeTrue()
        ->and($indexes->pluck('name')->all())->not->toContain('blog_posts_slug_unique');
});

test('a second row for the same post and language is refused by the pair unique, naming the index', function () {
    $language = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    BlogPostTranslation::factory()->forLanguage($language)->create(['blog_post_id' => $post->id, 'title' => 'Uno']);

    $caught = null;

    try {
        BlogPostTranslation::factory()->forLanguage($language)->create(['blog_post_id' => $post->id, 'title' => 'Dos']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1062)
        ->and($caught->getMessage())->toContain('blog_post_translations_post_language_unique');
});

test('two posts cannot hold the same slug in one language, the refusal naming the language-slug index', function () {
    $language = StoreLanguage::factory()->default()->create();
    $first = BlogPost::factory()->withoutTranslations()->create();
    $second = BlogPost::factory()->withoutTranslations()->create();

    BlogPostTranslation::factory()->forLanguage($language)->create(['blog_post_id' => $first->id, 'title' => 'Resumen semanal']);

    $caught = null;

    try {
        BlogPostTranslation::factory()->forLanguage($language)->create(['blog_post_id' => $second->id, 'title' => 'Resumen semanal']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1062)
        ->and($caught->getMessage())->toContain(BlogPostTranslation::LANGUAGE_SLUG_UNIQUE_INDEX);
});

test('the same slug in two different languages is accepted', function () {
    $default = StoreLanguage::factory()->default()->create();
    $fr = StoreLanguage::factory()->create();
    $first = BlogPost::factory()->withoutTranslations()->create();
    $second = BlogPost::factory()->withoutTranslations()->create();

    BlogPostTranslation::factory()->forLanguage($default)->create(['blog_post_id' => $first->id, 'title' => 'Resumen semanal']);
    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $second->id, 'title' => 'Resumen semanal']);

    expect(DB::table('blog_post_translations')->where('slug', 'resumen-semanal')->count())->toBe(2);
});

test('soft-deleting a post keeps every translation row, because the foreign key cascade never fires on an UPDATE', function () {
    $default = StoreLanguage::factory()->default()->create();
    $fr = StoreLanguage::factory()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    BlogPostTranslation::factory()->forLanguage($default)->create(['blog_post_id' => $post->id]);
    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $post->id]);

    $post->delete();

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(2);
});

test('force-deleting a post cascades to its translations and leaves other posts alone', function () {
    $default = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();
    $survivor = BlogPost::factory()->withoutTranslations()->create();

    BlogPostTranslation::factory()->forLanguage($default)->create(['blog_post_id' => $post->id]);
    BlogPostTranslation::factory()->forLanguage($default)->create(['blog_post_id' => $survivor->id]);

    $post->forceDelete();

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(0)
        ->and(DB::table('blog_post_translations')->where('blog_post_id', $survivor->id)->count())->toBe(1);
});

test('the store_languages foreign key restricts: a language that still holds post translations cannot be hard-deleted', function () {
    $language = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    BlogPostTranslation::factory()->forLanguage($language)->create(['blog_post_id' => $post->id]);

    $caught = null;

    try {
        DB::table('store_languages')->where('id', $language->id)->delete();
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1451);
});

test('a translation row accepts a null body but refuses a null title', function () {
    $language = StoreLanguage::factory()->default()->create();
    $other = StoreLanguage::factory()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    DB::table('blog_post_translations')->insert([
        'id' => (string) Str::uuid7(),
        'blog_post_id' => $post->id,
        'store_language_id' => $language->id,
        'title' => 'Con titulo',
        'slug' => 'con-titulo',
        'body' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caught = null;

    try {
        DB::table('blog_post_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_post_id' => $post->id,
            'store_language_id' => $other->id,
            'title' => null,
            'slug' => 'otro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1048);
});

test('the create migration sorts after blog_posts and store_languages, and the drop migration after the create one', function () {
    $create = basename(bp78MigrationPath('create_blog_post_translations_table'));
    $drop = basename(bp78MigrationPath('drop_translatable_columns_from_blog_posts_table'));
    $posts = basename(bp78MigrationPath('create_blog_posts_table'));
    $storeLanguages = basename(bp78MigrationPath('create_store_languages_table'));

    expect(strcmp($create, $posts))->toBeGreaterThan(0)
        ->and(strcmp($create, $storeLanguages))->toBeGreaterThan(0)
        ->and(strcmp($drop, $create))->toBeGreaterThan(0);
});

test('the drop migration refuses to run while a post has no default-language translation, before any DDL', function () {
    StoreLanguage::factory()->default()->create();
    BlogPost::factory()->withoutTranslations()->create();

    $migration = require bp78MigrationPath('drop_translatable_columns_from_blog_posts_table');

    // The columns were already dropped by RefreshDatabase: a guard that threw only AFTER dropColumn()
    // would surface as a QueryException here instead of the RuntimeException.
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
});

test('the drop migration guard sees a trashed post that has no default-language translation', function () {
    StoreLanguage::factory()->default()->create();
    BlogPost::factory()->withoutTranslations()->create()->delete();

    $migration = require bp78MigrationPath('drop_translatable_columns_from_blog_posts_table');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
});

test('the create migration refuses before any DDL when posts exist and no default store language does', function () {
    BlogPost::factory()->withoutTranslations()->create([
        'blog_category_id' => BlogCategory::factory()->withoutTranslations()->create()->id,
    ]);

    expect(StoreLanguage::query()->count())->toBe(0);

    $migration = require bp78MigrationPath('create_blog_post_translations_table');

    // The table already exists, so a Schema::create() reached before the guard would raise a
    // QueryException ("table already exists") rather than this RuntimeException.
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
});
