<?php

namespace Database\Factories;

use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<BlogTag>
 */
class BlogTagFactory extends Factory
{
    /**
     * Story 0074: `blog_tags` carries no translatable column of its own any more. `configure()`
     * writes the default-language translation, so every `BlogTag::factory()->create()` call site
     * keeps working.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    public function configure(): static
    {
        return $this->afterCreating(
            fn (BlogTag $tag) => $this->writeDefaultTranslation($tag, null),
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
            fn (BlogTag $tag) => $this->writeDefaultTranslation($tag, $name),
        ])]);
    }

    /**
     * Create the parent row and nothing else: no translation, no default StoreLanguage.
     */
    public function withoutTranslations(): static
    {
        return $this->newInstance(['afterCreating' => new Collection]);
    }

    private function writeDefaultTranslation(BlogTag $tag, ?string $name): void
    {
        $defaultLanguage = StoreLanguage::query()->where('is_default', true)->first()
            ?? StoreLanguage::factory()->default()->create();

        BlogTagTranslation::factory()
            ->forLanguage($defaultLanguage)
            ->create([
                'blog_tag_id' => $tag->id,
                'name' => $name ?? fake()->unique()->words(2, true),
            ]);
    }
}
