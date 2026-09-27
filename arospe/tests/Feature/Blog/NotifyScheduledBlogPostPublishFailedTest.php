<?php

// Story 0064b, Phase 3 (TDD "red" step): App\Actions\Blog\NotifyScheduledBlogPostPublishFailed,
// App\Enums\BlogPostPublishFailureStage and App\Notifications\ScheduledBlogPostPublishFailed do not
// exist yet -- every test below is expected to fail with a "class not found" error until
// backend-expert implements D-2, D-3, D-6 and D-8.
//
// Exercised directly against app(NotifyScheduledBlogPostPublishFailed::class)($blogPostId) wherever
// possible, per D-3's own contract (a plain string id in, nothing out) and matching
// tests/Feature/Customers/NotifyCustomerCreatedTest.php's own "exercised directly, not only through
// the caller" design -- only the classification tests that must prove the SWEEP's own two failure
// mechanisms (a failing write, a throwing listener) go through the real
// `blog:publish-scheduled-posts` command.
//
// Conventions inherited from docs/testing/backend/scheduled-commands.md: freeze the clock in every
// case; NO actingAs() anywhere in this file (D-3: the action reads no actor, assert Auth::check() is
// false); force a write to fail with FailingBlogPostWrites::next(); the permission catalog is seeded
// explicitly in every test (Phase 2 test note under D-8) so Spatie's permission('blog.edit') exercises
// the real fallback query rather than throwing PermissionDoesNotExist by accident.

use App\Actions\Blog\NotifyScheduledBlogPostPublishFailed;
use App\Enums\BlogPostPublishFailureStage;
use App\Enums\BlogPostStatus;
use App\Enums\UserStatus;
use App\Events\Blog\ScheduledBlogPostPublished;
use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\ScheduledBlogPostPublishFailed;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Blog\FailingBlogPostWrites;
use Tests\Support\Blog\ScheduledPosts;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-09-26 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function pfReachableCreator(): User
{
    $user = User::factory()->create(['status' => UserStatus::Active]);
    $user->givePermissionTo('blog.edit');

    return $user;
}

function pfBlogEditAdministrator(): User
{
    $admin = User::factory()->create(['status' => UserStatus::Active]);
    $admin->givePermissionTo('blog.edit');

    return $admin;
}

function pfDedupKey(BlogPost $post, string $stage = 'publish'): string
{
    return sprintf('blog-publish-failed:%s:%s:%d', $post->id, $stage, (int) $post->fresh()->published_at->timestamp);
}

