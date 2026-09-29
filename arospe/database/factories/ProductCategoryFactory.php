<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Story 0070 (D-4, D-15): `product_categories` carries no translatable column of its own any
     * more -- every real write path (App\Actions\ProductCategories\CreateProductCategory) writes
     * the default-language name into `product_category_translations` instead. `configure()`
     * below reproduces that shape for the ~40 existing `ProductCategory::factory()->create()`
     * call sites, so they keep working unedited.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    /**
     * Write one default-language translation after the parent row is created, provisioning a
     * default StoreLanguage when none exists yet. Deliberately built as a fresh closure per
     * chained state (`named()`, `withoutTranslations()`) rather than a mutable property read
     * from inside this callback -- afterCreating closures registered here stay bound to the
     * Factory instance that existed when configure() ran, so a later state() call mutating `$this`
     * would never be visible to it. See `named()`'s own comment for the concrete failure mode.
     */
    public function configure(): static
    {
        return $this->afterCreating(
            fn (ProductCategory $category) => $this->writeDefaultTranslation($category, null),
        );
    }

    /**
     * The default-language name to write instead of a fake one.
     */
    public function named(string $name): static
    {
        // Replaces the whole afterCreating collection with a closure built HERE, capturing
        // $name by value -- not appended to configure()'s closure, which would write a second,
        // conflicting translation row for the same (category, default language) pair.
        return $this->newInstance(['afterCreating' => new Collection([
            fn (ProductCategory $category) => $this->writeDefaultTranslation($category, $name),
        ])]);
    }

    /**
     * Create the parent row and nothing else: no translation, and no default StoreLanguage
     * provisioned either. Two tests depend on the second half specifically -- the "no
     * translation anywhere" fallback test needs the missing translation, and the backfill
     * precondition test needs "categories exist and no default store language exists", which it
     * cannot arrange if this state quietly created one.
     */
    public function withoutTranslations(): static
    {
        return $this->newInstance(['afterCreating' => new Collection]);
    }

    /**
     * Reuses the existing default store language when one is already seeded, so multiple
     * categories created in the same test land under the same language rather than one each.
     */
    private function writeDefaultTranslation(ProductCategory $category, ?string $name): void
    {
        $defaultLanguage = StoreLanguage::query()->where('is_default', true)->first()
            ?? StoreLanguage::factory()->default()->create();

        ProductCategoryTranslation::factory()
            ->forLanguage($defaultLanguage)
            ->create([
                'product_category_id' => $category->id,
                'name' => $name ?? fake()->unique()->words(2, true),
            ]);
    }
}
