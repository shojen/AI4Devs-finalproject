<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSetting;
use Carbon\CarbonImmutable;

/**
 * The state of both invoice counters for the settings screen (story 0103). A pure read: one query on
 * `invoice_sequences`. A series counts as issued when `last_issued_at` is set; a starting number
 * moves the counter without issuing anything.
 */
class ReadInvoiceSequenceState
{
    public function __construct(private LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt) {}

    /**
     * @return array{standard: array{hasIssued: bool, lastNumber: int, lastYear: int|null, lastIssuedAt: CarbonImmutable|null}, corrective: array{hasIssued: bool, lastNumber: int, lastYear: int|null, lastIssuedAt: CarbonImmutable|null}}
     */
    public function __invoke(): array
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'viewAny',
            InvoiceSetting::class,
            targetType: 'invoice_setting',
            targetId: InvoiceSetting::SINGLETON_ID,
        );

        $sequences = InvoiceSequence::query()->get()->keyBy('series');

        return [
            'standard' => $this->stateOf($sequences->get(InvoiceType::Standard->value)),
            'corrective' => $this->stateOf($sequences->get(InvoiceType::Corrective->value)),
        ];
    }

    /**
     * @return array{hasIssued: bool, lastNumber: int, lastYear: int|null, lastIssuedAt: CarbonImmutable|null}
     */
    private function stateOf(?InvoiceSequence $sequence): array
    {
        /** @var CarbonImmutable|null $lastIssuedAt */
        $lastIssuedAt = $sequence?->last_issued_at;

        return [
            'hasIssued' => $lastIssuedAt !== null,
            'lastNumber' => $sequence->last_number ?? 0,
            'lastYear' => $sequence?->last_year,
            'lastIssuedAt' => $lastIssuedAt,
        ];
    }
}
