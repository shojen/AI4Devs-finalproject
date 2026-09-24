<?php

namespace App\Enums;

/**
 * A blog post's persisted publication status (story 0061, D-5/D-6): the PRD's Borrador / Publicado /
 * Programado.
 *
 * Deliberately no `label()` yet: a label is added when a second consumer appears, not the first,
 * and this story renders nothing -- story 0063's list is the first consumer and may add it then.
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
}
