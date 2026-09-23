<?php

namespace App\Livewire\Orders;

use App\Concerns\ResolvesFlagReasonLabel;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The order book: a read-only, newest-first list (story 0055, PRD §3.2).
 *
 * No public method mutates anything -- every write lives on the detail screen
 * (App\Livewire\Orders\Show). That is this component's central claim and is asserted by a
 * reflection test rather than left to inspection: a mutating method reachable over
 * `/livewire/update` would falsify it while every markup assertion still passed.
 *
 * `orders()` implements story 0045's D-6 list contract: `created_at desc, id desc` with
 * `with('customer')` ONLY -- deliberately not D-14's five-relation detail contract, since nothing
 * on a list row comes from `items`, `paymentMethod`, `salesRegion` or `shippingRate`. The `id`
 * tie-break is not decoration: UUID v7 is time-ordered, so orders sharing a `created_at` second
 * still sort deterministically. The customer is loaded `withTrashed()` because orders may
 * legitimately reference a soft-deleted customer (0045 D-12) and `Order::customer()` carries no
 * such scope -- without it `customerName` would fatal on a null.
 */
#[Title('Orders')]
class Index extends Component
{
    use ResolvesFlagReasonLabel;

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
     * @return array<int, array{id: string, orderNumber: string, customerId: string, customerName: string, customerLinkable: bool, status: string, statusLabel: string, paymentStatus: string, paymentStatusLabel: string, total: string, createdAt: string, isFlagged: bool, flagReasonLabel: string|null}>
     */
    #[Computed]
    public function orders(): array
    {
        $canLinkCustomers = $this->canViewCustomers();

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
            ])
            ->all();
    }

    /**
     * The summary line, resolved with `trans_choice()` -- one plural-aware key, never a PHP ternary.
     */
    #[Computed]
    public function ordersSummary(): string
    {
        $count = count($this->orders());

        return trans_choice('orders.index.summary', $count, ['count' => $count]);
    }
}
