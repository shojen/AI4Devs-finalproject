<?php

use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\FindOrCreateBlogTag;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

// Story 0059. D-12: DeleteBlogTag authorizes itself first, so every test
// runs actingAs() an actor holding blog.delete (plus create/view for the reuse tests).
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.delete', 'blog.create', 'blog.view']);
    $this->actingAs($this->actor);
});

test('deleting a tag removes the row outright, not as a soft delete', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    $deleted = app(DeleteBlogTag::class)($tag);

    expect($deleted)->toBeTrue();
    $this->assertDatabaseMissing('blog_tags', ['id' => $tag->id]);
});

// Nothing lingers to hold the unique index -- exactly what a soft delete would have broken (D-5).
test('the freed name can immediately be reused by a new tag and by FindOrCreateBlogTag', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    app(DeleteBlogTag::class)($tag);

    $reused = app(CreateBlogTag::class)('running');

    expect($reused->fresh()->name)->toBe('running')
        ->and(BlogTag::count())->toBe(1);

    app(DeleteBlogTag::class)($reused);

    $resolved = app(FindOrCreateBlogTag::class)('Running');

    expect($resolved->wasRecentlyCreated)->toBeTrue()
        ->and($resolved->id)->not->toBe($tag->id)
        ->and(BlogTag::count())->toBe(1);
});

// The action takes an already-resolved model, so an unknown id cannot reach it directly; what CAN
// reach it is an instance whose row has since gone. A silent no-op reported as success is the failure.
test('deleting a tag whose row is already gone fails cleanly instead of reporting success', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    app(DeleteBlogTag::class)($tag);

    expect(fn () => app(DeleteBlogTag::class)($tag))->toThrow(ModelNotFoundException::class);
});

test('a malformed or unknown id never resolves to a tag to delete', function () {
    expect(fn () => BlogTag::query()->findOrFail('not-a-uuid'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new BlogTag)->resolveRouteBinding('not-a-uuid'))->toThrow(ModelNotFoundException::class);
});

test('deleting one tag leaves the others untouched', function () {
    $doomed = BlogTag::factory()->create(['name' => 'running']);
    $kept = BlogTag::factory()->create(['name' => 'invierno']);

    app(DeleteBlogTag::class)($doomed);

    $this->assertDatabaseHas('blog_tags', ['id' => $kept->id, 'name' => 'invierno']);
});

// D-8: the ABSENCE of an in-use guard is this story's contract, so it is asserted positively. A tag
// that has been resolved for reuse again and again is the nearest thing to "in use" that exists before
// story 0061 creates blog_posts; the delete still succeeds unconditionally.
//
// Honest limit (R-6): this cannot prove the post-side half -- "removed from every post that used it"
// is honoured by the blog_post_tag pivot's cascadeOnDelete(), which story 0061 owns and must test.
test('deleting is unconditional: a tag that has been reused many times is still deleted', function () {
    $tag = app(CreateBlogTag::class)('running');

    foreach (['running', 'Running', ' RUNNING '] as $name) {
        expect(app(FindOrCreateBlogTag::class)($name)->id)->toBe($tag->id);
    }

    expect(app(DeleteBlogTag::class)($tag))->toBeTrue()
        ->and(BlogTag::count())->toBe(0);
});

// --- Story 0061: the post-side half of PRD "deleting a tag removes it from every post" (0059's R-6) ---
//
// Honest against the REAL blog_post_tag pivot, never a stub. The cascade is the database's:
// blog_tag_id is cascadeOnDelete(), and these tests go red if anyone writes restrictOnDelete() there.

test('deleting a tag attached to several posts removes exactly its pivot rows', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $invierno = BlogTag::factory()->create(['name' => 'invierno']);

    foreach (BlogPost::factory()->count(3)->create() as $post) {
        $post->tags()->attach([$running->id, $invierno->id]);
    }

    expect(app(DeleteBlogTag::class)($running))->toBeTrue();

    $this->assertDatabaseMissing('blog_post_tag', ['blog_tag_id' => $running->id]);
    $this->assertDatabaseMissing('blog_tags', ['id' => $running->id]);
    expect(DB::table('blog_post_tag')->where('blog_tag_id', $invierno->id)->count())->toBe(3);
});

// The control: without it, a bug that deletes the POST instead of detaching the tag still passes a
// "the pivot row is gone" assertion.
test('every post that carried the deleted tag survives, untouched, with its other tags intact', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $invierno = BlogTag::factory()->create(['name' => 'invierno']);
    $posts = BlogPost::factory()->count(3)->create();

    foreach ($posts as $post) {
        $post->tags()->attach([$running->id, $invierno->id]);
    }

    app(DeleteBlogTag::class)($running);

    foreach ($posts as $post) {
        $this->assertNotSoftDeleted('blog_posts', ['id' => $post->id, 'title' => $post->title]);
        $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $invierno->id]);
        expect($post->fresh()->tags->pluck('id')->all())->toBe([$invierno->id]);
    }

    expect(BlogPost::count())->toBe(3);
});

test('deleting a tag leaves another post\'s unrelated tag associations alone', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $other = BlogTag::factory()->create(['name' => 'invierno']);
    $decoy = BlogPost::factory()->create();
    $decoy->tags()->attach($other->id);
    BlogPost::factory()->create()->tags()->attach($running->id);

    app(DeleteBlogTag::class)($running);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $decoy->id, 'blog_tag_id' => $other->id]);
});

// D-7c: the tag-side cascade is unaffected by the post-side soft delete, because tags still
// hard-delete. Otherwise a restored post would come back carrying a tag the catalog no longer has.
test('deleting a tag also detaches it from a trashed post', function () {
    $running = BlogTag::factory()->create(['name' => 'running']);
    $post = BlogPost::factory()->create();
    $post->tags()->attach($running->id);
    $post->delete();

    app(DeleteBlogTag::class)($running);

    $this->assertDatabaseMissing('blog_post_tag', ['blog_tag_id' => $running->id]);
    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
});
