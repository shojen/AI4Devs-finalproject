<?php

use App\Actions\Dashboard\GetLatestBlogPosts;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;
use Tests\Support\Orders\OrdersUi;

// Story 0082 (D-3, D-9), Phase 3 TDD red step: App\Actions\Dashboard\GetLatestBlogPosts does not
// exist yet. Every row is built with an explicit created_at -- factory rows share timestamps.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Europe/Madrid'));
    test()->actingAs(OrdersUi::actor(['blog.view']));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A post created `$minutesAgo` minutes before the frozen now.
 *
 * @param  array<string, mixed>  $attributes
 */
function postCreatedAgo(int $minutesAgo, string $state = 'published', array $attributes = []): BlogPost
{
    return BlogPost::factory()->{$state}()->create(array_merge([
        'created_at' => Carbon::now()->subMinutes($minutesAgo),
    ], $attributes));
}

/**
 * The description of a single published post with the given body.
 */
function descriptionOfBody(?string $body): string
{
    postCreatedAgo(1, 'published', ['body' => $body]);

    return app(GetLatestBlogPosts::class)()[0]['description'];
}

it('lists the 3 newest published or scheduled posts, newest first, and no draft', function () {
    $draft = postCreatedAgo(1, 'draft');
    $newest = postCreatedAgo(2, 'scheduled');
    $second = postCreatedAgo(3, 'published');
    $third = postCreatedAgo(4, 'scheduled');
    postCreatedAgo(5, 'published');

    $posts = app(GetLatestBlogPosts::class)();

    expect(array_column($posts, 'id'))->toBe([$newest->id, $second->id, $third->id])
        ->and(array_column($posts, 'id'))->not->toContain($draft->id);
});

it('returns the documented shape per post', function () {
    $post = postCreatedAgo(1, 'published', ['title' => 'Hello', 'body' => '<p>World</p>']);

    $posts = app(GetLatestBlogPosts::class)();

    expect($posts)->toHaveCount(1)
        ->and(array_keys($posts[0]))->toBe(['id', 'title', 'description', 'status', 'publishAt'])
        ->and($posts[0]['id'])->toBe($post->id)
        ->and($posts[0]['title'])->toBe('Hello')
        ->and($posts[0]['description'])->toBe('World')
        ->and($posts[0]['status'])->toBe(BlogPostStatus::Published);
});

it('returns plain arrays, never Eloquent models', function () {
    postCreatedAgo(1);

    $posts = app(GetLatestBlogPosts::class)();

    expect($posts[0])->toBeArray()
        ->and($posts)->toBeList();
});

it('excludes soft-deleted posts and lists the next newest instead', function () {
    $deleted = postCreatedAgo(1);
    $a = postCreatedAgo(2);
    $b = postCreatedAgo(3);
    $c = postCreatedAgo(4);
    $deleted->delete();

    expect(array_column(app(GetLatestBlogPosts::class)(), 'id'))->toBe([$a->id, $b->id, $c->id]);
});

it('excludes a draft that still carries a stale published_at', function () {
    $stale = postCreatedAgo(1, 'draft', ['published_at' => Carbon::now()->subYear()]);
    $real = postCreatedAgo(2, 'published');

    expect(array_column(app(GetLatestBlogPosts::class)(), 'id'))->toBe([$real->id])
        ->and(array_column(app(GetLatestBlogPosts::class)(), 'id'))->not->toContain($stale->id);
});

it('returns fewer than 3 posts when fewer exist', function () {
    postCreatedAgo(1);
    postCreatedAgo(2, 'scheduled');

    expect(app(GetLatestBlogPosts::class)())->toHaveCount(2);
});

it('returns an empty list when there are no eligible posts', function () {
    postCreatedAgo(1, 'draft');

    expect(app(GetLatestBlogPosts::class)())->toBe([]);
});

it('breaks created_at ties by id descending and lists the same posts on every call', function () {
    $sameSecond = Carbon::now()->subHour();
    $ids = [];

    foreach (range(1, 5) as $ignored) {
        $ids[] = BlogPost::factory()->published()->create(['created_at' => $sameSecond])->id;
    }

    rsort($ids);
    $expected = array_slice($ids, 0, 3);

    expect(array_column(app(GetLatestBlogPosts::class)(), 'id'))->toBe($expected)
        ->and(array_column(app(GetLatestBlogPosts::class)(), 'id'))->toBe($expected);
});

