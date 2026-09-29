<?php

namespace App\Notifications;

use App\Models\BlogPost;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Story 0065 -- a statement of fact about what happened (naming.md's
 * imperative-verb-phrase-free class naming for events), fired once per
 * eligible administrator when a blog post becomes Published, through any of
 * its three triggers (App\Actions\Blog\NotifyBlogPostPublished resolves the
 * recipient set for all three). A shape copy of App\Notifications\
 * CustomerCreated (story 0043) and App\Notifications\OrderCreated (story
 * 0046), not an inheritance -- same reasoning, applied to a different model.
 *
 * `database` channel only: the PRD's requirement is an in-panel bell, not
 * outbound email, and a mail channel here would be an unasked-for
 * per-publication send with no opt-out or throttle. Not ShouldQueue: the
 * whole "delivery" is a single local INSERT, cheaper than the `jobs` row
 * queueing it would cost.
 *
 * `SerializesModels` is used even though this class is never queued today,
 * matching `OrderCreated`'s own reasoning: without it, a future `mail`
 * channel + `ShouldQueue` reversal would serialize the WHOLE hydrated
 * `$post` into `jobs.payload`. With it, a future queued form stores only the
 * model's key.
 *
 * `title` is frozen at construction time (Phase 2 resolution, 2026-09-27):
 * story 0078 (translatable-content retrofit) has not shipped, so
 * `BlogPost::$title` is still a native column and this reads it directly,
 * per this story's D-4.
 */
class BlogPostPublished extends Notification
{
    use SerializesModels;

    public function __construct(private readonly BlogPost $post) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * D-4: exactly two keys -- blog_post_id, title. `title` is a frozen
     * literal snapshot taken at publication, not an id the viewer joins on
     * and not a relation read at render time, so a later rename of the post
     * never changes an already-stored notification.
     *
     * @return array{blog_post_id: string, title: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'blog_post_id' => $this->post->id,
            'title' => $this->post->title,
        ];
    }
}
