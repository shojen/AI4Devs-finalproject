<?php

// Story 0063 (layer 1) -- Pest 4 browser tests for the blog post list (route blog-posts.index,
// GET /blog/posts). Mirrored folder per 0060's V-1, like tests/Browser/BlogTags/ and
// tests/Browser/BlogCategories/; the editor's own journey lives in EditorJourneyTest.php.
//
// What only a real browser proves, and Livewire::test() cannot: that BOTH `wire:model.live` filter
// selects actually deliver a picked value to the server (->set() writes the property directly), that
// the closed-on-first-paint trashed section really expands on a click (Alpine, not Livewire), and
// that the compiled `wire:click` arguments on the delete/restore controls work.
//
// The two filter tests are wrapped in Laravel's own retry(3, ..., 250): a `<flux:select>` +
// `wire:model` binding has a RECORDED, unresolved race under Playwright in this repo
// (docs/testing/frontend/playwright-setup/waiting-rules.md). retry() is the accepted lever, never a
// longer ->wait() -- a bare wait that matters races the plugin's own 5000 ms ceiling and fails MORE.
// ->waitForEvent('networkidle') is banned in this repo (it never settles here).
//
// Rows are asserted through their row-scoped `data-test` hooks, never a page-global assertSee: a
// blog list realistically has several rows sharing a status, a date or a tag name.

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogPostsBrowserActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('the list renders its rows, filters and trashed section with no JavaScript error', function () {
    $this->actingAs(blogPostsBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = BlogPost::factory()->published()->create(['title' => 'Botas de invierno', 'blog_category_id' => $category->id]);
    BlogPost::factory()->create()->delete();

    visit('/blog/posts')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@title-blog-post-'.$post->id)
        ->assertPresent('@category-filter')
        ->assertPresent('@tag-filter')
        ->assertPresent('@trashed-posts-toggle');
});

test('choosing a category in the filter narrows the visible rows, and choosing All restores them', function () {
    $this->actingAs(blogPostsBrowserActor());
    $guides = BlogCategory::factory()->create(['name' => 'Guías']);
    $news = BlogCategory::factory()->create(['name' => 'Novedades']);
    $guide = BlogPost::factory()->create(['title' => 'Una guía', 'blog_category_id' => $guides->id]);
    $newsPost = BlogPost::factory()->create(['title' => 'Una novedad', 'blog_category_id' => $news->id]);

    retry(3, function () use ($guides, $guide, $newsPost): void {
        visit('/blog/posts')
            ->assertNoJavaScriptErrors()
            ->assertPresent('@title-blog-post-'.$newsPost->id)
            ->select('categoryFilter', $guides->id)
            ->assertNoJavaScriptErrors()
            ->assertMissing('@title-blog-post-'.$newsPost->id)
            ->assertPresent('@title-blog-post-'.$guide->id)
            ->select('categoryFilter', '')
            ->assertPresent('@title-blog-post-'.$newsPost->id)
            ->assertPresent('@title-blog-post-'.$guide->id);
    }, 250);
});

test('choosing a tag in the filter narrows the visible rows', function () {
    $this->actingAs(blogPostsBrowserActor());
    $running = BlogTag::factory()->create(['name' => 'running']);
    $winter = BlogTag::factory()->create(['name' => 'invierno']);
    $runner = BlogPost::factory()->create(['title' => 'Para correr']);
    $runner->tags()->attach($running->id);
    $skier = BlogPost::factory()->create(['title' => 'Para esquiar']);
    $skier->tags()->attach($winter->id);

    retry(3, function () use ($running, $runner, $skier): void {
        visit('/blog/posts')
            ->assertNoJavaScriptErrors()
            ->assertPresent('@title-blog-post-'.$skier->id)
            ->select('tagFilter', $running->id)
            ->assertNoJavaScriptErrors()
            ->assertMissing('@title-blog-post-'.$skier->id)
            ->assertPresent('@title-blog-post-'.$runner->id);
    }, 250);
});

test('the trashed section starts closed, expands on a click, and restoring a post returns it to the main table', function () {
    $this->actingAs(blogPostsBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno', 'blog_category_id' => $category->id]);
    $post->delete();

    visit('/blog/posts')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@trashed-posts-toggle')
        ->assertMissing('@title-blog-post-'.$post->id)
        ->assertMissing('@restore-blog-post-'.$post->id)
        ->click('@trashed-posts-toggle')
        ->assertVisible('@restore-blog-post-'.$post->id)
        ->click('@restore-blog-post-'.$post->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@title-blog-post-'.$post->id)
        ->assertMissing('@trashed-posts-section');

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('deleting a post through the confirmation modal removes its row and moves it to the trashed section', function () {
    $this->actingAs(blogPostsBrowserActor());
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    visit('/blog/posts')
        ->assertNoJavaScriptErrors()
        ->click('@delete-blog-post-'.$post->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@confirm-delete-blog-post')
        ->click('@confirm-delete-blog-post')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@title-blog-post-'.$post->id)
        ->assertPresent('@trashed-blog-post-'.$post->id);

    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('cancelling the delete confirmation keeps the row and deletes nothing', function () {
    $this->actingAs(blogPostsBrowserActor());
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    visit('/blog/posts')
        ->click('@delete-blog-post-'.$post->id)
        ->assertPresent('@confirm-delete-blog-post')
        ->click('@cancel-delete-blog-post')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-delete-blog-post')
        ->assertPresent('@title-blog-post-'.$post->id);

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});
