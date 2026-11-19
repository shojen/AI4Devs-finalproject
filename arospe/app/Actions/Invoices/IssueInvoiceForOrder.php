<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The ungated core of issuing a standard invoice (story 0101b, FD-12, FD-13): it holds the
 * transaction and authorizes nothing. Only already-authorized actions or system follow-ons may
 * call it (an architecture test lists the callers): IssueInvoice, SendOrderInvoices,
 * MarkOrderAsPaid and IssueAutomaticInvoice.
 *
 * One transaction: the `orders` row FOR UPDATE first (every invoice writer locks the order
 * first, which makes the cap safe), then CreateStandardInvoice (sequence lock, then the invoices
 * locking read, then the inserts; a null amount means the whole uninvoiced amount). A collision on
 * `invoices_number_unique` rolls everything back, counter included, and is reported after the
 * rollback as a validation error on `number` (FD-2); any other failure propagates.
 */
class IssueInvoiceForOrder
{
    public function __construct(
        private readonly CreateStandardInvoice $createStandardInvoice,
    ) {}

    /**
     * @param  string|null  $amount  A normalised decimal string; null means the whole uninvoiced amount.
     *
     * @throws ValidationException
     */
    public function __invoke(Order $order, ?string $amount, ?User $actor): Invoice
    {
        try {
            return DB::transaction(function () use ($order, $amount, $actor): Invoice {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

                if (! $locked instanceof Order) {
                    throw new LogicException('The order row could not be locked.');
                }

                return ($this->createStandardInvoice)($locked, $amount, $actor);
            });
        } catch (QueryException $exception) {
            if ($this->isNumberCollision($exception)) {
                throw ValidationException::withMessages(['number' => [__('invoices.errors.number_collision')]]);
            }

            throw $exception;
        }
    }

    private function isNumberCollision(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), 'invoices_number_unique');
    }
}
