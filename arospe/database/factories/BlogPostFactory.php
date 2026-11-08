<?php

namespace Database\Factories;

use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use ReflectionFunction;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Parent columns only (story 0078, D-15): title, slug and body live on `blog_post_translations`
     * and are written by configure()'s default-language step. `status` matches the column default, and `published_at` is
     * null, which is what a Draft carries (D-6). `created_by` defaults to null (story 0064b, D-7): the
     * factory bypasses CreateBlogPost entirely, so there is no authenticated actor to stamp -- a test
     * that needs a creator uses the createdBy() state below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blog_category_id' => BlogCategory::factory(),
            'status' => BlogPostStatus::Draft,
            'published_at' => null,
            'created_by' => null,
        ];
    }

    /**
     * The default-language translation is written by an identifiable callback
     * (WritesDefaultBlogPostTranslation), so titled()/withoutTranslations() swap only that step and
     * leave every other afterCreating callback (e.g. withTags()') in place.
     */
    public function configure(): static
    {
        return $this->afterCreating((new WritesDefaultBlogPostTranslation)->__invoke(...));
    }

    /**
     * Write the given default-language title/body instead of fake ones.
     */
    public function titled(string $title, ?string $body = null): static
    {
        return $this->withTranslationStep(new WritesDefaultBlogPostTranslation($title, $body));
    }

    /**
     * Create the parent row and no translation.
     */
    public function withoutTranslations(): static
    {
        return $this->withTranslationStep(null);
    }

    private function withTranslationStep(?WritesDefaultBlogPostTranslation $step): static
    {
        $callbacks = $this->afterCreating
            ->reject(fn (Closure $callback): bool => (new ReflectionFunction($callback))->getClosureThis() instanceof WritesDefaultBlogPostTranslation)
            ->values();

        if ($step !== null) {
            $callbacks->push($step->__invoke(...));
        }

        return $this->newInstance(['afterCreating' => new Collection($callbacks->all())]);
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
     * Record $user as the post's creator (story 0064b, D-7).
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by' => $user->id]);
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
