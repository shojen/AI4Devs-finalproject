<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogCategory>
 */
class BlogCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `normalized_name` is deliberately not set: BlogCategory's saving hook derives it, which is
     * itself a small proof the hook fires on the insert path. Faker's unique() only guards one
     * Faker instance, never the database (R-5), so a test needing a guaranteed-distinct or
     * guaranteed-colliding name passes it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
        ];
    }
}
