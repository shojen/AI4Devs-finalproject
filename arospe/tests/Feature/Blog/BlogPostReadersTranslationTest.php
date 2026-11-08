<?php

use App\Actions\Blog\NotifyScheduledBlogPostPublishFailed;
use App\Enums\BlogPostStatus;
use App\Enums\UserStatus;
use App\Livewire\BlogPosts\Editor;
use App\Livewire\BlogPosts\Index;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use App\Notifications\ScheduledBlogPostPublishFailed;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Story 0078 (D-14): one behaviour test per reader of the dropped blog_posts columns, so a missed
// reader fails -- a grep criterion is not testable in Pest. Every fixture uses a post whose default
// row is absent or differs from the language that would otherwise leak into the reader.
//
// Dashboard's GetLatestBlogPosts is in GetLatestBlogPostsTranslationTest.php (it has its own query log).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();

    $this->default = StoreLanguage::factory()->default()->create(['code' => 'es']);
    $this->fr = StoreLanguage::factory()->create(['code' => 'fr']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($actor);
});

/**
 * @return list<string> the titles the post list currently renders
 */
function bprListTitles(Testable $component): array
{
    return collect($component->get('posts')->items())->pluck('title')->all();
}

/**
 * A post holding the given default-language and French titles (null leaves that language out).
 */
function bprPost(?string $defaultTitle, ?string $frenchTitle, string $state = 'draft'): BlogPost
{
    $post = BlogPost::factory()->withoutTranslations()->{$state}()->create();

    if ($defaultTitle !== null) {
        BlogPostTranslation::factory()
            ->forLanguage(StoreLanguage::query()->where('is_default', true)->sole())
            ->create(['blog_post_id' => $post->id, 'title' => $defaultTitle, 'body' => '<p>cuerpo es</p>']);
    }

    if ($frenchTitle !== null) {
        BlogPostTranslation::factory()
            ->forLanguage(StoreLanguage::query()->where('code', 'fr')->sole())
            ->create(['blog_post_id' => $post->id, 'title' => $frenchTitle, 'body' => '<p>corps fr</p>']);
    }

    return $post;
}

// --- Index: list -----------------------------------------------------------------------------

test('the posts list shows the default-language title when a French translation also exists', function () {
    bprPost('Botas de invierno', "Bottes d'hiver");

    expect(bprListTitles(Livewire::test(Index::class)))->toBe(['Botas de invierno']);
});

test('a post written only in French is listed under its French title rather than nameless', function () {
    bprPost(null, "Bottes d'hiver");

    expect(bprListTitles(Livewire::test(Index::class)))->toBe(["Bottes d'hiver"]);
});

test('a post with no translation at all is listed under a dash instead of being dropped', function () {
    bprPost(null, null);

    expect(bprListTitles(Livewire::test(Index::class)))->toBe(['—']);
});

test('the posts list loads translations in one statement that selects neither body nor slug', function () {
    foreach (range(1, 5) as $i) {
        bprPost('Post '.$i, 'Poste '.$i);
    }

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    Livewire::test(Index::class)->get('posts');

    $translationStatements = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'blog_post_translations')));

    expect($translationStatements)->toHaveCount(1);

    $selectList = substr($translationStatements[0], 0, strpos($translationStatements[0], ' from '));

    expect($selectList)->toContain('`title`')
        ->and($selectList)->not->toContain('`body`')
        ->and($selectList)->not->toContain('`slug`')
        ->and($selectList)->not->toContain('*');
});

// --- Index: trashed list ---------------------------------------------------------------------

test('the deleted-posts section lists the default-language title', function () {
    bprPost('Botas de invierno', "Bottes d'hiver")->delete();

    $titles = collect(Livewire::test(Index::class)->get('trashedPosts'))->pluck('title')->all();

    expect($titles)->toBe(['Botas de invierno']);
});

test('a deleted post written only in French is listed under its French title', function () {
    bprPost(null, "Bottes d'hiver")->delete();

    $titles = collect(Livewire::test(Index::class)->get('trashedPosts'))->pluck('title')->all();

    expect($titles)->toBe(["Bottes d'hiver"]);
});

test('the deleted-posts list loads translations in one statement that selects neither body nor slug', function () {
    foreach (range(1, 4) as $i) {
        bprPost('Post '.$i, null)->delete();
    }

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    Livewire::test(Index::class)->get('trashedPosts');

    $translationStatements = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'blog_post_translations')));

    expect($translationStatements)->toHaveCount(1);

    $selectList = substr($translationStatements[0], 0, strpos($translationStatements[0], ' from '));

    expect($selectList)->not->toContain('`body`')->and($selectList)->not->toContain('`slug`');
});

// --- Index: delete confirmation --------------------------------------------------------------

test('the delete confirmation names the default-language title', function () {
    $post = bprPost('Botas de invierno', "Bottes d'hiver");

    Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->assertSet('deletingBlogPostTitle', 'Botas de invierno');
});

