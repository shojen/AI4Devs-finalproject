<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\NotifyBlogPostPublished;
use App\Actions\Blog\RestoreBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Story 0061, D-19 / D-19a: publishing announces the post from BOTH of this story's paths (an update
// INTO Published, and a creation already Published), after the commit, once per transition.
//
// Its own file because the "did it fire / did it not" axis is orthogonal to what create and update
// otherwise assert. NotifyBlogPostPublished is story 0065's; it is bound to a spy here (it is
// constructor-injected, so this needs no container hackery) and only invocation count and argument
// are asserted -- never its class, channel, recipients or payload, which this story does not own.
//
// Every case freezes the clock, so a "future" scheduled date cannot go stale on a slow run.
beforeEach(function () {
    Carbon::setTestNow('2026-09-24 12:00:00');

    $this->seed(RolePermissionSeeder::class);

    // Bound BEFORE any action is resolved, so the container hands the spy to the constructors.
    $this->notifier = $this->spy(NotifyBlogPostPublished::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($this->actor);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Creates a post through the real action.
 */
function bpnCreate(string $status, ?string $publishedAt = null, array $tagNames = []): BlogPost
{
    return app(CreateBlogPost::class)(
        'Botas de invierno',
        '<p>Cuerpo</p>',
        BlogCategory::factory()->create()->id,
        $status,
        $publishedAt,
        $tagNames,
    );
}

/**
 * Updates a post through the real action. Everything not overridden is re-submitted as the post
 * currently holds it, so a bare call is a genuine no-op edit.
 *
 * @param  array{title?: string, status?: string, published_at?: ?string, tag_names?: list<string>}  $overrides
 */
function bpnUpdate(BlogPost $post, array $overrides = []): BlogPost
{
    return app(UpdateBlogPost::class)(
        $post,
        $overrides['title'] ?? $post->title,
        $post->body,
        $post->blog_category_id,
        $overrides['status'] ?? $post->status->value,
        array_key_exists('published_at', $overrides) ? $overrides['published_at'] : $post->published_at?->toDateTimeString(),
        $overrides['tag_names'] ?? $post->tags->pluck('name')->all(),
    );
}

test('creating a post with status published dispatches exactly once, with that post', function () {
    $post = bpnCreate('published');

    $this->notifier->shouldHaveReceived('__invoke')
        ->withArgs(fn (BlogPost $announced): bool => $announced->is($post))
        ->once();
    $this->notifier->shouldHaveReceived('__invoke')->once();
});

test('creating a draft or a scheduled post dispatches nothing', function (string $status, ?string $publishedAt) {
    bpnCreate($status, $publishedAt);

    $this->notifier->shouldNotHaveReceived('__invoke');
})->with([
    'draft' => ['draft', null],
    'scheduled' => ['scheduled', '2026-09-25 12:00:00'],
]);

test('updating a draft into published dispatches exactly once', function () {
    $post = BlogPost::factory()->draft()->create();

    bpnUpdate($post, ['status' => 'published', 'published_at' => null]);

    $this->notifier->shouldHaveReceived('__invoke')
        ->withArgs(fn (BlogPost $announced): bool => $announced->is($post))
        ->once();
    $this->notifier->shouldHaveReceived('__invoke')->once();
});

// Pins the TRANSITION guard rather than a state check. Assert zero, not "not more than once":
// without the guard every subsequent edit re-announces the post to its subscribers.
test('re-saving an already-published post with a title-only edit dispatches zero times', function () {
    $post = BlogPost::factory()->published()->create();

    bpnUpdate($post, ['title' => 'Botas de invierno 2026']);

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->title)->toBe('Botas de invierno 2026');
});

test('re-saving an already-published post with a tag-only edit dispatches zero times', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $post = BlogPost::factory()->published()->create();

    bpnUpdate($post, ['tag_names' => ['running']]);

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->tags->pluck('name')->all())->toBe(['running']);
});

// Proves the guard reads the pre-save value rather than a one-time flag.
test('published to draft to published dispatches once per transition into published', function () {
    $post = BlogPost::factory()->draft()->create();

    bpnUpdate($post, ['status' => 'published', 'published_at' => null]);
    bpnUpdate($post, ['status' => 'draft', 'published_at' => null]);
    bpnUpdate($post, ['status' => 'published', 'published_at' => null]);

    $this->notifier->shouldHaveReceived('__invoke')->times(2);
});

test('updating a scheduled post into published dispatches once', function () {
    $post = BlogPost::factory()->scheduled()->create();

    bpnUpdate($post, ['status' => 'published', 'published_at' => null]);

    $this->notifier->shouldHaveReceived('__invoke')
        ->withArgs(fn (BlogPost $announced): bool => $announced->is($post))
        ->once();
    $this->notifier->shouldHaveReceived('__invoke')->once();
});

// That outcome is 0064's sweep, not this story's.
test('updating a draft into scheduled dispatches zero times', function () {
    $post = BlogPost::factory()->draft()->create();

    bpnUpdate($post, ['status' => 'scheduled', 'published_at' => '2026-09-25 12:00:00']);

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('scheduled');
});

