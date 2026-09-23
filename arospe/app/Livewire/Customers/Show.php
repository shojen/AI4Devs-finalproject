<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Customer detail screen: a read-only identity header plus that customer's read-only order
 * history (story 0047, PRD §3.1).
 *
 * No public method mutates anything -- there is no save(), no delete*(), no modal state, no
 * wire:model-bound property. This is the story's central claim, asserted by a reflection test
 * (ShowRenderingTest.php) rather than left to inspection.
 *
 * Two abilities gate two different things (D-1): `customers.view` gates the whole page (route
 * middleware + this component's own `mount()`, the same defence-in-depth shape every other module
 * screen in this repo uses), and `orders.view` gates only the order-history SECTION -- checked in
 * `orders()` before any query runs, never only in the view. A screen owned by the Customers
 * module disclosing another module's records (order_number, status, total) asks that other
 * module's own ability, mirroring task 0015's `updateSensitiveAttributes` disclosure-gate
 * precedent. An actor without `orders.view` still sees a 200 with the identity header -- the
 * section is OMITTED, never a 403, since refusing the whole page would deny access `customers.view`
 * grants, and a 403 would itself disclose that this customer has an order-history surface behind
 * an ability the actor lacks.
 */
#[Title('Customer detail')]
class Show extends Component
{
    /**
     * Server-authoritative: route-model binding resolves and authorizes the real `Customer` in
     * mount() (which is also what produces the 404 for a trashed or malformed id), and only the
     * id is kept as component state from then on -- the server-authoritative-id idiom
     * App\Livewire\Customers\Index already uses for `$editingCustomerId`. `#[Locked]` because a
     * client-writable id would let any holder of `customers.view` re-point this component at a
     * different customer over `/livewire/update`, which the route gate never re-evaluates.
     */
    #[Locked]
    public string $customerId;

    /**
     * Authorize against the resolved `$customer` and store only its id.
     *
     * Route-model binding already 404s a malformed or soft-deleted `{customer}` segment before
     * this method ever runs (HasUuids::resolveRouteBindingQuery() for the former, the
     * SoftDeletingScope for the latter) -- see docs/conventions/base-standards/stack-and-model-conventions.md#uuid-primary-keys.
     */
    public function mount(Customer $customer): void
    {
        Gate::authorize('viewAny', Customer::class);

        $this->customerId = $customer->id;
    }

    /**
     * The resolved customer, re-read from its id on every render rather than cached as public
     * state.
     */
    #[Computed]
    public function customer(): Customer
    {
        return Customer::findOrFail($this->customerId);
    }

    /**
     * Whether the acting user may see this customer's order history -- the section-level ability
     * (D-1), asked once and read by both `orders()`'s guard and the view's own conditional so the
     * two can never drift.
     *
     * `Gate::allows('viewAny', Order::class)` is this component's own first real caller of
     * `App\Policies\OrderPolicy::viewAny()`, which had shipped from story 0045 with no caller yet
     * (a deliberate hand-off, per that policy's own docblock) -- not a raw permission string,
     * since a policy ability call is what every other module-gate in this repo uses for the
     * identical shape.
     */
    #[Computed]
    public function canViewOrderHistory(): bool
    {
        return Gate::allows('viewAny', Order::class);
    }

    /**
     * The order-history rows, newest first (`created_at desc, id desc` -- 0045 D-6 applied to the
     * relation query, matching D-4's "no default ordering on the relation itself" decision). The
     * `id` tie-break is not decoration: UUID v7 is time-ordered, so two orders sharing a
     * `created_at` second still sort deterministically.
     *
     * Guards BEFORE querying, returning an empty collection rather than throwing -- the early
     * return is the disclosure gate itself, not the view's `@if`. A view-only conditional would
     * leave the query running and the rows sitting in the component's render context regardless
     * of the check outcome; see docs/security/livewire-authorization.md's "gate at the top of
     * every method that mutates or discloses" rule.
     *
     * `total` stays the raw `decimal:2` STRING the model casts it to -- no `(float)` anywhere on
     * this path (D-9). No eager loading (D-8): nothing here renders `items`, `paymentMethod`,
     * `salesRegion` or `shippingRate`, so 0045's own detail-screen eager-load contract is story
     * 0055's to reuse, not this one's to copy for a four-column list.
     *
     * @return array<int, array{id: string, orderNumber: string, status: string, statusLabel: string, total: string, createdAt: string}>
     */
    #[Computed]
    public function orders(): array
    {
        if (! $this->canViewOrderHistory()) {
            return [];
        }

        return $this->customer()->orders()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'total' => $order->total,
                'createdAt' => $order->created_at?->format('d/m/Y H:i') ?? '',
            ])
            ->all();
    }
}
