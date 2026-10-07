<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogCategoryTranslation>
 */
class BlogCategoryTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `normalized_name` is deliberately not set: BlogCategoryTranslation's saving hook derives it,
     * which is itself a small proof the hook fires on the insert path. Faker's unique() only
     * guards one Faker instance, never the database, so a test needing a guaranteed-colliding
     * name passes it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blog_category_id' => BlogCategory::factory()->withoutTranslations(),
            'store_language_id' => StoreLanguage::factory(),
            'name' => fake()->unique()->words(3, true),
        ];
    }

    /**
     * Attach the translation to a specific store language, so no test has to hand-build the FK
     * pair itself.
     */
    public function forLanguage(StoreLanguage $language): static
    {
        return $this->state(fn (array $attributes): array => [
            'store_language_id' => $language->id,
        ]);
    }
}
