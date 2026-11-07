<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductTranslation>
 */
class ProductTranslationFactory extends Factory
{
    /**
     * The default description is plain text, so the model's sanitizer hook leaves it untouched.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->withoutTranslations(),
            'store_language_id' => StoreLanguage::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forLanguage(StoreLanguage $language): static
    {
        return $this->state(fn (array $attributes): array => [
            'store_language_id' => $language->id,
        ]);
    }

    public function withSeo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => fake()->unique()->slug(3),
            'meta_title' => fake()->sentence(4),
            'meta_description' => fake()->sentence(12),
        ]);
    }
}
