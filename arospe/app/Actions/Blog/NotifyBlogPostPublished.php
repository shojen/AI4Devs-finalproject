<?php

namespace App\Actions\Blog;

use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\BlogPostPublished;
use Illuminate\Support\Facades\Notification;

/**
 * Story 0065 -- resolves the recipient set and dispatches BlogPostPublished. A shape copy of
 * App\Actions\Customers\NotifyCustomerCreated (story 0043) and App\Actions\Orders\NotifyOrderCreated
 * (story 0046), not an inheritance -- same collaborator shape, applied to a different model.
 *
 * Called from three independent triggers (D-5): App\Actions\Blog\CreateBlogPost and
 * App\Actions\Blog\UpdateBlogPost call it directly (both already authorized, self-authorized flows --
 * see NotifyOrderCreated's own reasoning for why a collaborator invoked only by an already-authorized
 * action needs no gate of its own), and App\Listeners\SendBlogPostPublishedNotification calls it for
 * the automatic (scheduled sweep) trigger. Deliberately authorizes NOTHING of its own.
 *
 * Recipients are resolved LIVE at dispatch time, never cached: `User::permission('blog.view')`
 * (Spatie's own scope) matches a permission held via a role OR directly, against the `web` guard --
 * "Gate on permissions, never role names" (architecture/authorization.md) applied to a recipient set.
 * Soft-deleted administrators are excluded for free by the SoftDeletingScope already on
 * `User::query()`. The Super Admin is deliberately excluded (D-1): their access comes from the
 * Gate::before bypass, which grants no role_has_permissions/model_has_permissions row for this data
 * query to match.
 */
class NotifyBlogPostPublished
{
    public function __invoke(BlogPost $blogPost): void
    {
        $recipients = User::permission('blog.view')->get();

        Notification::send($recipients, new BlogPostPublished($blogPost));
    }
}
