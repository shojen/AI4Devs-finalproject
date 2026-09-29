<?php

// Story 0064b, Phase 3 (TDD "red" step): App\Notifications\ScheduledBlogPostPublishFailed and
// App\Enums\BlogPostPublishFailureStage do not exist yet -- every test below is expected to fail
// with a "class not found" error until backend-expert implements D-4, D-5, D-9 and D-10.
//
// Judgment call, recorded here and in this agent's final report: D-9 fixes the group name
// (notifications.mail.blog_post_publish_failed.*) and the `untitled` leaf's name explicitly, but not
// every leaf's exact key. This file pins six leaves as the contract the implementation must satisfy:
// `subject`, `untitled`, `line_publish`, `line_announce`, `edit_button`, `all_posts_link`. Any of
// these tests using trans('notifications.mail.blog_post_publish_failed.<key>') is deliberately tied
// to that exact key existing in BOTH lang/en/notifications.php and lang/es/notifications.php
// (key-for-key, D-9's own convention note) -- a differently-named key satisfying the same intent
// would need this test updated, which is expected and fine at TDD's "tests define the contract" step.

use App\Enums\BlogPostPublishFailureStage;
use App\Enums\UserStatus;
use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\ScheduledBlogPostPublishFailed;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Blog\FailingBlogPostWrites;
use Tests\Support\Blog\ScheduledPosts;

afterEach(function () {
    Carbon::setTestNow();
});

describe('payload privacy (D-4)', function () {
    // Risk: exception text or SQL reaching an inbox -- the worst realistic leak of this story.
    // Mutation: put $e->getMessage() in toArray(), in toMail(), or in the notifier's Log::warning.
    // Driven through the REAL command + notifier pipeline (not the notification class directly),
    // because the leak this guards against would happen at the boundary where the exception is
    // available -- the notifier -- not inside the notification class in isolation.
    test('the exception message never reaches the notification data, the rendered mail, or the notifier\'s own log lines', function () {
        Carbon::setTestNow('2026-09-26 12:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
        $creator = User::factory()->create(['status' => UserStatus::Active]);
        $creator->givePermissionTo('blog.edit');
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        $marker = 'MARKER-7f3a';

        Log::spy();
        DB::connection()->beforeExecuting(function (string $query) use ($marker): void {
            if (str_starts_with($query, 'update `blog_posts`')) {
                throw new RuntimeException("Simulated blog_posts write failure {$marker}");
            }
        });

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        $row = DB::table('notifications')
            ->where('notifiable_id', $creator->id)
            ->where('type', ScheduledBlogPostPublishFailed::class)
            ->first();
        expect($row)->not->toBeNull()
            ->and($row->data)->not->toContain($marker);

        // Not a blanket count: RolePermissionSeeder (seeded above) also sends a real password-reset
        // email to the Super Admin whenever it freshly provisions that account on a clean database
        // (RefreshDatabase gives every test one), so the transport legitimately holds 2 messages here.
        // Narrow to the one addressed to $creator -- still a meaningful assertion, since it would
        // still catch a real double-send bug for the notification under test.
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $messagesToCreator = collect($messages)->filter(function ($sentMessage) use ($creator): bool {
            $to = $sentMessage->getOriginalMessage()->getTo();

            return collect($to)->contains(fn ($address) => $address->getAddress() === $creator->email);
        })->values();
        expect($messagesToCreator)->toHaveCount(1);
        $raw = $messagesToCreator[0]->getOriginalMessage();
        expect((string) $raw->getHtmlBody())->not->toContain($marker)
            ->and((string) $raw->getTextBody())->not->toContain($marker)
            ->and((string) $raw->getSubject())->not->toContain($marker);

        // The command's own report() line IS allowed to carry the marker (0064's existing operator
        // log, not a new sink) -- only the notifier's own `warning`-level line must never carry it,
        // and in this scenario (a reachable recipient) that line is never even written.
        Log::shouldNotHaveReceived('warning', function (string $message, array $context = []) use ($marker): bool {
            return str_contains($message, $marker) || str_contains((string) json_encode($context), $marker);
        });
    });

    // Mutation: an added key is permanent in a column with no update path (D-4).
    test('toArray() returns exactly blog_post_id, title and stage', function () {
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'Some Title', BlogPostPublishFailureStage::Publish);

        expect($notification->toArray(User::factory()->make()))->toBe([
            'blog_post_id' => 'post-id',
            'title' => 'Some Title',
            'stage' => 'publish',
        ]);
    });

    // D-4: no SerializesModels, no model in the constructor -- primitives serialise exactly and
    // cannot be rehydrated stale.
    test('the serialised notification holds no Eloquent model and no exception', function () {
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'Some Title', BlogPostPublishFailureStage::Announce);

        $serialized = serialize($notification);

        expect($serialized)->not->toContain('App\\Models\\')
            ->and($serialized)->not->toContain('Throwable');
    });
});

