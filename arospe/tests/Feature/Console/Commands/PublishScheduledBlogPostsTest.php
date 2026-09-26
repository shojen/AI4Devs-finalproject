<?php

use App\Enums\BlogPostStatus;
use App\Events\Blog\ScheduledBlogPostPublished;
use App\Models\BlogPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\Blog\FailingBlogPostWrites;
use Tests\Support\Blog\ScheduledPosts;

// Story 0064: the console entry point. Thin by design -- it proves the command SELECTS the due posts and
// DELEGATES each to PublishScheduledBlogPost, not that the transition is right through a second door
// (tests/Feature/Blog/PublishScheduledBlogPostTest.php owns that).
beforeEach(function () {
    Carbon::setTestNow('2026-09-26 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a run with nothing due exits 0 and says so', function () {
    ScheduledPosts::scheduled(3600);

    $this->artisan('blog:publish-scheduled-posts')
        ->expectsOutputToContain('No scheduled blog posts are due.')
        ->assertExitCode(0);
});

// One integration assertion that the command delegates to the action instead of carrying parallel logic.
test('it publishes a due post', function () {
    $post = ScheduledPosts::scheduled();

    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Published);
});

test('it reports how many posts it published', function () {
    ScheduledPosts::scheduled(-30);
    ScheduledPosts::scheduled(-20);

    $this->artisan('blog:publish-scheduled-posts')
        ->expectsOutputToContain('Published 2 scheduled blog posts.')
        ->assertExitCode(0);
});

test('it leaves a post scheduled for later alone', function () {
    $due = ScheduledPosts::scheduled();
    $later = ScheduledPosts::scheduled(3600);

    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    expect(BlogPost::query()->find($due->id)->status)->toBe(BlogPostStatus::Published)
        ->and(BlogPost::query()->find($later->id)->status)->toBe(BlogPostStatus::Scheduled);
});

test('running the whole sweep twice publishes N posts the first time and none the second', function () {
    ScheduledPosts::scheduled(-30);
    ScheduledPosts::scheduled(-20);
    ScheduledPosts::scheduled(-10);

    $this->artisan('blog:publish-scheduled-posts')
        ->expectsOutputToContain('Published 3 scheduled blog posts.')
        ->assertExitCode(0);
    $this->artisan('blog:publish-scheduled-posts')
        ->expectsOutputToContain('No scheduled blog posts are due.')
        ->assertExitCode(0);
});

// D-4's whole justification: without a per-post catch, one bad row silently blocks every scheduled post
// behind it on every subsequent tick, with the backlog growing behind it. The command publishes in
// `published_at` order, so the earlier post is the one whose write is made to fail.
test('a failure on one post does not stop the rest of the run, and does not fail it', function () {
    $first = ScheduledPosts::scheduled(-30);
    $second = ScheduledPosts::scheduled(-20);
    Event::fake([ScheduledBlogPostPublished::class]);
    FailingBlogPostWrites::next();

    $this->artisan('blog:publish-scheduled-posts')
        ->expectsOutputToContain('Published 1 scheduled blog post.')
        ->expectsOutputToContain('1 failed')
        ->assertExitCode(0);

    expect(BlogPost::query()->find($first->id)->status)->toBe(BlogPostStatus::Scheduled)
        ->and(BlogPost::query()->find($second->id)->status)->toBe(BlogPostStatus::Published);
    Event::assertDispatchedTimes(ScheduledBlogPostPublished::class, 1);
});

// A swallowed failure must still be visible somewhere: the exception is reported, not discarded.
test('a failed post is reported to the log rather than swallowed', function () {
    ScheduledPosts::scheduled();
    Log::spy();
    FailingBlogPostWrites::next();

    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Simulated blog_posts write failure'));
});

// OQ-3: the operator's safe look before enabling the first scheduled write in production.
test('--dry-run lists what is due and publishes and announces nothing', function () {
    $due = ScheduledPosts::scheduled();
    Event::fake([ScheduledBlogPostPublished::class]);
    $before = ScheduledPosts::row($due);

    $this->artisan('blog:publish-scheduled-posts', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 scheduled blog post is due.')
        ->expectsOutputToContain($due->id)
        ->assertExitCode(0);

    expect(ScheduledPosts::row($due))->toBe($before);
    Event::assertNotDispatched(ScheduledBlogPostPublished::class);
});

// The selection is observable nowhere else: the action's own guard makes a wrong selection harmless to the
// transition, so this is the only test that pins the command's query -- its status predicate, its due-time
// bound and its default soft-delete scope (D-9).
test('--dry-run lists only the due, non-deleted, Scheduled posts', function () {
    $due = ScheduledPosts::scheduled();
    $future = ScheduledPosts::scheduled(3600);
    $draft = BlogPost::factory()->draft()->create();
    BlogPost::query()->whereKey($draft->id)->toBase()->update(['published_at' => now()->subDay()]);
    $trashed = ScheduledPosts::scheduled();
    $trashed->delete();
    $published = BlogPost::factory()->published()->create();

    $this->artisan('blog:publish-scheduled-posts', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 scheduled blog post is due.')
        ->expectsOutputToContain($due->id)
        ->doesntExpectOutputToContain($future->id)
        ->doesntExpectOutputToContain($draft->id)
        ->doesntExpectOutputToContain($trashed->id)
        ->doesntExpectOutputToContain($published->id)
        ->assertExitCode(0);
});

test('--dry-run with nothing due says so', function () {
    $this->artisan('blog:publish-scheduled-posts', ['--dry-run' => true])
        ->expectsOutputToContain('No scheduled blog posts are due.')
        ->assertExitCode(0);
});

// D-13: a per-run summary alongside the per-post line, but only when something was due -- the command
// runs every minute, and 1,440 "nothing to do" lines a day would bury the ones that matter.
test('a run that published something logs a summary, and an empty run logs none', function () {
    Log::spy();

    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
    Log::shouldNotHaveReceived('info');

    ScheduledPosts::scheduled();
    $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

    Log::shouldHaveReceived('info')->with('Scheduled blog post sweep finished', ['published' => 1, 'failed' => 0])->once();
});
