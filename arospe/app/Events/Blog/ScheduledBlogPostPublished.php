<?php

namespace App\Events\Blog;

use App\Models\BlogPost;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised by PublishScheduledBlogPost, once per post and only after its write succeeded, when the
 * scheduled sweep flips a due post from Scheduled to Published (story 0064, D-12).
 *
 * This is the "a post went live" half of the trigger story 0065 turns into a notification; 0065 adds a
 * listener and no second dispatch on this path. It is an explicit event rather than an Eloquent
 * observer because the sweep's write is a query-builder update (no model events fire) and because a
 * `saved`/`restored` hook would re-announce a restored post that was already published.
 *
 * Carries the model, not its id (0065's D-9): the action already holds a freshly read instance, and the
 * listener is synchronous, so there is no queue round trip that could leave the model stale. Deliberately
 * not queued and not broadcast.
 */
class ScheduledBlogPostPublished
{
    use Dispatchable;

    public function __construct(
        public readonly BlogPost $post,
    ) {}
}
