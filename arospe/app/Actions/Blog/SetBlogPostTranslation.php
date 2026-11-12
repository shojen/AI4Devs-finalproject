<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Products\SanitizeProductDescription;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogPostValidationRules;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Story 0079: the backend layer of the two-layer write guard for one blog post's title and body in
 * one store language. Self-authorizing (and refusal-logging), sanitizing and self-validating wrapper
 * over story 0070's deliberately unguarded SetTranslation primitive -- the only class that reaches it
 * for a non-default post translation from a UI path.
 *
 * It takes no slug: BlogPostTranslation's saving hook derives it from the title (0078 D-4). Like its
 * siblings it does NOT filter on `is_active`; only the tab strip does.
 */
final class SetBlogPostTranslation
{
    use BlogPostValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly SanitizeProductDescription $sanitizeBody,
        private readonly BlogBodyHasVisibleContent $bodyHasVisibleContent,
        private readonly SetTranslation $setTranslation,
    ) {}

    /**
     * Authorize, sanitize, validate and persist one post's title and body in one store language.
     *
     * The error keys are derived from the language, never accepted as parameters, so a caller
     * cannot point a refusal at the wrong tab.
     *
     * @throws AuthorizationException when the actor lacks blog.edit (logged first)
     * @throws ValidationException keyed "titles.{$language->id}" / "bodies.{$language->id}"
     * @throws LogicException when the primitive returns an unexpected model
     */
    public function __invoke(
        BlogPost $blogPost,
        StoreLanguage $language,
        string $title,
        ?string $body = null,
    ): BlogPostTranslation {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogPost,
            targetType: 'blog_post',
            targetId: $blogPost->id,
        );

        // Once authorized the post is re-read: a post deleted meanwhile is a clean 404.
        $blogPost = BlogPost::query()->whereKey($blogPost->getKey())->firstOrFail();

        $titleKey = "titles.{$language->id}";
        $bodyKey = "bodies.{$language->id}";

        $title = trim($title);

        $this->assertBodyWithinLimitOnKey($body, $bodyKey);
        $body = $this->cleanBody($body);
        $this->assertBodyWithinLimitOnKey($body, $bodyKey, trim: false);

        Validator::make(
            ['titles' => [$language->id => $title], 'bodies' => [$language->id => $body]],
            [
                $titleKey => [...$this->titleRules(), ...$this->titleSlugRules($language->id, $blogPost->id)],
                $bodyKey => $this->translatedBodyRules(),
            ],
            [],
            ['titles.*' => __('blog-posts.editor.title_label'), 'bodies.*' => __('blog-posts.editor.body_label')],
        )->validate();

        try {
            $translation = ($this->setTranslation)($blogPost, $language, ['title' => $title, 'body' => $body]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), BlogPostTranslation::LANGUAGE_SLUG_UNIQUE_INDEX)) {
                throw ValidationException::withMessages([
                    $titleKey => trans('validation.unique', ['attribute' => __('blog-posts.editor.title_label')]),
                ]);
            }

            throw $e;
        }

        if (! $translation instanceof BlogPostTranslation) {
            throw new LogicException('SetTranslation returned an unexpected model for a blog post.');
        }

        return $translation;
    }

    /**
     * The trait's size guard throws on the bare `body` key, which nothing on this screen renders;
     * re-throw it on this language's own key.
     *
     * @throws ValidationException
     */
    private function assertBodyWithinLimitOnKey(?string $body, string $bodyKey, bool $trim = true): void
    {
        try {
            $this->assertBodyWithinSanitizerLimit($body, $trim);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([$bodyKey => $e->errors()['body'] ?? []]);
        }
    }

    /**
     * Sanitize, then collapse a body that shows a reader nothing to null so the language keeps
     * falling back to the default body (0061b).
     */
    private function cleanBody(?string $body): ?string
    {
        $sanitized = ($this->sanitizeBody)($body === null ? null : trim($body));

        return ($this->bodyHasVisibleContent)($sanitized) ? $sanitized : null;
    }
}
