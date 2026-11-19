<?php

namespace App\Actions\Invoices;

use App\Actions\Orders\ToNumericString;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The transaction-bound writer of a standard invoice (story 0101b, FD-5, FD-6, FD-12, FD-13).
 * Authorizes nothing: callers are the ungated issuing core and the system follow-ons of later
 * stories. It refuses to run outside a transaction, for an order that was not read (locked) in
 * the caller's transaction, or with a non-positive or over-ceiling total, and writes nothing
 * in any refusal.
 *
 * Lock order inside the transaction: the `orders` row FOR UPDATE (re-taken here, so the writer
 * does not trust the caller's instance), then the settings FOR SHARE and the sequence row FOR
 * UPDATE through AllocateInvoiceNumber, then the locking read of the order's invoices, then the
 * inserts. The sequence lock comes before the invoices read so that two issuers for different
 * orders with no invoices yet never meet in a gap-lock/insert-intention deadlock. `$at` is taken
 * once, after the order lock, and is both the allocation instant and `issued_at`.
 */
class CreateStandardInvoice
{
    /** The largest value a decimal(10,2) column holds. */
    private const MAX_TOTAL = '99999999.99';

    public function __construct(
        private readonly ComputeOrderInvoiceBalance $computeOrderInvoiceBalance,
        private readonly SplitInvoiceAmount $splitInvoiceAmount,
        private readonly BuildInvoiceLines $buildInvoiceLines,
        private readonly AssertInvoicesAreCurrent $assertInvoicesAreCurrent,
        private readonly ResolveInvoiceLocale $resolveInvoiceLocale,
        private readonly AllocateInvoiceNumber $allocateInvoiceNumber,
        private readonly ToNumericString $toNumericString,
    ) {}

    /**
     * @param  string|null  $amount  A decimal string; null means the whole uninvoiced amount.
     * @param  array{collected_at?: mixed, collected_by?: string|null, collection_payment_method_id?: string|null, collection_type?: string|null, collection_carried_over?: bool}|null  $collected  The collection record of an invoice born collected (0106, 0108).
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function __invoke(Order $lockedOrder, ?string $amount, ?User $actor, ?array $collected = null): Invoice
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A standard invoice must be created inside the transaction that locked its order.');
        }

        if (! $lockedOrder->exists || $lockedOrder->wasRecentlyCreated) {
            throw new LogicException('The order must be read under its row lock in the current transaction.');
        }

        if ($amount !== null) {
            $amount = $this->normaliseAmount($amount);
        }

        $lockedOrder = Order::query()->lockForUpdate()->findOrFail($lockedOrder->getKey());

        if (! $lockedOrder instanceof Order) {
            throw new LogicException('The order row could not be locked.');
        }

        ($this->assertInvoicesAreCurrent)($lockedOrder);

        if ($lockedOrder->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.cancelled')]]);
        }

        $at = now();
        $locked = $this->allocateInvoiceNumber->lock([InvoiceType::Standard], $at);
        $settings = $locked['settings'];

        $balance = ($this->computeOrderInvoiceBalance)($lockedOrder, true);

        if (bccomp($balance['uninvoiced'], '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.nothing_left')]]);
        }

        $amount ??= $balance['uninvoiced'];

        if (bccomp($amount, $balance['uninvoiced'], 2) > 0) {
            throw ValidationException::withMessages(['amount' => [__('invoices.errors.amount_exceeds_remaining', ['remaining' => $balance['uninvoiced']])]]);
        }

        if (blank($settings->issuer_legal_name) || blank($settings->issuer_tax_id) || blank($settings->issuer_address_line1)) {
            throw ValidationException::withMessages(['issuer' => [__('invoices.errors.issuer_incomplete')]]);
        }

        $customer = Customer::withTrashed()->sharedLock()->find($lockedOrder->customer_id);

        if ($customer === null) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.customer_missing')]]);
        }

        [$parts, $lines] = $this->partsAndLines($lockedOrder, $amount, $balance);
        $number = $this->allocateInvoiceNumber->__invoke(InvoiceType::Standard, $at);

        $invoice = Invoice::forceCreate([
            'order_id' => $lockedOrder->getKey(),
            'order_number' => $lockedOrder->order_number,
            'type' => InvoiceType::Standard,
            'reason' => null,
            'series' => $number['series'],
            'sequence_year' => $number['sequence_year'],
            'sequence_number' => $number['sequence_number'],
            'number' => $number['number'],
            'issued_at' => $at,
            'corrects_invoice_id' => null,
            'voided_at' => null,
            'template' => $settings->template->value,
            'primary_color' => $settings->primary_color,
            'accent_color' => $settings->accent_color,
            'logo_media_id' => $settings->logo_media_id,
            'issuer_legal_name' => $settings->issuer_legal_name,
            'issuer_tax_id' => $settings->issuer_tax_id,
            'issuer_address_line1' => $settings->issuer_address_line1,
            'issuer_address_line2' => $settings->issuer_address_line2,
            'issuer_city' => $settings->issuer_city,
            'issuer_postal_code' => $settings->issuer_postal_code,
            'issuer_province' => $settings->issuer_province,
            'issuer_country' => $settings->issuer_country,
            'recipient_name' => $customer->name,
            'recipient_tax_id' => $customer->tax_id,
            'recipient_address_line1' => $lockedOrder->billing_address_line1,
            'recipient_address_line2' => $lockedOrder->billing_address_line2,
            'recipient_city' => $lockedOrder->billing_city,
            'recipient_postal_code' => $lockedOrder->billing_postal_code,
            'recipient_province' => $lockedOrder->billing_province,
            'recipient_country' => $lockedOrder->billing_country,
            'tax_rate' => $lockedOrder->tax_rate,
            'subtotal' => $parts['base'],
            'shipping_amount' => $parts['shipping'],
            'tax_amount' => $parts['tax'],
            'total' => $amount,
            'issued_by' => $actor?->getKey(),
            'collected_at' => $collected['collected_at'] ?? null,
            'collected_by' => $collected['collected_by'] ?? null,
            'collection_payment_method_id' => $collected['collection_payment_method_id'] ?? null,
            'collection_type' => $collected['collection_type'] ?? null,
            'collection_carried_over' => $collected['collection_carried_over'] ?? false,
        ]);

        foreach ($lines as $line) {
            InvoiceLine::forceCreate($line + ['invoice_id' => $invoice->getKey()]);
        }

        return $invoice;
    }

    /**
     * @return numeric-string
     *
     * @throws LogicException
     */
    private function normaliseAmount(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new LogicException('The invoice total must be a decimal string.');
        }

        $amount = bcadd(($this->toNumericString)($amount), '0', 2);

        if (bccomp($amount, '0.01', 2) < 0 || bccomp($amount, self::MAX_TOTAL, 2) > 0) {
            throw new LogicException('The invoice total must be between 0.01 and '.self::MAX_TOTAL.'.');
        }

        return $amount;
    }

    /**
     * The invoice's base, tax and shipping and its lines (FD-6): itemised exactly when the amount
     * is the whole billable amount, nothing is net invoiced and nothing was refunded; otherwise
     * one concept line over the proportional split.
     *
     * @param  numeric-string  $amount
     * @param  array{billable: numeric-string, netInvoiced: numeric-string, uninvoiced: numeric-string, liveCount: int, components: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}, invoicedComponents: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}}  $balance
     * @return array{0: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}, 1: list<array<string, mixed>>}
     */
    private function partsAndLines(Order $order, string $amount, array $balance): array
    {
        $itemised = bccomp($amount, $balance['billable'], 2) === 0
            && bccomp($balance['netInvoiced'], '0.00', 2) === 0
            && bccomp(($this->toNumericString)($order->refunded_amount), '0.00', 2) === 0;

        if ($itemised) {
            $items = $order->items()->sharedLock()->orderBy('created_at')->orderBy('id')->get();
            $lines = $this->buildInvoiceLines->itemised($items, $order->tax_amount, $order->tax_rate);
            $toNumeric = $this->toNumericString;

            return [
                ['base' => $toNumeric($order->subtotal), 'tax' => $toNumeric($order->tax_amount), 'shipping' => $toNumeric($order->shipping_amount)],
                $lines,
            ];
        }

        $parts = ($this->splitInvoiceAmount)($amount, $balance['billable'], $balance['uninvoiced'], $balance['components'], $balance['invoicedComponents']);

        return [
            $parts,
            [$this->buildInvoiceLines->adjustment(
                __('invoices.lines.payment_on_account', ['number' => $order->order_number], ($this->resolveInvoiceLocale)()),
                $parts['base'],
                $parts['tax'],
                $order->tax_rate,
            )],
        ];
    }
}
