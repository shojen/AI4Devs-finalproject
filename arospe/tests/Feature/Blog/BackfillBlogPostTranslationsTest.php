<?php

use App\Actions\Blog\BackfillBlogPostTranslations;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0078 (D-6): the backfill carries each existing post's title, slug and body, TRASHED POSTS
// INCLUDED, into the default store language. Both migrations have already run before every test body,
// so blog_posts no longer carries the three legacy columns: the real runs re-add them for one test
// and put the schema back afterwards. MySQL DDL implicitly commits, which defeats RefreshDatabase's
// rollback, so every real run cleans every table it touched in a finally block.

test('translationRowsFor() writes one row per post in the given language, copying title, slug and body byte-for-byte', function () {
    $now = Carbon::parse('2026-11-08 10:00:00');
    $languageId = (string) Str::uuid7();
    $posts = [
        (object) ['id' => (string) Str::uuid7(), 'title' => '  Botas  de invierno ', 'slug' => 'legacy-slug', 'body' => '<script>old()</script><p>kept</p>'],
        (object) ['id' => (string) Str::uuid7(), 'title' => 'Sin cuerpo', 'slug' => 'sin-cuerpo', 'body' => null],
    ];

    $rows = app(BackfillBlogPostTranslations::class)->translationRowsFor($posts, $languageId, $now);

    expect($rows)->toHaveCount(2);

    foreach ($rows as $index => $row) {
        expect($row['blog_post_id'])->toBe($posts[$index]->id)
            ->and($row['store_language_id'])->toBe($languageId)
            ->and($row['title'])->toBe($posts[$index]->title)
            ->and($row['slug'])->toBe($posts[$index]->slug)
            ->and($row['body'])->toBe($posts[$index]->body)
            ->and($row['created_at'])->toBe($now)
            ->and($row['updated_at'])->toBe($now)
            ->and(Str::isUuid($row['id'], 7))->toBeTrue();
    }

    expect(array_unique(array_column($rows, 'id')))->toHaveCount(2);
});

test('translationRowsFor() over zero posts returns an empty list', function () {
    expect(app(BackfillBlogPostTranslations::class)->translationRowsFor([], (string) Str::uuid7(), Carbon::now()))->toBe([]);
});

test('with posts present and no default store language, the backfill refuses loudly and writes nothing', function () {
    BlogPost::factory()->withoutTranslations()->create([
        'blog_category_id' => BlogCategory::factory()->withoutTranslations()->create()->id,
    ]);

    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => app(BackfillBlogPostTranslations::class)->assertCanBackfill())->toThrow(RuntimeException::class);
    expect(fn () => app(BackfillBlogPostTranslations::class)())->toThrow(RuntimeException::class);

    expect(DB::table('blog_post_translations')->count())->toBe(0);
});

// The fresh-install case: an empty blog_posts table must never block a migration on a missing default.
test('with zero posts, assertCanBackfill() does not throw even with no default store language', function () {
    expect(StoreLanguage::query()->count())->toBe(0)
        ->and(DB::table('blog_posts')->count())->toBe(0);

    app(BackfillBlogPostTranslations::class)->assertCanBackfill();

    expect(app(BackfillBlogPostTranslations::class)())->toBe(0);
});

test('with posts present and a default store language, assertCanBackfill() does not throw', function () {
    StoreLanguage::factory()->default()->create();
    BlogPost::factory()->withoutTranslations()->create();

    expect(fn () => app(BackfillBlogPostTranslations::class)->assertCanBackfill())->not->toThrow(RuntimeException::class);
});

test('a trashed post alone is enough to require a default store language', function () {
    BlogPost::factory()->withoutTranslations()->create([
        'blog_category_id' => BlogCategory::factory()->withoutTranslations()->create()->id,
        'deleted_at' => now(),
    ]);

    // DB::table() applies no SoftDeletingScope, so a trashed post alone is enough to need a default.
    expect(fn () => app(BackfillBlogPostTranslations::class)->assertCanBackfill())->toThrow(RuntimeException::class);
});

/**
 * Re-adds the legacy columns if the schema no longer has them, runs $callback, then removes every row
 * and the columns it added.
 */
