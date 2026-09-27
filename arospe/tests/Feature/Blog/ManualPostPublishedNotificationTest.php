<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\BlogPostPublished;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

// Story 0065 -- LEAN by design (Phase 2 resolution, 2026-09-27). The full Draft/Scheduled/Published
// transition matrix in BOTH directions, every row, the create-path rows, the stale-instance race
// (R-1) and every authorization/validation refusal are already covered by
// tests/Feature/Blog/BlogPostPublishedNotificationTest.php (story 0061), which spies on
// NotifyBlogPostPublished and asserts invocation count/argument only, green today. This file does
// NOT re-run any of those rows.
//
// What this file adds instead: one UN-FAKED happy path per manual trigger, proving a REAL
// `notifications` row lands with the right notifiable_id/type/data (0043's R-1: a faked assertion
// passes even against a `notifiable_id` column shape that could never store the row at all), and the
// two rollback cases with a REAL row-count assertion rather than a fake-only assertNothingSent() --
// a dispatch moved inside the transaction would still pass a fake-based check trivially once
// Notification::fake() is in play, because faking intercepts delivery before any row could ever be
// written either way.

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.create', 'blog.edit']);
    $this->actingAs($this->actor);

    $this->recipient = User::factory()->create();
    $this->recipient->givePermissionTo('blog.view');
});

test('creating a post that is already published stores a real notification for each blog.view holder', function () {
    $post = app(CreateBlogPost::class)(
        'Botas de invierno',
        '<p>Cuerpo</p>',
        BlogCategory::factory()->create()->id,
        'published',
        null,
        [],
    );

    $row = DB::table('notifications')
        ->where('notifiable_id', $this->recipient->id)
        ->where('type', BlogPostPublished::class)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->notifiable_type)->toBe(User::class);

    $data = json_decode($row->data, true);
    expect($data['blog_post_id'])->toBe($post->id);
});

test('updating a draft into published stores a real notification for each blog.view holder', function () {
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

    $row = DB::table('notifications')
        ->where('notifiable_id', $this->recipient->id)
        ->where('type', BlogPostPublished::class)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->notifiable_type)->toBe(User::class);

    $data = json_decode($row->data, true);
    expect($data['blog_post_id'])->toBe($post->id);
});

// D-15/D-19: a dispatch placed inside the transaction -- or above it -- would still announce a post
// that was never really saved once the outer, caller-opened transaction rolls back.
test('a create wrapped in a caller transaction that rolls back stores zero real notification rows', function () {
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
        ->and(BlogPost::count())->toBe(0);
});

test('an update into published wrapped in a caller transaction that rolls back stores zero real notification rows', function () {
    $post = BlogPost::factory()->draft()->create();

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

    expect(DB::table('notifications')->count())->toBe(0);
});
