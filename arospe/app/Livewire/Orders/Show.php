<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\RecordRefund;
use App\Actions\Orders\RemoveOrderItem;
use App\Actions\Orders\TransitionOrderStatus;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Concerns\ResolvesFlagReasonLabel;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Exceptions\OrderCancellationBlockedException;
use App\Exceptions\OrderNotEditableException;
use App\Exceptions\OrderStatusRegressionRequiresConfirmationException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Order detail and editor: the single consumer surface for every write stories 0048-0052 built
 * (line-item editing, status transitions, manual cancellation, refunds), and the only place
 * 0053/0054's resolved tax basis and `flagged_for_review` flag become visible to a human
 * (story 0055, PRD §3.2).
 *
 * It adds NO backend rule. Every control mirrors a predicate its own guard already reads --
 * `Order::isLineItemEditable()`, `Order::isRefundable()`, `Order::isManuallyCancellable()` (through
 * `OrderPolicy::cancel()`), `OrderStatus::isBackwardFrom()` -- and the component never writes a
 * status set, a payment-state comparison or a rank comparison of its own.
 *
 * THREE abilities govern this screen and only one gates the route (D-5): `orders.view` (route +
 * `mount()`), `orders.edit` (line items, status, and -- with `orders.refund`, per
 * `OrderPolicy::cancel()` -- cancellation) and `orders.refund` (refunds, independent of edit). Each
 * mutating AND each disclosing method authorizes as its own first statement, the two dialog openers
 * included.
 *
 * Every `#[Computed]` is memoised for the request and the write actions never refresh the instance
 * they are handed, so `refreshOrderState()` runs after EVERY successful write -- a screen that kept
 * the pre-write `$order` would show a stale total, and would hide 0052's auto-cancel.
 */
#[Title('Order detail')]
class Show extends Component
{
    use ResolvesFlagReasonLabel;

    /**
     * The interim product picker's bound (D-1, R-4): a plain select over the catalog, with no
     * server-side search, so it is capped and a truncation notice is rendered rather than a silent
     * `limit()`. Story 0022's searchable multi-select removes it.
     */
    public const PRODUCT_PICKER_LIMIT = 50;

    /**
     * Server-authoritative: route-model binding resolves the real `Order` in `mount()`, and only the
     * id is kept as component state from then on. `#[Locked]` because a client-writable id would let
     * any holder of `orders.view` re-point this component at another order over `/livewire/update`,
     * which the route gate never re-evaluates.
     */
    #[Locked]
    public string $orderId;

    public string $newProductId = '';

    public string $newProductVariantId = '';

    public int $newQuantity = 1;

    /**
     * Per-line-item quantity inputs, keyed by line-item id.
     *
     * @var array<string, int>
     */
    public array $editingQuantities = [];

    /**
     * Per-line-item refund inputs (units), keyed by line-item id.
     *
     * @var array<string, int>
     */
    public array $refundQuantities = [];

    /**
     * The status select's bound value: a real backing-value STRING, never a nullable enum. A null
     * `wire:model` property desynchronises a native <select> and silently drops the user's own pick,
     * and a typed enum property is hydrated through `::from()` before validation runs, so a forged
     * value would be an unhandled ValueError rather than a validation error (D-8).
     */
    public string $selectedStatus = '';

    public bool $showBackwardConfirm = false;

    public bool $showCancelConfirm = false;

    public bool $showRefundModal = false;

    /**
     * The status a pending backward confirmation would apply. `#[Locked]` AND the only thing that
     * makes `applyStatusChange()` pass `confirmed: true`: a client forging `$showBackwardConfirm`
     * confirms nothing.
     */
    #[Locked]
    public string $pendingStatus = '';

    public function mount(Order $order): void
    {
        Gate::authorize('viewAny', Order::class);

        $this->orderId = $order->id;
        $this->selectedStatus = $order->status->value;

        $this->syncQuantityInputs();
    }

