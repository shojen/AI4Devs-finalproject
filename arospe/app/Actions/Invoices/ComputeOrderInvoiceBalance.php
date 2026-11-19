<?php

namespace App\Actions\Invoices;

use App\Actions\Orders\ToNumericString;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;

/**
 * The invoicing balance of an order (story 0101b, shared definitions, FD-8, FD-9): what is
 * billable, what the invoices already carry (signed, correctives are negative), what is left
 * to invoice (never negative) and the same split into base, tax and shipping.
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
     * @return array{billable: numeric-string, netInvoiced: numeric-string, uninvoiced: numeric-string, liveCount: int, components: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}, invoicedComponents: array{base: numeric-string, tax: numeric-string, shipping: numeric-string}}
     */
    public function __invoke(Order $order, bool $locking = false): array
    {
        $query = Invoice::query()->where('order_id', $order->getKey());

        $invoices = $locking ? $query->lockForUpdate()->get() : $query->get();

        $net = '0.00';
        $invoiced = ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00'];
        $liveCount = 0;

        foreach ($invoices as $invoice) {
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
            'components' => $components,
            'invoicedComponents' => $invoiced,
        ];
    }
}
