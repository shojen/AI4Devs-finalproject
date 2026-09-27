<?php

namespace Database\Factories;

use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreLanguage>
 */
class StoreLanguageFactory extends Factory
{
    /**
     * Define the model's default state: a plain, active, non-default entry.
     *
     * `code` uses `fake()->unique()->lexify('??')` because it is the table's only unique column
     * -- the classic factory collision otherwise. Factory writes bypass #[Fillable([])] entirely
     * (Laravel's Factory::create() wraps model instantiation in Model::unguarded()), so no
     * forceFill()/forceCreate() gymnastics are needed here, unlike the real actions.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('??'),
            'name' => fake()->unique()->word(),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * Flag the entry as the catalog default. Deliberately also forces is_active true: an
     * inactive default violates D6 and no test should have to also chain ->inactive(false) to
     * get a normal default row.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the entry is inactive (removed) -- D5.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