    /**
     * 0045 D-14's detail contract: the order with its customer, line items, payment method, sales
     * region and shipping rate loaded, re-read from its id on every render. The customer is loaded
     * `withTrashed()` because an order may legitimately reference a soft-deleted customer.
     */
    #[Computed]
    public function order(): Order
    {
        return Order::query()
            ->with(['customer' => fn ($query) => $query->withTrashed(), 'items', 'paymentMethod', 'salesRegion', 'shippingRate'])
            ->findOrFail($this->orderId);
    }

    /**
     * @return array<int, array{id: string, productName: string, productSku: string, quantity: int, unitPrice: string, lineTotal: string, refundedQuantity: int, canRemove: bool}>
     */
    #[Computed]
    public function lineItems(): array
    {
        return $this->order()->items
            ->sortBy('id')
            ->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'productName' => $item->product_name,
                'productSku' => $item->product_sku,
                'quantity' => $item->quantity,
                'unitPrice' => $item->unit_price,
                'lineTotal' => $item->line_total,
                'refundedQuantity' => $item->refunded_quantity,
                // A line with refunded units can never be deleted (refunds.order_item_id is
                // restrictOnDelete()); RemoveOrderItem refuses it too. This reads a data fact, not a rule.
                'canRemove' => $item->refunded_quantity === 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the customer name may link to the customer's detail screen -- the target route gates on
     * `customers.view`, and a soft-deleted customer's detail 404s.
     */
    #[Computed]
    public function canLinkCustomer(): bool
    {
        return ! $this->order()->customer->trashed() && Gate::allows('viewAny', Customer::class);
    }

    /**
     * The fourth call site of the line-item hard block (D-4): the permission half is the policy's
     * `update` ability, the state half is `Order::isLineItemEditable()` -- the same predicate the three
     * actions throw from, never a status set written here.
     */
    #[Computed]
    public function canEditLineItems(): bool
    {
        return Gate::allows('update', $this->order()) && $this->order()->isLineItemEditable();
    }

    #[Computed]
    public function canTransitionStatus(): bool
    {
        return Gate::allows('transitionStatus', $this->order());
    }

    /**
     * `OrderPolicy::cancel()`: `orders.edit` AND `orders.refund` AND `Order::isManuallyCancellable()`
     * (0050 D-6). The component re-derives neither the status set nor the PartiallyRefunded exclusion.
     */
    #[Computed]
    public function canCancel(): bool
    {
        return Gate::allows('cancel', $this->order());
    }

    /**
     * The PERMISSION half of the refund control -- a distinct ability (D-5), disabled when absent.
     */
    #[Computed]
    public function canRefund(): bool
    {
        return Gate::allows('orders.refund');
    }

    /**
     * The payment-STATE half of the refund control -- the control is absent from the DOM when false
     * (PRD §3.2: "the refund action does not render"), the UI half of a defence-in-depth pair whose
     * backend half is RecordRefund's own refusal (D-10).
     */
    #[Computed]
    public function isRefundable(): bool
    {
        return $this->order()->isRefundable();
    }

    /**
     * The offered transitions: every linear status except the current one, and never `Cancelled`
     * (D-8). Cancellation has its own control and ability; 0049 refuses every transition to or from
     * `Cancelled`, so offering it would be a guaranteed refusal. Empty on a cancelled order.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function statusOptions(): array
    {
        $current = $this->order()->status;

        if ($current === OrderStatus::Cancelled) {
            return [];
        }

        return collect(OrderStatus::cases())
            ->reject(fn (OrderStatus $status): bool => $status === OrderStatus::Cancelled || $status === $current)
            ->map(fn (OrderStatus $status): array => ['value' => $status->value, 'label' => $status->label()])
            ->values()
            ->all();
    }

    /**
     * The interim picker's options (D-1): active products only, name-ordered, bounded, and offered only
     * to an actor who may edit line items at all (R-4 -- an `orders.edit` holder can already order any
     * product, so this discloses nothing they could not otherwise reach).
     *
     * @return array<int, array{id: string, name: string, sku: string}>
     */
    #[Computed]
    public function productOptions(): array
    {
        if (! Gate::allows('update', $this->order())) {
            return [];
        }

        return Product::query()
            ->where('status', ProductStatus::Active)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::PRODUCT_PICKER_LIMIT)
            ->get(['id', 'name', 'sku'])
            ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku])
            ->all();
    }

    /**
     * Whether the sellable catalog is larger than the picker's bound -- rendered as a visible notice.
     */
    #[Computed]
    public function productCatalogTruncated(): bool
    {
        return Gate::allows('update', $this->order())
            && Product::query()->where('status', ProductStatus::Active)->count() > self::PRODUCT_PICKER_LIMIT;
    }

    /**
     * The variants of the currently selected product; empty for a product without variants, which is
     * what tells the view not to render the variant select at all.
     *
     * @return array<int, array{id: string, label: string}>
     */
    #[Computed]
    public function variantOptions(): array
    {
        if ($this->newProductId === '' || ! $this->isOfferedProduct($this->newProductId)) {
            return [];
        }

        return ProductVariant::query()
            ->where('product_id', $this->newProductId)
            ->with('values.type')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'label' => $variant->label() !== '' ? $variant->label() : $variant->sku,
            ])
            ->all();
    }

    /**
     * The tax panel's state, branched on `sales_region_id` and `tax_rate` and NEVER on `tax_amount`
     * (which is 0.00 both when unresolved and when the rate is a legitimate 0%) -- D-16. A flagged
     * order may still carry the basis its resolver fell back to (0053), so it is rendered marked
     * provisional; only an order with no region and no rate renders as "not yet resolved".
     *
     * @return array{regionName: string|null, rate: string|null, isFlagged: bool, flagLabel: string|null}
     */
    #[Computed]
    public function taxBasis(): array
    {
        $order = $this->order();

        return [
            'regionName' => $order->salesRegion?->name,
            'rate' => $order->tax_rate,
            'isFlagged' => $order->flagged_for_review,
            'flagLabel' => $order->flagged_for_review ? $this->flagReasonLabel($order->flag_reason) : null,
        ];
    }

    /**
     * The order's own FROZEN shipping address lines (0045 D-4), never the customer's live address.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function shippingAddressLines(): array
    {
        $order = $this->order();

        return array_values(array_filter([
            $order->shipping_address_line1,
            $order->shipping_address_line2,
            trim(($order->shipping_postal_code ?? '').' '.($order->shipping_city ?? '')),
            $order->shipping_province,
            $order->shipping_country,
        ], fn (?string $line): bool => $line !== null && $line !== ''));
    }

    // ---------------------------------------------------------------------
    // Line items (0048)
    // ---------------------------------------------------------------------

    public function addLineItem(AddOrderItem $addOrderItem): void
    {
        Gate::authorize('update', $this->order());

        if (! $this->isOfferedProduct($this->newProductId)) {
            $this->addError('newProductId', __('orders.line_items.product_unavailable'));

            return;
        }

        if ($this->variantOptions() !== [] && $this->newProductVariantId === '') {
            $this->addError('newProductVariantId', __('orders.line_items.variant_required'));

            return;
        }

        try {
            $addOrderItem(
                $this->order(),
                $this->newProductId,
                $this->newProductVariantId === '' ? null : $this->newProductVariantId,
                $this->newQuantity,
            );
        } catch (OrderNotEditableException $exception) {
            $this->addError('lineItems', $exception->getMessage());

            return;
        }

        $this->reset('newProductId', 'newProductVariantId', 'newQuantity');
        $this->refreshOrderState();
    }

    public function removeLineItem(string $itemId, RemoveOrderItem $removeOrderItem): void
    {
        Gate::authorize('update', $this->order());

        try {
            $removeOrderItem($this->order(), $itemId);
        } catch (OrderNotEditableException $exception) {
            $this->addError('lineItems', $exception->getMessage());

            return;
        }

        $this->refreshOrderState();
    }

    public function updateLineItemQuantity(string $itemId, UpdateOrderItemQuantity $updateOrderItemQuantity): void
    {
        Gate::authorize('update', $this->order());

        try {
            $updateOrderItemQuantity($this->order(), $itemId, (int) ($this->editingQuantities[$itemId] ?? 0));
        } catch (OrderNotEditableException $exception) {
            $this->addError('lineItems', $exception->getMessage());

            return;
        }

        $this->refreshOrderState();
    }

    // ---------------------------------------------------------------------
    // Status transitions (0049)
    // ---------------------------------------------------------------------

    /**
     * Apply the selected status: straight through when it moves forward, or open the confirmation
     * when it moves BACKWARD. The direction is PREDICTED via `OrderStatus::isBackwardFrom()` -- the
     * non-throwing predicate 0049's guard wraps -- rather than discovered by catching a 409 (D-11).
     */
    public function requestStatusChange(TransitionOrderStatus $transitionOrderStatus): void
    {
        Gate::authorize('transitionStatus', $this->order());

        $target = $this->selectedStatus;

        // Validated against the OFFERED options before any `from()` / `isBackwardFrom()` call:
        // `rank()` has no Cancelled arm, so a forged 'cancelled' would otherwise reach an
        // UnhandledMatchError, and `selectedStatus` is client-writable.
        if (! $this->isOfferedStatus($target)) {
            $this->rejectStatusSelection($target);

            return;
        }

        if ($this->isBackwardTransition($target)) {
            $this->pendingStatus = $target;
            $this->showBackwardConfirm = true;

            return;
        }

        $this->transitionTo($target, false, $transitionOrderStatus);
    }

    /**
     * The confirmation's confirm action, and also what a forged client would call directly. `confirmed:
     * true` is passed ONLY when `requestStatusChange()` recorded a validated backward target in the
     * locked `$pendingStatus`; otherwise a backward target reaches the action unconfirmed and is
     * refused, and that refusal is rendered (defence in depth, D-11/D-12).
     */
    public function applyStatusChange(TransitionOrderStatus $transitionOrderStatus): void
    {
        Gate::authorize('transitionStatus', $this->order());

        $confirmed = $this->pendingStatus !== '';
        $target = $confirmed ? $this->pendingStatus : $this->selectedStatus;

        if (! $this->isOfferedStatus($target)) {
            $this->rejectStatusSelection($target);

            return;
        }

        $this->transitionTo($target, $confirmed, $transitionOrderStatus);
    }

    /**
     * Dismiss the backward confirmation and put the select back on the order's REAL status -- a select
     * left showing a value the server never accepted is the desync class of bug this repo already paid
     * for once. Also the target of the dialog's own `@close`, so Esc/backdrop/X behave identically.
     */
    public function dismissBackwardConfirm(): void
    {
        $this->pendingStatus = '';
        $this->showBackwardConfirm = false;
        $this->selectedStatus = $this->order()->status->value;
    }

    // ---------------------------------------------------------------------
    // Manual cancellation (0050)
    // ---------------------------------------------------------------------

    public function confirmCancel(): void
    {
        Gate::authorize('cancel', $this->order());

        $this->showCancelConfirm = true;
    }

    public function dismissCancelConfirm(): void
    {
        $this->showCancelConfirm = false;
    }

    public function cancelOrder(CancelOrder $cancelOrder): void
    {
        Gate::authorize('cancel', $this->order());

        try {
            $cancelOrder($this->order());
        } catch (OrderCancellationBlockedException $exception) {
            // Reachable without tampering only for the one actor the policy's state clause cannot
            // bind -- a Super Admin (Gate::before) -- or through a race; rendered, never a 500.
            $this->showCancelConfirm = false;
            $this->addError('cancel', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->showCancelConfirm = false;
            $this->addError('cancel', $this->firstMessage($exception));

            return;
        }

        $this->showCancelConfirm = false;
        $this->refreshOrderState();
    }

    // ---------------------------------------------------------------------
    // Refunds (0051 / 0052)
    // ---------------------------------------------------------------------

    public function openRefundModal(): void
    {
        Gate::authorize('orders.refund');

        if (! $this->order()->isRefundable()) {
            return;
        }

        $this->showRefundModal = true;
    }

    public function closeRefundModal(): void
    {
        $this->showRefundModal = false;
        $this->resetErrorBag('refund');
    }

    public function recordRefund(RecordRefund $recordRefund): void
    {
        Gate::authorize('orders.refund');

        $items = collect($this->refundQuantities)
            ->map(fn ($quantity): int => (int) $quantity)
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();

        if ($items === []) {
            $this->addError('refund', __('orders.refunds.nothing_selected'));

            return;
        }

        try {
            $recordRefund($this->order(), $items);
        } catch (ValidationException $exception) {
            $this->addError('refund', $this->firstMessage($exception));

            return;
        }

        $this->showRefundModal = false;
        $this->refreshOrderState();
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function transitionTo(string $target, bool $confirmed, TransitionOrderStatus $transitionOrderStatus): void
    {
        try {
            $transitionOrderStatus($this->order(), OrderStatus::from($target), $confirmed);
        } catch (OrderStatusRegressionRequiresConfirmationException $exception) {
            $this->clearPendingConfirmation();
            $this->addError('selectedStatus', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->clearPendingConfirmation();
            $this->addError('selectedStatus', $this->firstMessage($exception));

            return;
        }

        $this->clearPendingConfirmation(resetSelectionTo: $target);
        $this->refreshOrderState();
    }

    /**
     * Whether `$target` is a status this order may be moved to right now -- exactly `statusOptions()`.
     */
    private function isOfferedStatus(string $target): bool
    {
        return collect($this->statusOptions())->contains('value', $target);
    }

    private function isOfferedProduct(string $productId): bool
    {
        return $productId !== '' && collect($this->productOptions())->contains('id', $productId);
    }

    /**
     * Whether moving the order to `$target` is a regression. Only ever called with a value already
     * validated by `isOfferedStatus()`, so `OrderStatus::from()` cannot throw and `rank()` never sees
     * `Cancelled`.
     */
    private function isBackwardTransition(string $target): bool
    {
        return OrderStatus::from($target)->isBackwardFrom($this->order()->status);
    }

    /**
     * Render why a selection was refused and put the select back on the order's real status.
     */
    private function rejectStatusSelection(string $target): void
    {
        $current = $this->order()->status;

        $message = match (true) {
            $current === OrderStatus::Cancelled, $target === OrderStatus::Cancelled->value => __('orders.transitions.cancellation_unsupported'),
            $target === $current->value => __('orders.transitions.same_status'),
            default => __('validation.in', ['attribute' => __('orders.lifecycle.status')]),
        };

        $this->clearPendingConfirmation();
        $this->addError('selectedStatus', $message);
    }

    /**
     * Close the backward confirmation and put the select on `$resetSelectionTo`, or on the order's
     * real status when none is given.
     */
    private function clearPendingConfirmation(?string $resetSelectionTo = null): void
    {
        $this->pendingStatus = '';
        $this->showBackwardConfirm = false;
        $this->selectedStatus = $resetSelectionTo ?? $this->order()->status->value;
    }

    /**
     * Drop every memoised computed and re-seed the per-line inputs. Runs after every successful
     * write: the actions never refresh the `$order` they are handed, so a kept instance would show
     * pre-write totals, a pre-write status, and would hide the 0052 auto-cancel.
     */
    private function refreshOrderState(): void
    {
        unset(
            $this->order,
            $this->lineItems,
            $this->canLinkCustomer,
            $this->canEditLineItems,
            $this->canTransitionStatus,
            $this->canCancel,
            $this->isRefundable,
            $this->statusOptions,
            $this->productOptions,
            $this->productCatalogTruncated,
            $this->variantOptions,
            $this->taxBasis,
            $this->shippingAddressLines,
        );

        $this->selectedStatus = $this->order()->status->value;
        $this->syncQuantityInputs();
    }

    private function syncQuantityInputs(): void
    {
        $items = $this->order()->items;

        $this->editingQuantities = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => $item->quantity])->all();
        $this->refundQuantities = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => 0])->all();
    }

    private function firstMessage(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }
}
