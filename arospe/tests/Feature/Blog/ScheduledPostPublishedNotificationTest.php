<?php

use App\Actions\Blog\PublishScheduledBlogPost;
use App\Models\User;
use App\Notifications\BlogPostPublished;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Blog\ScheduledPosts;

// Story 0065 -- the automatic trigger, end to end: 0064's sweep -> App\Events\Blog\
// ScheduledBlogPostPublished -> App\Listeners\SendBlogPostPublishedNotification ->
// NotifyBlogPostPublished -> a real `notifications` row.
//
// Deliberately UN-FAKED throughout (R-6): Notification::fake() and Event::fake() would both bypass
// the machinery this file exists to prove is actually wired -- in particular, that the listener is
// really REGISTERED, not merely correctly written. Driving the real sweep through the real event
// dispatcher and finding a real row is the only assertion in the whole story that catches an
// unregistered listener.
//
// No actingAs() anywhere in this file: 0064's D-5 makes the sweep deliberately ungated, and this
// story's own listener/action add no Auth-dependent step of their own.

beforeEach(function () {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $this->seed(RolePermissionSeeder::class);

    $this->recipient = User::factory()->create();
    $this->recipient->givePermissionTo('blog.view');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a due scheduled post swept by the scheduler stores a real notification for each blog.view holder', function () {
    $post = ScheduledPosts::scheduled();

    app(PublishScheduledBlogPost::class)($post->id);

    $row = DB::table('notifications')
        ->where('notifiable_id', $this->recipient->id)
        ->where('type', BlogPostPublished::class)
        ->first();

    expect($row)->not->toBeNull();

    $data = json_decode($row->data, true);
    expect($data['blog_post_id'])->toBe($post->id);
});

// The transitive half of 0064's D-5: catches a reflexive Auth/Gate call added to the listener or the
// notification later. Captured via NotificationSending -- the real event Laravel fires the instant a
// channel is about to deliver -- rather than merely checking Auth::check() before and after the
// call, which would miss a check that only happens transiently inside the chain.
test('the listener runs with no authenticated user', function () {
    expect(Auth::check())->toBeFalse();

    $post = ScheduledPosts::scheduled();

    $authChecksWhileSending = [];
    Event::listen(NotificationSending::class, function () use (&$authChecksWhileSending): void {
        $authChecksWhileSending[] = Auth::check();
    });

    app(PublishScheduledBlogPost::class)($post->id);

    expect($authChecksWhileSending)->not->toBeEmpty()
        ->and($authChecksWhileSending)->each->toBeFalse();
    expect(Auth::check())->toBeFalse();
});

// A batch-shaped implementation that notified one recipient about three posts in a single row would
// also satisfy a bare row-count-of-three assertion (R-10): assert the CONTENTS, distinguished by
// blog_post_id, not merely a count.
test('three due posts in one run produce three separate notifications distinguishable by blog_post_id', function () {
    $posts = [
        ScheduledPosts::scheduled(-30),
        ScheduledPosts::scheduled(-20),
        ScheduledPosts::scheduled(-10),
    ];

    foreach ($posts as $post) {
        app(PublishScheduledBlogPost::class)($post->id);
    }

    $rows = DB::table('notifications')
        ->where('notifiable_id', $this->recipient->id)
        ->where('type', BlogPostPublished::class)
        ->get();

    expect($rows)->toHaveCount(3);

    $announcedPostIds = $rows
        ->map(fn ($row) => json_decode($row->data, true)['blog_post_id'])
        ->sort()->values()->all();
    $expectedPostIds = collect($posts)->pluck('id')->sort()->values()->all();

    expect($announcedPostIds)->toBe($expectedPostIds);
});