describe('classification (D-2)', function () {
    // Mutation: hard-code Announce.
    test('a due Scheduled post whose write fails yields stage publish, and the post stays Scheduled', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTo(
            $creator,
            ScheduledBlogPostPublishFailed::class,
            fn (ScheduledBlogPostPublishFailed $n): bool => $n->stage === BlogPostPublishFailureStage::Publish,
        );
        expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Scheduled);
    });

    // Both failure drivers throw the SAME exception class (RuntimeException) on purpose -- a
    // classifier that inspected the exception's type instead of re-reading the post could not tell
    // these two apart, and would necessarily get at least one of this test and the one above wrong.
    // Mutation: classify from the exception type instead of the re-read.
    test('a throwing listener on ScheduledBlogPostPublished yields stage announce, and the post stays Published', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        Event::listen(ScheduledBlogPostPublished::class, function (): never {
            throw new RuntimeException('listener failed');
        });

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTo(
            $creator,
            ScheduledBlogPostPublishFailed::class,
            fn (ScheduledBlogPostPublishFailed $n): bool => $n->stage === BlogPostPublishFailureStage::Announce,
        );
        expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Published);
    });

    // The documented limit (D-2): a concurrent manual publish between the failed write and the
    // re-read reads as announce, whatever caused it. Exercised directly against the notifier, since
    // reproducing a real race through the command is not the point -- the re-read logic is.
    test('a post that is Published when re-read is classified as announce, whatever caused the failure (documented limit)', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        DB::table('blog_posts')->where('id', $post->id)->update(['status' => BlogPostStatus::Published->value]);
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTo(
            $creator,
            ScheduledBlogPostPublishFailed::class,
            fn (ScheduledBlogPostPublishFailed $n): bool => $n->stage === BlogPostPublishFailureStage::Announce,
        );
    });

    // Risk: a "could not be published at its scheduled time" mail about a post that is no longer
    // scheduled or no longer exists. Mutation: drop the published_at <= now() clause; use
    // withTrashed().
    test('a post that is not (still) eligible reports nothing at all', function (Closure $makeBlogPostId) {
        Notification::fake();
        Log::spy();

        $id = $makeBlogPostId();

        app(NotifyScheduledBlogPostPublishFailed::class)($id);

        Notification::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
    })->with([
        'Scheduled but not yet due (rescheduled between the failure and the re-read)' => [
            fn (): string => ScheduledPosts::scheduled(secondsFromNow: 3600)->id,
        ],
        'Draft' => [fn (): string => BlogPost::factory()->draft()->create()->id],
        'a missing post' => [fn (): string => '00000000-0000-7000-8000-000000000000'],
        'a soft-deleted post' => [function (): string {
            $post = ScheduledPosts::scheduled();
            $post->delete();

            return $post->id;
        }],
    ]);

    // OQ-2(a): the same read supplies the title, so a failed re-read cannot produce a useful message
    // anyway -- report and skip, never rethrow.
    test('a failing re-read is reported, sends nothing, and does not rethrow', function () {
        $post = ScheduledPosts::scheduled();
        Notification::fake();
        Log::spy();

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'select') && str_contains($query, '`blog_posts`')) {
                throw new RuntimeException('Simulated re-read failure.');
            }
        });

        // Must not throw.
        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertNothingSent();
        Log::shouldHaveReceived('error')->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'Simulated re-read failure'));
    });
});

