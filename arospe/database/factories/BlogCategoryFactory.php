<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<BlogCategory>
 */
class BlogCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Story 0072: `blog_categories` carries no translatable column of its own any more -- every
     * real write path (App\Actions\Blog\CreateBlogCategory) writes the default-language name into
     * `blog_category_translations` instead. `configure()` below reproduces that shape, so every
     * `BlogCategory::factory()->create()` call site keeps working.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    /**
     * Write one default-language translation after the parent row is created, provisioning a
     * default StoreLanguage when none exists yet. Built as a fresh closure per chained state
     * (`named()`, `withoutTranslations()`) for the reason documented on ProductCategoryFactory.
     */
    public function configure(): static
    {
        return $this->afterCreating(
            fn (BlogCategory $category) => $this->writeDefaultTranslation($category, null),
        );
    }

    /**
     * The default-language name to write instead of a fake one. Faker's unique() only guards one
     * Faker instance, never the database, so a test needing a guaranteed-colliding or
     * guaranteed-distinct name passes it here.
     */
    public function named(string $name): static
    {
        return $this->newInstance(['afterCreating' => new Collection([
            fn (BlogCategory $category) => $this->writeDefaultTranslation($category, $name),
        ])]);
    }

    /**
     * Create the parent row and nothing else: no translation, and no default StoreLanguage
     * provisioned either.
     */
    public function withoutTranslations(): static
    {
        return $this->newInstance(['afterCreating' => new Collection]);
    }

    /**
     * Reuses the existing default store language when one is already seeded, so multiple
     * categories created in the same test land under the same language rather than one each.
     */
    private function writeDefaultTranslation(BlogCategory $category, ?string $name): void
    {
        $defaultLanguage = StoreLanguage::query()->where('is_default', true)->first()
            ?? StoreLanguage::factory()->default()->create();

        BlogCategoryTranslation::factory()
            ->forLanguage($defaultLanguage)
            ->create([
                'blog_category_id' => $category->id,
                'name' => $name ?? fake()->unique()->words(3, true),
            ]);
    }
}
