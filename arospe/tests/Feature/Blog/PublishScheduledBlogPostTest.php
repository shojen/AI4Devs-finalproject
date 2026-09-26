<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\PublishScheduledBlogPost;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Events\Blog\ScheduledBlogPostPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\Blog\ScheduledPosts;

// Story 0064: the action that flips ONE due Scheduled post to Published.
//
// Two disciplines. (a) Every case freezes the clock: a sweep is time-dependent by definition.
// (b) NO case calls actingAs() -- the action is deliberately ungated and reads no actor (D-5), so a
// test that authenticated out of habit would pass while proving nothing about the property that
// matters most. The one test that needs an actor (the no-gap proof) creates its post as one and then
// logs out before the sweep.
beforeEach(function () {
    Carbon::setTestNow('2026-09-26 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('core behaviour', function () {
    test('a Scheduled post whose publication time has passed becomes Published', function () {
        $post = ScheduledPosts::scheduled();

        $published = app(PublishScheduledBlogPost::class)($post->id);

        expect($published)->toBeInstanceOf(BlogPost::class)
            ->and($published->status)->toBe(BlogPostStatus::Published)
            ->and(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Published);
    });

    // D-11: 0061's D-6 stamps now() on a MANUAL Published save with no date. A sweep written by analogy
    // would overwrite every scheduled post's intended date with the tick time.
    test('the publication date its editor chose is not restamped', function () {
        $chosen = now()->subHours(3)->startOfMinute();
        $post = ScheduledPosts::scheduled(overrides: ['published_at' => $chosen]);

        Carbon::setTestNow(now()->addHour());
        $published = app(PublishScheduledBlogPost::class)($post->id);

        expect($published->published_at->equalTo($chosen))->toBeTrue()
            ->and(ScheduledPosts::row($post)['published_at'])->toBe($chosen->format('Y-m-d H:i:s'));
    });

    // The whole row, not a hand-picked list of columns: a sweep copy-adapted from UpdateBlogPost would
    // re-run a full save and touch columns it has no business touching.
    test('only status and updated_at change on the row', function () {
        $post = ScheduledPosts::scheduled(overrides: ['title' => 'Botas de invierno', 'body' => '<p>Cuerpo</p>']);
        $before = ScheduledPosts::row($post);

        Carbon::setTestNow(now()->addMinutes(5));
        app(PublishScheduledBlogPost::class)($post->id);
        $after = ScheduledPosts::row($post);

        $changed = array_keys(array_diff_assoc($after, $before));
        sort($changed);

        expect($changed)->toBe(['status', 'updated_at'])
            ->and($after['status'])->toBe('published')
            ->and($after['updated_at'])->toBe(now()->format('Y-m-d H:i:s'))
            ->and($after['title'])->toBe('Botas de invierno')
            ->and($after['slug'])->toBe($before['slug'])
            ->and($after['body'])->toBe('<p>Cuerpo</p>')
            ->and($after['blog_category_id'])->toBe($before['blog_category_id']);
    });

    // Asserted against the pivot directly, never via $post->tags(): UpdateBlogPost also runs
    // SyncBlogPostTags, so the same copy-adaptation would silently detach every tag.
    test('the post keeps its tags', function () {
        $post = ScheduledPosts::scheduled();
        $post->tags()->attach(BlogTag::factory()->count(3)->create()->modelKeys());
        $before = DB::table('blog_post_tag')->where('blog_post_id', $post->id)->orderBy('blog_tag_id')->pluck('blog_tag_id')->all();

        app(PublishScheduledBlogPost::class)($post->id);

        $after = DB::table('blog_post_tag')->where('blog_post_id', $post->id)->orderBy('blog_tag_id')->pluck('blog_tag_id')->all();
        expect($before)->toHaveCount(3)
            ->and($after)->toBe($before);
    });

    // The single executable proof of D-5. No actingAs() anywhere in this file's happy path, and the
    // guard is asserted rather than assumed, so a reflexive Gate::authorize('update', ...) added later
    // turns this red instead of silently publishing nothing forever.
    test('it succeeds with no authenticated actor', function () {
        $post = ScheduledPosts::scheduled();

        expect(Auth::check())->toBeFalse();

        $published = app(PublishScheduledBlogPost::class)($post->id);

        expect($published?->status)->toBe(BlogPostStatus::Published)
            ->and(Auth::check())->toBeFalse();
    });

    test('it returns the transitioned post, and null when the row was not eligible', function () {
        $due = ScheduledPosts::scheduled();
        $notDue = ScheduledPosts::scheduled(secondsFromNow: 3600);

        expect(app(PublishScheduledBlogPost::class)($due->id))->toBeInstanceOf(BlogPost::class)
            ->and(app(PublishScheduledBlogPost::class)($due->id))->toBeNull()
            ->and(app(PublishScheduledBlogPost::class)($notDue->id))->toBeNull()
            ->and(app(PublishScheduledBlogPost::class)('00000000-0000-7000-8000-000000000000'))->toBeNull();
    });

    // D-13: this write has no actor and no UI, so the log line is the only durable record it happened.
    test('a successful transition is logged with the post id and nothing else', function () {
        Log::spy();
        $post = ScheduledPosts::scheduled();

        app(PublishScheduledBlogPost::class)($post->id);

        Log::shouldHaveReceived('info')->once()->with('Scheduled blog post published', ['blog_post_id' => $post->id]);
    });

    test('a row that was not eligible logs nothing', function () {
        Log::spy();
        $post = ScheduledPosts::scheduled(secondsFromNow: 3600);

        app(PublishScheduledBlogPost::class)($post->id);

        Log::shouldNotHaveReceived('info');
    });
});

describe('what the sweep must not touch', function () {
    test('a Scheduled post whose time is still in the future is untouched', function () {
        $post = ScheduledPosts::scheduled(secondsFromNow: 3600);
        $before = ScheduledPosts::row($post);

        $result = app(PublishScheduledBlogPost::class)($post->id);

        expect($result)->toBeNull()
            ->and(ScheduledPosts::row($post))->toBe($before)
            ->and(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Scheduled);
    });

    // updated_at is what separates "returned early" from "rewrote the same value".
    test('an already Published post is not re-processed and its updated_at is unchanged', function () {
        $post = BlogPost::factory()->published()->create();
        $before = ScheduledPosts::row($post);

        Carbon::setTestNow(now()->addHour());
        $result = app(PublishScheduledBlogPost::class)($post->id);

        expect($result)->toBeNull()
            ->and(ScheduledPosts::row($post))->toBe($before);
    });

    // A Draft cannot legitimately carry a past date (0061's D-6 nulls it on every Draft save), so it is
    // seeded raw. A query missing its `status = Scheduled` predicate would sweep every row with a past
    // date, and the only rows that expose it are ones the happy path never creates.
    test('a Draft is never published, even when seeded with a past publication date', function () {
        $draft = BlogPost::factory()->draft()->create();
        DB::table('blog_posts')->where('id', $draft->id)->update(['published_at' => now()->subDay()]);
        $before = ScheduledPosts::row($draft);

        $result = app(PublishScheduledBlogPost::class)($draft->id);

        expect($result)->toBeNull()
            ->and(ScheduledPosts::row($draft))->toBe($before)
            ->and($before['status'])->toBe('draft');
    });

    // D-9, the sharpest correctness risk in the story. Asserted through withTrashed(): a default query
    // cannot see the row, so an assertDatabaseMissing-style check would pass for entirely the wrong reason.
    test('a soft-deleted Scheduled post whose time has passed is not published', function () {
        $post = ScheduledPosts::scheduled();
        $post->delete();
        $before = ScheduledPosts::row($post);

        $result = app(PublishScheduledBlogPost::class)($post->id);

        $trashed = BlogPost::withTrashed()->find($post->id);
        expect($result)->toBeNull()
            ->and($trashed->status)->toBe(BlogPostStatus::Scheduled)
            ->and($trashed->trashed())->toBeTrue()
            ->and(ScheduledPosts::row($post))->toBe($before);
    });
});

describe('the boundary, from both sides', function () {
    // Pins the operator as `<=`, not `<` (D-10).
    test('a post whose time is exactly the current instant is published', function () {
        $post = ScheduledPosts::scheduled(secondsFromNow: 0);

        expect(app(PublishScheduledBlogPost::class)($post->id))->not->toBeNull();
    });

    test('a post whose time is one second away is not published', function () {
        $post = ScheduledPosts::scheduled(secondsFromNow: 1);

        expect(app(PublishScheduledBlogPost::class)($post->id))->toBeNull()
            ->and(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Scheduled);
    });

    test('a post whose time was one second ago is published', function () {
        $post = ScheduledPosts::scheduled(secondsFromNow: -1);

        expect(app(PublishScheduledBlogPost::class)($post->id))->not->toBeNull();
    });

    // The only test proving the two stories' boundaries interlock. 0061 refuses to schedule at exactly
    // now() (strictly `>`); this story publishes at exactly now() (`<=`). With `<` there would be an
    // instant a post can be scheduled for but not published at, surfacing one tick late in production.
    test('the earliest time 0061 accepts for scheduling is published the instant it arrives', function () {
        $this->seed(RolePermissionSeeder::class);
        $editor = User::factory()->create();
        $editor->givePermissionTo(['blog.view', 'blog.create']);
        $this->actingAs($editor);

        $post = app(CreateBlogPost::class)(
            'Botas de invierno',
            '<p>Cuerpo</p>',
            BlogCategory::factory()->create()->id,
            BlogPostStatus::Scheduled->value,
            now()->addSecond()->toDateTimeString(),
            [],
        );
        expect($post->status)->toBe(BlogPostStatus::Scheduled);

        // Not yet due at T, due at T + 1s -- and by then nobody is logged in.
        Auth::forgetGuards();
        expect(app(PublishScheduledBlogPost::class)($post->id))->toBeNull();

        Carbon::setTestNow(now()->addSecond());

        expect(Auth::check())->toBeFalse()
            ->and(app(PublishScheduledBlogPost::class)($post->id)?->status)->toBe(BlogPostStatus::Published);
    });
});

describe('idempotency', function () {
    // A guard that returns early and a guard that rewrites the same value are indistinguishable on
    // `status` alone; updated_at is what tells them apart, so the clock moves between the two calls.
    test('calling it twice publishes once, and the second call writes nothing', function () {
        $post = ScheduledPosts::scheduled();

        $first = app(PublishScheduledBlogPost::class)($post->id);
        $afterFirst = ScheduledPosts::row($post);

        Carbon::setTestNow(now()->addMinutes(10));
        $second = app(PublishScheduledBlogPost::class)($post->id);

        expect($first)->not->toBeNull()
            ->and($second)->toBeNull()
            ->and(ScheduledPosts::row($post))->toBe($afterFirst);
    });
});

describe('when the announcement or the re-read goes wrong after the write', function () {
    // At-most-once, by design: the write is the commit point (D-7) and the announcement follows it (D-12).
    // A synchronous listener that throws (story 0065's is not queued) surfaces the exception to the caller,
    // but the post is already Published and the next tick will not retry it -- so the announcement is lost
    // and reported, never duplicated. Pinned so a later change to that trade-off is a decision, not drift.
    test('a listener that throws surfaces after the write and does not undo the publication', function () {
        Event::listen(ScheduledBlogPostPublished::class, function (): never {
            throw new RuntimeException('listener failed');
        });
        $post = ScheduledPosts::scheduled();

        expect(fn () => app(PublishScheduledBlogPost::class)($post->id))->toThrow(RuntimeException::class, 'listener failed');

        expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Published)
            ->and(app(PublishScheduledBlogPost::class)($post->id))->toBeNull();
    });

    // The post went live and was deleted in the same instant: announcing a deleted post is the worse mistake.
    test('a post deleted between the write and the re-read is not announced', function () {
        Event::fake([ScheduledBlogPostPublished::class]);
        $post = ScheduledPosts::scheduled();
        $deletedOnce = false;

        DB::listen(function ($query) use ($post, &$deletedOnce): void {
            if (! $deletedOnce && str_starts_with($query->sql, 'update `blog_posts` set `status`')) {
                $deletedOnce = true;
                DB::table('blog_posts')->where('id', $post->id)->update(['deleted_at' => now()]);
            }
        });

        expect(app(PublishScheduledBlogPost::class)($post->id))->toBeNull();

        Event::assertNotDispatched(ScheduledBlogPostPublished::class);
        expect(BlogPost::withTrashed()->find($post->id)->status)->toBe(BlogPostStatus::Published);
    });
});
