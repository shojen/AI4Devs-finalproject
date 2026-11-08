<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use App\Notifications\BlogPostPublished;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Story 0078 (D-17): BlogPostPublished's title is a frozen default-language snapshot taken at
// publication. It must be FRESH (the new title when a post is retitled and published in one save),
// and a later rename never changes a stored notification. Real notifications rows, no fakes.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();
    StoreLanguage::factory()->default()->create(['code' => 'es']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit']);
    $this->actingAs($actor);
});

/**
 * @return list<string> the title of every stored BlogPostPublished notification, read from the database
 */
function bpnStoredTitles(): array
{
    return DB::table('notifications')
        ->where('type', BlogPostPublished::class)
        ->pluck('data')
        ->map(fn (string $data): string => json_decode($data, true)['title'])
        ->all();
}

function bpntUpdate(BlogPost $post, string $title, string $status): BlogPost
{
    return app(UpdateBlogPost::class)($post, $title, '<p>Cuerpo</p>', $post->blog_category_id, $status, null, []);
}

test('retitling a draft and publishing it in one save stores the NEW title, even when translations were already loaded', function () {
    $post = BlogPost::factory()->titled('Borrador', '<p>Cuerpo</p>')->draft()->create();
    $post->load('translations');

    expect($post->translations->first()->title)->toBe('Borrador');

    bpntUpdate($post, 'Titular final', 'published');

    expect(bpnStoredTitles())->toBe(['Titular final']);
});

test('creating an already-published post stores its title in the notification', function () {
    app(CreateBlogPost::class)('Botas de invierno', '<p>Cuerpo</p>', BlogCategory::factory()->create()->id, 'published', null, []);

    expect(bpnStoredTitles())->toBe(['Botas de invierno']);
});

test('a later retitle never changes a notification already stored', function () {
    $post = BlogPost::factory()->titled('Titulo original', '<p>Cuerpo</p>')->draft()->create();
    bpntUpdate($post, 'Titulo original', 'published');

    expect(bpnStoredTitles())->toBe(['Titulo original']);

    bpntUpdate($post->fresh(), 'Titulo renombrado', 'published');

    expect(bpnStoredTitles())->toBe(['Titulo original'])
        ->and(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('title'))->toBe('Titulo renombrado');
});

test('publishing a post that carries three translations announces it once, titled with the default-language title', function () {
    $post = BlogPost::factory()->titled('Botas de invierno', '<p>Cuerpo</p>')->draft()->create();
    BlogPostTranslation::factory()->forLanguage(StoreLanguage::factory()->create(['code' => 'fr']))->create(['blog_post_id' => $post->id, 'title' => "Bottes d'hiver"]);
    BlogPostTranslation::factory()->forLanguage(StoreLanguage::factory()->create(['code' => 'de']))->create(['blog_post_id' => $post->id, 'title' => 'Winterstiefel']);

    bpntUpdate($post, 'Botas de invierno', 'published');

    // The only blog.view holder is the actor: one recipient, one notification.
    expect(DB::table('notifications')->where('type', BlogPostPublished::class)->count())->toBe(1)
        ->and(bpnStoredTitles())->toBe(['Botas de invierno']);
});

test('a post written only in French uses displayTitle() for the notification title', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();
    BlogPostTranslation::factory()->forLanguage(StoreLanguage::factory()->create(['code' => 'fr']))->create(['blog_post_id' => $post->id, 'title' => "Bottes d'hiver"]);

    $payload = (new BlogPostPublished($post->fresh()))->toArray(User::factory()->create());

    expect($payload)->toBe(['blog_post_id' => $post->id, 'title' => "Bottes d'hiver"]);
});

test('a post with no translation at all produces an empty-string title rather than failing', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();

    $payload = (new BlogPostPublished($post->fresh()))->toArray(User::factory()->create());

    expect($payload)->toBe(['blog_post_id' => $post->id, 'title' => '']);
});
