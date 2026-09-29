<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Products\SanitizeProductDescription;
use App\Concerns\BlogPostValidationRules;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateBlogPost
{
    use BlogPostValidationRules;

    /**
     * Constructor injection, not method injection: __invoke()'s domain arguments are this action's
     * whole public signature, matched verbatim by story 0063 and every direct-call test (see
     * CreateBlogPost).
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly SyncBlogPostTags $syncBlogPostTags,
        private readonly SanitizeProductDescription $sanitizeBody,
        private readonly BlogBodyHasVisibleContent $bodyHasVisibleContent,
        private readonly NotifyBlogPostPublished $notifyBlogPostPublished,
    ) {}

    /**
     * Update a blog post and replace its tags with exactly `$tagNames`.
     *
     * ⚠️ `$blogPost->refresh()` is the LITERAL FIRST STATEMENT, before authorization and before the
     * pre-save status is read (D-19a). It defends two things at once, and moving it silently reopens
     * both: a STALE instance (a scheduler sweep published the row after the caller loaded it, so the
     * pre-save status read below would see `scheduled` and announce a transition that already
     * happened), and a DIRTIED one (`save()` writes the whole dirty set, so an attribute the caller
     * dirtied -- `slug`, say -- would ride into the UPDATE; refresh() discards it). It narrows the
     * stale window rather than closing it (R-18): a sweep landing between this line and the
     * transaction still double-announces, an accepted residual whose fix -- a locked re-read inside
     * the transaction -- is recorded in D-19a.
     *
     * Then authorizes `update` (D-13), and follows CreateBlogPost for status-as-string, body
     * sanitizing, atomicity and the after-commit announcement. Publishing notifies on the TRANSITION
     * into Published, never on the state: the pre-save status is read from the refreshed row, before
     * any mutation, so re-saving an already-published post announces nothing (D-19).
     *
     * A Scheduled post being re-saved with the very date it already carries is not asked for a
     * future date again: an editor retitling an overdue scheduled post resubmits the stored, now-past
     * date, and refusing it would make every such edit impossible (D-6). Any changed date, and any
     * transition into Scheduled, must still be strictly in the future. A Published post re-saved
     * without a date keeps the one it has instead of being re-stamped.
     *
     * A Published request whose resolved date is strictly in the future is stored as Scheduled and
     * announces nothing (story 0061a, D-1), unless the post is ALREADY live: moving a live post out of
     * view is refused on `published_at` instead (D-4), so a date edit never un-publishes a post nor
     * makes the sweep announce it a second time.
     *
     * ⚠️ The tag set is a full replace -- see SyncBlogPostTags: pass the COMPLETE set on every save.
     *
     * @param  list<string>  $tagNames
     */
    public function __invoke(
        BlogPost $blogPost,
        string $title,
        ?string $body,
        ?string $blogCategoryId,
        string $status,
        ?string $publishedAt,
        array $tagNames,
    ): BlogPost {
        $blogPost->refresh();

        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogPost,
            targetType: 'blog_post',
            targetId: $blogPost->id,
        );

        // refresh() neither fails on a row that is not persisted (it returns silently, and save()
        // would then INSERT -- creating a post with only blog.edit) nor skips a trashed one. A
        // deleted post is restored first, never edited.
        if (! $blogPost->exists || $blogPost->trashed()) {
            throw (new ModelNotFoundException)->setModel(BlogPost::class, [$blogPost->getKey()]);
        }

        $wasPublished = $blogPost->getRawOriginal('status') === BlogPostStatus::Published->value;
        $wasScheduled = $blogPost->getRawOriginal('status') === BlogPostStatus::Scheduled->value;
        $storedPublishedAt = $blogPost->published_at;

        $title = trim($title);
        $this->assertBodyWithinSanitizerLimit($body);
        $body = $this->cleanBody($body);

        $ruleStatus = BlogPostStatus::tryFrom($status) ?? BlogPostStatus::Draft;
        $publishedAt = $ruleStatus === BlogPostStatus::Draft || $publishedAt === null || trim($publishedAt) === ''
            ? null
            : $publishedAt;

        $keepsStoredDate = $wasScheduled
            && $ruleStatus === BlogPostStatus::Scheduled
            && $this->sameInstant($publishedAt, $storedPublishedAt);

        Validator::make(
            [
                'title' => $title,
                'body' => $body,
                'blog_category_id' => $blogCategoryId,
                'status' => $status,
                'published_at' => $publishedAt,
                'tag_names' => $tagNames,
            ],
            [
                'title' => [...$this->titleRules(), ...$this->titleSlugRules($blogPost->id)],
                'body' => $this->bodyRules($ruleStatus),
                'blog_category_id' => $this->blogCategoryIdRules(),
                'status' => $this->statusRules(),
                'published_at' => $this->publishedAtRules($ruleStatus, enforceFuture: ! $keepsStoredDate),
                'tag_names' => $this->tagNamesRules(),
            ],
        )->validate();

        // One instant for the whole decision, so the stamp and the future-or-not comparison cannot
        // disagree across a second boundary.
        $now = now();
        $resolvedStatus = BlogPostStatus::from($status);
        $resolvedPublishedAt = match ($resolvedStatus) {
            BlogPostStatus::Draft => null,
            BlogPostStatus::Scheduled => Carbon::parse((string) $publishedAt),
            BlogPostStatus::Published => match (true) {
                $publishedAt !== null => Carbon::parse($publishedAt),
                $wasPublished && $storedPublishedAt !== null => $storedPublishedAt,
                default => $now,
            },
        };

        if ($resolvedStatus === BlogPostStatus::Published && $resolvedPublishedAt->greaterThan($now)) {
            if ($wasPublished) {
                throw ValidationException::withMessages([
                    'published_at' => trans('validation.before_or_equal', ['attribute' => 'published_at', 'date' => 'now']),
                ]);
            }

            $resolvedStatus = BlogPostStatus::Scheduled;
        }

        try {
            DB::transaction(function () use ($blogPost, $title, $body, $blogCategoryId, $resolvedStatus, $resolvedPublishedAt, $tagNames): void {
                $blogPost->forceFill([
                    'title' => $title,
                    'body' => $body,
                    'blog_category_id' => (string) $blogCategoryId,
                    'status' => $resolvedStatus,
                    'published_at' => $resolvedPublishedAt,
                ])->save();

                ($this->syncBlogPostTags)($blogPost, $tagNames);
            });
        } catch (QueryException $e) {
            // The slug's unique index is the last word behind the pre-flight check (OQ-2).
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'slug')) {
                throw ValidationException::withMessages([
                    'title' => trans('validation.unique', ['attribute' => 'title']),
                ]);
            }

            throw $e;
        }

        if (! $wasPublished && $blogPost->status === BlogPostStatus::Published) {
            DB::afterCommit(fn () => ($this->notifyBlogPostPublished)($blogPost));
        }

        return $blogPost;
    }

    /**
     * Sanitize the body, then judge what is LEFT (story 0061b, D-3): a body that renders no visible
     * text and no image is no body at all, so it becomes null and bodyRules() decides, exactly as for
     * an empty string.
     */
    private function cleanBody(?string $body): ?string
    {
        $sanitized = ($this->sanitizeBody)($body === null ? null : trim($body));

        return ($this->bodyHasVisibleContent)($sanitized) ? $sanitized : null;
    }

    /**
     * Whether the submitted date names the very instant the post already carries. Unparseable input
     * is simply "not the same": the `date` rule refuses it right after.
     */
    private function sameInstant(?string $submitted, ?CarbonInterface $stored): bool
    {
        if ($submitted === null || $stored === null) {
            return false;
        }

        try {
            return Carbon::parse($submitted)->equalTo($stored);
        } catch (InvalidFormatException) {
            return false;
        }
    }
}