test('the delete confirmation for a French-only post names the French title', function () {
    $post = bprPost(null, "Bottes d'hiver");

    Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->assertSet('deletingBlogPostTitle', "Bottes d'hiver");
});

test('the delete confirmation for a post with no translation at all names an empty string, not null', function () {
    $post = bprPost(null, null);

    Livewire::test(Index::class)
        ->call('confirmDelete', $post->id)
        ->assertSet('deletingBlogPostTitle', '');
});

// --- Editor ----------------------------------------------------------------------------------

test('the editor opens a post with its default-language title and body, never the French ones', function () {
    $post = bprPost('Botas de invierno', "Bottes d'hiver");

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('title', 'Botas de invierno')
        ->assertSet('body', '<p>cuerpo es</p>');
});

test('the editor shows empty title and body for a post that holds only a French translation', function () {
    $post = bprPost(null, "Bottes d'hiver");

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('title', '')
        ->assertSet('body', '');
});

test('the editor does not borrow the French body when the default-language body is null', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();
    BlogPostTranslation::factory()->forLanguage($this->default)->create(['blog_post_id' => $post->id, 'title' => 'Sin cuerpo', 'body' => null]);
    BlogPostTranslation::factory()->forLanguage($this->fr)->create(['blog_post_id' => $post->id, 'title' => 'Sans corps', 'body' => '<p>corps fr</p>']);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('title', 'Sin cuerpo')
        ->assertSet('body', '');
});

test('saving a French-only post with an empty title is refused and copies no fallback into a default-language row', function () {
    $post = bprPost(null, "Bottes d'hiver");
    $frenchBefore = (array) DB::table('blog_post_translations')->where('blog_post_id', $post->id)->first();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('save')
        ->assertHasErrors(['title']);

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(1)
        ->and((array) DB::table('blog_post_translations')->where('blog_post_id', $post->id)->first())->toBe($frenchBefore);
});

test('saving a French-only post with a typed title adds the default-language row and leaves the French one unchanged', function () {
    $post = bprPost(null, "Bottes d'hiver");
    $frenchBefore = (array) DB::table('blog_post_translations')
        ->where('blog_post_id', $post->id)->where('store_language_id', $this->fr->id)->first();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('title', 'Botas nuevas')
        ->set('body', '<p>Nuevo</p>')
        ->call('save')
        ->assertHasNoErrors();

    $rows = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->get()->keyBy('store_language_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$this->default->id]->title)->toBe('Botas nuevas')
        ->and((array) $rows[$this->fr->id])->toBe($frenchBefore);
});

// --- NotifyScheduledBlogPostPublishFailed ----------------------------------------------------

function bprBlogEditHolder(): User
{
    $user = User::factory()->create(['status' => UserStatus::Active]);
    $user->givePermissionTo('blog.edit');

    return $user;
}

function bprDueScheduledPost(?string $defaultTitle, ?string $frenchTitle): BlogPost
{
    $post = bprPost($defaultTitle, $frenchTitle, 'scheduled');
    $post->forceFill(['published_at' => now()->subMinute()])->save();

    return $post;
}

test('the scheduled-publish failure notification carries the default-language title, not the French one', function () {
    $holder = bprBlogEditHolder();
    $post = bprDueScheduledPost('Guia de invierno secreta', "Guide d'hiver secret");

    app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

    // The beforeEach actor also holds blog.edit, so the alert fans out to every holder: read the
    // row addressed to the dedicated holder instead of assuming a single recipient.
    $row = DB::table('notifications')
        ->where('type', ScheduledBlogPostPublishFailed::class)
        ->where('notifiable_id', $holder->id)
        ->sole();

    expect(json_decode($row->data, true)['title'])->toBe('Guia de invierno secreta');
});

test('the scheduled-publish failure alert is still raised for a French-only post, never silently dropped', function () {
    bprBlogEditHolder();
    $post = bprDueScheduledPost(null, "Guide d'hiver secret");

    app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

    $row = DB::table('notifications')->where('type', ScheduledBlogPostPublishFailed::class)->first();

    expect($row)->not->toBeNull()
        ->and(json_decode($row->data, true)['title'])->toBe("Guide d'hiver secret");
});

// --- DemoDataSeeder --------------------------------------------------------------------------

test('running the demo seeder gives every blog post exactly one default-language translation with a title, slug and body', function () {
    (new DemoDataSeeder)();

    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $posts = BlogPost::query()->get();

    expect($posts)->toHaveCount(10)
        ->and(DB::table('blog_post_translations')->count())->toBe(10)
        ->and(DB::table('blog_post_translations')->where('store_language_id', $default->id)->count())->toBe(10)
        ->and(DB::table('blog_post_translations')->where('title', '')->orWhere('slug', '')->orWhereNull('body')->count())->toBe(0)
        ->and(DB::table('blog_post_translations')->distinct()->count('slug'))->toBe(10);

    foreach ($posts as $post) {
        expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(1)
            ->and($post->status)->toBe(BlogPostStatus::Published);
    }

    expect(BlogCategory::query()->count())->toBe(10);
});