describe('copy parity (V-13)', function () {
    // tests/Feature/Notifications/BellTest.php already asserts es keys ⊇ en keys, one direction.
    // This adds the reverse across the WHOLE file, so the new mail.blog_post_publish_failed.* group
    // is covered in both directions without depending on this test knowing its exact leaf names.
    test('every notifications translation key in es/ exists in en/ too (the reverse of BellTest)', function () {
        expect(Arr::dot(require lang_path('en/notifications.php')))
            ->toHaveKeys(array_keys(Arr::dot(require lang_path('es/notifications.php'))));
    });
});

describe('subject, per-stage lines, and locale (D-9)', function () {
    test('the subject is built from the subject key with the title interpolated, and stays one line', function () {
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'Guía de invierno', BlogPostPublishFailureStage::Publish);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe(trans('notifications.mail.blog_post_publish_failed.subject', ['title' => 'Guía de invierno']))
            ->and(str_contains((string) $mail->subject, "\n"))->toBeFalse();
    });

    // The dataset carries the stage as a plain string (never the enum case itself): a dataset array
    // is evaluated eagerly when this file is collected, before BlogPostPublishFailureStage exists --
    // referencing the enum case directly here would abort collection of the WHOLE file with a class-
    // not-found error rather than failing this one test. BlogPostPublishFailureStage::from() below
    // defers the reference to test-run time, same as every other enum reference in this file (all of
    // which already sit inside a closure body).
    test('each stage renders its own distinct line', function (string $stageValue, string $key) {
        $stage = BlogPostPublishFailureStage::from($stageValue);
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'Guía de invierno', $stage);
        $html = (string) $notification->toMail(User::factory()->make())->render();

        expect($html)->toContain(trans("notifications.mail.blog_post_publish_failed.{$key}"));
    })->with([
        'publish' => ['publish', 'line_publish'],
        'announce' => ['announce', 'line_announce'],
    ]);

    test('the two stage lines are worded differently from each other', function () {
        expect(trans('notifications.mail.blog_post_publish_failed.line_publish'))
            ->not->toBe(trans('notifications.mail.blog_post_publish_failed.line_announce'));
    });

    // Belt-and-braces on top of the key-based tests above: the ACTUAL required content, independent
    // of whatever key names the implementation settles on, matching the acceptance criteria verbatim
    // ("publish mentions the automatic retry and the 24-hour quiet period; announce mentions the
    // follow-up notification").
    test('the publish line mentions the 24-hour quiet period, and the announce line mentions the follow-up notification', function () {
        $publishHtml = (string) (new ScheduledBlogPostPublishFailed('id', 'T', BlogPostPublishFailureStage::Publish))
            ->toMail(User::factory()->make())->render();
        $announceHtml = (string) (new ScheduledBlogPostPublishFailed('id', 'T', BlogPostPublishFailureStage::Announce))
            ->toMail(User::factory()->make())->render();

        expect($publishHtml)->toContain('24')
            ->and(mb_strtolower($announceHtml))->toContain('follow-up')
            ->and($publishHtml)->not->toBe($announceHtml);
    });
});

describe('the buttons (D-10)', function () {
    // Mutation: link tests stop at the route name -- these assert url(route(...)), never rendering
    // 0063's own screens.
    test('the primary button reads "Edit post" and points at the edit route; the set of hrefs is exactly the two real URLs', function () {
        $post = BlogPost::factory()->create();
        $notification = new ScheduledBlogPostPublishFailed($post->id, $post->title, BlogPostPublishFailureStage::Publish);
        $mail = $notification->toMail(User::factory()->make());
        $html = (string) $mail->render();

        $editUrl = url(route('blog-posts.edit', $post));
        $indexUrl = url(route('blog-posts.index'));

        preg_match_all('/href="([^"]+)"/', $html, $matches);
        $hrefs = collect($matches[1])
            ->reject(fn (string $href): bool => $href === config('app.url'))
            ->unique()->values()->all();

        expect($mail->actionText)->toBe(trans('notifications.mail.blog_post_publish_failed.edit_button'))
            ->and($mail->actionText)->toBe('Edit post')
            ->and($mail->actionUrl)->toBe($editUrl)
            ->and($hrefs)->toEqualCanonicalizing([$editUrl, $indexUrl]);
    });

    test('a plain "All posts" link points at the posts index route', function () {
        $post = BlogPost::factory()->create();
        $notification = new ScheduledBlogPostPublishFailed($post->id, $post->title, BlogPostPublishFailureStage::Publish);
        $html = (string) $notification->toMail(User::factory()->make())->render();

        expect($html)->toContain(trans('notifications.mail.blog_post_publish_failed.all_posts_link'))
            ->and($html)->toContain(url(route('blog-posts.index')));
    });
});

