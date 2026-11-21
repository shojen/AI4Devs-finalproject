<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send one invoice to its customer by e-mail (story 0104 FD-6): a live standard invoice or a
 * corrective. Authorization (`invoices.send`, refusals logged on the invoice) comes before any
 * state is read; then, under the order lock, the out-of-date guard, the voided check and the
 * recording of the delivery. The recipient is always the customer's current address; this action
 * accepts none.
 */
class SendInvoice
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly AssertInvoicesAreCurrent $assertInvoicesAreCurrent,
        private readonly RecordInvoiceDeliveries $recordInvoiceDeliveries,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Invoice $invoice): InvoiceDelivery
    {
        $this->logRefusedPrivilegedAttempt->authorize('send', $invoice, targetType: 'invoice', targetId: $invoice->id);

        $actor = Auth::user();

        return DB::transaction(function () use ($invoice, $actor): InvoiceDelivery {
            $order = Order::query()->whereKey($invoice->order_id)->lockForUpdate()->firstOrFail();

            ($this->assertInvoicesAreCurrent)($order);

            $locked = Invoice::query()->whereKey($invoice->getKey())->orderBy('id')->lockForUpdate()->firstOrFail();

            if ($locked->type === InvoiceType::Standard && $locked->voided_at !== null) {
                throw ValidationException::withMessages(['invoice' => [__('invoices.errors.nothing_to_send')]]);
            }

            return ($this->recordInvoiceDeliveries)($order, [$locked], $actor instanceof User ? $actor : null)[0];
        });
    }
}
