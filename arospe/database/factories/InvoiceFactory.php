<?php

namespace Database\Factories;

use App\Actions\Orders\ToNumericString;
use App\Enums\InvoiceCorrectionReason;
use App\Enums\InvoiceType;
use App\Enums\OrderPaymentType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 *
 * A bare row is a valid standard invoice on its own freshly created order, with a unique
 * `number` that cannot collide with the F/R formats tests assert on. Any column may be
 * overridden; the model guards only refuse later updates, never inserts.
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state: a standard invoice for 100.00 plus 21.00 tax.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'order_number' => fn (array $attributes): string => (string) Order::query()->whereKey($attributes['order_id'])->value('order_number'),
            'type' => InvoiceType::Standard,
            'reason' => null,
            'series' => 'standard',
            'sequence_year' => 2026,
            'sequence_number' => fake()->unique()->numberBetween(1, 4_000_000),
            'number' => 'FT-'.fake()->unique()->numerify('########'),
            'issued_at' => now(),
            'corrects_invoice_id' => null,
            'voided_at' => null,
            'template' => 'classic',
            'primary_color' => null,
            'accent_color' => null,
            'logo_media_id' => null,
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada',
            'issuer_tax_id' => 'ESB12345678',
            'issuer_address_line1' => 'Calle Mayor 1',
            'issuer_address_line2' => null,
            'issuer_city' => 'Madrid',
            'issuer_postal_code' => '28001',
            'issuer_province' => null,
            'issuer_country' => 'ES',
            'recipient_name' => fake()->name(),
            'recipient_tax_id' => null,
            'recipient_address_line1' => fake()->streetAddress(),
            'recipient_address_line2' => null,
            'recipient_city' => fake()->city(),
            'recipient_postal_code' => '08001',
            'recipient_province' => null,
            'recipient_country' => 'ES',
            'tax_rate' => null,
            'subtotal' => '100.00',
            'shipping_amount' => '0.00',
            'tax_amount' => '21.00',
            'total' => '121.00',
            'issued_by' => null,
        ];
    }

    /**
     * A standard invoice (the default state, named for readability).
     */
    public function standard(): static
    {
        return $this->state(fn (): array => [
            'type' => InvoiceType::Standard,
            'series' => 'standard',
            'reason' => null,
            'corrects_invoice_id' => null,
        ]);
    }

    /**
     * A corrective of the given invoice: same order, negated amounts, corrective series.
     */
    public function corrective(Invoice $of): static
    {
        $negate = fn (string $amount): string => bcmul(app(ToNumericString::class)($amount), '-1', 2);

        return $this->state(fn (): array => [
            'type' => InvoiceType::Corrective,
            'series' => 'corrective',
            'reason' => InvoiceCorrectionReason::Refund,
            'corrects_invoice_id' => $of->id,
            'order_id' => $of->order_id,
            'order_number' => $of->order_number,
            'number' => 'RT-'.fake()->unique()->numerify('########'),
            'subtotal' => $negate($of->subtotal),
            'shipping_amount' => $negate($of->shipping_amount),
            'tax_amount' => $negate($of->tax_amount),
            'total' => $negate($of->total),
        ]);
    }

    /**
     * A collected invoice: `collected_at` set, collected through the first existing payment method
     * (or a new one), by no actor and not carried over unless overridden.
     */
    public function collected(): static
    {
        return $this->state(fn (): array => [
            'collected_at' => now(),
            'collection_payment_method_id' => fn (): string => PaymentMethod::query()->value('id')
                ?? PaymentMethod::factory()->create()->getKey(),
            'collection_type' => OrderPaymentType::Transfer,
            'collection_carried_over' => false,
        ]);
    }

    /**
     * Issue the invoice for the given order.
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);
    }
}
