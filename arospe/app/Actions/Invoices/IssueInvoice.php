<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\InvoiceValidationRules;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The gated entry point of issuing a standard invoice (story 0101b, FD-13): the only action a
 * screen may call. Authorization comes first (`invoices.create` AND the permission to view orders,
 * `orders.view`, every refusal logged once as `create` on `invoice`), so an unprivileged caller learns nothing about
 * the order; then the amount format (FD-11); then the ungated core. An omitted amount means the
 * whole uninvoiced amount. There is no issue-date parameter: the invoice is dated at the moment
 * of issue.
 */
class IssueInvoice
{
    use InvoiceValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly IssueInvoiceForOrder $issueInvoiceForOrder,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Order $order, ?string $amount = null): Invoice
    {
        $caller = Auth::user();

        if (Gate::forUser($caller)->denies('viewAny', Order::class)) {
            $this->logRefusedPrivilegedAttempt->log($caller, 'create', 'invoice');

            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->logRefusedPrivilegedAttempt->authorize('create', Invoice::class, targetType: 'invoice');

        if ($amount !== null) {
            $validated = Validator::make(
                ['amount' => $amount],
                ['amount' => $this->invoiceAmountRules()],
                $this->invoiceAmountMessages(),
            )->validate();

            $amount = bcadd(is_numeric($validated['amount']) ? (string) $validated['amount'] : '0', '0', 2);
        }

        $actor = Auth::user();

        return ($this->issueInvoiceForOrder)($order, $amount, $actor instanceof User ? $actor : null);
    }
}
