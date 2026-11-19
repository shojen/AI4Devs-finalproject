<?php

namespace Database\Factories;

use App\Enums\InvoiceDeliveryStatus;
use App\Models\InvoiceDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDelivery>
 */
class InvoiceDeliveryFactory extends Factory
{
    /**
     * Define the model's default state: a queued original on a fresh standard invoice.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new()->standard(),
            'recipient_email' => fake()->safeEmail(),
            'is_copy' => false,
            'status' => InvoiceDeliveryStatus::Queued,
            'sent_by' => null,
            'queued_at' => now(),
            'sent_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * A delivery still waiting for the queue.
     */
    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceDeliveryStatus::Queued,
            'sent_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * A delivery the mailer accepted.
     */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceDeliveryStatus::Sent,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * A delivery that failed.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceDeliveryStatus::Failed,
            'sent_at' => null,
            'failed_at' => now(),
            'failure_reason' => 'The mail transport refused the message.',
        ]);
    }

    /**
     * A delivery that is a copy rather than the original.
     */
    public function copy(): static
    {
        return $this->state(fn (): array => ['is_copy' => true]);
    }
}
