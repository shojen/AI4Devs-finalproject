<?php

namespace App\Actions\Invoices;

use App\Models\Order;

/**
 * The shared hook every totals change of an order goes through (story 0106, FD-15). Called from
 * RecalculateOrderTotals, inside the caller's transaction, on the locked order. In story 0106 it
 * only synchronises the collection; story 0108 adds the out-of-date mark of the invoices.
 * Authorizes nothing: a collaborator of already-authorized actions.
 */
class AfterOrderTotalsChanged
{
    public function __construct(
        private readonly SyncOrderCollection $syncOrderCollection,
    ) {}

    public function __invoke(Order $lockedOrder): void
    {
        ($this->syncOrderCollection)($lockedOrder);
    }
}
