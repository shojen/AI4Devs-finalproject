<?php

namespace Database\Factories;

use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state: the seeded bank-transfer method
     * with no IBAN configured yet.
     *
     * `code` is deliberately not randomised -- one method genuinely exists
     * in this domain this phase, so a default matching seeded reality is
     * more honest than a fake unique value. Because `code` is unique, a
     * test needing a second row must override it explicitly; that friction
     * is intentional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => PaymentMethodCode::BankTransfer->value,
            'iban' => null,
        ];
    }

    /**
     * Configure the method with a given IBAN.
     */
    public function withIban(string $iban): static
    {
        return $this->state(fn (array $attributes) => [
            'iban' => $iban,
        ]);
    }
}
