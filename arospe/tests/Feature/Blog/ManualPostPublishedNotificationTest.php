<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\NotifyBlogPostPublished;
use App\Actions\Blog\UpdateBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\BlogPostPublished;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

// Story 0065 -- Phase 5 review (2026-09-28) narrowed the "lean by design" claim below: 0061's
// tests/Feature/Blog/BlogPostPublishedNotificationTest.php owns the full Draft/Scheduled/Published
// transition matrix and every authorization/validation refusal EXCEPT six rows it never exercised --
// Draft->Draft (no status change), Scheduled->Scheduled (date edited, still future), a standalone
// Scheduled->Draft (un-scheduled), a standalone Published->Draft (unpublish), a CreateBlogPost
// authorization refusal, and a CreateBlogPost validation refusal -- which this file now covers
// directly, spy-only, matching 0061's own idiom (invocation count/argument, nothing about the
// notification's content).
//
// What this file adds on top of that: one UN-FAKED happy path per manual trigger, proving a REAL
// `notifications` row lands with the right notifiable_id/type/data for EVERY blog.view holder (0043's
// R-1: a faked assertion passes even against a `notifiable_id` column shape that could never store
// the row at all), and two rollback cases asserting BOTH a real row-count of zero AND that
// Illuminate\Notifications\Events\NotificationSending never fired. Both are needed together: the row
// count alone proves nothing landed in the end state, but a dispatch moved INSIDE the transaction
// would insert on the same connection the outer transaction later rolls back, so the `notifications`
// row-count would still read 0 even though the dispatch itself fired -- passing vacuously against
// exactly the regression this guards. NotificationSending fires synchronously the instant
// ChannelManager is about to deliver, regardless of whether the surrounding transaction later commits
// or rolls back, so it catches that regression where the row count cannot.

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.create', 'blog.edit']);
    $this->actingAs($this->actor);

    $this->recipient = User::factory()->create();
    $this->recipient->givePermissionTo('blog.view');
});

test('creating a post that is already published stores a real notification for each blog.view holder', function () {
    $secondRecipient = User::factory()->create();
    $secondRecipient->givePermissionTo('blog.view');

    $post = app(CreateBlogPost::class)(
        'Botas de invierno',
        '<p>Cuerpo</p>',
        BlogCategory::factory()->create()->id,
        'published',
        null,
        [],
    );

    foreach ([$this->recipient, $secondRecipient] as $recipient) {
        $row = DB::table('notifications')
            ->where('notifiable_id', $recipient->id)
            ->where('type', BlogPostPublished::class)
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->notifiable_type)->toBe(User::class);

        $data = json_decode($row->data, true);
        expect($data['blog_post_id'])->toBe($post->id);
    }
});

test('updating a draft into published stores a real notification for each blog.view holder', function () {
    $secondRecipient = User::factory()->create();
    $secondRecipient->givePermissionTo('blog.view');

    $post = BlogPost::factory()->draft()->create();

    app(UpdateBlogPost::class)(
        $post,
        $post->title,
        $post->body,
        $post->blog_category_id,
        'published',
        null,
        [],
    );

    foreach ([$this->recipient, $secondRecipient] as $recipient) {
        $row = DB::table('notifications')
            ->where('notifiable_id', $recipient->id)
            ->where('type', BlogPostPublished::class)
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->notifiable_type)->toBe(User::class);

        $data = json_decode($row->data, true);
        expect($data['blog_post_id'])->toBe($post->id);
    }
});

