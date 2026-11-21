<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\InvoiceType;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The gated entry point of marking one invoice as collected (story 0106, FD-3, FD-5, FD-6, FD-17).
 *
 * In this order: `authorize('collect')` through the refusal-logging wrapper, before any row is
 * read, so an unprivileged caller learns nothing; then one transaction that locks the `orders` row
 * FOR UPDATE (every invoice writer locks the order first), refuses a stale-invoices order, locks the
 * invoice row and refuses by the state read under the locks (voided, corrective, already
 * collected, cancelled order; each a validation error keyed `invoice`), checks the payment method
 * still exists, and writes through the ungated ApplyInvoiceCollection (the five collection columns,
 * then the order's collected amount and payment status). The collection is a whole-invoice fact
 * (U-10): there is no amount parameter, the actor is the authenticated user and the moment is the
 * server clock. The order `status` is never touched.
 *
 * Returns the invoice, refreshed, with its `order` relation loaded so a screen can show the new
 * debt and payment status without another query.
 */
class CollectInvoice
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly AssertInvoicesAreCurrent $assertInvoicesAreCurrent,
        private readonly ApplyInvoiceCollection $applyInvoiceCollection,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Invoice $invoice, PaymentMethod $method, OrderPaymentType $type): Invoice
    {
        $this->logRefusedPrivilegedAttempt->authorize('collect', $invoice, targetType: 'invoice', targetId: $invoice->getKey());

        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $collected = DB::transaction(function () use ($invoice, $method, $type, $actor): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);

            ($this->assertInvoicesAreCurrent)($order);

            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCollectable($locked, $order);
            $this->assertMethodExists($method);

            ($this->applyInvoiceCollection)($order, [$locked], $method, $type, $actor);

            return $locked->setRelation('order', $order->refresh());
        });

        Log::info('Invoice collected', [
            'actor_id' => $actor->getKey(),
            'invoice_id' => $collected->getKey(),
            'order_id' => $collected->order_id,
        ]);

        return $collected;
    }

    /**
     * @throws ValidationException
     */
    private function assertCollectable(Invoice $invoice, Order $order): void
    {
        $key = match (true) {
            $invoice->voided_at !== null => 'invoices.errors.collect_voided',
            $invoice->type === InvoiceType::Corrective => 'invoices.errors.collect_corrective',
            $invoice->isCollected() => 'invoices.errors.already_collected',
            $order->status === OrderStatus::Cancelled => 'invoices.errors.collection_cancelled',
            default => null,
        };

        if ($key !== null) {
            throw ValidationException::withMessages(['invoice' => [__($key)]]);
        }
    }

    /**
     * Payment methods have no inactive state today; when one is introduced an inactive method must be refused here.
     *
     * @throws ValidationException
     */
    private function assertMethodExists(PaymentMethod $method): void
    {
        if ($method->getKey() === null || ! PaymentMethod::query()->sharedLock()->whereKey($method->getKey())->exists()) {
            throw ValidationException::withMessages(['payment_method' => [__('invoices.errors.payment_method_missing')]]);
        }
    }
}
