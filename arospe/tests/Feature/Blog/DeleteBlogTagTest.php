<?php

use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\FindOrCreateBlogTag;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

// Story 0059, Phase 3 (TDD "red" step). D-12: DeleteBlogTag authorizes itself first, so every test
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
