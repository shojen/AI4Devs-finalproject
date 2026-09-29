<?php

namespace Database\Factories;

use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    /**
     * Define the model's default state: a plain 0-2kg bracket, matching the
     * prototype's own reference row (docs/arospe-handoff/project/js/envios.js).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'shipping_carrier_id' => ShippingCarrier::factory(),
            'shipping_zone_id' => ShippingZone::factory(),
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => fake()->randomFloat(2, 1, 20),
            'delivery_estimate' => '24-48h',
        ];
    }

    /**
     * Indicate that the rate has no upper weight limit -- the "and above"
     * open-ended top tier (D-4).
     */
    public function openEnded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_weight_kg' => null,
        ]);
    }

    /**
     * Set an explicit weight bracket.
     */
    public function bracket(string $min, ?string $max): static
    {
        return $this->state(fn (array $attributes): array => [
            'min_weight_kg' => $min,
            'max_weight_kg' => $max,
        ]);
    }

    /**
     * Set an explicit price.
     */
    public function pricedAt(string $price): static
    {
        return $this->state(fn (array $attributes): array => [
            'price' => $price,
        ]);
    }
}
