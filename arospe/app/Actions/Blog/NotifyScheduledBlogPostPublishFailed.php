<?php

namespace App\Actions\Blog;

use App\Enums\BlogPostPublishFailureStage;
use App\Enums\BlogPostStatus;
use App\Enums\UserStatus;
use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\ScheduledBlogPostPublishFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * A collaborator of the scheduled sweep's command (story 0064b), called once from the command's own
 * `catch` (D-11) for BOTH of its failure classes: the write itself failing (still `Scheduled`) and a
 * synchronous listener throwing after the write (already `Published`, D-1).
 *
 * SYSTEM-TRIGGERED AND DELIBERATELY UNGATED, following the same exception
 * App\Actions\Blog\PublishScheduledBlogPost already carries -- no Gate::authorize(), no policy call, no
 * Auth:: read and no request() anywhere in this class (D-3). There is no actor: the caller is the sweep
 * command, and the recipient predicate below is DATA (a permission held, a status, an email) rather
 * than an authorization decision about who is calling. What restricts this class is deployment access,
 * exactly as for PublishScheduledBlogPost -- condition two of the ungated-write rule.
 *
 * It NEVER receives the original exception -- only the post's id. A class that cannot see the exception
 * text cannot leak it into the notification, the email or a log line (D-3, D-4).
 *
 * The four steps run in this exact order, and the order is the contract (D-3):
 *
 * 1. Load the post (scoped -- missing or soft-deleted resolves to null) and classify it (D-2). Nothing
 *    to report -> return silently, logging nothing.
 * 2. Resolve the recipients BEFORE claiming the dedup key (D-8, backend-qa's correction, OQ-4). None
 *    reachable -> one privacy-safe Log::warning naming only the post id and the stage -> return, having
 *    claimed nothing, so a later administrator who becomes reachable still gets the report.
 * 3. Claim the per-(post, stage, published_at) dedup key (D-6). Already claimed this episode -> return.
 * 4. Send. If the send throws, release the key first -- a failed queue push must not silence a
 *    genuinely unreported post for 24 hours -- then let it propagate to the outer catch below.
 *
 * Everything is wrapped in one outer try/catch that report()s and NEVER rethrows, so a failure inside
 * this class can never reach the sweep's own catch, change its exit code, or stop the loop (D-12).
 */
class NotifyScheduledBlogPostPublishFailed
{
    public function __invoke(string $blogPostId): void
    {
        try {
            $post = BlogPost::query()->find($blogPostId);
            $stage = $post === null ? null : $this->stageFor($post);

            if ($post === null || $stage === null) {
                return;
            }

            $recipients = $this->recipients($post);

            if ($recipients->isEmpty()) {
                Log::warning('Scheduled blog post failure has no reachable recipient', [
                    'blog_post_id' => $post->id,
                    'stage' => $stage->value,
                ]);

                return;
            }

            $key = $this->dedupKey($post, $stage);

            if (! Cache::add($key, 1, now()->addDay())) {
                // Already reported for this (post, stage, published_at) episode within the last 24
                // hours -- a stuck post reminds at most once a day (D-6).
                return;
            }

            try {
                Notification::send($recipients, new ScheduledBlogPostPublishFailed($post->id, $post->title, $stage));
            } catch (Throwable $e) {
                Cache::forget($key);

                throw $e;
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Which failure class the re-read post matches, or null when there is nothing (still) to report
     * (D-2): a `Scheduled` post that is not yet due (rescheduled between the failure and this read), a
     * `Draft`, or -- handled before this method is even called -- a missing or soft-deleted post.
     *
     * Documented limit, accepted: any post read back as `Published` classifies as `Announce`, whatever
     * caused it -- including a concurrent manual publish racing the failed write. The window is a
     * fraction of a second, and the message is true enough (the post is live) even when it is not the
     * write that this notifier's caller actually observed failing.
     */
    private function stageFor(BlogPost $post): ?BlogPostPublishFailureStage
    {
        if ($post->status === BlogPostStatus::Published) {
            return BlogPostPublishFailureStage::Announce;
        }

        if ($post->status === BlogPostStatus::Scheduled
            && $post->published_at !== null
            && $post->published_at->lessThanOrEqualTo(now())
        ) {
            return BlogPostPublishFailureStage::Publish;
        }

        return null;
    }

    /**
     * The reachable creator, if any, else the live `blog.edit` holders (D-8). One recipient set for
     * both channels -- the bell and the email go to the same people.
     *
     * The creator relation (App\Models\BlogPost::creator()) already resolves to null for BOTH causes
     * of "unreachable" that a NULL/trashed relation can mean -- a NULL `created_by` column and a
     * soft-deleted creator -- via the default SoftDeletingScope on User::query(), so nothing here
     * branches on which of the two happened; both fall through to the same fallback query.
     *
     * `->can('blog.edit')` is Illuminate\Foundation\Auth\Access\Authorizable::can(), an instance method
     * on the SPECIFIC $creator being checked -- not the Gate or Auth facade, and it reads no current
     * actor. It is what makes a Super Admin creator reachable (Gate::before fires for any user passed
     * to Gate::forUser(), not only the currently authenticated one).
     *
     * The fallback, `User::permission('blog.edit')`, is a plain data query (a permission held via a
     * role OR directly) and is NOT subject to the Gate::before bypass -- the Super Admin is deliberately
     * excluded from it (D-8, OQ-6), consistent with 0043/0046/0065's own D-1. `blog.edit`, not
     * `blog.view` (D-8): the email's button opens the editor, and telling a view-only administrator
     * about a failure they cannot act on is noise and an unnecessary title disclosure.
     *
     * @return Collection<int, User>
     */
    private function recipients(BlogPost $post): Collection
    {
        $creator = $post->creator;

        if ($creator !== null
            && $creator->isActive()
            && trim((string) $creator->email) !== ''
            && $creator->can('blog.edit')
        ) {
            return new Collection([$creator]);
        }

        return User::permission('blog.edit')
            ->where('status', UserStatus::Active->value)
            ->where('email', '!=', '')
            ->get();
    }

    /**
     * One atomic cache key per (post, stage, published_at), 24-hour TTL, no schema (D-6). `published_at`
     * is in the key so rescheduling starts a fresh episode; the stage is in the key so a `publish`
     * report never suppresses a later `announce` report for the same post.
     */
    private function dedupKey(BlogPost $post, BlogPostPublishFailureStage $stage): string
    {
        // Defensive, matching the task file's own D-3 pseudocode verbatim: published_at IS nullable
        // on blog_posts, and Larastan's model-property inference does not see that nullability from
        // this call site alone.
        return sprintf(
            'blog-publish-failed:%s:%s:%d',
            $post->id,
            $stage->value,
            $post->published_at?->timestamp ?? 0, // @phpstan-ignore nullsafe.neverNull
        );
    }
}
