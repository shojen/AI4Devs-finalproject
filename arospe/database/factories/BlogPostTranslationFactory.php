<?php

namespace Database\Factories;

use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogPostTranslation>
 */
class BlogPostTranslationFactory extends Factory
{
    /**
     * `slug` is deliberately not set: BlogPostTranslation's saving hook derives it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blog_post_id' => BlogPost::factory()->withoutTranslations(),
            'store_language_id' => StoreLanguage::factory(),
            'title' => fake()->unique()->sentence(4),
            'body' => '<p>'.fake()->paragraph().'</p>',
        ];
    }

    public function forLanguage(StoreLanguage $language): static
    {
        return $this->state(fn (array $attributes): array => [
            'store_language_id' => $language->id,
        ]);
    }
}
