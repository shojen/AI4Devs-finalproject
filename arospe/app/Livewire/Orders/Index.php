<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\MarkOrderAsPaid;
use App\Concerns\ResolvesFlagReasonLabel;
use App\Enums\OrderPaymentType;
use App\Enums\PaymentMethodCode;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The order book: a newest-first list (story 0055, PRD §3.2) with one write, "Mark as paid"
 * (story 0085).
 *
 * `markAsPaid()` is the ONLY public method that mutates anything -- every other write lives on the
 * detail screen (App\Livewire\Orders\Show). `confirmMarkAsPaid()` / `dismissMarkAsPaid()` only
 * toggle dialog state and `confirmingPaidRow` is a read-only computed. The public-method allow-list
 * is asserted by a reflection test rather than left to inspection (a new public method forces a
 * deliberate decision); reflection cannot prove WHICH method writes, so this docblock states it.
 *
 * The action is called unconditionally (never behind a bare `Gate::authorize`) so a forged call by
 * an actor without `orders.edit` is refused AND logged by the action itself; the button is only
 * hidden for such an actor, and the payment method is resolved server-side, never from the client.
 *
 * `orders()` implements story 0045's D-6 list contract: `created_at desc, id desc` with
 * `with('customer')` ONLY -- deliberately not D-14's five-relation detail contract, since nothing
 * on a list row comes from `items`, `paymentMethod`, `salesRegion` or `shippingRate`. The `id`
 * tie-break is not decoration: UUID v7 is time-ordered, so orders sharing a `created_at` second
 * still sort deterministically. The customer is loaded `withTrashed()` because orders may
 * legitimately reference a soft-deleted customer (0045 D-12) and `Order::customer()` carries no
 * such scope -- without it `customerName` would fatal on a null.
 *
 * @property-read bool $canViewCustomers
 * @property-read array<int, array{id: string, orderNumber: string, customerId: string, customerName: string, customerLinkable: bool, status: string, statusLabel: string, paymentStatus: string, paymentStatusLabel: string, total: string, createdAt: string, isFlagged: bool, flagReasonLabel: string|null, canMarkPaid: bool}> $orders
 * @property-read array{id: string, orderNumber: string, customerId: string, customerName: string, customerLinkable: bool, status: string, statusLabel: string, paymentStatus: string, paymentStatusLabel: string, total: string, createdAt: string, isFlagged: bool, flagReasonLabel: string|null, canMarkPaid: bool}|null $confirmingPaidRow
 */
#[Title('Orders')]
class Index extends Component
{
    use ResolvesFlagReasonLabel;

    /**
     * Bound by the confirmation modal; opened only by `confirmMarkAsPaid()`.
     */
    public bool $showMarkPaidConfirm = false;