describe('recipients (D-8)', function () {
    // Risk: mail to a person who cannot act, or (for a soft-deleted user) to an obfuscated address
    // (V-9). Mutation: drop each predicate in turn; each dataset row must have a killer -- dropping
    // the predicate that made THIS row unreachable makes the assertSentTo($fallback, ...) below fail,
    // because the creator would be judged reachable and would receive it INSTEAD of the fallback.
    test('every unreachable-creator variant falls back to exactly the blog.edit administrator', function (Closure $makeUnreachableCreator) {
        $fallback = pfBlogEditAdministrator();
        $unreachable = $makeUnreachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $unreachable);
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTo($fallback, ScheduledBlogPostPublishFailed::class);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        if ($unreachable !== null) {
            Notification::assertNotSentTo($unreachable, ScheduledBlogPostPublishFailed::class);
        }
    })->with([
        'created_by is NULL (a legacy row)' => [fn (): ?User => null],
        'the creator is soft-deleted' => [function (): User {
            $u = User::factory()->create(['status' => UserStatus::Active]);
            $u->givePermissionTo('blog.edit');
            $u->delete();

            return $u;
        }],
        'the creator is Inactive' => [function (): User {
            $u = User::factory()->create(['status' => UserStatus::Inactive]);
            $u->givePermissionTo('blog.edit');

            return $u;
        }],
        'the creator is Suspended' => [function (): User {
            $u = User::factory()->create(['status' => UserStatus::Suspended]);
            $u->givePermissionTo('blog.edit');

            return $u;
        }],
        'the creator has an empty email' => [function (): User {
            $u = User::factory()->create(['status' => UserStatus::Active, 'email' => '']);
            $u->givePermissionTo('blog.edit');

            return $u;
        }],
        'the creator lost blog.edit (holds only blog.view)' => [function (): User {
            $u = User::factory()->create(['status' => UserStatus::Active]);
            $u->givePermissionTo('blog.view');

            return $u;
        }],
    ]);

    // Mutation: union the two sets.
    test('a reachable creator is the only recipient; the blog.edit administrators do not also get it', function () {
        $creator = pfReachableCreator();
        $administrator = pfBlogEditAdministrator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTo($creator, ScheduledBlogPostPublishFailed::class);
        Notification::assertNotSentTo($administrator, ScheduledBlogPostPublishFailed::class);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);
    });

    // Mutation: replace ->can() with hasPermissionTo()/a permission query.
    test('a Super Admin creator is reachable through Gate::before even with no explicit blog.edit grant', function () {
        $superAdmin = User::factory()->create(['status' => UserStatus::Active]);
        $superAdmin->assignRole('Super Admin');
        expect($superAdmin->getAllPermissions())->toHaveCount(0);

        $post = ScheduledPosts::scheduled(createdBy: $superAdmin);
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTo($superAdmin, ScheduledBlogPostPublishFailed::class);
    });

    // D-1/OQ-6: unlike the creator, the fallback set is a data query (permission()) that grants no
    // rows to the Super Admin's Gate::before bypass -- excluded deliberately, not a gap.
    test('a Super Admin holding no explicit blog.edit grant is not among the fallback recipients', function () {
        $post = ScheduledPosts::scheduled(overrides: ['created_by' => null]);
        $superAdmin = User::factory()->create(['status' => UserStatus::Active]);
        $superAdmin->assignRole('Super Admin');
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertNotSentTo($superAdmin, ScheduledBlogPostPublishFailed::class);
    });

    // Gherkin: "An administrator who can only view the blog is not told" -- the action item this
    // message carries (open the editor) needs blog.edit, so a view-only administrator gets nothing.
    test('an administrator holding only blog.view is not notified when the creator is unreachable', function () {
        $deletedCreator = User::factory()->create(['status' => UserStatus::Active]);
        $deletedCreator->givePermissionTo('blog.edit');
        $deletedCreator->delete();
        $post = ScheduledPosts::scheduled(createdBy: $deletedCreator);

        $viewOnlyAdmin = User::factory()->create(['status' => UserStatus::Active]);
        $viewOnlyAdmin->givePermissionTo('blog.view');
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertNotSentTo($viewOnlyAdmin, ScheduledBlogPostPublishFailed::class);
    });

    // Mutation: permission('blog.view'); drop the status filter. Combining all five exclusions in
    // one assertion means dropping ANY single guard lets that one row through, breaking
    // assertNothingSent() -- each excluded user is its own killer.
    test('the fallback excludes a Super Admin, a soft-deleted holder, a non-Active holder, an empty-email holder, and a view-only administrator', function () {
        $post = ScheduledPosts::scheduled(overrides: ['created_by' => null]);

        $superAdmin = User::factory()->create(['status' => UserStatus::Active]);
        $superAdmin->assignRole('Super Admin');

        $softDeleted = User::factory()->create(['status' => UserStatus::Active]);
        $softDeleted->givePermissionTo('blog.edit');
        $softDeleted->delete();

        $inactive = User::factory()->create(['status' => UserStatus::Inactive]);
        $inactive->givePermissionTo('blog.edit');

        $emptyEmail = User::factory()->create(['status' => UserStatus::Active, 'email' => '']);
        $emptyEmail->givePermissionTo('blog.edit');

        $viewOnly = User::factory()->create(['status' => UserStatus::Active]);
        $viewOnly->givePermissionTo('blog.view');

        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertNothingSent();
    });

    test('the fallback includes a role-granted and a directly-granted blog.edit holder', function () {
        $post = ScheduledPosts::scheduled(overrides: ['created_by' => null]);

        $role = Role::create(['name' => 'Blog Watcher', 'guard_name' => 'web']);
        $role->givePermissionTo('blog.edit');
        $viaRole = User::factory()->create(['status' => UserStatus::Active]);
        $viaRole->assignRole($role);

        $viaDirect = User::factory()->create(['status' => UserStatus::Active]);
        $viaDirect->givePermissionTo('blog.edit');

        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTo($viaRole, ScheduledBlogPostPublishFailed::class);
        Notification::assertSentTo($viaDirect, ScheduledBlogPostPublishFailed::class);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 2);
    });

    // The pseudocode's own fixed message and context shape, quoted verbatim from the task file.
    test('nobody reachable results in nothing sent and exactly one privacy-safe log line', function () {
        $post = ScheduledPosts::scheduled(overrides: ['created_by' => null]);
        Notification::fake();
        Log::spy();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with(
            'Scheduled blog post failure has no reachable recipient',
            ['blog_post_id' => $post->id, 'stage' => 'publish'],
        );
    });

    // Gherkin: "Nobody who can fix the post is reachable" -- through the real command, proving the
    // run itself completes without error, not merely that the notifier call does.
    test('when nobody is reachable, the whole sweep still completes without error', function () {
        ScheduledPosts::scheduled(overrides: ['created_by' => null]);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertNothingSent();
    });

    // Guards against a "send to all users" style bug: only the reachable blog.edit holder's row may
    // ever carry the title.
    test('only the reachable blog.edit holder gets a notification, and only theirs carries the title', function () {
        $blogEditHolder = pfBlogEditAdministrator();
        $bystander = User::factory()->create(['status' => UserStatus::Active]);
        $bystander->givePermissionTo('customers.view');
        $post = ScheduledPosts::scheduled(overrides: ['title' => 'Guía de invierno secreta']);

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        $rows = DB::table('notifications')->where('type', ScheduledBlogPostPublishFailed::class)->get();

        // notifications.data is a plain text() column (not native JSON), and DatabaseNotification's
        // 'data' => 'array' cast (which this raw DB::table() query bypasses) round-trips through
        // json_encode() with no JSON_UNESCAPED_UNICODE, so a non-ASCII title is stored \uXXXX-escaped
        // -- decode before asserting, matching CustomerCreatedNotificationTest.php's own precedent.
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->notifiable_id)->toBe($blogEditHolder->id)
            ->and(json_decode($rows->first()->data, true)['title'])->toBe('Guía de invierno secreta');

        expect(DB::table('notifications')->where('notifiable_id', $bystander->id)->exists())->toBeFalse();
    });
});

