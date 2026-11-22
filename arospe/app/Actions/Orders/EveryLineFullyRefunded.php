<?php

namespace App\Actions\Orders;

use App\Models\OrderItem;

/**
 * The one definition of "every line of the order is fully refunded" (story 0106, truth table row 1),
 * shared by RecordRefund and the derived payment status so the two cannot drift. An empty set is
 * vacuously true, exactly like the `every()` RecordRefund always used.
 */
class EveryLineFullyRefunded
{
    /**
     * @param  iterable<OrderItem>  $items
     */
    public function __invoke(iterable $items): bool
    {
        foreach ($items as $item) {
            if ($item->refunded_quantity !== $item->quantity) {
                return false;
            }
        }

        return true;
    }
}