describe('a hostile title cannot inject markup or links (D-9, R-4)', function () {
    // Risk: a phishing link inside a trusted email. V-7: Markdown::render HTML-encodes < and > but
    // does not neutralise Markdown syntax. Mutation: pass the raw title to line() and to subject().
    test('a markdown link in the title never becomes a real link, and the visible text still shows it as plain text', function () {
        $post = BlogPost::factory()->create();
        $title = '[x](https://evil.example)';
        $notification = new ScheduledBlogPostPublishFailed($post->id, $title, BlogPostPublishFailureStage::Publish);
        $mail = $notification->toMail(User::factory()->make());
        $html = (string) $mail->render();

        preg_match_all('/href="([^"]+)"/', $html, $matches);
        $hrefs = collect($matches[1])
            ->reject(fn (string $href): bool => $href === config('app.url'))
            ->unique()->values()->all();

        $editUrl = url(route('blog-posts.edit', $post));
        $indexUrl = url(route('blog-posts.index'));

        expect($hrefs)->toEqualCanonicalizing([$editUrl, $indexUrl])
            ->and(strip_tags($html))->toContain('evil.example')
            ->and(str_contains((string) $mail->subject, "\n"))->toBeFalse();
    });

    test('HTML tags in the title never become real elements, but the visible words survive', function () {
        $post = BlogPost::factory()->create();
        $title = '<b>bold</b> and <script>alert(1)</script>';
        $notification = new ScheduledBlogPostPublishFailed($post->id, $title, BlogPostPublishFailureStage::Publish);
        $html = (string) $notification->toMail(User::factory()->make())->render();

        expect($html)->not->toContain('<b>bold</b>')
            ->and($html)->not->toContain('<script>')
            ->and(strip_tags($html))->toContain('bold');
    });

    test('a title with an embedded newline still produces a single-line subject', function () {
        $post = BlogPost::factory()->create();
        $title = "Line one\nLine two";
        $notification = new ScheduledBlogPostPublishFailed($post->id, $title, BlogPostPublishFailureStage::Publish);
        $mail = $notification->toMail(User::factory()->make());

        expect(str_contains((string) $mail->subject, "\n"))->toBeFalse();
    });

    // D-9: "a null/blank title uses the fallback wording."
    test('a null title renders the untitled fallback, never the word null or empty quotes', function () {
        $notification = new ScheduledBlogPostPublishFailed('post-id', null, BlogPostPublishFailureStage::Publish);
        $mail = $notification->toMail(User::factory()->make());
        $html = (string) $mail->render();

        expect(mb_strtolower((string) $mail->subject))->not->toContain('null')
            ->and($mail->subject)->not->toContain('""')
            ->and($mail->subject)->not->toContain("''")
            ->and($mail->subject.$html)->toContain(trans('notifications.mail.blog_post_publish_failed.untitled'));
    });
});

describe('channels and queueing (D-5)', function () {
    // Mutation: any channel added or removed here changes what a worker-less deployment silently
    // drops (V-6, R-1).
    test('via() returns exactly database and mail, and the class implements ShouldQueue', function () {
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->via(User::factory()->make()))->toBe(['database', 'mail'])
            ->and($notification)->toBeInstanceOf(ShouldQueue::class);
    });

    // The fixture must satisfy shouldSend()'s D-8 predicate (Phase 4 finding L-1), or
    // NotificationFake::sendNow() skips recording it entirely -- a bare, permission-less user is no
    // longer a valid "this notification is deliverable" fixture, matching the payload-privacy test's
    // own setup above.
    test('Notification::fake() intercepts it and assertSentTo works', function () {
        Notification::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');

        Notification::send($user, new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish));

        Notification::assertSentTo($user, ScheduledBlogPostPublishFailed::class);
    });

    // V-6: ShouldQueue queues every channel -- 2 recipients x 2 channels = 4 jobs.
    test('one SendQueuedNotifications job is pushed per channel per recipient', function () {
        Queue::fake();
        $recipients = User::factory()->count(2)->create();

        Notification::send($recipients, new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish));

        Queue::assertPushed(SendQueuedNotifications::class, 4);
    });
});

