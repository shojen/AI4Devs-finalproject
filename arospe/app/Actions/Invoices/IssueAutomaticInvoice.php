<?php

namespace App\Actions\Invoices;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Story 0105 -- issues and queues the invoice of a just-created order when the store setting
 * `auto_issue_on_order` is on. Called only by CreateOrder, from a DB::afterCommit() callback
 * (an architecture test enforces it), with the id of the order just created.
 *
 * Deliberately authorizes NOTHING and never reads the authenticated user (FD-6, FD-7): it is a
 * system follow-on of an already-authorized order creation, so `issued_by` and `sent_by` are null.
 *
 * Two independent failure scopes (FD-5), nothing propagates: an issue failure leaves the order
 * untouched and notifies `issue`; a send failure leaves the invoice issued and notifies `send`.
 * The issue is retried once, only on MySQL deadlock (1213) or lock wait timeout (1205).
 * Idempotent per order (FD-3): an order with any invoice is skipped.
 */
class IssueAutomaticInvoice
{
    public function __construct(
        private readonly ComputeOrderInvoiceBalance $computeOrderInvoiceBalance,
        private readonly IssueInvoiceForOrder $issueInvoiceForOrder,
        private readonly AssertInvoicesAreCurrent $assertInvoicesAreCurrent,
        private readonly RecordInvoiceDeliveries $recordInvoiceDeliveries,
        private readonly NotifyAutomaticInvoiceFailed $notifyAutomaticInvoiceFailed,
    ) {}

    /**
     * Never throws.
     */
    public function __invoke(string $orderId): void
    {
        try {
            $settings = InvoiceSetting::query()->find(InvoiceSetting::SINGLETON_ID) ?? InvoiceSetting::defaults();

            if (! $settings->auto_issue_on_order) {
                return;
            }

            $order = Order::query()->find($orderId);

            if (! $order instanceof Order || $order->status === OrderStatus::Cancelled) {
                return;
            }

            if (Invoice::query()->where('order_id', $order->getKey())->exists()) {
                return;
            }

            if (bccomp(($this->computeOrderInvoiceBalance)($order)['uninvoiced'], '0.00', 2) <= 0) {
                return;
            }
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $invoice = $this->issue($order, $settings);

        if ($invoice instanceof Invoice) {
            $this->send($order, $invoice);
        }
    }

    private function issue(Order $order, InvoiceSetting $settings): ?Invoice
    {
        try {
            if (blank($settings->issuer_legal_name) || blank($settings->issuer_tax_id) || blank($settings->issuer_address_line1)) {
                throw ValidationException::withMessages(['issuer' => [__('invoices.errors.issuer_incomplete')]]);
            }

            try {
                return ($this->issueInvoiceForOrder)($order, null, null);
            } catch (QueryException $exception) {
                if (! $this->isLockFailure($exception)) {
                    throw $exception;
                }

                return ($this->issueInvoiceForOrder)($order, null, null);
            }
        } catch (Throwable $exception) {
            $this->fail($order, 'issue', $exception);

            return null;
        }
    }

    private function send(Order $order, Invoice $invoice): void
    {
        try {
            if (! Customer::query()->whereKey($order->customer_id)->exists()) {
                Log::warning('Automatic invoice not sent: the customer of the order was deleted.', ['order_id' => $order->id]);

                $this->notify($order, 'send', ValidationException::withMessages(['customer' => ['deleted']]));

                return;
            }

            DB::transaction(function () use ($order, $invoice): void {
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

                ($this->assertInvoicesAreCurrent)($locked);

                $fresh = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

                ($this->recordInvoiceDeliveries)($locked, [$fresh], null);
            });
        } catch (Throwable $exception) {
            $this->fail($order, 'send', $exception);
        }
    }

    private function fail(Order $order, string $stage, Throwable $cause): void
    {
        report($cause);

        $this->notify($order, $stage, $cause);
    }

    private function notify(Order $order, string $stage, Throwable $cause): void
    {
        try {
            ($this->notifyAutomaticInvoiceFailed)($order, $stage, $cause);
        } catch (Throwable $exception) {
            Log::error('The automatic invoicing failure could not be notified.', ['order_id' => $order->id, 'stage' => $stage, 'cause' => $exception::class]);
        }
    }

    private function isLockFailure(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [1213, 1205], true);
    }
}
