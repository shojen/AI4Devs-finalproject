<?php

namespace Database\Factories;

use App\Models\InvoiceSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSetting>
 *
 * `id` is always InvoiceSetting::SINGLETON_ID: a second bare create() in one test duplicate-key
 * errors by design, like LocaleSettingFactory.
 */
class InvoiceSettingFactory extends Factory
{
    /**
     * Define the model's default state: the table's column defaults, no issuer data.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => InvoiceSetting::SINGLETON_ID,
        ];
    }

    /**
     * Complete issuer data (legal name, tax ID, address).
     */
    public function withIssuer(): static
    {
        return $this->state(fn (): array => [
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada',
            'issuer_tax_id' => 'ESB12345678',
            'issuer_address_line1' => 'Calle Mayor 1',
            'issuer_city' => 'Madrid',
            'issuer_postal_code' => '28001',
            'issuer_country' => 'ES',
        ]);
    }

    /**
     * Automatic invoicing on.
     */
    public function autoIssue(): static
    {
        return $this->state(fn (): array => ['auto_issue_on_order' => true]);
    }
}
