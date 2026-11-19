<?php

namespace App\Listeners;

use App\Enums\InvoiceDeliveryStatus;
use App\Models\InvoiceDelivery;
use App\Notifications\InvoiceSent;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Story 0104 FD-10: when the mail of an InvoiceSent leaves, its rows become `sent` (and lose any
 * failure data of an earlier attempt). Every other notification is ignored. Registered by Laravel
 * 13's auto-discovery of app/Listeners.
 */
class MarkInvoiceDeliverySent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof InvoiceSent || $event->channel !== 'mail') {
            return;
        }

        InvoiceDelivery::query()
            ->whereIn('id', $event->notification->deliveryIds)
            ->where('status', '!=', InvoiceDeliveryStatus::Sent->value)
            ->update([
                'status' => InvoiceDeliveryStatus::Sent->value,
                'sent_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);
    }
}