function bp78WithLegacyPostColumns(Closure $callback): void
{
    $added = [];

    try {
        Schema::table('blog_posts', function ($table) use (&$added): void {
            if (! Schema::hasColumn('blog_posts', 'title')) {
                $table->string('title', 255)->nullable();
                $added[] = 'title';
            }

            if (! Schema::hasColumn('blog_posts', 'slug')) {
                $table->string('slug', 255)->nullable();
                $added[] = 'slug';
            }

            if (! Schema::hasColumn('blog_posts', 'body')) {
                $table->mediumText('body')->nullable();
                $added[] = 'body';
            }
        });

        $callback();
    } finally {
        if (Schema::hasTable('blog_post_translations')) {
            DB::table('blog_post_translations')->delete();
        }

        DB::table('blog_posts')->delete();
        DB::table('blog_category_translations')->delete();
        DB::table('blog_categories')->delete();
        DB::table('store_languages')->delete();

        $toDrop = array_values(array_filter($added, fn (string $column): bool => Schema::hasColumn('blog_posts', $column)));

        if ($toDrop !== []) {
            Schema::table('blog_posts', function ($table) use ($toDrop): void {
                $table->dropColumn($toDrop);
            });
        }
    }
}

/**
 * @param  list<array{title: string, body: ?string, trashed?: bool}>  $legacy
 * @return list<string> the inserted post ids, in insertion order
 */
