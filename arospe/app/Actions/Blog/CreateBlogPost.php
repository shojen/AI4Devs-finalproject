<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Products\SanitizeProductDescription;
use App\Concerns\BlogPostValidationRules;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateBlogPost
{
    use BlogPostValidationRules;

    /**
     * Constructor injection, not method injection: __invoke()'s domain arguments are this action's
     * whole public signature, matched verbatim by story 0063 and every direct-call test, so the
     * collaborators are resolved from the container without widening it (code-style.md's
     * constructor-injection exception).
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly SyncBlogPostTags $syncBlogPostTags,
        private readonly SanitizeProductDescription $sanitizeBody,
        private readonly BlogBodyHasVisibleContent $bodyHasVisibleContent,
        private readonly NotifyBlogPostPublished $notifyBlogPostPublished,
    ) {}

    /**
     * Create a blog post together with its tags.
     *
     * Authorizes `create` as its own first statement (D-13), so a non-dashboard caller inherits the
     * refusal. `$status` is a STRING, null meaning Draft: a typed enum parameter (or a typed enum
     * property upstream in a Livewire component) would turn a forged value into an uncaught
     * \ValueError instead of a ValidationException (task 0015's finding F8).
     *
     * The body is trimmed, then sanitized through story 0024a's existing sanitizer -- the one
     * allow-list in the tree, consumed and never forked (D-14) -- BEFORE validation, so `required`
     * judges what will actually be stored and a whitespace-only body is refused for a Published post.
     * An empty body is stored as null.
     *
     * `$publishedAt` is governed by the status (D-6): a Draft's date is discarded, a Scheduled post
     * needs a strictly future one, a Published post takes a given date verbatim or is stamped `now()`.
     * A Published request whose resolved date is strictly in the future is stored as Scheduled instead
     * (story 0061a, D-1): the caller gets the model back with its real status, and nothing is announced
     * now -- the sweep announces the post when its date arrives.
     *
     * The row is built from a literal whitelist through `forceCreate()`, never a spread of validated
     * input, and the post and its tag sync are ONE transaction (D-15): a refusal while resolving a
     * tag leaves no post behind. Publishing is announced AFTER the commit and only on success
     * (D-19): a notification cannot be recalled, and one sent from inside the transaction would
     * still go out on a rollback.
     *
     * A title whose slug is already taken is refused on `title`; the unique index is the last word
     * under a race (OQ-2).
     *
     * @param  list<string>  $tagNames  the COMPLETE tag set, never a delta
     */
    public function __invoke(
        string $title,
        ?string $body,
        ?string $blogCategoryId,
        ?string $status,
        ?string $publishedAt,
        array $tagNames,
    ): BlogPost {
        $this->logRefusedPrivilegedAttempt->authorize('create', BlogPost::class, targetType: 'blog_post');

        $title = trim($title);
        $status ??= BlogPostStatus::Draft->value;
        $this->assertBodyWithinSanitizerLimit($body);
        $body = $this->cleanBody($body);

        // An unrecognised status still yields a rule set, so every other error is reported in the
        // same pass; statusRules() is what refuses it.
        $ruleStatus = BlogPostStatus::tryFrom($status) ?? BlogPostStatus::Draft;
        $publishedAt = $ruleStatus === BlogPostStatus::Draft || $publishedAt === null || trim($publishedAt) === ''
            ? null
            : $publishedAt;

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
                'title' => [...$this->titleRules(), ...$this->titleSlugRules()],
                'body' => $this->bodyRules($ruleStatus),
                'blog_category_id' => $this->blogCategoryIdRules(),
                'status' => $this->statusRules(),
                'published_at' => $this->publishedAtRules($ruleStatus),
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
            BlogPostStatus::Published => $publishedAt === null ? $now : Carbon::parse($publishedAt),
        };

        if ($resolvedStatus === BlogPostStatus::Published && $resolvedPublishedAt->greaterThan($now)) {
            $resolvedStatus = BlogPostStatus::Scheduled;
        }

        try {
            $post = DB::transaction(function () use ($title, $body, $blogCategoryId, $resolvedStatus, $resolvedPublishedAt, $tagNames): BlogPost {
                $post = BlogPost::forceCreate([
                    'title' => $title,
                    'body' => $body,
                    'blog_category_id' => (string) $blogCategoryId,
                    'status' => $resolvedStatus,
                    'published_at' => $resolvedPublishedAt,
                ]);

                ($this->syncBlogPostTags)($post, $tagNames);

                return $post;
            });
        } catch (QueryException $e) {
            // 1062 = MySQL ER_DUP_ENTRY on the slug's unique index: a concurrent save claimed the
            // same slug between the pre-flight check above and this INSERT. SQLSTATE 23000 alone
            // would be too broad -- the category FK raises it too.
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'slug')) {
                throw ValidationException::withMessages([
                    'title' => trans('validation.unique', ['attribute' => 'title']),
                ]);
            }

            throw $e;
        }

        if ($post->status === BlogPostStatus::Published) {
            DB::afterCommit(fn () => ($this->notifyBlogPostPublished)($post));
        }

        return $post;
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
}