describe('dedup (D-6)', function () {
    // V-5 asserted rather than assumed, per the task file's own instruction, before the day-later
    // test below relies on it.
    test('the array cache store honours Carbon::setTestNow() for TTL expiry', function () {
        Cache::put('pf-probe-key', 1, now()->addDay());
        expect(Cache::has('pf-probe-key'))->toBeTrue();

        Carbon::setTestNow(now()->addDay()->addSecond());

        expect(Cache::has('pf-probe-key'))->toBeFalse();
    });

    // Mutation: remove the Cache::add guard.
    test('two failing ticks yield one notification, not two', function () {
        $creator = pfReachableCreator();
        ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next(2);

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);
    });

    // Gherkin: "The next run publishes the post once the fault is gone and says nothing more" --
    // distinct from the dedup-while-still-failing tests above: here the SECOND tick genuinely
    // succeeds (no forced failure), so the notifier must never be reached a second time at all,
    // dedup key aside.
    test('once the fault is fixed, the post publishes and the creator is not notified again', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Published);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);
    });

    // Mutation: drop the stage from the key.
    test('a publish-stage report does not suppress a later announce-stage report for the same post and episode', function () {
        $creator = pfReachableCreator();
        ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        Event::listen(ScheduledBlogPostPublished::class, function (): never {
            throw new RuntimeException('listener failed');
        });

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 2);
        Notification::assertSentTo(
            $creator,
            ScheduledBlogPostPublishFailed::class,
            fn (ScheduledBlogPostPublishFailed $n): bool => $n->stage === BlogPostPublishFailureStage::Announce,
        );
    });

    // Mutation: drop published_at from the key. published_at is not fillable, so the reschedule is
    // done through a raw update, exactly as PublishScheduledBlogPostTest.php seeds an impossible
    // application state directly.
    test('rescheduling the post starts a new dedup episode', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        // ScheduledPosts::scheduled() defaults published_at to now()->addSeconds(-60), i.e.
        // now()->subMinute() -- under this test's frozen clock, rescheduling to that exact same
        // expression would leave the dedup key (keyed on published_at->timestamp, D-6) unchanged, so
        // the second failure would be correctly suppressed as the same episode rather than exercising
        // "a new episode" at all. Must stay <= now() (the command's own due-selection clause) while
        // still differing from the original -60s offset.
        DB::table('blog_posts')->where('id', $post->id)->update(['published_at' => now()->subMinutes(5)]);
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 2);
    });

    // Documented limit, accepted (D-6).
    test('rescheduling to the exact same published_at within 24 hours stays suppressed', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        DB::table('blog_posts')->where('id', $post->id)->update(['published_at' => $post->fresh()->published_at]);
        FailingBlogPostWrites::next();
        Carbon::setTestNow(now()->addMinutes(10));

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);
    });

    // Mutation: now()->addYears(1).
    test('a day later, a still-failing post is reported again', function () {
        $creator = pfReachableCreator();
        ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        Carbon::setTestNow(now()->addDay()->addMinute());
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 2);
    });

    // A day later within the 24-hour window (23h59m) must still be suppressed -- the boundary from
    // the other side.
    test('a few minutes before the 24-hour window closes, a still-failing post is still suppressed', function () {
        $creator = pfReachableCreator();
        ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);
        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);

        Carbon::setTestNow(now()->addHours(23)->addMinutes(59));
        FailingBlogPostWrites::next();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);
    });

    test('a cache-write failure while claiming the dedup key is skipped and reported, never fatal', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();
        Log::spy();

        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('cache store down'));

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertNothingSent();
        Log::shouldHaveReceived('error')->once();
    });

    // Mutation: delete the Cache::forget.
    test('the dedup key is released when the notification send throws, so the next attempt can retry', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Log::spy();

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('send failed'));

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        expect(Cache::has(pfDedupKey($post)))->toBeFalse();

        Notification::fake();
        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTimes(ScheduledBlogPostPublishFailed::class, 1);
    });

    // QA's correction (D-3/OQ-4): claiming the key BEFORE resolving recipients would silence the
    // post for 24 hours the moment nobody is reachable, even after a reachable recipient later
    // appears. Mutation: claim before resolving.
    test('the dedup key is not claimed when there is no reachable recipient, so a later administrator still gets notified', function () {
        $post = ScheduledPosts::scheduled(overrides: ['created_by' => null]);
        Notification::fake();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);
        Notification::assertNothingSent();

        expect(Cache::has(pfDedupKey($post)))->toBeFalse();

        $administrator = pfBlogEditAdministrator();
        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Notification::assertSentTo($administrator, ScheduledBlogPostPublishFailed::class);
    });
});