function bp78InsertLegacyPosts(string $categoryId, array $legacy): array
{
    $ids = [];
    $rows = [];

    foreach ($legacy as $row) {
        $ids[] = $id = (string) Str::uuid7();
        $rows[] = [
            'id' => $id,
            'blog_category_id' => $categoryId,
            'title' => $row['title'],
            'slug' => Str::slug($row['title']),
            'body' => $row['body'],
            'status' => 'draft',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => ($row['trashed'] ?? false) ? now() : null,
        ];

        if (count($rows) === 500) {
            DB::table('blog_posts')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('blog_posts')->insert($rows);
    }

    return $ids;
}

/**
 * @return list<array{title: string, body: ?string}>
 */
function bp78LegacyFixtures(int $count): array
{
    $fixtures = [];

    for ($i = 0; $i < $count; $i++) {
        $fixtures[] = ['title' => 'Post '.$i, 'body' => $i % 2 === 0 ? '<p>Body '.$i.'</p>' : null];
    }

    return $fixtures;
}

test('a real run gives every post, a trashed one and a null body included, a default-language translation copied byte-for-byte', function () {
    $category = BlogCategory::factory()->create();
    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $other = StoreLanguage::factory()->create();

    bp78WithLegacyPostColumns(function () use ($category, $default, $other): void {
        $legacy = [
            ['title' => 'Botas de invierno', 'body' => '<p>Ligeras</p>'],
            ['title' => 'Sin cuerpo', 'body' => null],
            ['title' => 'Borrado hace tiempo', 'body' => '<script>old()</script><p>kept</p>', 'trashed' => true],
        ];
        $ids = bp78InsertLegacyPosts($category->id, $legacy);

        expect(app(BackfillBlogPostTranslations::class)())->toBe(3);

        foreach ($legacy as $index => $row) {
            $translations = DB::table('blog_post_translations')->where('blog_post_id', $ids[$index])->get();

            expect($translations)->toHaveCount(1)
                ->and($translations[0]->store_language_id)->toBe($default->id)
                ->and($translations[0]->title)->toBe($row['title'])
                ->and($translations[0]->slug)->toBe(Str::slug($row['title']))
                ->and($translations[0]->body)->toBe($row['body']);
        }

        expect(DB::table('blog_post_translations')->where('store_language_id', $other->id)->count())->toBe(0);
    });
});

// The silent-loss guard (D-6): translated() never throws, so a trashed post left without a row
// would simply read as nameless after a restore.
test('a trashed post is backfilled and stays trashed', function () {
    $category = BlogCategory::factory()->create();

    bp78WithLegacyPostColumns(function () use ($category): void {
        [$liveId, $trashedId] = bp78InsertLegacyPosts($category->id, [
            ['title' => 'Viva', 'body' => '<p>a</p>'],
            ['title' => 'Borrada', 'body' => '<p>b</p>', 'trashed' => true],
        ]);

        app(BackfillBlogPostTranslations::class)();

        expect(DB::table('blog_post_translations')->where('blog_post_id', $trashedId)->value('title'))->toBe('Borrada')
            ->and(DB::table('blog_post_translations')->where('blog_post_id', $liveId)->value('title'))->toBe('Viva')
            ->and(DB::table('blog_posts')->where('id', $trashedId)->whereNotNull('deleted_at')->exists())->toBeTrue();
    });
});

test('a second run returns 0 and leaves the translation rows untouched', function () {
    $category = BlogCategory::factory()->create();

    bp78WithLegacyPostColumns(function () use ($category): void {
        bp78InsertLegacyPosts($category->id, bp78LegacyFixtures(3));

        expect(app(BackfillBlogPostTranslations::class)())->toBe(3);

        $before = DB::table('blog_post_translations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        expect(app(BackfillBlogPostTranslations::class)())->toBe(0);

        $after = DB::table('blog_post_translations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        expect($after)->toHaveCount(3)->and($after)->toBe($before);
    });
});

test('a re-run writes only the missing default rows, never overwriting an existing one, and treats a French-only post as missing', function () {
    $category = BlogCategory::factory()->create();
    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $other = StoreLanguage::factory()->create();

    bp78WithLegacyPostColumns(function () use ($category, $default, $other): void {
        $ids = bp78InsertLegacyPosts($category->id, bp78LegacyFixtures(4));
        $now = now();

        DB::table('blog_post_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_post_id' => $ids[0],
            'store_language_id' => $default->id,
            'title' => 'Edited',
            'slug' => 'edited',
            'body' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('blog_post_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_post_id' => $ids[1],
            'store_language_id' => $other->id,
            'title' => 'Otro',
            'slug' => 'otro',
            'body' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(app(BackfillBlogPostTranslations::class)())->toBe(3);

        $first = DB::table('blog_post_translations')->where('blog_post_id', $ids[0])->get();

        expect($first)->toHaveCount(1)
            ->and($first[0]->title)->toBe('Edited')
            ->and(DB::table('blog_post_translations')->where('blog_post_id', $ids[1])->count())->toBe(2)
            ->and(DB::table('blog_post_translations')->where('blog_post_id', $ids[1])->where('store_language_id', $default->id)->value('title'))->toBe('Post 1')
            ->and(DB::table('blog_post_translations')->count())->toBe(5);
    });
});

test('a table larger than one chunk gets exactly one default-language translation per post', function () {
    $category = BlogCategory::factory()->create();
    $default = StoreLanguage::query()->where('is_default', true)->sole();

    bp78WithLegacyPostColumns(function () use ($category, $default): void {
        $ids = bp78InsertLegacyPosts($category->id, bp78LegacyFixtures(1201));

        expect(app(BackfillBlogPostTranslations::class)())->toBe(1201)
            ->and(DB::table('blog_post_translations')->count())->toBe(1201)
            ->and(DB::table('blog_post_translations')->distinct()->count('blog_post_id'))->toBe(1201)
            ->and(DB::table('blog_post_translations')->where('store_language_id', $default->id)->count())->toBe(1201);

        foreach ([0, 499, 500, 1000, 1200] as $index) {
            $row = DB::table('blog_post_translations')->where('blog_post_id', $ids[$index])->first();

            expect($row->title)->toBe('Post '.$index)
                ->and($row->body)->toBe($index % 2 === 0 ? '<p>Body '.$index.'</p>' : null);
        }
    });
});

test('the backfill reads through the query builder, so no BlogPostTranslation model event fires and the body is not re-sanitized', function () {
    $category = BlogCategory::factory()->create();
    $fired = 0;

    bp78WithLegacyPostColumns(function () use ($category, &$fired): void {
        bp78InsertLegacyPosts($category->id, [['title' => '!!!', 'body' => '<script>old()</script>']]);

        Event::listen('eloquent.saving: '.BlogPostTranslation::class, function () use (&$fired): void {
            $fired++;
        });

        app(BackfillBlogPostTranslations::class)();

        expect($fired)->toBe(0)
            ->and(DB::table('blog_post_translations')->value('body'))->toBe('<script>old()</script>');
    });
});
