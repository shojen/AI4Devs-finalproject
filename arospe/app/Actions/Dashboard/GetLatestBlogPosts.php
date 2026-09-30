<?php

namespace App\Actions\Dashboard;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Story 0082 (D-3) -- the 3 most recently created published or scheduled blog posts, for the
 * dashboard's "latest posts" widget. `__invoke()` takes no argument, so `LogRefusedPrivilegedAttempt`
 * is constructor-injected (docs/conventions/code-style.md).
 *
 * Authorizes first (`viewAny` on BlogPost, i.e. `blog.view`); a refusal is logged and throws before
 * the query runs. A caller should check the ability itself and call this only when permitted, or
 * every unprivileged dashboard load would log a refusal.
 *
 * One query, no relations: drafts and soft-deleted posts are excluded, ordered by `created_at` then
 * `id` (both descending, so posts created in the same second keep a stable order). Returns scalars,
 * never models. `publishAt` is `published_at` only for a scheduled post.
 *
 * D-9 -- translatable-content seam: `title` and `body` are read ONLY through `resolveTitle()` and
 * `resolveBody()`, and the query selects `blog_posts.*` without naming, filtering or ordering on
 * either column, so the pending translatable-content retrofit (story 0078) swaps those two methods
 * and nothing else.
 */
class GetLatestBlogPosts
{
    private const LIMIT = 3;

    private const DESCRIPTION_MAX_LENGTH = 80;

    /** How much of the body is processed at all, so a `mediumText` body is never handled whole. */
    private const DESCRIPTION_SOURCE_LENGTH = 2000;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * @return list<array{id: string, title: ?string, description: string, status: BlogPostStatus, publishAt: ?CarbonImmutable}>
     */
    public function __invoke(): array
    {
        $this->logRefusedPrivilegedAttempt->authorize('viewAny', BlogPost::class, targetType: 'blog_post');

        $posts = BlogPost::query()
            ->select('blog_posts.*')
            ->whereIn('blog_posts.status', [BlogPostStatus::Published, BlogPostStatus::Scheduled])
            ->orderByDesc('blog_posts.created_at')
            ->orderByDesc('blog_posts.id')
            ->limit(self::LIMIT)
            ->get();

        $rows = [];

        foreach ($posts as $post) {
            $isScheduled = $post->status === BlogPostStatus::Scheduled;

            $rows[] = [
                'id' => $post->id,
                'title' => $this->resolveTitle($post),
                'description' => $this->describe($this->resolveBody($post)),
                'status' => $post->status,
                'publishAt' => $isScheduled && $post->published_at !== null
                    ? CarbonImmutable::instance($post->published_at)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * D-9 seam: the post's title. Today the plain attribute; the translatable-content retrofit may
     * return null when no translation exists, which is why the row shape types it nullable.
     */
    private function resolveTitle(BlogPost $post): string
    {
        return $post->title;
    }

    /**
     * D-9 seam: the post's body. Today the plain attribute.
     */
    private function resolveBody(BlogPost $post): ?string
    {
        return $post->body;
    }

    /**
     * Derive the plain-text description: the first 2000 characters, tags stripped, THEN entities
     * decoded (so an encoded `&lt;b&gt;` stays literal text), whitespace collapsed and trimmed, and
     * cut so the total, ellipsis included, never exceeds 80 characters. Multibyte-safe; the result
     * is only ever rendered escaped.
     */
    private function describe(?string $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $text = mb_substr($body, 0, self::DESCRIPTION_SOURCE_LENGTH);
        $text = strip_tags($text);
        $text = html_entity_decode($text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            return Str::limit($text, self::DESCRIPTION_MAX_LENGTH - 1, '').'…';
        }

        return $text;
    }
}
