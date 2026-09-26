<?php

use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\PublishScheduledBlogPost;
use App\Actions\Blog\RestoreBlogPost;
use App\Enums\BlogPostStatus;
use App\Events\Blog\ScheduledBlogPostPublished;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\Blog\FailingBlogPostWrites;
use Tests\Support\Blog\ScheduledPosts;

// Story 0064, D-12: the cross-story contract with 0065. Split out of PublishScheduledBlogPostTest.php
// on purpose, so a reader arriving from 0065 finds the event's guarantees without wading through the
// sweep's own behavioural cases (the same reason 0061 split BlogPostStatusAndPublicationDateTest.php).
//
// Only the event's DISPATCH is asserted here, never its consequences: 0065 owns the listener, the
// notification and its recipients, and asserting one would be asserting a contract nothing has been
// built to fulfil. As in the sibling file, no case authenticates unless it must.
beforeEach(function () {
    Carbon::setTestNow('2026-09-26 12:00:00');
    Event::fake([ScheduledBlogPostPublished::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a successful transition dispatches the event exactly once, carrying that post', function () {
    $post = ScheduledPosts::scheduled();

    app(PublishScheduledBlogPost::class)($post->id);

    Event::assertDispatchedTimes(ScheduledBlogPostPublished::class, 1);
    Event::assertDispatched(ScheduledBlogPostPublished::class, fn (ScheduledBlogPostPublished $event): bool => $event->post->is($post)
        && $event->post->status === BlogPostStatus::Published);
});

// 0065's listener acts per post; a batch-shaped dispatch is invisible to a single-post test and would
// force 0065 to redesign around it.
test('three due posts dispatch three separate events, one per post', function () {
    $posts = collect([ScheduledPosts::scheduled(-30), ScheduledPosts::scheduled(-20), ScheduledPosts::scheduled(-10)]);

    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    Event::assertDispatchedTimes(ScheduledBlogPostPublished::class, 3);
    foreach ($posts as $post) {
        Event::assertDispatched(ScheduledBlogPostPublished::class, fn (ScheduledBlogPostPublished $event): bool => $event->post->is($post));
    }
});

// An implementation that dispatches unconditionally and leaves 0065's listener to filter is a
// different, worse design that still passes every status-only assertion.
test('nothing is dispatched for a post the sweep does not touch', function (Closure $seed) {
    $post = $seed();

    expect(app(PublishScheduledBlogPost::class)($post->id))->toBeNull();
    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    Event::assertNotDispatched(ScheduledBlogPostPublished::class);
})->with([
    'future-dated' => [fn (): BlogPost => ScheduledPosts::scheduled(3600)],
    'already published' => [fn (): BlogPost => BlogPost::factory()->published()->create()],
    'draft with a past date' => [function (): BlogPost {
        $draft = BlogPost::factory()->draft()->create();
        BlogPost::query()->whereKey($draft->id)->toBase()->update(['published_at' => now()->subDay()]);

        return $draft;
    }],
    'soft-deleted' => [function (): BlogPost {
        $post = ScheduledPosts::scheduled();
        $post->delete();

        return $post;
    }],
]);

// A duplicate row write is invisible; a duplicate notification is not.
test('a second sweep dispatches nothing further', function () {
    ScheduledPosts::scheduled();

    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    Event::assertDispatchedTimes(ScheduledBlogPostPublished::class, 1);
});

// 0061's D-20 makes this reachable: a restore leaves `status` at Published and fires `restored`, so an
// Eloquent observer keyed on either would re-announce a post its subscribers already heard about. This is
// the reason D-12 rules an observer out.
test('restoring a previously published post dispatches nothing', function () {
    $this->seed(RolePermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->givePermissionTo(['blog.view', 'blog.edit', 'blog.delete']);
    $this->actingAs($editor);

    $post = BlogPost::factory()->published()->create();
    app(DeleteBlogPost::class)($post);
    $trashed = BlogPost::withTrashed()->find($post->id);

    app(RestoreBlogPost::class)($trashed);

    expect(BlogPost::query()->find($post->id))->not->toBeNull();
    Event::assertNotDispatched(ScheduledBlogPostPublished::class);
});

// The order of the write and the dispatch is exactly what a later refactor reverses.
test('a failed write dispatches nothing and leaves the post Scheduled', function () {
    $post = ScheduledPosts::scheduled();
    FailingBlogPostWrites::next();

    expect(fn () => app(PublishScheduledBlogPost::class)($post->id))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(ScheduledBlogPostPublished::class);
    expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Scheduled);
});
