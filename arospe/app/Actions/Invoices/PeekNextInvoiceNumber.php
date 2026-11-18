<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\InvoiceSettingValidationRules;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Read-only preview of the next invoice number of a series (story 0101a, FD-7, FD-12). Takes no
 * lock and writes nothing. A candidate is raw, possibly partial screen input: only the series'
 * numbering fields and the reset flag are read, validated as fields before any model is built.
 * Cross-field rules stay with SaveInvoiceSettings.
 */
class PeekNextInvoiceNumber
{
    use InvoiceSettingValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly AllocateInvoiceNumber $allocateInvoiceNumber,
        private readonly ComposeInvoiceNumber $composeInvoiceNumber,
    ) {}

    /**
     * @param  array<string, mixed>|null  $candidate
     *
     * @throws ValidationException
     */
    public function __invoke(InvoiceType $series, ?array $candidate = null, ?CarbonInterface $at = null): string
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'viewAny',
            InvoiceSetting::class,
            targetType: 'invoice_setting',
            targetId: InvoiceSetting::SINGLETON_ID,
        );

        $at ??= now();
        $settings = InvoiceSetting::current();

        if ($candidate !== null) {
            $key = $series->value;
            $subset = ["{$key}_prefix", "{$key}_year_format", "{$key}_separator", "{$key}_padding", 'reset_numbering_yearly'];

            $data = $this->normalizeInvoiceSettingAttributes([
                ...Arr::only($settings->attributesToArray(), $subset),
                ...Arr::only($candidate, $subset),
            ]);

            $validated = Validator::make($data, Arr::only($this->invoiceSettingRules(), $subset), [], $this->attributeNames())->validate();

            $settings = $settings->replicate()->forceFill($validated);
        }

        $counter = $this->allocateInvoiceNumber->nextCounter($series, $settings, $at);

        return ($this->composeInvoiceNumber)($series, $settings, $this->allocateInvoiceNumber->toAppTimezone($at), $counter);
    }
}
