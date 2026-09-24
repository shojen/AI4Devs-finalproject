<?php

namespace Database\Factories;

use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `slug` is deliberately not set: BlogPost's saving hook derives it, which is itself a small proof
     * the hook fires on the insert path. `status` matches the column default, and `published_at` is
     * null, which is what a Draft carries (D-6).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blog_category_id' => BlogCategory::factory(),
            'title' => fake()->unique()->sentence(4),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'status' => BlogPostStatus::Draft,
            'published_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogPostStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogPostStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogPostStatus::Scheduled,
            'published_at' => now()->addDay(),
        ]);
    }

    /**
     * Attach $count freshly created tags after the post is created.
     */
    public function withTags(int $count = 1): static
    {
        return $this->afterCreating(function (BlogPost $post) use ($count): void {
            $post->tags()->attach(BlogTag::factory()->count($count)->create()->modelKeys());
        });
    }
}
