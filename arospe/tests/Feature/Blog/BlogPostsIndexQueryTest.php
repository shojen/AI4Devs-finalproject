<?php

// Story 0063 (layer 1) -- the list's OWN queries, split out because each is a named risk: the
// explicit-column select that never drags `body` (a mediumText kept inline in the clustered index,
// 0061 R-7) or `slug` into a paginated list, the absence of any per-row query, and the two scopes
// the filters are built on. Everything here is asserted against the SQL the database actually
// received (DB::listen), never by reading the component -- a `select *` looks identical in every
// rendering test.
//
// Written against the pre-Epic-5 schema, which is what ships today.

use App\Livewire\BlogPosts\Index;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($actor);
});

/**
 * Runs `$callback` and returns every SQL statement the database received meanwhile.
 *
 * @param  Closure(): mixed  $callback
 * @return list<string>
 */
function blogPostsQueryCapture(Closure $callback): array
{
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

/**
 * The column list of every row-fetching `select ... from blog_posts` in the captured statements
 * (the paginator's own `count(*)` is not a row fetch), split into trimmed, unquoted column names.
 *
 * @param  list<string>  $statements
 * @return list<list<string>>
 */
function blogPostsQuerySelectedColumns(array $statements): array
{
    $lists = [];

    foreach ($statements as $sql) {
        if (preg_match('/^select (?!count\()(.+?) from [`"]blog_posts[`"]/i', $sql, $matches) === 1) {
            $lists[] = array_map(
                fn (string $column): string => trim(str_replace(['`', '"'], '', $column)),
                explode(',', $matches[1]),
            );
        }
    }

    return $lists;
}

test('every row-fetching query on blog_posts names explicit columns and never body, slug or *', function (string $scenario) {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $tag = BlogTag::factory()->create(['name' => 'running']);
    BlogPost::factory()->count(3)->create(['blog_category_id' => $category->id])
        ->each(fn (BlogPost $post) => $post->tags()->attach($tag->id));
    BlogPost::factory()->create(['blog_category_id' => $category->id])->delete();

    $statements = blogPostsQueryCapture(function () use ($scenario, $category, $tag): void {
        $component = Livewire::test(Index::class);

        match ($scenario) {
            'category filter' => $component->set('categoryFilter', $category->id),
            'tag filter' => $component->set('tagFilter', $tag->id),
            'both filters' => $component->set('categoryFilter', $category->id)->set('tagFilter', $tag->id),
            default => null,
        };
    });

    $lists = blogPostsQuerySelectedColumns($statements);

    // The list itself and the trashed section's own query both fetch rows from blog_posts.
    expect($lists)->not->toBeEmpty();

    foreach ($lists as $columns) {
        expect($columns)->not->toContain('*')
            ->and($columns)->not->toContain('body')
            ->and($columns)->not->toContain('slug')
            ->and($columns)->not->toContain('blog_posts.*');
    }

    // The column lists are the whole story, but no statement of the request may select them
    // through the back door either (an eager load naming `body`).
    foreach ($statements as $sql) {
        expect($sql)->not->toContain('`body`')->and($sql)->not->toContain('`slug`');
    }
})->with(['unfiltered', 'category filter', 'tag filter', 'both filters']);

test('the list query fetches exactly the columns the row needs', function () {
    BlogPost::factory()->count(2)->create();

    $lists = blogPostsQuerySelectedColumns(blogPostsQueryCapture(fn () => Livewire::test(Index::class)));

    // The first row-fetching select is the live list; the second is the trashed section's.
    expect($lists[0])->toEqualCanonicalizing(['id', 'blog_category_id', 'title', 'status', 'published_at', 'created_at']);
});

test('the trashed query is explicit too, and selects only trashed rows', function () {
    BlogPost::factory()->create();
    BlogPost::factory()->create()->delete();

    $statements = blogPostsQueryCapture(fn () => Livewire::test(Index::class));

    $trashedStatements = collect($statements)->filter(
        fn (string $sql): bool => preg_match('/from [`"]blog_posts[`"].*[`"]deleted_at[`"] is not null/is', $sql) === 1,
    );

    expect($trashedStatements)->toHaveCount(1);

    $columns = blogPostsQuerySelectedColumns($trashedStatements->all())[0];

    expect($columns)->not->toContain('*')->and($columns)->not->toContain('body')->and($columns)->not->toContain('slug')
        ->and($columns)->toContain('id')->and($columns)->toContain('deleted_at');
});

test('rendering N posts each with a category and tags issues the same bounded number of queries for N=1 and N=5', function () {
    // Distinct categories and DISTINCT tags per post are load-bearing: identical relations would
    // pass through Eloquent's identity map and hide a per-row query.
    //
    // Warm the actor's permission cache BEFORE either counted run. The row hints call
    // Gate::allows() per row, and Spatie loads and caches the whole roles+permissions graph on the
    // first such check -- a one-time cost unrelated to this query's shape that would otherwise land
    // in whichever counted run goes first (docs/errors-log.md).
    Gate::allows('update', BlogPost::factory()->create());
    BlogPost::withTrashed()->forceDelete();

    $queryCountFor = function (int $postCount): int {
        BlogPost::withTrashed()->forceDelete();

        for ($i = 0; $i < $postCount; $i++) {
            $post = BlogPost::factory()->create(['blog_category_id' => BlogCategory::factory()->create()->id]);
            $post->tags()->attach(BlogTag::factory()->count(2)->create()->modelKeys());
            BlogPost::factory()->create(['blog_category_id' => BlogCategory::factory()->create()->id])->delete();
        }

        return count(blogPostsQueryCapture(fn () => Livewire::test(Index::class)->get('posts')->items()));
    };

    $countForOne = $queryCountFor(1);
    $countForFive = $queryCountFor(5);

    expect($countForFive)->toBe($countForOne)->and($countForOne)->toBeLessThanOrEqual(20);
});

test('the query counter can see a per-row query, so the equality above is not vacuous', function () {
    $countLazyLoads = function (int $postCount): int {
        BlogPost::withTrashed()->forceDelete();
        BlogPost::factory()->count($postCount)->create();

        return count(blogPostsQueryCapture(
            fn () => BlogPost::query()->get()->each(fn (BlogPost $post) => $post->category->name),
        ));
    };

    expect($countLazyLoads(5))->toBeGreaterThan($countLazyLoads(1));
});

// =====================================================================
// The scopes the filters are built on, with decoys in every fixture
// =====================================================================

test('forCategory() returns only that category\'s posts, leaving a decoy category out', function () {
    $guides = BlogCategory::factory()->create();
    $decoy = BlogCategory::factory()->create();
    BlogPost::factory()->count(2)->create(['blog_category_id' => $guides->id]);
    BlogPost::factory()->count(3)->create(['blog_category_id' => $decoy->id]);

    expect(BlogPost::query()->forCategory($guides->id)->count())->toBe(2);
});

test('forTag() returns only posts carrying that tag, leaving a decoy tag out', function () {
    $tag = BlogTag::factory()->create();
    $decoyTag = BlogTag::factory()->create();
    BlogPost::factory()->count(2)->create()->each(fn (BlogPost $post) => $post->tags()->attach($tag->id));
    BlogPost::factory()->count(3)->create()->each(fn (BlogPost $post) => $post->tags()->attach($decoyTag->id));

    expect(BlogPost::query()->forTag($tag->id)->count())->toBe(2);
});

test('the scopes compose: category and tag together narrow further', function () {
    $guides = BlogCategory::factory()->create();
    $tag = BlogTag::factory()->create();
    BlogPost::factory()->create(['blog_category_id' => $guides->id])->tags()->attach($tag->id);
    BlogPost::factory()->create(['blog_category_id' => $guides->id]);
    BlogPost::factory()->create()->tags()->attach($tag->id);

    expect(BlogPost::query()->forCategory($guides->id)->forTag($tag->id)->count())->toBe(1);
});

test('neither scope returns a trashed post', function () {
    $category = BlogCategory::factory()->create();
    $tag = BlogTag::factory()->create();
    $trashed = BlogPost::factory()->create(['blog_category_id' => $category->id]);
    $trashed->tags()->attach($tag->id);
    $trashed->delete();

    expect(BlogPost::query()->forCategory($category->id)->count())->toBe(0)
        ->and(BlogPost::query()->forTag($tag->id)->count())->toBe(0);
});

test('the trashed section\'s own query returns only trashed posts and ignores the active filters', function () {
    $guides = BlogCategory::factory()->create();
    $news = BlogCategory::factory()->create();
    BlogPost::factory()->create(['title' => 'Live', 'blog_category_id' => $guides->id]);
    BlogPost::factory()->create(['title' => 'Trashed in guides', 'blog_category_id' => $guides->id])->delete();
    BlogPost::factory()->create(['title' => 'Trashed in news', 'blog_category_id' => $news->id])->delete();

    $component = Livewire::test(Index::class)->set('categoryFilter', $guides->id);

    expect(collect($component->get('trashedPosts'))->pluck('title')->all())
        ->toEqualCanonicalizing(['Trashed in guides', 'Trashed in news']);
});

test('the trashed section is ordered most recently deleted first', function () {
    BlogPost::factory()->create(['title' => 'Deleted long ago', 'deleted_at' => '2026-01-01 00:00:00']);
    BlogPost::factory()->create(['title' => 'Deleted yesterday', 'deleted_at' => '2026-03-02 00:00:00']);
    BlogPost::factory()->create(['title' => 'Deleted last week', 'deleted_at' => '2026-02-20 00:00:00']);

    expect(collect(Livewire::test(Index::class)->get('trashedPosts'))->pluck('title')->all())
        ->toBe(['Deleted yesterday', 'Deleted last week', 'Deleted long ago']);
});
