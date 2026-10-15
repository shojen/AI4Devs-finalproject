<?php

namespace Database\Factories;

use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogTagTranslation>
 */
class BlogTagTranslationFactory extends Factory
{
    /**
     * `normalized_name` is deliberately not set: BlogTagTranslation's saving hook derives it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blog_tag_id' => BlogTag::factory()->withoutTranslations(),
            'store_language_id' => StoreLanguage::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }

    public function forLanguage(StoreLanguage $language): static
    {
        return $this->state(fn (array $attributes): array => [
            'store_language_id' => $language->id,
        ]);
    }
}
