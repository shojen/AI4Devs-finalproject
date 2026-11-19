<?php

namespace App\Actions\Invoices;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Refuses to go on while the order's invoices are stale after an edit (story 0101b, FD-15,
 * FD-19). Only story 0108 sets and clears the column; every existing order reads current.
 * Called on the order re-read under its row lock. The error key is the one the caller's screen
 * renders (default `invoice`).
 */
class AssertInvoicesAreCurrent
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Order $lockedOrder, string $errorKey = 'invoice'): void
    {
        if ($lockedOrder->isInvoiceOutOfDate()) {
            throw ValidationException::withMessages([$errorKey => [__('invoices.errors.out_of_date')]]);
        }
    }
}
