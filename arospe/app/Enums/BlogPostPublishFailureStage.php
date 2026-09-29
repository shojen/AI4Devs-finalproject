<?php

namespace App\Enums;

/**
 * Which of the scheduled sweep's two failure classes a due Scheduled post's re-read matches (story
 * 0064b, D-2): either the write itself failed and the post is still Scheduled (`Publish`, retried
 * every minute), or the write succeeded but a synchronous listener of
 * App\Events\Blog\ScheduledBlogPostPublished threw afterwards and the post is already Published
 * (`Announce`, never retried).
 *
 * Used as the notification payload's `stage` value (App\Notifications\ScheduledBlogPostPublishFailed),
 * as part of the per-episode dedup cache key, and to select which mail copy to render -- see
 * App\Actions\Blog\NotifyScheduledBlogPostPublishFailed.
 *
 * No label(): this enum has no rendering site of its own yet (H-3, the bell's future arm); the mail
 * copy match()es on the case directly rather than deriving a translation key from `label()`.
 */
enum BlogPostPublishFailureStage: string
{
    case Publish = 'publish';
    case Announce = 'announce';
}
