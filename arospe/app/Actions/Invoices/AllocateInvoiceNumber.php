<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Hands out the next gap-free invoice number inside the caller's transaction (story 0101a,
 * FD-1..FD-6). A collaborator: it authorizes nothing, its callers do. The counter row is written
 * in the caller's transaction, so a rollback gives the number back.
 *
 * Lock order (FD-3): `invoice_settings` FOR SHARE first, then the needed sequence rows in one
 * `ORDER BY series FOR UPDATE` query, always the last locks of an issuing transaction.
 */
class AllocateInvoiceNumber
{
    /** The largest value of the unsigned INT counter column. */
    public const MAX_COUNTER = 4294967295;

    private ?InvoiceSetting $lockedSettings = null;

    /** The transaction record the cached lock state belongs to; a different one discards it. */
    private ?DatabaseTransactionRecord $lockedIn = null;

    /** @var array<string, InvoiceSequence> */
    private array $lockedSequences = [];

    public function __construct(
        private readonly ComposeInvoiceNumber $composeInvoiceNumber,
    ) {}

    /**
     * Lock the settings (shared) and the given series rows (exclusive), in that order.
     *
     * @param  list<InvoiceType>  $series
     * @param  CarbonInterface  $at  Part of the contract and reserved: not read yet, callers pass the issuing instant.
     * @return array{settings: InvoiceSetting, sequences: array<string, InvoiceSequence>}
     *
     * @throws LogicException When called outside a transaction or when a series row is missing.
     */
    public function lock(array $series, CarbonInterface $at): array
    {
        $this->assertInTransaction();
        $this->discardStaleState();

        $transaction = $this->currentTransaction();

        if ($transaction === null) {
            throw new LogicException('No transaction record for the invoice connection.');
        }

        $settings = InvoiceSetting::query()->lock('for share')->find(InvoiceSetting::SINGLETON_ID) ?? InvoiceSetting::defaults();

        $keys = array_values(array_unique(array_map(fn (InvoiceType $type): string => $type->value, $series)));

        /** @var array<string, InvoiceSequence> $sequences */
        $sequences = InvoiceSequence::query()
            ->whereIn('series', $keys)
            ->orderBy('series')
            ->lockForUpdate()
            ->get()
            ->keyBy('series')
            ->all();

        if (count($sequences) !== count($keys)) {
            throw new LogicException('An invoice_sequences row is missing; the migration creates both rows.');
        }

        $this->lockedSettings = $settings;
        $this->lockedSequences = $sequences;
        $this->lockedIn = $transaction;

        return ['settings' => $settings, 'sequences' => $sequences];
    }

    /**
     * Take the next number of a series. Reuses the state locked by `lock()`, or locks for itself.
     *
     * @return array{number: string, series: string, sequence_year: int, sequence_number: int}
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function __invoke(InvoiceType $series, CarbonInterface $issuedAt): array
    {
        $this->assertInTransaction();

        $this->discardStaleState();

        if ($this->lockedSettings === null) {
            $this->lock([$series], $issuedAt);
        } elseif (! isset($this->lockedSequences[$series->value])) {
            throw new LogicException("The {$series->value} invoice series was not locked; lock every series up front, in order.");
        }

        $settings = $this->lockedSettings;
        $sequence = $this->lockedSequences[$series->value] ?? null;

        if ($settings === null || $sequence === null) {
            throw new LogicException('The invoice sequence was not locked.');
        }

        $at = $this->toAppTimezone($issuedAt);

        if ($sequence->last_issued_at !== null && $at->lt($sequence->last_issued_at)) {
            throw ValidationException::withMessages(['invoice' => [__('invoice-settings.numbering.clock_skew')]]);
        }

        $next = $this->counterFor($sequence, $settings, $at);

        if ($next > self::MAX_COUNTER) {
            throw ValidationException::withMessages(['number' => [__('invoice-settings.numbering.overflow')]]);
        }

        $sequence->forceFill([
            'last_number' => $next,
            'last_year' => $at->year,
            'last_issued_at' => $at,
        ])->save();

        return [
            'number' => ($this->composeInvoiceNumber)($series, $settings, $at, $next),
            'series' => $series->value,
            'sequence_year' => $at->year,
            'sequence_number' => $next,
        ];
    }

    /**
     * The counter the next allocation of the series would take with the given settings. Read-only,
     * takes no lock.
     */
    public function nextCounter(InvoiceType $series, InvoiceSetting $settings, CarbonInterface $at): int
    {
        $sequence = InvoiceSequence::query()->where('series', $series->value)->first();

        if ($sequence === null) {
            throw new LogicException('An invoice_sequences row is missing; the migration creates both rows.');
        }

        return $this->counterFor($sequence, $settings, $this->toAppTimezone($at));
    }

    /**
     * The year rule of FD-1: restart only when reset is on, the series has allocated before, and
     * the calendar year moved forward; otherwise continue.
     */
    private function counterFor(InvoiceSequence $sequence, InvoiceSetting $settings, CarbonImmutable $at): int
    {
        if ($settings->reset_numbering_yearly && $sequence->last_year !== null && $at->year > $sequence->last_year) {
            return 1;
        }

        return $sequence->last_number + 1;
    }

    /**
     * The FD-1 conversion: every invoice instant is read in the application timezone.
     */
    public function toAppTimezone(CarbonInterface $at): CarbonImmutable
    {
        return $at->toImmutable()->setTimezone((string) config('app.timezone'));
    }

    /**
     * Forget the cached rows unless they were locked in the transaction that is open right now: a
     * rollback (or a commit) ends the locks, and the next transaction gets a new record object.
     */
    private function discardStaleState(): void
    {
        if ($this->lockedIn !== null && $this->lockedIn !== $this->currentTransaction()) {
            $this->lockedSettings = null;
            $this->lockedSequences = [];
            $this->lockedIn = null;
        }
    }

    private function currentTransaction(): ?DatabaseTransactionRecord
    {
        $connection = DB::connection()->getName();

        return app('db.transactions')->getPendingTransactions()
            ->last(fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection);
    }

    /**
     * @throws LogicException
     */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Invoice numbers must be allocated inside a database transaction.');
        }
    }
}
