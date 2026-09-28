<?php

use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\NotifyBlogPostPublished;
use App\Actions\Blog\RestoreBlogPost;
use App\Events\Blog\ScheduledBlogPostPublished;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Story 0065 -- one case, its own file, deliberately (0061's D-20 hand-off and 0064's hand-off fact
// 5 both name this by number as THE trap a generic "saved"/"restored" observer gets wrong).
//
// Under the shipped design (D-11) RestoreBlogPost touches neither of this story's triggers, so the
// "no notification" assertion below is vacuous by construction: no code path here could ever fire,
// which looks identical, in green output, to a guard that actually worked. Regression-proofed
// 2026-09-28: a `static::restored()` hook was temporarily added to App\Models\BlogPost, keyed on
// `status === Published` (the exact wrong shape D-11 forbids), which turned this test red
// ("should be called exactly 0 times but called 1 times"); the hook was then reverted and this test
// confirmed green again with no other change. See the task file's Definition of Done.

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.edit', 'blog.delete']);
    $this->actingAs($this->actor);

    $this->recipient = User::factory()->create();
    $this->recipient->givePermissionTo('blog.view');
});

test('restoring a post that was published before it was soft-deleted announces nothing', function () {
    $post = BlogPost::factory()->published()->create();

    app(DeleteBlogPost::class)($post);

    Event::fake([ScheduledBlogPostPublished::class]);
    $notifier = $this->spy(NotifyBlogPostPublished::class);

    app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id));

    expect(DB::table('notifications')->count())->toBe(0);
    Event::assertNotDispatched(ScheduledBlogPostPublished::class);
    $notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('published');
});
