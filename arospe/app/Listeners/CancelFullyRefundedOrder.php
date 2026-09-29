<?php

namespace App\Listeners;

use App\Actions\Orders\AutoCancelFullyRefundedOrder;
use App\Events\OrderFullyRefunded;

/**
 * Thin adapter from OrderFullyRefunded to AutoCancelFullyRefundedOrder.
 * Deliberately synchronous (not ShouldQueue): a status transition's latency
 * is a correctness property, unlike a notification's.
 */
class CancelFullyRefundedOrder
{
    public function __construct(
        private readonly AutoCancelFullyRefundedOrder $autoCancelFullyRefundedOrder,
    ) {}

    public function handle(OrderFullyRefunded $event): void
    {
        ($this->autoCancelFullyRefundedOrder)($event->orderId);
    }
}