// D-15/D-19: a dispatch placed inside the transaction -- or above it -- would still announce a post
// that was never really saved once the outer, caller-opened transaction rolls back.
//
// Regression-proofed 2026-09-28 (Phase 5 review F-1): DB::afterCommit(...) was temporarily removed
// from App\Actions\Blog\CreateBlogPost, calling `($this->notifyBlogPostPublished)($post)` directly
// inside the transaction instead. Re-running THIS test with only the old
// `DB::table('notifications')->count())->toBe(0)` assertion still passed -- vacuously -- because the
// database INSERT the `database` channel performs runs on the very connection the outer transaction
// rolls back, so the row disappears either way. Adding the NotificationSending listener below turned
// the test red ("expected [] to be empty, but 1 item(s) found"), because that event fires
// synchronously the instant the notification is actually dispatched, before any transaction outcome
// is known. CreateBlogPost.php was then reverted to its committed state (confirmed via `git diff`)
// and this test is green again with no other change.
test('a create wrapped in a caller transaction that rolls back stores zero real notification rows', function () {
    $notificationSendingCount = 0;
    Event::listen(NotificationSending::class, function () use (&$notificationSendingCount): void {
        $notificationSendingCount++;
    });

    try {
        DB::transaction(function (): void {
            app(CreateBlogPost::class)(
                'Botas de invierno',
                '<p>Cuerpo</p>',
                BlogCategory::factory()->create()->id,
                'published',
                null,
                [],
            );

            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
        //
    }

    expect(DB::table('notifications')->count())->toBe(0)
        ->and(BlogPost::count())->toBe(0)
        ->and($notificationSendingCount)->toBe(0);
});

test('an update into published wrapped in a caller transaction that rolls back stores zero real notification rows', function () {
    $post = BlogPost::factory()->draft()->create();

    $notificationSendingCount = 0;
    Event::listen(NotificationSending::class, function () use (&$notificationSendingCount): void {
        $notificationSendingCount++;
    });

    try {
        DB::transaction(function () use ($post): void {
            app(UpdateBlogPost::class)(
                $post,
                $post->title,
                $post->body,
                $post->blog_category_id,
                'published',
                null,
                [],
            );

            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
        //
    }

    expect(DB::table('notifications')->count())->toBe(0)
        ->and($notificationSendingCount)->toBe(0);
});

// Phase 5 review F-2: six matrix rows 0061's BlogPostPublishedNotificationTest.php never exercised.
// Spy-only, matching that file's own idiom (invocation count/argument, nothing about the
// notification's content or channel) -- these six are not about the real row this file otherwise
// proves, only about whether the dispatch fires at all.

test('updating a draft with no status change dispatches nothing', function () {
    $post = BlogPost::factory()->draft()->create();
    $notifier = $this->spy(NotifyBlogPostPublished::class);

    app(UpdateBlogPost::class)(
        $post,
        $post->title,
        $post->body,
        $post->blog_category_id,
        'draft',
        null,
        [],
    );

    $notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('draft');
});

test('updating a scheduled post with its date edited but still in the future dispatches nothing', function () {
    $post = BlogPost::factory()->scheduled()->create();
    $notifier = $this->spy(NotifyBlogPostPublished::class);

    app(UpdateBlogPost::class)(
        $post,
        $post->title,
        $post->body,
        $post->blog_category_id,
        'scheduled',
        now()->addDays(3)->toDateTimeString(),
        [],
    );

    $notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('scheduled');
});

test('un-scheduling a post back into draft dispatches nothing', function () {
    $post = BlogPost::factory()->scheduled()->create();
    $notifier = $this->spy(NotifyBlogPostPublished::class);

    app(UpdateBlogPost::class)(
        $post,
        $post->title,
        $post->body,
        $post->blog_category_id,
        'draft',
        null,
        [],
    );

    $notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('draft');
});

test('unpublishing a published post back into draft dispatches nothing', function () {
    $post = BlogPost::factory()->published()->create();
    $notifier = $this->spy(NotifyBlogPostPublished::class);

    app(UpdateBlogPost::class)(
        $post,
        $post->title,
        $post->body,
        $post->blog_category_id,
        'draft',
        null,
        [],
    );

    $notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('draft');
});

test('creating a published post as an actor lacking blog.create is refused and dispatches nothing', function () {
    $limited = User::factory()->create();
    $limited->givePermissionTo('blog.edit');
    $this->actingAs($limited);

    $notifier = $this->spy(NotifyBlogPostPublished::class);

    expect(fn () => app(CreateBlogPost::class)(
        'Botas de invierno',
        '<p>Cuerpo</p>',
        BlogCategory::factory()->create()->id,
        'published',
        null,
        [],
    ))->toThrow(AuthorizationException::class);

    $notifier->shouldNotHaveReceived('__invoke');
    expect(BlogPost::count())->toBe(0);
});

test('creating a published post with a validation refusal dispatches nothing', function () {
    $notifier = $this->spy(NotifyBlogPostPublished::class);

    expect(fn () => app(CreateBlogPost::class)(
        'Botas de invierno',
        null,
        BlogCategory::factory()->create()->id,
        'published',
        null,
        [],
    ))->toThrow(ValidationException::class);

    $notifier->shouldNotHaveReceived('__invoke');
    expect(BlogPost::count())->toBe(0);
});
