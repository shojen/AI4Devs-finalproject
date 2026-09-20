<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised by RecordRefund, after its transaction commits, when the refund it
 * just wrote left every line item of the order fully refunded.
 *
 * Carries the order's identifier only, never a hydrated Order: the listener
 * re-reads the row under a lock, so a stale in-memory instance must not be
 * reachable from here at all. Deliberately not queued and not broadcast.
 */
class OrderFullyRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
    ) {}
}
