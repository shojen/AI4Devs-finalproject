<?php

use App\Actions\Dashboard\GetLatestBlogPosts;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Story 0078 (D-14): the dashboard's latest-posts block reads title and description through the
// translation layer. tests/Support/Dashboard/DomainQueryLog::TABLES names blog_posts but NOT
// blog_post_translations, so the existing 'posts' => 1 budget is blind to an N+1 on translations; this
// file carries its own statement counter for blog_post_translations instead of editing that helper.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();
    Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Europe/Madrid'));

    $this->default = StoreLanguage::factory()->default()->create(['code' => 'es']);
    $this->fr = StoreLanguage::factory()->create(['code' => 'fr']);

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.view'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function glbPublishedPost(int $minutesAgo, ?string $defaultTitle, ?string $defaultBody, ?string $frenchTitle = null): BlogPost
{
    $post = BlogPost::factory()->withoutTranslations()->published()->create([
        'created_at' => Carbon::now()->subMinutes($minutesAgo),
    ]);

    if ($defaultTitle !== null) {
        BlogPostTranslation::factory()
            ->forLanguage(StoreLanguage::query()->where('is_default', true)->sole())
            ->create(['blog_post_id' => $post->id, 'title' => $defaultTitle, 'body' => $defaultBody]);
    }

    if ($frenchTitle !== null) {
        BlogPostTranslation::factory()
            ->forLanguage(StoreLanguage::query()->where('code', 'fr')->sole())
            ->create(['blog_post_id' => $post->id, 'title' => $frenchTitle, 'body' => '<p>corps fr</p>']);
    }

    return $post;
}

/**
 * Counts the statements that named blog_post_translations while $callback ran.
 */
function glbTranslationStatements(Closure $callback): int
{
    $count = 0;
    $recording = true;

    DB::listen(function (QueryExecuted $query) use (&$count, &$recording): void {
        if ($recording && preg_match('/\bblog_post_translations\b/', $query->sql) === 1) {
            $count++;
        }
    });

    try {
        $callback();
    } finally {
        $recording = false;
    }

    return $count;
}

it('shows the default-language title and a description derived from the default-language body, not the French ones', function () {
    glbPublishedPost(1, 'Botas de invierno', '<p>Cuerpo en castellano</p>', "Bottes d'hiver");

    $row = app(GetLatestBlogPosts::class)()[0];

    expect($row['title'])->toBe('Botas de invierno')
        ->and($row['description'])->toBe('Cuerpo en castellano');
});

it('resolves the title to null and the description to empty for a post written only in French, without error', function () {
    glbPublishedPost(1, null, null, "Bottes d'hiver");

    $row = app(GetLatestBlogPosts::class)()[0];

    expect($row['title'])->toBeNull()
        ->and($row['description'])->toBe('');
});

it('issues exactly one statement naming blog_post_translations whatever the number of posts', function (int $scale) {
    foreach (range(1, $scale) as $i) {
        glbPublishedPost($i, 'Post '.$i, '<p>Cuerpo '.$i.'</p>', 'Poste '.$i);
    }

    $statements = glbTranslationStatements(fn () => app(GetLatestBlogPosts::class)());

    expect($statements)->toBe(1);
})->with([3, 30]);
