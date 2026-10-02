<?php

namespace Database\Factories;

use App\Enums\OrderPaymentType;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    /**
     * Define the model's default state: a bank-transfer payment for a new order.
     *
     * `payment_method_id` reuses an existing `payment_methods` row when one exists (its `code` is a
     * fixed literal, so a second nested factory would collide on the UNIQUE index), exactly as
     * OrderFactory does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'payment_method_id' => fn (): string => PaymentMethod::query()->value('id')
                ?? PaymentMethod::factory()->create()->getKey(),
            'type' => OrderPaymentType::Transfer,
            'paid_at' => now(),
        ];
    }
}