    /**
     * The order the open dialog refers to. `#[Locked]`: the client can never write the id, only ask
     * `confirmMarkAsPaid()` to store one that matches a server-built row.
     */
    #[Locked]
    public ?string $confirmingPaidOrderId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Order::class);
    }

    /**
     * Whether the customer cell may link to the customer's detail screen: the target route gates
     * on `customers.view`, so an actor holding only `orders.view` would otherwise be handed a link
     * to a 403.
     */
    #[Computed]
    public function canViewCustomers(): bool
    {
        return Gate::allows('viewAny', Customer::class);
    }

    /**
     * The order rows, newest first.
     *
     * `total` stays the raw `decimal:2` STRING the model casts it to -- no float cast anywhere on
     * this path (D-7). `paymentStatusLabel` is resolved from the lang file directly because
     * `PaymentStatus` carries no `label()` and `app/Enums/**` is out of this story's scope.
     *
     * @return array<int, array{id: string, orderNumber: string, customerId: string, customerName: string, customerLinkable: bool, status: string, statusLabel: string, paymentStatus: string, paymentStatusLabel: string, total: string, createdAt: string, isFlagged: bool, flagReasonLabel: string|null, canMarkPaid: bool}>
     */
    #[Computed]
    public function orders(): array
    {
        $canLinkCustomers = $this->canViewCustomers;

        return Order::query()
            ->with(['customer' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'customerId' => $order->customer_id,
                'customerName' => $order->customer->name,
                'customerLinkable' => $canLinkCustomers && ! $order->customer->trashed(),
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'paymentStatus' => $order->payment_status->value,
                'paymentStatusLabel' => __('orders.payment_statuses.'.$order->payment_status->value),
                'total' => $order->total,
                'createdAt' => $order->created_at?->format('d/m/Y H:i') ?? '',
                'isFlagged' => $order->flagged_for_review,
                'flagReasonLabel' => $order->flagged_for_review ? $this->flagReasonLabel($order->flag_reason) : null,
                'canMarkPaid' => $order->isAwaitingPayment() && Gate::allows('markPaid', $order),
            ])
            ->all();
    }

    /**
     * The summary line, resolved with `trans_choice()` -- one plural-aware key, never a PHP ternary.
     */
    #[Computed]
    public function ordersSummary(): string
    {
        $count = count($this->orders);

        return trans_choice('orders.index.summary', $count, ['count' => $count]);
    }

    /**
     * The row the open dialog refers to: the server-built row whose id is `confirmingPaidOrderId`
     * AND which may be marked as paid. Null for no id, an unknown id or an ineligible row.
     *
     * @return array{id: string, orderNumber: string, customerId: string, customerName: string, customerLinkable: bool, status: string, statusLabel: string, paymentStatus: string, paymentStatusLabel: string, total: string, createdAt: string, isFlagged: bool, flagReasonLabel: string|null, canMarkPaid: bool}|null
     */
    #[Computed]
    public function confirmingPaidRow(): ?array
    {
        if ($this->confirmingPaidOrderId === null) {
            return null;
        }

        $row = collect($this->orders)->firstWhere('id', $this->confirmingPaidOrderId);

        return $row !== null && $row['canMarkPaid'] ? $row : null;
    }

    /**
     * Store the id (only when it matches a listed row) and open the dialog only when that row may be
     * marked. No authorization here on purpose: a forged opener by an unauthorized actor opens no
     * dialog, but a following forged `markAsPaid()` reaches the action, which refuses and logs it.
     */
    public function confirmMarkAsPaid(string $orderId): void
    {
        if (! collect($this->orders)->contains('id', $orderId)) {
            return;
        }

        $this->confirmingPaidOrderId = $orderId;
        unset($this->confirmingPaidRow);

        $this->showMarkPaidConfirm = $this->confirmingPaidRow !== null;
    }

    public function dismissMarkAsPaid(): void
    {
        $this->closeMarkPaidConfirm();
    }

    /**
     * The list's only write. Re-loads the order (never trusts the row snapshot) and calls the action
     * unconditionally: it authorizes (and logs a refusal), checks the state and writes. An
     * AuthorizationException is deliberately not caught -- the action already logged it and it
     * surfaces as a 403.
     */
    public function markAsPaid(MarkOrderAsPaid $markOrderAsPaid): void
    {
        $order = $this->confirmingPaidOrderId === null ? null : Order::query()->find($this->confirmingPaidOrderId);

        if ($order === null) {
            $this->closeMarkPaidConfirm();
            Flux::toast(variant: 'danger', text: __('orders.payment.not_found'));

            return;
        }

        $paymentMethod = PaymentMethod::query()->where('code', PaymentMethodCode::BankTransfer)->firstOrFail();

        try {
            $markOrderAsPaid($order, $paymentMethod, OrderPaymentType::Transfer);
        } catch (ValidationException $exception) {
            $this->closeMarkPaidConfirm();
            Flux::toast(variant: 'danger', text: (string) $exception->validator->errors()->first());

            return;
        }

        $this->closeMarkPaidConfirm();
        Flux::toast(variant: 'success', text: __('orders.payment.marked', ['number' => $order->order_number]));
    }

    /**
     * Clear the dialog state and drop the memoised rows so the list re-reads in place.
     */
    private function closeMarkPaidConfirm(): void
    {
        $this->showMarkPaidConfirm = false;
        $this->confirmingPaidOrderId = null;

        unset($this->orders, $this->ordersSummary, $this->confirmingPaidRow);
    }
}