describe('ungated system action (D-3)', function () {
    // Mutation: add a Gate::authorize() -- the notifier would then send nothing and look like a
    // quiet system, exactly the failure mode this test exists to catch.
    test('the notifier works with no authenticated actor and reads none', function () {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Notification::fake();

        expect(Auth::check())->toBeFalse();

        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        expect(Auth::check())->toBeFalse();
        Notification::assertSentTo($creator, ScheduledBlogPostPublishFailed::class);
    });

    // Containment: whatever breaks inside (the dispatcher, the cache, the recipient query),
    // __invoke() must still return normally and report() exactly once.
    test('containment: whatever throws inside, __invoke() returns normally and reports exactly once', function (Closure $breakSomething) {
        $creator = pfReachableCreator();
        $post = ScheduledPosts::scheduled(createdBy: $creator);
        Log::spy();
        $breakSomething();

        // Must not throw.
        app(NotifyScheduledBlogPostPublishFailed::class)($post->id);

        Log::shouldHaveReceived('error')->once();
    })->with([
        'the notification dispatcher throws' => [
            fn () => Notification::shouldReceive('send')->andThrow(new RuntimeException('dispatch broke')),
        ],
        'the cache add() throws' => [
            fn () => Cache::shouldReceive('add')->andThrow(new RuntimeException('cache broke')),
        ],
        'the recipient permission query throws' => [
            fn () => DB::connection()->beforeExecuting(function (string $query): void {
                if (str_contains($query, 'permission')) {
                    throw new RuntimeException('permission query broke');
                }
            }),
        ],
    ]);
});
