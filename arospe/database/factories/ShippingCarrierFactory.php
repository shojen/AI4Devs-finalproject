<?php

namespace Database\Factories;

use App\Models\ShippingCarrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingCarrier>
 */
class ShippingCarrierFactory extends Factory
{
    /**
     * Define the model's default state: a plain, active carrier with a
     * unique code (the table's only unique column -- the classic factory
     * collision otherwise). A leading `F-` marks every factory-generated
     * code as structurally distinct from the seeder's own four real codes
     * (`SEUR`/`CRRS`/`MRW`/`DHL`) -- Phase 5 code-review finding N4: a bare
     * 4-letter `lexify('????')` could otherwise, however rarely, generate
     * one of those exact strings in a test that mixes seeded and
     * factory-created rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'F-'.strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->unique()->company(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the carrier is inactive.
     *
     * `is_active` is not mass-assignable on the model (see
     * ShippingCarrier's own docblock) -- a factory state CAN still set it,
     * matching UserFactory's identical shape for the non-fillable `status`
     * column.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
