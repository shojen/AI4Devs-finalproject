<?php

namespace App\Actions\Invoices;

use App\Actions\Orders\ToNumericString;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;

/**
 * The invoicing balance of an order (story 0101b, shared definitions, FD-8, FD-9): what is
 * billable, what the invoices already carry (signed, correctives are negative), what is left
 * to invoice (never negative) and the same split into base, tax and shipping.
 *
 * Story 0106 adds the collection side: `netCollected` (the signed sum of the collected standard
 * invoices plus the correctives of collected invoices; for a legacy order, which has no invoice and
 * a paid-like stored status, its billable amount), `debt` (`max(0.00, billable - netCollected)`) and
 * `isLegacy`.
 *
 * Pass `$locking = true` from inside an issuing transaction: under REPEATABLE READ a plain
 * SELECT reads the transaction snapshot, not the latest committed invoices, which would break
 * the cap (FD-12). The locking read sees them.
 */
class ComputeOrderInvoiceBalance
{
    public function __construct(
        private readonly ToNumericString $toNumericString,
    ) {}

    /**
     * @return array{billable: numeric-string, netInvoiced: numeric-string, uninvoiced: numeric-string, liveCount: int, netCollected: numeric-string, debt: numeric-string, isLegacy: bool, components: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}, invoicedComponents: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}}
     */
    public function __invoke(Order $order, bool $locking = false): array
    {
        $query = Invoice::query()->where('order_id', $order->getKey());

        $invoices = $locking ? $query->lockForUpdate()->get() : $query->get();

        $net = '0.00';
        $invoiced = ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00'];
        $liveCount = 0;
        $netCollected = '0.00';
        $collectedIds = $invoices->filter(fn (Invoice $invoice): bool => $invoice->type === InvoiceType::Standard && $invoice->collected_at !== null)
            ->pluck('id')->flip();

        foreach ($invoices as $invoice) {
            $isCollected = $invoice->type === InvoiceType::Standard
                ? $invoice->collected_at !== null
                : $collectedIds->has($invoice->corrects_invoice_id);

            if ($isCollected) {
                $netCollected = bcadd($netCollected, ($this->toNumericString)($invoice->total), 2);
            }

            $net = bcadd($net, ($this->toNumericString)($invoice->total), 2);
            $invoiced['base'] = bcadd($invoiced['base'], ($this->toNumericString)($invoice->subtotal), 2);
            $invoiced['tax'] = bcadd($invoiced['tax'], ($this->toNumericString)($invoice->tax_amount), 2);
            $invoiced['shipping'] = bcadd($invoiced['shipping'], ($this->toNumericString)($invoice->shipping_amount), 2);

            if ($invoice->type === InvoiceType::Standard && $invoice->voided_at === null) {
                $liveCount++;
            }
        }

        $billable = ($this->toNumericString)($order->billableAmount());
        $uninvoiced = bcsub($billable, $net, 2);
        $isLegacy = $invoices->isEmpty() && in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true);

        if ($isLegacy) {
            $netCollected = $billable;
        }

        $debt = bcsub($billable, $netCollected, 2);

        if (bccomp($debt, '0.00', 2) < 0) {
            $debt = '0.00';
        }

        if (bccomp($uninvoiced, '0.00', 2) < 0) {
            $uninvoiced = '0.00';
        }

        $components = ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00'];

        if ($order->status !== OrderStatus::Cancelled) {
            $components = [
                'base' => bcsub(($this->toNumericString)($order->subtotal), ($this->toNumericString)($order->refunded_amount), 2),
                'tax' => ($this->toNumericString)($order->tax_amount),
                'shipping' => ($this->toNumericString)($order->shipping_amount),
            ];
        }

        return [
            'billable' => $billable,
            'netInvoiced' => $net,
            'uninvoiced' => $uninvoiced,
            'liveCount' => $liveCount,
            'netCollected' => $netCollected,
            'debt' => $debt,
            'isLegacy' => $isLegacy,
            'components' => $components,
            'invoicedComponents' => $invoiced,
        ];
    }
}
