<?php

namespace Database\Factories;

use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;

/**
 * Story 0078 (D-15): the identifiable `afterCreating` step of BlogPostFactory. A class rather than
 * a closure so titled()/withoutTranslations() can recognise and swap just this callback, leaving
 * every other one (e.g. withTags()') in place.
 */
class WritesDefaultBlogPostTranslation
{
    public function __construct(
        private readonly ?string $title = null,
        private readonly ?string $body = null,
    ) {}

    public function __invoke(BlogPost $post): void
    {
        $defaultLanguage = StoreLanguage::query()->where('is_default', true)->first()
            ?? StoreLanguage::factory()->default()->create();

        $attributes = [
            'blog_post_id' => $post->id,
        ];

        if ($this->title !== null) {
            $attributes['title'] = $this->title;
            $attributes['body'] = $this->body;
        }

        BlogPostTranslation::factory()->forLanguage($defaultLanguage)->create($attributes);
    }
}
