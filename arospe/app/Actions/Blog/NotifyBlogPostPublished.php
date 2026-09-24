<?php

namespace App\Actions\Blog;

use App\Models\BlogPost;

/**
 * PLACEHOLDER -- story 0065 owns this action and replaces its body (recipients, notification class,
 * channel, queuing).
 *
 * Story 0061 must call `__invoke(BlogPost): void` from both of its manual publish paths (D-19), but
 * 0065 depends on 0061, so the class has to exist for the two actions to resolve it from the
 * container. It is a deliberate no-op here: this story defines no notification, channel or
 * recipient list. When 0065 lands it keeps this exact signature and fills the body in.
 */
class NotifyBlogPostPublished
{
    /**
     * Announce that a post has just been published.
     */
    public function __invoke(BlogPost $blogPost): void
    {
        //
    }
}
