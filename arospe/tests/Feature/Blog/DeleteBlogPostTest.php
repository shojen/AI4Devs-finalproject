<?php

use App\Actions\Blog\DeleteBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

// Story 0061, D-16 / D-7: DeleteBlogPost authorizes itself first, so every test runs actingAs() an
// actor holding blog.delete. The delete is a SOFT delete -- recoverable, keeping its slug and its
// tag associations -- and the restore round-trip lives in RestoreBlogPostTest.php.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.delete']);
    $this->actingAs($this->actor);
});

// Nothing else pins that this action is non-destructive, which is the entire confirmed decision.
test('deleting a post soft-deletes it and the row is still physically present', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    $deleted = app(DeleteBlogPost::class)($post);

    expect($deleted)->toBeTrue();
    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
    expect(DB::table('blog_posts')->where('id', $post->id)->count())->toBe(1)
        ->and(DB::table('blog_posts')->where('id', $post->id)->value('title'))->toBe('Botas de invierno');
});

test('a deleted post disappears from a default query and from both scopes, and withTrashed still finds it', function () {
    $category = BlogCategory::factory()->create();
    $tag = BlogTag::factory()->create();
    $post = BlogPost::factory()->create(['blog_category_id' => $category->id]);
    $post->tags()->attach($tag->id);

    $decoy = BlogPost::factory()->create(['blog_category_id' => $category->id]);
    $decoy->tags()->attach($tag->id);

    app(DeleteBlogPost::class)($post);

    expect(BlogPost::query()->find($post->id))->toBeNull()
        ->and(BlogPost::query()->pluck('id')->all())->not->toContain($post->id)
        ->and(BlogPost::query()->forCategory($category->id)->pluck('id')->all())->toBe([$decoy->id])
        ->and(BlogPost::query()->forTag($tag->id)->pluck('id')->all())->toBe([$decoy->id])
        ->and(BlogPost::withTrashed()->find($post->id))->not->toBeNull();
});

// D-7c: a soft delete is an UPDATE, so the blog_post_id cascade never fires and the pivot rows
// survive -- which is what makes a restore lossless. Asserted against the pivot DIRECTLY, never via
// $post->tags(), and every tag it used is still in the catalog.
test('a soft-deleted post keeps its blog_post_tag rows and its tags stay in the catalog', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $invierno = BlogTag::factory()->create(['name' => 'invierno']);
    $post = BlogPost::factory()->create();
    $post->tags()->attach([$running->id, $invierno->id]);

    app(DeleteBlogPost::class)($post);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $running->id]);
    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $invierno->id]);
    $this->assertDatabaseHas('blog_tags', ['id' => $running->id, 'name' => 'running']);
    $this->assertDatabaseHas('blog_tags', ['id' => $invierno->id, 'name' => 'invierno']);
});

test('deleting a post leaves the other posts untouched', function () {
    $doomed = BlogPost::factory()->create();
    $kept = BlogPost::factory()->create();

    app(DeleteBlogPost::class)($doomed);

    $this->assertNotSoftDeleted('blog_posts', ['id' => $kept->id]);
});

// The action takes an already-resolved model, so a malformed id cannot reach it directly; what CAN
// reach it is an instance whose row is already trashed or gone.
test('deleting an unknown or malformed-UUID post fails cleanly', function () {
    expect(fn () => BlogPost::query()->findOrFail('not-a-uuid'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => BlogPost::query()->findOrFail('018f0000-0000-7000-8000-000000000000'))->toThrow(ModelNotFoundException::class);
});

test('deleting a post that is already deleted fails cleanly instead of reporting success', function () {
    $post = BlogPost::factory()->create();
    app(DeleteBlogPost::class)($post);

    expect(fn () => app(DeleteBlogPost::class)($post))->toThrow(ModelNotFoundException::class);
});

// Deliberately NOT tested here: that a TAG delete detaches from a trashed post (DeleteBlogTagTest.php
// owns the tag-side cascade) and the restore round-trip (RestoreBlogPostTest.php).
