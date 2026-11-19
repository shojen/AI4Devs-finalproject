<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Refuses a numbering format whose next number already exists (story 0101b, FD-16; 0100 FD-12,
 * 0101a FD-11). For each series it composes the number the series would issue next under the
 * proposed format (an unlocked read of the counter) and looks for it in `invoices.number`,
 * case-insensitively by collation. The error goes on that series' prefix.
 */
class AssertNumberingFormatIsFree
{
    public function __construct(
        private readonly AllocateInvoiceNumber $allocateInvoiceNumber,
        private readonly ComposeInvoiceNumber $composeInvoiceNumber,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(InvoiceSetting $proposed, CarbonInterface $at): void
    {
        $errors = [];

        foreach (InvoiceType::cases() as $series) {
            $counter = $this->allocateInvoiceNumber->nextCounter($series, $proposed, $at);
            $number = ($this->composeInvoiceNumber)($series, $proposed, $this->allocateInvoiceNumber->toAppTimezone($at), $counter);

            if (Invoice::query()->where('number', $number)->exists()) {
                $errors["{$series->value}_prefix"] = [__('invoice-settings.numbering.next_number_exists')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
