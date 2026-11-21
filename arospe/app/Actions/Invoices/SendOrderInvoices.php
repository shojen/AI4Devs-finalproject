<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\InvoiceDeliveryStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Send an order's invoices in one mail (story 0104 FD-7, FD-8, FD-16): every live standard invoice
 * and every corrective not yet sent, each attachment original or copy by its own row. When the
 * order has no live standard invoice and something is left to invoice, it first issues one invoice
 * for the whole uninvoiced amount ("Issue and send"), which also needs `invoices.create`; the
 * pre-checks run before anything is issued. The issue commits in its own transaction; if recording
 * the delivery then fails the invoice stays issued and unsent, reported as a `delivery` error.
 */
class SendOrderInvoices
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly AssertInvoicesAreCurrent $assertInvoicesAreCurrent,
        private readonly ResolveInvoiceRecipient $resolveInvoiceRecipient,
        private readonly ComputeOrderInvoiceBalance $computeOrderInvoiceBalance,
        private readonly IssueInvoiceForOrder $issueInvoiceForOrder,
        private readonly RecordInvoiceDeliveries $recordInvoiceDeliveries,
        private readonly ClassifyDeliveryFailure $classifyDeliveryFailure,
    ) {}

    /**
     * @return list<InvoiceDelivery>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Order $order): array
    {
        $this->logRefusedPrivilegedAttempt->authorize('send', Invoice::class, targetType: 'order', targetId: $order->id);

        $actor = Auth::user();
        $actor = $actor instanceof User ? $actor : null;
        $issued = false;

        $hasLiveStandard = Invoice::query()->where('order_id', $order->getKey())->standard()->live()->exists();

        if (! $hasLiveStandard) {
            $issued = $this->issueWhenSomethingIsLeft($order, $actor);
        }

        try {
            return DB::transaction(fn (): array => $this->record($order, $actor));
        } catch (Throwable $exception) {
            if (! $issued) {
                throw $exception;
            }

            report(new RuntimeException('Issued invoice could not be queued: '.($this->classifyDeliveryFailure)($exception)));

            throw ValidationException::withMessages(['delivery' => [__('invoices.errors.issued_but_not_queued')]]);
        }
    }

    /**
     * Run the pre-checks and issue the invoice for the whole uninvoiced amount; returns false when
     * nothing is left to invoice (unsent correctives may still go out).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function issueWhenSomethingIsLeft(Order $order, ?User $actor): bool
    {
        $fresh = Order::query()->whereKey($order->getKey())->firstOrFail();

        ($this->assertInvoicesAreCurrent)($fresh);
        ($this->resolveInvoiceRecipient)($fresh);

        $balance = ($this->computeOrderInvoiceBalance)($fresh);

        if (bccomp($balance['uninvoiced'], '0.00', 2) <= 0) {
            $hasUnsentCorrective = Invoice::query()
                ->where('order_id', $fresh->getKey())
                ->corrective()
                ->whereDoesntHave('deliveries', fn ($query) => $query->whereIn('status', [InvoiceDeliveryStatus::Queued->value, InvoiceDeliveryStatus::Sent->value]))
                ->exists();

            if ($fresh->status === OrderStatus::Cancelled && ! $hasUnsentCorrective) {
                throw ValidationException::withMessages(['invoice' => [__('invoices.errors.cancelled')]]);
            }

            return false;
        }

        $this->logRefusedPrivilegedAttempt->authorize('create', Invoice::class, targetType: 'order', targetId: $fresh->id);

        if ($fresh->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.cancelled')]]);
        }

        $settings = InvoiceSetting::query()->find(InvoiceSetting::SINGLETON_ID) ?? InvoiceSetting::defaults();

        if (blank($settings->issuer_legal_name) || blank($settings->issuer_tax_id) || blank($settings->issuer_address_line1)) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.issuer_incomplete')]]);
        }

        ($this->issueInvoiceForOrder)($fresh, null, $actor);

        return true;
    }

    /**
     * @return list<InvoiceDelivery>
     */
    private function record(Order $order, ?User $actor): array
    {
        $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        ($this->assertInvoicesAreCurrent)($locked);

        $invoices = Invoice::query()->where('order_id', $locked->getKey())->orderBy('id')->lockForUpdate()->get();

        /** @var list<string> $active */
        $active = InvoiceDelivery::query()
            ->whereIn('invoice_id', $invoices->modelKeys())
            ->whereIn('status', [InvoiceDeliveryStatus::Queued->value, InvoiceDeliveryStatus::Sent->value])
            ->lockForUpdate()
            ->pluck('invoice_id')
            ->all();

        /** @var list<Invoice> $candidates */
        $candidates = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->type === InvoiceType::Standard
                ? $invoice->voided_at === null
                : ! in_array($invoice->getKey(), $active, true))
            ->sortBy([['issued_at', 'asc'], ['id', 'asc']])
            ->values()
            ->all();

        if ($candidates === []) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.nothing_to_send')]]);
        }

        return ($this->recordInvoiceDeliveries)($locked, $candidates, $actor);
    }
}
