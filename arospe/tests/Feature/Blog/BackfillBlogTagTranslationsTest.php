<?php

use App\Actions\Blog\BackfillBlogTagTranslations;
use App\Models\BlogTag;
use App\Models\StoreLanguage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0074 (D-10): the backfill carries each tag's name into the default store language,
// COPYING name and normalized_name byte-for-byte (the pre-retrofit global UNIQUE already proves the
// folds pairwise distinct, so no recompute is needed -- and a recompute could only introduce drift).
// Both migrations have already run before every test body, so blog_tags no longer carries `name` or
// `normalized_name`: the pure transform is exercised with {id, name, normalized_name} fixtures, the
// database-facing guards against the real schema. Per-row assertions throughout -- a count would
// pass even if every row got the wrong name.

function blogTagBackfillFixture(string $id, string $name, string $normalizedName): object
{
    return (object) ['id' => $id, 'name' => $name, 'normalized_name' => $normalizedName];
}

test('translationRowsFor() writes one row per tag, in the given language, name and normalized_name byte-identical', function () {
    $now = Carbon::parse('2026-10-15 10:00:00');
    $languageId = (string) Str::uuid7();
    $tags = [
        blogTagBackfillFixture((string) Str::uuid7(), 'Niño', 'nino'),
        blogTagBackfillFixture((string) Str::uuid7(), 'Trail  Running', 'trail running'),
    ];

    $rows = app(BackfillBlogTagTranslations::class)->translationRowsFor($tags, $languageId, $now);

    expect($rows)->toHaveCount(2);

    foreach ($rows as $index => $row) {
        expect($row['blog_tag_id'])->toBe($tags[$index]->id)
            ->and($row['store_language_id'])->toBe($languageId)
            ->and($row['name'])->toBe($tags[$index]->name)
            ->and($row['normalized_name'])->toBe($tags[$index]->normalized_name)
            ->and($row['created_at'])->toBe($now)
            ->and($row['updated_at'])->toBe($now)
            ->and(Str::isUuid($row['id'], 7))->toBeTrue();
    }

    expect(array_unique(array_column($rows, 'id')))->toHaveCount(2);
});

// The fold is copied, not recomputed: a value no normaliser would produce for this name must survive
// untouched, which a recompute-then-compare implementation could not satisfy.
test('translationRowsFor() copies a stored normalized_name as-is instead of recomputing it', function () {
    $rows = app(BackfillBlogTagTranslations::class)->translationRowsFor([
        blogTagBackfillFixture((string) Str::uuid7(), 'Niño', 'legacy-fold'),
    ], (string) Str::uuid7(), Carbon::now());

    expect($rows[0]['normalized_name'])->toBe('legacy-fold');
});

test('translationRowsFor() preserves edge whitespace and a boundary-length name unchanged', function () {
    $boundary = str_repeat('a', 100);
    $rows = app(BackfillBlogTagTranslations::class)->translationRowsFor([
        blogTagBackfillFixture((string) Str::uuid7(), '  running  ', 'running'),
        blogTagBackfillFixture((string) Str::uuid7(), $boundary, $boundary),
    ], (string) Str::uuid7(), Carbon::now());

    expect($rows[0]['name'])->toBe('  running  ')
        ->and($rows[0]['normalized_name'])->toBe('running')
        ->and($rows[1]['name'])->toBe($boundary)
        ->and($rows[1]['normalized_name'])->toBe($boundary);
});

test('translationRowsFor() over zero tags returns an empty list', function () {
    expect(app(BackfillBlogTagTranslations::class)->translationRowsFor([], (string) Str::uuid7(), Carbon::now()))->toBe([]);
});

test('with existing tags and no default store language, the backfill refuses loudly and writes nothing', function () {
    BlogTag::factory()->withoutTranslations()->create();

    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => app(BackfillBlogTagTranslations::class)->assertCanBackfill())->toThrow(RuntimeException::class);
    expect(fn () => app(BackfillBlogTagTranslations::class)())->toThrow(RuntimeException::class);

    expect(DB::table('blog_tag_translations')->count())->toBe(0);
});

// Every fresh install and every RefreshDatabase run takes exactly this branch.
test('with zero tags the backfill is a no-op returning 0, whatever store_languages holds', function () {
    expect(app(BackfillBlogTagTranslations::class)())->toBe(0);

    StoreLanguage::factory()->default()->create();

    expect(app(BackfillBlogTagTranslations::class)())->toBe(0)
        ->and(DB::table('blog_tag_translations')->count())->toBe(0);
});

