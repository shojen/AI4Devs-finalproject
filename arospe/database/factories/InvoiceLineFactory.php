<?php

namespace Database\Factories;

use App\Enums\InvoiceLineKind;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /**
     * Define the model's default state: one item line on a fresh standard invoice, no order line.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new()->standard(),
            'order_item_id' => null,
            'position' => 1,
            'kind' => InvoiceLineKind::Item,
            'description' => fake()->words(3, true),
            'sku' => strtoupper(fake()->bothify('??-####')),
            'quantity' => 2,
            'unit_price' => '50.00',
            'tax_rate' => '21.000',
            'line_subtotal' => '100.00',
            'tax_amount' => '21.00',
            'line_total' => '121.00',
        ];
    }
}
