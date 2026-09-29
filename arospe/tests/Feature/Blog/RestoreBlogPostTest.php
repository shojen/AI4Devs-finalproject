<?php

use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\RestoreBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

// Story 0061, D-20: RestoreBlogPost is DeleteBlogPost's mirror image, gated on blog.edit (NOT
// blog.delete). The caller resolves the target with withTrashed(), since a default query cannot see it.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['blog.edit']);
    $this->actingAs($this->editor);
});

/**
 * A trashed post, deleted through the real action by an actor holding blog.delete, after which the
 * blog.edit actor is signed back in.
 */
function restoreTestTrashedPost(User $editor, array $attributes = []): BlogPost
{
    $post = BlogPost::factory()->create($attributes);

    test()->actingAs(User::factory()->create()->givePermissionTo('blog.delete'));
    app(DeleteBlogPost::class)($post);
    test()->actingAs($editor);

    return BlogPost::withTrashed()->findOrFail($post->id);
}

test('restoring a trashed post clears deleted_at and returns it to a default query', function () {
    $post = restoreTestTrashedPost($this->editor);

    expect(app(RestoreBlogPost::class)($post))->toBeTrue();

    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
    expect(BlogPost::query()->find($post->id))->not->toBeNull();
});

// Each half is covered by DeleteBlogPostTest.php; only the round trip proves they compose.
test('the round trip preserves title, body, category and tags', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $running = BlogTag::factory()->create(['name' => 'running']);
    $invierno = BlogTag::factory()->create(['name' => 'invierno']);
    $original = BlogPost::factory()->create([
        'title' => 'Botas de invierno',
        'body' => '<p>Cuerpo <img src="https://example.com/a.jpg" alt="a"></p>',
        'blog_category_id' => $category->id,
    ]);
    $original->tags()->attach([$running->id, $invierno->id]);

    test()->actingAs(User::factory()->create()->givePermissionTo('blog.delete'));
    app(DeleteBlogPost::class)($original);
    test()->actingAs($this->editor);

    app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($original->id));

    $restored = BlogPost::query()->findOrFail($original->id);

    expect($restored->title)->toBe('Botas de invierno')
        ->and($restored->body)->toBe('<p>Cuerpo <img src="https://example.com/a.jpg" alt="a"></p>')
        ->and($restored->blog_category_id)->toBe($category->id)
        ->and($restored->tags->pluck('id')->sort()->values()->all())->toBe(collect([$running->id, $invierno->id])->sort()->values()->all());
});

test('a restored post is still in the category it had', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = restoreTestTrashedPost($this->editor, ['blog_category_id' => $category->id]);

    app(RestoreBlogPost::class)($post);

    expect($post->fresh()->blog_category_id)->toBe($category->id);
});

// D-7b: pairs with the slug reservation. If a future change ever freed the slug on delete, this goes
// red instead of a restore silently 404-ing later.
test('a restored post reclaims its own slug, unchanged', function () {
    $post = restoreTestTrashedPost($this->editor, ['title' => 'Botas de invierno']);
    $slug = $post->slug;

    app(RestoreBlogPost::class)($post);

    expect($slug)->toBe('botas-de-invierno')
        ->and($post->fresh()->slug)->toBe('botas-de-invierno');
});

// D-7c / R-17: a tag hard-deleted while the post was trashed cascaded through the pivot, so the post
// restores with the surviving tags only. A second tag is the control, so "restores with no tags at
// all" cannot pass.
test('a tag deleted while the post was trashed does not come back', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $invierno = BlogTag::factory()->create(['name' => 'invierno']);
    $post = BlogPost::factory()->create();
    $post->tags()->attach([$running->id, $invierno->id]);

    test()->actingAs(User::factory()->create()->givePermissionTo('blog.delete'));
    app(DeleteBlogPost::class)($post);
    $running->delete();
    test()->actingAs($this->editor);

    app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id));

    $this->assertDatabaseMissing('blog_tags', ['id' => $running->id]);
    $this->assertDatabaseMissing('blog_post_tag', ['blog_tag_id' => $running->id]);
    expect($post->fresh()->tags->pluck('id')->all())->toBe([$invierno->id]);
});

// D-20: the second case is the whole content of the gating decision. A policy method that reused
// DELETE_PERMISSION by copy-paste would pass every test that only exercises a full-permission actor.
test('restoring is gated on blog.edit: blog.edit succeeds, blog.delete alone is refused', function () {
    $post = restoreTestTrashedPost($this->editor);

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.delete'));
    expect(fn () => app(RestoreBlogPost::class)($post))->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);

    $this->actingAs($this->editor);
    expect(app(RestoreBlogPost::class)($post))->toBeTrue();
    $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('an actor holding neither permission is refused and the post stays trashed', function () {
    $post = restoreTestTrashedPost($this->editor);

    $this->actingAs(User::factory()->create()->givePermissionTo(['blog.view', 'blog.create']));

    expect(fn () => app(RestoreBlogPost::class)($post))->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
});

test('restoring a post that is not trashed is a harmless no-op rather than an error', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect(app(RestoreBlogPost::class)($post))->toBeTrue();

    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Botas de invierno')
        ->and($fresh->deleted_at)->toBeNull();
});

test('the refusal is logged with target_type blog_post', function () {
    $post = restoreTestTrashedPost($this->editor);
    $actor = User::factory()->create()->givePermissionTo('blog.delete');
    $this->actingAs($actor);

    Log::spy();

    try {
        app(RestoreBlogPost::class)($post);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['actor_id'] === $actor->id
            && $context['ability'] === 'restore'
            && $context['target_type'] === 'blog_post'
            && $context['target_id'] === $post->id)
        ->once();
});

// Explicitly NOT tested: that a restore cannot dangle its blog_category_id. That is structurally
// impossible (D-20) -- restrictOnDelete() refuses to delete a category any trashed post references --
// so a test would assert a state the database cannot produce. The property is pinned from the other
// side by the trashed-post block case in DeleteBlogCategoryTest.php.

// Review finding: restore() ends in save(), which writes the whole dirty set, so restoring the caller's
// instance would persist an attribute the caller dirtied -- unsanitized body HTML included.
test('a dirtied caller instance cannot smuggle a column through a restore', function () {
    $post = restoreTestTrashedPost($this->editor, ['title' => 'Botas de invierno']);
    $bodyBefore = $post->body;
    $slugBefore = $post->slug;

    $post->body = '<script>alert(1)</script>';
    $post->slug = 'hijacked';

    app(RestoreBlogPost::class)($post);

    $fresh = BlogPost::query()->findOrFail($post->id);

    expect($fresh->body)->toBe($bodyBefore)
        ->and($fresh->slug)->toBe($slugBefore);
});

test('restoring a post whose row is gone fails cleanly', function () {
    $post = restoreTestTrashedPost($this->editor);
    $post->forceDelete();

    expect(fn () => app(RestoreBlogPost::class)($post))
        ->toThrow(ModelNotFoundException::class);
});
