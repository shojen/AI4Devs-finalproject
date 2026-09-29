<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategoryTranslation>
 */
class ProductCategoryTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bare ->create() must work standalone (no explicit ->for()), so both FKs carry their own
     * factory default rather than requiring every caller to supply one -- matching
     * ProductAttributeValueFactory's own precedent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_category_id' => ProductCategory::factory(),
            'store_language_id' => StoreLanguage::factory(),
            'name' => fake()->unique()->words(2, true),
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
