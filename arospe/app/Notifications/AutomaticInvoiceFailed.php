<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Story 0105 -- tells the people who can fix it that the automatic invoicing of a new order failed
 * at one stage (`issue` or `send`). `database` channel only, never queued.
 *
 * The payload holds only structural values the viewer renders through `invoices.automatic.*`; it
 * never carries the exception text (it can contain fiscal data, paths or credentials), which goes
 * to the log through report().
 */
class AutomaticInvoiceFailed extends Notification
{
    public function __construct(
        private readonly string $orderId,
        private readonly string $orderNumber,
        private readonly string $stage,
        private readonly string $reasonKey,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{order_id: string, order_number: string, stage: string, reason_key: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'stage' => $this->stage,
            'reason_key' => $this->reasonKey,
        ];
    }
}
