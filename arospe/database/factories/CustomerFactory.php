<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state — a fully-populated, valid customer
     * (story 0041): name, a unique email, phone, and both addresses.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'shipping_address_line1' => $this->faker->streetAddress(),
            'shipping_address_line2' => $this->addressLine2(),
            'shipping_city' => $this->faker->city(),
            'shipping_postal_code' => $this->faker->postcode(),
            'shipping_province' => $this->province(),
            'shipping_country' => 'ES',
            'billing_address_line1' => $this->faker->streetAddress(),
            'billing_address_line2' => $this->addressLine2(),
            'billing_city' => $this->faker->city(),
            'billing_postal_code' => $this->faker->postcode(),
            'billing_province' => $this->province(),
            'billing_country' => 'ES',
        ];
    }

    /**
     * Only the identifying details — name and email — with every optional
     * column left `null` (D-3: name + email are required, everything else
     * is optional).
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone' => null,
            'shipping_address_line1' => null,
            'shipping_address_line2' => null,
            'shipping_city' => null,
            'shipping_postal_code' => null,
            'shipping_province' => null,
            'shipping_country' => null,
            'billing_address_line1' => null,
            'billing_address_line2' => null,
            'billing_city' => null,
            'billing_postal_code' => null,
            'billing_province' => null,
            'billing_country' => null,
        ]);
    }

    /**
     * Override the faked, unique email with an explicit one — for the
     * duplicate-email/collision test cases, so they never depend on
     * Faker's own uniqueness pool.
     */
    public function withEmail(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => $email,
        ]);
    }

    /**
     * A "Piso N, Puerta X"-shaped second address line, built from Faker
     * methods Faker\Generator's own declared method-tag stubs actually
     * list -- Faker's own `secondaryAddress()` (Address provider) is not
     * one of them, so it fails Larastan level 7 even though it resolves
     * fine at runtime.
     */
    private function addressLine2(): string
    {
        return 'Piso '.$this->faker->buildingNumber().', Puerta '.$this->faker->randomLetter();
    }

    /**
     * A Spanish province name. Faker's own `state()` (Address provider) is,
     * like `secondaryAddress()` above, not one of Faker\Generator's
     * declared method-tag stubs, so `randomElement()` (which is declared)
     * is used instead over a small, real list.
     */
    private function province(): string
    {
        return $this->faker->randomElement([
            'Madrid', 'Barcelona', 'Valencia', 'Sevilla', 'Málaga',
            'Alicante', 'Vizcaya', 'Zaragoza', 'Las Palmas', 'Murcia',
        ]);
    }
}
