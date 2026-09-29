<?php

namespace Database\Factories;

use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogTag>
 */
class BlogTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `normalized_name` is deliberately not set: BlogTag's saving hook derives it, which is itself a
     * small proof the hook fires on the insert path. Faker's unique() only guards one Faker
     * instance, never the database (R-5), so a test needing a guaranteed-distinct or
     * guaranteed-colliding name passes it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