describe('shouldSend() re-checks the D-8 predicate at delivery time (Phase 4 finding L-1)', function () {
    // NotifyScheduledBlogPostPublishFailed::recipients() checks reachability once, at dispatch time,
    // before this ShouldQueue notification is ever pushed. shouldSend() is the SECOND check, run again
    // when a queue worker actually delivers each channel -- the regression net for a recipient who was
    // reachable at dispatch but becomes unreachable during the queue-latency window (soft-deleted,
    // suspended, or stripped of blog.edit) before the job runs.
    //
    // Exercised by calling shouldSend() directly against notifiable states, rather than by manufacturing
    // a real queue-latency window: phpunit.xml pins QUEUE_CONNECTION=sync (per V-8), so a notification
    // dispatched in this suite is delivered inline, in the same process, with no window to mutate the
    // recipient inside. Calling the method directly is also what the vulnerability itself is about --
    // NewQueryForRestoration's own scope-bypassing rehydration hands shouldSend() exactly a $notifiable
    // in one of these states, and the method must answer correctly given that instance alone.
    beforeEach(function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
    });

    test('a reachable recipient (active, not trashed, holds blog.edit, real email) is sent to on every channel', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend($user, 'database'))->toBeTrue()
            ->and($notification->shouldSend($user, 'mail'))->toBeTrue();
    });

    // Models NewQueryForRestoration rehydrating a since-soft-deleted recipient with every global scope
    // (including SoftDeletingScope) bypassed -- the exact mechanism the docblock names.
    test('a since-soft-deleted recipient is refused on every channel', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');
        $user->delete();
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend($user, 'database'))->toBeFalse()
            ->and($notification->shouldSend($user, 'mail'))->toBeFalse();
    });

    test('a since-suspended recipient is refused', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');
        $user->status = UserStatus::Suspended;
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend($user, 'database'))->toBeFalse();
    });

    test('a since-deactivated recipient is refused', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');
        $user->status = UserStatus::Inactive;
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend($user, 'database'))->toBeFalse();
    });

    // Models blog.edit being revoked (or a role change dropping it) after dispatch but before delivery.
    test('a recipient who has since lost blog.edit is refused', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');
        $user->revokePermissionTo('blog.edit');
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend($user, 'database'))->toBeFalse();
    });

    test('a recipient with a blank email is refused', function () {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->givePermissionTo('blog.edit');
        $user->email = '';
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend($user, 'database'))->toBeFalse();
    });

    // The instanceof User guard must fail CLOSED (false), never fatal, against a notifiable this
    // notification's own via() channels were never designed for.
    test('a non-User notifiable is refused, never fatal', function () {
        $notification = new ScheduledBlogPostPublishFailed('post-id', 'T', BlogPostPublishFailureStage::Publish);

        expect($notification->shouldSend(new AnonymousNotifiable, 'database'))->toBeFalse();
    });
});

describe('one un-faked end-to-end test', function () {
    // Nothing faked: catches a via() typo or a broken toMail() that every faked test above hides.
    // QUEUE_CONNECTION=sync and MAIL_MAILER=array (V-8) mean the queued notification really runs and
    // really lands in the array transport within this one process.
    test('a real failing sweep leaves a real notifications row and a real mail message addressed to the creator', function () {
        Carbon::setTestNow('2026-09-26 12:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
        $creator = User::factory()->create(['status' => UserStatus::Active, 'email' => 'creator-e2e@example.com']);
        $creator->givePermissionTo('blog.edit');
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        $row = DB::table('notifications')
            ->where('notifiable_id', $creator->id)
            ->where('type', ScheduledBlogPostPublishFailed::class)
            ->first();
        expect($row)->not->toBeNull();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $addressedToCreator = collect($messages)->first(function ($sentMessage) use ($creator): bool {
            $to = $sentMessage->getOriginalMessage()->getTo();

            return collect($to)->contains(fn ($address) => $address->getAddress() === $creator->email);
        });

        expect($addressedToCreator)->not->toBeNull();
    });
});
