<?php

namespace App\Listeners;

use App\Actions\Blog\NotifyBlogPostPublished;
use App\Events\Blog\ScheduledBlogPostPublished;

/**
 * Thin adapter from ScheduledBlogPostPublished to NotifyBlogPostPublished (story 0065's automatic
 * trigger). Deliberately synchronous (not ShouldQueue), matching CancelFullyRefundedOrder's own
 * reasoning: a notification whose delivery lags the event it announces is a defect here too.
 *
 * Registered by Laravel 13's auto-discovery of app/Listeners and by nothing else -- this app has no
 * hand-written Event::listen() call (see naming.md's classes section).
 *
 * Deliberately no try/catch: an exception here must propagate out of
 * ScheduledBlogPostPublished::dispatch() so 0064b's sweep-level catch can see it and count the post as
 * a sweep failure.
 */
class SendBlogPostPublishedNotification
{
    public function __construct(
        private readonly NotifyBlogPostPublished $notifyBlogPostPublished,
    ) {}

    public function handle(ScheduledBlogPostPublished $event): void
    {
        ($this->notifyBlogPostPublished)($event->post);
    }
}