// A real __invoke() needs the legacy columns the retrofit dropped, so they are re-added for this one
// test. MySQL DDL implicitly commits, which defeats RefreshDatabase's rollback: the finally block
// removes every row and both columns again so no state leaks into later tests.
test('a real run gives every existing tag a default-language translation copied byte-for-byte', function () {
    $default = StoreLanguage::factory()->default()->create();
    $other = StoreLanguage::factory()->create();

    Schema::table('blog_tags', function ($table): void {
        $table->string('name', 100)->nullable();
        $table->string('normalized_name', 100)->nullable();
    });

    try {
        $legacy = [
            ['name' => 'Niño', 'normalized_name' => 'nino'],
            ['name' => '  Trail  Running ', 'normalized_name' => 'legacy-fold'],
            ['name' => str_repeat('a', 100), 'normalized_name' => str_repeat('a', 100)],
        ];
        $ids = [];

        foreach ($legacy as $row) {
            $ids[$row['normalized_name']] = (string) Str::uuid7();
            DB::table('blog_tags')->insert($row + [
                'id' => $ids[$row['normalized_name']],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(app(BackfillBlogTagTranslations::class)())->toBe(3);

        foreach ($legacy as $row) {
            $translations = DB::table('blog_tag_translations')->where('blog_tag_id', $ids[$row['normalized_name']])->get();

            expect($translations)->toHaveCount(1)
                ->and($translations[0]->store_language_id)->toBe($default->id)
                ->and($translations[0]->name)->toBe($row['name'])
                ->and($translations[0]->normalized_name)->toBe($row['normalized_name']);
        }

        expect(DB::table('blog_tag_translations')->where('store_language_id', $other->id)->count())->toBe(0);
    } finally {
        DB::table('blog_tag_translations')->delete();
        DB::table('blog_tags')->delete();
        Schema::table('blog_tags', function ($table): void {
            $table->dropColumn(['name', 'normalized_name']);
        });
        DB::table('store_languages')->delete();
    }
});

// Runs $callback with the legacy columns re-added, then removes every row and both columns again
// (see the DDL note on the real-run test above).
function withLegacyBlogTagColumns(Closure $callback): void
{
    Schema::table('blog_tags', function ($table): void {
        $table->string('name', 100)->nullable();
        $table->string('normalized_name', 100)->nullable();
    });

    try {
        $callback();
    } finally {
        DB::table('blog_tag_translations')->delete();
        DB::table('blog_tags')->delete();
        Schema::table('blog_tags', function ($table): void {
            $table->dropColumn(['name', 'normalized_name']);
        });
        DB::table('store_languages')->delete();
    }
}

/**
 * @return list<string> the inserted tag ids, in insertion order
 */
function insertLegacyBlogTags(int $count): array
{
    $ids = [];
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = $id = (string) Str::uuid7();
        $rows[] = [
            'id' => $id,
            'name' => 'Tag '.$i,
            'normalized_name' => 'tag '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($rows) === 500) {
            DB::table('blog_tags')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('blog_tags')->insert($rows);
    }

    return $ids;
}

test('a second run returns 0 and leaves the translation rows untouched', function () {
    StoreLanguage::factory()->default()->create();

    withLegacyBlogTagColumns(function (): void {
        insertLegacyBlogTags(3);

        expect(app(BackfillBlogTagTranslations::class)())->toBe(3);

        $before = DB::table('blog_tag_translations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        expect(app(BackfillBlogTagTranslations::class)())->toBe(0);

        $after = DB::table('blog_tag_translations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        expect($after)->toHaveCount(3)->and($after)->toBe($before);
    });
});

test('a re-run over a partially translated catalogue writes only the missing rows and never overwrites existing text', function () {
    $default = StoreLanguage::factory()->default()->create();
    $other = StoreLanguage::factory()->create();

    withLegacyBlogTagColumns(function () use ($default, $other): void {
        $ids = insertLegacyBlogTags(5);
        $now = now();

        // Tags 0 and 1 already have a default-language row with different text.
        foreach ([0, 1] as $index) {
            DB::table('blog_tag_translations')->insert([
                'id' => (string) Str::uuid7(),
                'blog_tag_id' => $ids[$index],
                'store_language_id' => $default->id,
                'name' => 'Edited '.$index,
                'normalized_name' => 'edited '.$index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Tag 2 only has a non-default translation: it still lacks the default-language one.
        DB::table('blog_tag_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_tag_id' => $ids[2],
            'store_language_id' => $other->id,
            'name' => 'Otro',
            'normalized_name' => 'otro',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(app(BackfillBlogTagTranslations::class)())->toBe(3);

        foreach ([0, 1] as $index) {
            $rows = DB::table('blog_tag_translations')->where('blog_tag_id', $ids[$index])->get();

            expect($rows)->toHaveCount(1)
                ->and($rows[0]->name)->toBe('Edited '.$index)
                ->and($rows[0]->normalized_name)->toBe('edited '.$index);
        }

        foreach ([2, 3, 4] as $index) {
            $rows = DB::table('blog_tag_translations')
                ->where('blog_tag_id', $ids[$index])
                ->where('store_language_id', $default->id)
                ->get();

            expect($rows)->toHaveCount(1)
                ->and($rows[0]->name)->toBe('Tag '.$index)
                ->and($rows[0]->normalized_name)->toBe('tag '.$index);
        }

        expect(DB::table('blog_tag_translations')->where('blog_tag_id', $ids[2])->count())->toBe(2)
            ->and(DB::table('blog_tag_translations')->count())->toBe(6);
    });
});

test('a catalogue larger than one chunk gets exactly one default-language translation per tag', function () {
    $default = StoreLanguage::factory()->default()->create();

    withLegacyBlogTagColumns(function () use ($default): void {
        $ids = insertLegacyBlogTags(1201);

        expect(app(BackfillBlogTagTranslations::class)())->toBe(1201)
            ->and(DB::table('blog_tag_translations')->count())->toBe(1201)
            ->and(DB::table('blog_tag_translations')->distinct()->count('blog_tag_id'))->toBe(1201)
            ->and(DB::table('blog_tag_translations')->where('store_language_id', $default->id)->count())->toBe(1201);

        // Spot-check rows on both sides of each chunk boundary for byte-identical copies.
        foreach ([0, 499, 500, 999, 1000, 1200] as $index) {
            $row = DB::table('blog_tag_translations')->where('blog_tag_id', $ids[$index])->first();

            expect($row->name)->toBe('Tag '.$index)
                ->and($row->normalized_name)->toBe('tag '.$index);
        }

        expect(app(BackfillBlogTagTranslations::class)())->toBe(0);
    });
});
