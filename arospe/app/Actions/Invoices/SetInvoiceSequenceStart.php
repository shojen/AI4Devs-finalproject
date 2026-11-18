<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sets the starting counter of a series, once, before it has allocated anything (story 0101a,
 * FD-8). Authorization comes first so a refusal never reveals whether the series has issued.
 */
class SetInvoiceSequenceStart
{
    /** The largest start: the counter column is an unsigned INT and `startAt - 1` is stored. */
    private const MAX_START = 4294967294;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(InvoiceType $series, int $startAt): void
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            InvoiceSetting::class,
            targetType: 'invoice_setting',
            targetId: InvoiceSetting::SINGLETON_ID,
        );

        if ($startAt < 1 || $startAt > self::MAX_START) {
            throw ValidationException::withMessages(['start_at' => [__('invoice-settings.numbering.start_invalid')]]);
        }

        DB::transaction(function () use ($series, $startAt): void {
            InvoiceSetting::query()->lock('for share')->find(InvoiceSetting::SINGLETON_ID);

            $sequence = InvoiceSequence::query()->where('series', $series->value)->lockForUpdate()->sole();

            if ($sequence->last_issued_at !== null) {
                throw ValidationException::withMessages(['start_at' => [__('invoice-settings.numbering.start_already_issued')]]);
            }

            $previous = $sequence->last_number;

            $sequence->forceFill(['last_number' => $startAt - 1])->save();

            Log::info('Invoice sequence start set', [
                'actor_id' => Auth::id(),
                'series' => $series->value,
                'old_last_number' => $previous,
                'new_last_number' => $startAt - 1,
            ]);
        });
    }
}
