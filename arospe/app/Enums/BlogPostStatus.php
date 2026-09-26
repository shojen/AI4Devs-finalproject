<?php

namespace App\Enums;

/**
 * A blog post's persisted publication status (story 0061, D-5/D-6): the PRD's Borrador / Publicado /
 * Programado.
 *
 * `label()` shipped with story 0063 (D-18), the first screen to render a status: the list's badge
 * and, in the editor, the status `<select>` over `cases()`.
 *
 * The status decides what `blog_posts.published_at` may hold (Draft: null, Scheduled: a future
 * instant, Published: a past-or-present one) and whether `body` may be empty -- see
 * App\Concerns\BlogPostValidationRules.
 */
enum BlogPostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Scheduled = 'scheduled';

    /**
     * Get the translated, human-readable label for the status.
     */
    public function label(): string
    {
        return __('blog-posts.statuses.'.$this->value);
    }
}
