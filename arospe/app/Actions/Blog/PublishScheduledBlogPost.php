<?php

namespace App\Actions\Blog;

use App\Enums\BlogPostStatus;
use App\Events\Blog\ScheduledBlogPostPublished;
use App\Models\BlogPost;
use Illuminate\Support\Facades\Log;

/**
 * Flip ONE due Scheduled post to Published and announce it (story 0064).
 *
 * SYSTEM-TRIGGERED AND DELIBERATELY UNGATED -- a documented exception, not an omission. It is the one
 * action in app/Actions/Blog/ that does not authorize itself:
 *
 * - No Gate::authorize(), no policy call and no actor read (D-5). There is no actor: the caller is the
 *   scheduled command, and what it decides is not "may this user publish" but "is this post now due",
 *   a fact about the row relative to the clock. Nothing upstream authorized a cron tick either, so what
 *   protects the entry point is deployment access: running the command needs shell access to the server,
 *   and anyone with that already holds the database credentials. Do not add a Gate call here, and do not
 *   route this through UpdateBlogPost: with no user both would refuse, and the sweep would silently
 *   publish nothing, forever, looking exactly like a scheduler that is not running.
 * - The write is ONE conditional UPDATE, not a locked read (D-7). Its WHERE clause is the guard -- the
 *   row, the state it leaves and the due time -- and InnoDB re-evaluates it against committed data when it
 *   takes the row lock, so a racing manual publish or a second sweep publishes and announces the post
 *   once. Do not "fix in" lockForUpdate() or a transaction.
 * - No withTrashed() (D-9): the default SoftDeletingScope is what stops a deleted post going live.
 * - Only `status` (and `updated_at`) is written. `published_at` is the date the editor chose and is
 *   never restamped (D-11).
 * - A query-builder update fires no model events, so the announcement is an explicit dispatch, made only
 *   after the write and never from an Eloquent observer (D-12).
 */
class PublishScheduledBlogPost
{
    /**
     * Publish the post if it is still eligible.
     *
     * Returns the transitioned post, or null when the row was not eligible: already published or drafted,
     * not due yet, soft-deleted, missing, or lost to a racing writer. None of those is an error, and none
     * dispatches anything. A post deleted in the instant between the write and the re-read is also null:
     * it went live and was deleted at once, and announcing a deleted post is the worse mistake.
     *
     * A failed write throws and dispatches nothing. The write is the commit point and the announcement
     * follows it, so a synchronous listener that throws surfaces after the post is already Published: the
     * next tick will not retry it, and the announcement is lost and reported rather than duplicated
     * (at-most-once). Making that retryable is a decision for story 0065, which owns the listener.
     */
    public function __invoke(string $blogPostId): ?BlogPost
    {
        $affected = BlogPost::query()
            ->whereKey($blogPostId)
            ->where('status', BlogPostStatus::Scheduled)
            ->where('published_at', '<=', now())
            ->update(['status' => BlogPostStatus::Published]);

        if ($affected === 0) {
            return null;
        }

        $post = BlogPost::query()->find($blogPostId);

        if ($post === null) {
            return null;
        }

        Log::info('Scheduled blog post published', ['blog_post_id' => $post->id]);

        ScheduledBlogPostPublished::dispatch($post);

        return $post;
    }
}