// Story 0061a: a post published with a future date is stored as Scheduled, so it is not live yet and
// announces nothing now; 0064's sweep announces it when its date arrives (D-19's trigger 3).
test('creating a post with status published and a future date dispatches nothing', function () {
    $post = bpnCreate('published', '2026-09-25 12:00:00');

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->status->value)->toBe('scheduled');
});

test('updating a draft into published with a future date dispatches nothing', function () {
    $post = BlogPost::factory()->draft()->create();

    bpnUpdate($post, ['status' => 'published', 'published_at' => '2026-09-25 12:00:00']);

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('scheduled');
});

test('creating a post with status published and a date equal to now still dispatches once', function () {
    bpnCreate('published', now()->toDateTimeString());

    $this->notifier->shouldHaveReceived('__invoke')->once();
});

test('a refused move of a published post into the future dispatches nothing', function () {
    $post = BlogPost::factory()->published()->create();

    expect(fn () => bpnUpdate($post, ['published_at' => '2026-09-25 12:00:00']))
        ->toThrow(ValidationException::class);

    $this->notifier->shouldNotHaveReceived('__invoke');
});

// D-15: a dispatch placed inside the transaction would still fire on a rollback, telling subscribers
// about a post that was never saved. Driven through the REAL rollback path: an actor holding
// blog.edit but not blog.create saving a Published post carrying one NEW tag name is refused by
// FindOrCreateBlogTag's insert branch after the post's own row was written.
test('a failed save dispatches zero times', function () {
    $post = BlogPost::factory()->draft()->create();
    $limited = User::factory()->create();
    $limited->givePermissionTo(['blog.view', 'blog.edit']);
    $this->actingAs($limited);

    expect(fn () => bpnUpdate($post, ['status' => 'published', 'published_at' => null, 'tag_names' => ['invierno']]))
        ->toThrow(AuthorizationException::class);

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('draft')
        ->and(BlogTag::count())->toBe(0);
});

test('a validation refusal dispatches zero times', function () {
    $post = BlogPost::factory()->draft()->create(['body' => null]);

    expect(fn () => app(UpdateBlogPost::class)(
        $post,
        $post->title,
        null,
        $post->blog_category_id,
        'published',
        null,
        [],
    ))->toThrow(ValidationException::class);

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('draft');
});

// The case an observer keyed on saved/restored gets wrong, and the one whose failure is most
// visible to real subscribers (D-20).
test('restoring a previously published post dispatches zero times', function () {
    $post = BlogPost::factory()->published()->create();

    app(DeleteBlogPost::class)($post);
    app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id));

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect($post->fresh()->status->value)->toBe('published');
});

// D-19a, closing 0065's R-1. Three steps against ONE row, with no mocking of the model layer:
//  (a) $stale is fetched while the post is Scheduled;
//  (b) 0064's sweep transitions the same row to Published through a separate query and announces it
//      -- simulated by driving the spy directly, since the sweep is not this story's;
//  (c) UpdateBlogPost is called with $stale and a no-op edit (same title, same tags, and the status
//      the row now holds -- an editor whose form re-synced submits Published).
// The spy must have fired exactly ONCE across the whole scenario -- the sweep's own announcement.
// Without refresh() as the action's first statement, (c) reads a stale pre-save status of
// `scheduled`, sees a false transition INTO Published and announces the post a second time. Counting
// across (b) and (c) together is what stops "step (c) fired zero times" passing vacuously.
test('a stale instance must not re-announce a post the sweep already announced', function () {
    $post = BlogPost::factory()->scheduled()->create();
    $stale = BlogPost::query()->findOrFail($post->id);

    BlogPost::query()->whereKey($post->id)->update([
        'status' => 'published',
        'published_at' => Carbon::now(),
    ]);
    $swept = BlogPost::query()->findOrFail($post->id);
    app(NotifyBlogPostPublished::class)($swept);

    app(UpdateBlogPost::class)(
        $stale,
        $stale->title,
        $stale->body,
        $stale->blog_category_id,
        'published',
        $swept->published_at?->toDateTimeString(),
        [],
    );

    $this->notifier->shouldHaveReceived('__invoke')->times(1);
    expect($post->fresh()->status->value)->toBe('published');
});

// Explicitly NOT tested here: anything about the notification's content, recipients, channel or
// queuing. Story 0065 owns all of it, and asserting it here would create a second specification of
// 0065's behaviour that can drift from the first. The dirtied-instance case (D-19a's second effect)
// belongs in UpdateBlogPostTest.php, since it is not about notifications.

// Review finding: called directly, the dispatch ran inside any OUTER transaction a caller opened, so a
// rollback there would still announce a post that was never saved. DB::afterCommit defers it.
test('a create wrapped in a caller transaction that rolls back announces nothing', function () {
    try {
        DB::transaction(function (): void {
            bpnCreate('published');

            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
        //
    }

    $this->notifier->shouldNotHaveReceived('__invoke');
    expect(BlogPost::count())->toBe(0);
});

test('an update into published inside a caller transaction that rolls back announces nothing', function () {
    $post = BlogPost::factory()->create();

    try {
        DB::transaction(function () use ($post): void {
            bpnUpdate($post, ['status' => 'published']);

            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
        //
    }

    $this->notifier->shouldNotHaveReceived('__invoke');
});