it('shows the publication date of a scheduled post and null for a published one', function () {
    $when = CarbonImmutable::parse('2026-06-15 10:00:00', 'Europe/Madrid');
    $scheduled = postCreatedAgo(1, 'scheduled', ['published_at' => $when]);
    $published = postCreatedAgo(2, 'published', ['published_at' => Carbon::now()->subDay()]);

    $posts = collect(app(GetLatestBlogPosts::class)())->keyBy('id');

    expect($posts[$scheduled->id]['status'])->toBe(BlogPostStatus::Scheduled)
        ->and($posts[$scheduled->id]['publishAt'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($posts[$scheduled->id]['publishAt']->equalTo($when))->toBeTrue()
        ->and($posts[$published->id]['publishAt'])->toBeNull();
});

it('still lists a scheduled post whose date has already passed, with that past date', function () {
    $past = CarbonImmutable::parse('2026-05-20 09:00:00', 'Europe/Madrid');
    $post = postCreatedAgo(1, 'scheduled', ['published_at' => $past]);

    $posts = app(GetLatestBlogPosts::class)();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['id'])->toBe($post->id)
        ->and($posts[0]['publishAt']->equalTo($past))->toBeTrue();
});

it('keeps a 255-character title unchanged', function () {
    $title = str_repeat('t', 255);
    postCreatedAgo(1, 'published', ['title' => $title]);

    expect(app(GetLatestBlogPosts::class)()[0]['title'])->toBe($title);
});

// --- Description (D-3) ---

it('derives an empty description from a missing, empty or tag-only body', function (?string $body) {
    expect(descriptionOfBody($body))->toBe('');
})->with([
    'null' => [null],
    'empty string' => [''],
    'empty paragraph' => ['<p></p>'],
    'tags only' => ['<div><br><hr></div>'],
    'image only' => ['<p><img src="/media/x.png" alt="An image"></p>'],
    'whitespace only' => ["  \n\t "],
]);

it('derives the description as plain text with entities decoded after stripping', function (string $body, string $expected) {
    expect(descriptionOfBody($body))->toBe($expected);
})->with([
    'bold word' => ['<p>Hello <b>brave</b> world</p>', 'Hello brave world'],
    'ampersand entity' => ['<p>Fish &amp; chips</p>', 'Fish & chips'],
    'encoded tags stay literal text' => ['<p>Use &lt;b&gt;bold&lt;/b&gt; here</p>', 'Use <b>bold</b> here'],
    'collapses whitespace between blocks' => ["<p>a</p>\n\n   <p>b\t c</p>", 'a b c'],
    'script text is kept as text, the tags are gone' => ["<p>Hi</p>\n<script>alert(1)</script>", 'Hi alert(1)'],
    'non-breaking space entity is collapsed' => ['<p>one&nbsp;two</p>', 'one two'],
]);

it('treats block-level tags as a single space separator even without whitespace between them', function (string $body, string $expected) {
    expect(descriptionOfBody($body))->toBe($expected);
})->with([
    'heading then paragraph' => ['<h2>Heading</h2><p>palabra</p>', 'Heading palabra'],
    'two paragraphs' => ['<p>one</p><p>two</p>', 'one two'],
    'list items' => ['<ul><li>a</li><li>b</li></ul>', 'a b'],
    'line break' => ['line<br>break', 'line break'],
    'self-closing line break' => ['line<br/>break', 'line break'],
    'divs' => ['<div>one</div><div>two</div>', 'one two'],
    'blockquote' => ['<p>one</p><blockquote>two</blockquote>', 'one two'],
    'table cells' => ['<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table>', 'a b c'],
    'block tags with whitespace already between them' => ["<h2>Heading</h2>\n<p>palabra</p>", 'Heading palabra'],
    'uppercase block tags' => ['<P>one</P><P>two</P>', 'one two'],
]);

it('does not add a space for inline tags', function (string $body, string $expected) {
    expect(descriptionOfBody($body))->toBe($expected);
})->with([
    'bold inside a word' => ['<b>wor</b>ld', 'world'],
    'em and strong' => ['<em>a</em><strong>b</strong>c', 'abc'],
    'link and span and code' => ['x<a href="/y">y</a><span>z</span><code>w</code>', 'xyzw'],
    'inline tags inside a block' => ['<p>un<i>bel</i>ievable</p>', 'unbelievable'],
]);

it('does not leave leading, trailing or doubled spaces when blocks are the whole body', function () {
    expect(descriptionOfBody('<p>a</p><p></p><p>b</p><br><p>c</p>'))->toBe('a b c');
});

it('still decodes entities after stripping when block tags are separated', function () {
    expect(descriptionOfBody('<p>&lt;b&gt;</p><p>x &amp; y</p>'))->toBe('<b> x & y');
});

it('keeps the 80-character limit, ellipsis included, when block tags add separators', function () {
    $description = descriptionOfBody('<p>'.str_repeat('a', 50).'</p><p>'.str_repeat('b', 50).'</p>');

    expect(mb_strlen($description))->toBe(80)
        ->and($description)->toBe(str_repeat('a', 50).' '.str_repeat('b', 28).'…');
});

it('reads only the first 2000 characters of the body', function () {
    $body = str_repeat('<b></b>', 300).'visible text';

    expect(strlen($body))->toBeGreaterThan(2000)
        ->and(descriptionOfBody($body))->toBe('');
});

it('never exceeds 80 characters including the ellipsis and only adds it when truncating', function (int $length, int $expectedLength, bool $truncated) {
    $body = '<p>'.str_repeat('a', $length).'</p>';

    $description = descriptionOfBody($body);

    expect(mb_strlen($description))->toBe($expectedLength)
        ->and(str_ends_with($description, '…'))->toBe($truncated);

    if ($truncated) {
        expect($description)->toBe(str_repeat('a', 79).'…');
    } else {
        expect($description)->toBe(str_repeat('a', $length));
    }
})->with([
    '50 characters' => [50, 50, false],
    '79 characters' => [79, 79, false],
    '80 characters' => [80, 80, false],
    '81 characters' => [81, 80, true],
    '300 characters' => [300, 80, true],
]);

it('truncates multibyte text without breaking a character', function (string $unit) {
    $text = str_repeat($unit, 120);

    $description = descriptionOfBody('<p>'.$text.'</p>');

    expect(mb_check_encoding($description, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($description))->toBeLessThanOrEqual(80)
        ->and(str_ends_with($description, '…'))->toBeTrue()
        ->and(str_starts_with($description, $unit))->toBeTrue();
})->with([
    'accented letter' => ['ñ'],
    'CJK' => ['日本語'],
    'emoji' => ['😀'],
]);

it('counts accented letters as single characters when truncating', function () {
    expect(descriptionOfBody('<p>'.str_repeat('ñ', 100).'</p>'))->toBe(str_repeat('ñ', 79).'…');
});

// --- D-9 translatable-content seam ---

it('reads the title and body only through the resolveTitle and resolveBody seam methods', function () {
    $action = new ReflectionClass(GetLatestBlogPosts::class);

    foreach (['resolveTitle', 'resolveBody'] as $seam) {
        expect($action->hasMethod($seam))->toBeTrue("missing seam method {$seam}")
            ->and($action->getMethod($seam)->isPrivate())->toBeTrue("{$seam} must be private");
    }
});

it('never names the title or body column in its query', function () {
    postCreatedAgo(1);
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    app(GetLatestBlogPosts::class)();

    $postQueries = array_values(array_filter($sql, fn (string $statement): bool => str_contains($statement, 'blog_posts')));

    expect($postQueries)->toHaveCount(1)
        ->and($postQueries[0])->not->toMatch('/[`.](title|body)`?\b/i');
});

it('runs a single blog_posts query and touches no other domain table', function () {
    postCreatedAgo(1);
    postCreatedAgo(2, 'scheduled');

    $queries = DomainQueryLog::capture(fn () => app(GetLatestBlogPosts::class)());

    expect($queries['blog_posts'])->toBe(1)
        ->and(DomainQueryLog::total($queries))->toBe(1);
});

it('resolves the title from the viewer locale translation')->todo();
it('falls back to the default-language title when the viewer locale has no translation')->todo();
it('derives the description from the translated body')->todo();
