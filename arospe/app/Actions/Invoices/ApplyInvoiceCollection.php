<?php

namespace App\Actions\Invoices;

use App\Enums\OrderPaymentType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use LogicException;

/**
 * The ungated writer of the five collection columns of invoices (story 0106, FD-3), then the
 * collection sync of the order. Reused by story 0106a. Authorizes nothing and refuses nothing about
 * state: callers (CollectInvoice and later ones) have already locked the order and the invoices
 * and decided the invoices may be collected. `collected_at` is the server clock.
 */
class ApplyInvoiceCollection
{
    public function __construct(
        private readonly SyncOrderCollection $syncOrderCollection,
    ) {}

    /**
     * @param  list<Invoice>  $lockedInvoices
     *
     * @throws LogicException
     */
    public function __invoke(Order $lockedOrder, array $lockedInvoices, PaymentMethod $method, OrderPaymentType $type, User $actor): void
    {
        $at = now();

        foreach ($lockedInvoices as $invoice) {
            if ($invoice->order_id !== $lockedOrder->getKey()) {
                throw new LogicException('An invoice of another order cannot be collected with this order.');
            }

            $invoice->forceFill([
                'collected_at' => $at,
                'collected_by' => $actor->getKey(),
                'collection_payment_method_id' => $method->getKey(),
                'collection_type' => $type->value,
                'collection_carried_over' => false,
            ])->save();
        }

        ($this->syncOrderCollection)($lockedOrder);
    }
}
