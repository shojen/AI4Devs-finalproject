<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\MarkOrderAsPaid;
use App\Actions\Orders\RecordRefund;
use App\Actions\Orders\RemoveOrderItem;
use App\Actions\Orders\TransitionOrderStatus;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Concerns\ResolvesFlagReasonLabel;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductStatus;
use App\Exceptions\OrderCancellationBlockedException;
use App\Exceptions\OrderNotEditableException;
use App\Exceptions\OrderStatusRegressionRequiresConfirmationException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use Flux\Flux;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use LogicException;

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
 *
 * @property-read Order $order
 * @property-read array<int, array{id: string, productName: string, productSku: string, quantity: int, outstandingQuantity: int, unitPrice: string, lineTotal: string, refundedQuantity: int, canRemove: bool}> $lineItems
 * @property-read bool $canLinkCustomer
 * @property-read bool $canEditLineItems
 * @property-read bool $canTransitionStatus
 * @property-read bool $canCancel
 * @property-read bool $canRefund
 * @property-read bool $isRefundable
 * @property-read bool $canMarkPaid
 * @property-read array<int, array{value: string, label: string}> $statusOptions
 * @property-read array<int, array{id: string, name: string, sku: string}> $productOptions
 * @property-read bool $productCatalogTruncated
 * @property-read array<int, array{id: string, label: string}> $variantOptions
 * @property-read array{regionName: string|null, rate: string|null, isFlagged: bool, flagLabel: string|null} $taxBasis
 * @property-read array<int, string> $shippingAddressLines
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

    /**
     * A string, not an int: a cleared number input arrives as '', which Livewire's int synthesizer
     * turns into null and then UNSETS the typed property, so reading it would be an uninitialized-
     * property 500 instead of a validation error. Cast at the call; the action validates `min:1`.
     */
    public string $newQuantity = '1';

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

    public bool $showMarkPaidConfirm = false;

    public bool $showStockConfirm = false;

    /**
     * The pending stock dialog's request (story 0094). All four are `#[Locked]`: `applyStockChoice()`
     * trusts only what `requestQuantityChange()` recorded, never a client-written value.
     */
    #[Locked]
    public string $pendingStockAction = '';

    #[Locked]
    public string $pendingItemId = '';

    #[Locked]
    public int $pendingQuantity = 0;

    /**
     * The line's quantity when the dialog opened (the stale guard).
     */
    #[Locked]
    public int $pendingFromQuantity = 0;

    /**
     * The status a pending backward confirmation would apply. `#[Locked]` AND the only thing that
     * makes `applyStatusChange()` pass `confirmed: true`: a client forging `$showBackwardConfirm`
     * confirms nothing.
     */
    #[Locked]
    public string $pendingStatus = '';

    /**
     * The order's status when the backward confirmation opened. `applyStatusChange()` refuses to
     * confirm when it no longer matches: another administrator may have moved the order while the
     * dialog was open, and `confirmed: true` would then apply a regression the user never saw.
     */
    #[Locked]
    public string $pendingFromStatus = '';

    /**
     * The order's status when the cancel dialog opened (story 0095, the stale guard). Empty means no
     * cancel request is pending: `cancelOrder()` refuses to run without it and `stockPreview` shows no
     * cancel rows. Cleared on every exit path of the cancel flow.
     */
    #[Locked]
    public string $pendingCancelFromStatus = '';

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
            ->with(['customer' => fn ($query) => $query->withTrashed(), 'items', 'paymentMethod', 'salesRegion', 'shippingRate', 'payment.recordedBy'])
            ->findOrFail($this->orderId);
    }

    /**
     * @return array<int, array{id: string, productName: string, productSku: string, quantity: int, outstandingQuantity: int, unitPrice: string, lineTotal: string, refundedQuantity: int, canRemove: bool}>
     */
    #[Computed]
    public function lineItems(): array
    {
        return $this->order->items
            ->sortBy('id')
            ->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'productName' => $item->product_name,
                'productSku' => $item->product_sku,
                'quantity' => $item->quantity,
                'outstandingQuantity' => $item->quantity - $item->refunded_quantity,
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
        return ! $this->order->customer->trashed() && Gate::allows('viewAny', Customer::class);
    }

    /**
     * The fourth call site of the line-item hard block (D-4): the permission half is the policy's
     * `update` ability, the state half is `Order::isLineItemEditable()` -- the same predicate the three
     * actions throw from, never a status set written here.
     */
    #[Computed]
    public function canEditLineItems(): bool
    {
        return Gate::allows('update', $this->order) && $this->order->isLineItemEditable();
    }

    #[Computed]
    public function canTransitionStatus(): bool
    {
        return Gate::allows('transitionStatus', $this->order);
    }

    /**
     * `OrderPolicy::cancel()`: `orders.edit` AND `orders.refund` AND `Order::isManuallyCancellable()`
     * (0050 D-6). The component re-derives neither the status set nor the PartiallyRefunded exclusion.
     */
    #[Computed]
    public function canCancel(): bool
    {
        return Gate::allows('cancel', $this->order);
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
        return $this->order->isRefundable();
    }

    /**
     * Story 0085 -- the "Mark as paid" control: the payment STATE (`Order::isAwaitingPayment()`, the one
     * rule 0084's action also refuses on) and the `markPaid` permission. Absent from the DOM when false.
     */
    #[Computed]
    public function canMarkPaid(): bool
    {
        return $this->order->isAwaitingPayment() && Gate::allows('markPaid', $this->order);
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
        $current = $this->order->status;

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
        if (! Gate::allows('update', $this->order)) {
            return [];
        }

        return Product::query()
            ->select(['products.id', 'products.sku'])
            ->where('products.status', ProductStatus::Active)
            ->orderByTranslatedName()
            ->orderBy('products.id')
            ->limit(self::PRODUCT_PICKER_LIMIT)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->getAttribute('translated_name') ?? '—',
                'sku' => $product->sku,
            ])
            ->all();
    }

    /**
     * Whether the sellable catalog is larger than the picker's bound -- rendered as a visible notice.
     */
    #[Computed]
    public function productCatalogTruncated(): bool
    {
        return Gate::allows('update', $this->order)
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
        $order = $this->order;

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
        $order = $this->order;

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
        Gate::authorize('update', $this->order);
        $this->resetErrorBag();

        if (! $this->isOfferedProduct($this->newProductId)) {
            $this->addError('newProductId', __('orders.line_items.product_unavailable'));

            return;
        }

        if ($this->variantOptions !== [] && $this->newProductVariantId === '') {
            $this->addError('newProductVariantId', __('orders.line_items.variant_required'));

            return;
        }

        try {
            $addOrderItem(
                $this->order,
                $this->newProductId,
                $this->newProductVariantId === '' ? null : $this->newProductVariantId,
                (int) $this->newQuantity,
            );
        } catch (OrderNotEditableException $exception) {
            $this->refreshOrderState();
            $this->addError('lineItems', $exception->getMessage());

            return;
        }

        $this->reset('newProductId', 'newProductVariantId', 'newQuantity');
        $this->refreshOrderState();
    }

    /**
     * Story 0094a -- the first half of a line removal. A line that moves stock records its current
     * quantity in the locked pending props and opens the stock dialog; a line with no stock target
     * (its catalog row was deleted) has nothing to ask about and is removed directly without moving
     * stock. It adds no validation of its own: the refunded-line and last-line refusals stay the
     * action's, rendered on apply (or directly, for the no-dialog path).
     */
    public function requestRemoveLineItem(string $itemId, RemoveOrderItem $removeOrderItem): void
    {
        Gate::authorize('update', $this->order);
        $this->resetErrorBag();
        $this->clearPendingCancel();

        $item = $this->order->items->firstWhere('id', $itemId);

        if (! $item instanceof OrderItem) {
            $this->addError('lineItems', __('orders.stock.changed_meanwhile'));

            return;
        }

        if ($item->stockTarget() === null) {
            $this->removeLine($itemId, false, $removeOrderItem);

            return;
        }

        $this->pendingStockAction = 'remove';
        $this->pendingItemId = $itemId;
        $this->pendingQuantity = 0;
        $this->pendingFromQuantity = $item->quantity;
        $this->showStockConfirm = true;
    }

    /**
     * Story 0094 -- the first half of a quantity change. It never writes for a line that moves stock:
     * it records the cast target and the line's current quantity in the locked pending props and opens
     * the stock dialog. It adds no validation of its own (`< 1`, `< refunded` and the hard block stay
     * the action's to refuse, rendered on apply). A line with no stock target (its catalog row was
     * deleted) has nothing to ask about and saves directly without moving stock.
     */
    public function requestQuantityChange(string $itemId, UpdateOrderItemQuantity $updateOrderItemQuantity): void
    {
        Gate::authorize('update', $this->order);
        $this->resetErrorBag();
        $this->clearPendingCancel();

        $item = $this->order->items->firstWhere('id', $itemId);

        if (! $item instanceof OrderItem) {
            $this->addError('lineItems', __('orders.stock.changed_meanwhile'));

            return;
        }

        $quantity = (int) ($this->editingQuantities[$itemId] ?? 0);

        if ($quantity === $item->quantity) {
            return;
        }

        if ($item->stockTarget() === null) {
            $this->saveQuantity($itemId, $quantity, false, $updateOrderItemQuantity);

            return;
        }

        $this->pendingStockAction = 'quantity';
        $this->pendingItemId = $itemId;
        $this->pendingQuantity = $quantity;
        $this->pendingFromQuantity = $item->quantity;
        $this->showStockConfirm = true;
    }

    /**
     * The stock dialog's choice: `$adjust` true moves the stock, false saves only the line. Re-authorizes,
     * refuses an empty or stale request (the line's quantity moved since the dialog opened), and clears
     * every pending field on every exit path other than a failed authorization.
     */
    public function applyStockChoice(bool $adjust, UpdateOrderItemQuantity $updateOrderItemQuantity, RemoveOrderItem $removeOrderItem): void
    {
        Gate::authorize('update', $this->order);
        $this->resetErrorBag();

        $mode = $this->pendingStockAction;
        $itemId = $this->pendingItemId;
        $quantity = $this->pendingQuantity;
        $fromQuantity = $this->pendingFromQuantity;
        $isPending = in_array($mode, ['quantity', 'remove'], true) && $itemId !== '';

        $this->clearPendingStock();

        $item = $isPending ? $this->order->items->firstWhere('id', $itemId) : null;

        if (! $item instanceof OrderItem || $item->quantity !== $fromQuantity) {
            $this->refreshOrderState();
            $this->addError('lineItems', __('orders.stock.changed_meanwhile'));

            return;
        }

        if ($mode === 'remove') {
            $this->removeLine($itemId, $adjust, $removeOrderItem);

            return;
        }

        $this->saveQuantity($itemId, $quantity, $adjust, $updateOrderItemQuantity);
    }

    /**
     * Renderless on purpose, like `dismissMarkAsPaid()`: a refused apply closes the dialog server-side,
     * the modal's `@close` then calls this method, and Livewire does not persist the error bag between
     * requests -- a re-render here would wipe the refusal message the apply just rendered. The cleared
     * properties still sync to the client.
     */
    #[Renderless]
    public function dismissStockConfirm(): void
    {
        $this->clearPendingStock();
    }

    /**
     * The stock dialogs' preview, from a fresh read of the inventory rows. The mode follows the pending
     * state: the line modes (`quantity` / `remove`) show the pending line; the cancel mode applies only
     * when no line request is pending (`pendingStockAction === ''`) and a cancel request is. `units` is
     * what the action will really move, clamped to what the line holds for input it will refuse.
     *
     * @return list<array{itemId: string, name: string, variant: string|null, units: int, current: int, resulting: int, willGoNegative: bool}>
     */
    #[Computed]
    public function stockPreview(): array
    {
        if ($this->pendingStockAction === '' && $this->pendingCancelFromStatus !== '') {
            return $this->cancelStockPreview();
        }

        if (! in_array($this->pendingStockAction, ['quantity', 'remove'], true) || $this->pendingItemId === '') {
            return [];
        }

        $item = OrderItem::query()->where('order_id', $this->orderId)->find($this->pendingItemId);
        $target = $item?->stockTarget();

        if ($item === null || $target === null) {
            return [];
        }

        $row = $this->stockRow($target);

        if ($row === null) {
            return [];
        }

        $returnable = max(0, $item->returnableStockUnits());

        if ($this->pendingStockAction === 'remove') {
            $units = $returnable;
            $resulting = $row->stock + $units;
        } else {
            $isIncrease = $this->pendingQuantity > $item->quantity;
            $units = $isIncrease
                ? $this->pendingQuantity - $item->quantity
                : min($returnable, max(0, $returnable - ($this->pendingQuantity - $item->refunded_quantity)));
            $resulting = $isIncrease ? $row->stock - $units : $row->stock + $units;
        }

        return [[
            'itemId' => $item->id,
            'name' => $item->product_name,
            'variant' => $row instanceof ProductVariant ? $row->label() : null,
            'units' => $units,
            'current' => $row->stock,
            'resulting' => $resulting,
            'willGoNegative' => $this->pendingStockAction !== 'remove' && $resulting < 0,
        ]];
    }

    /**
     * The cancel dialog's rows: one per line with returnable units and a live stock target. Two lines
     * of the same target show the combined resulting stock, like the backend's aggregation.
     *
     * @return list<array{itemId: string, name: string, variant: string|null, units: int, current: int, resulting: int, willGoNegative: bool}>
     */
    private function cancelStockPreview(): array
    {
        $lines = OrderItem::query()->where('order_id', $this->orderId)->orderBy('id')->get();
        $unitsByTarget = [];

        foreach ($lines as $item) {
            $target = $item->stockTarget();

            if ($target !== null && $item->returnableStockUnits() > 0) {
                $key = $target['type'].':'.$target['id'];
                $unitsByTarget[$key] = ($unitsByTarget[$key] ?? 0) + $item->returnableStockUnits();
            }
        }

        $rows = [];

        foreach ($lines as $item) {
            $target = $item->stockTarget();
            $units = $item->returnableStockUnits();

            if ($target === null || $units <= 0) {
                continue;
            }

            $row = $this->stockRow($target);

            if ($row === null) {
                continue;
            }

            $rows[] = [
                'itemId' => $item->id,
                'name' => $item->product_name,
                'variant' => $row instanceof ProductVariant ? $row->label() : null,
                'units' => $units,
                'current' => $row->stock,
                'resulting' => $row->stock + $unitsByTarget[$target['type'].':'.$target['id']],
                'willGoNegative' => false,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{type: string, id: string}  $target
     */
    private function stockRow(array $target): Product|ProductVariant|null
    {
        return $target['type'] === 'variant'
            ? ProductVariant::query()->find($target['id'])
            : Product::query()->find($target['id']);
    }

    /**
     * Run the quantity action and render its refusals; the pending state is already cleared by the caller.
     */
    private function saveQuantity(string $itemId, int $quantity, bool $adjustStock, UpdateOrderItemQuantity $updateOrderItemQuantity): void
    {
        try {
            $updateOrderItemQuantity($this->order, $itemId, $quantity, $adjustStock);
        } catch (OrderNotEditableException $exception) {
            $this->refreshOrderState();
            $this->addError('lineItems', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->refreshOrderState();
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (ModelNotFoundException|LogicException) {
            $this->refreshOrderState();
            $this->addError('lineItems', __('orders.stock.changed_meanwhile'));

            return;
        }

        $this->refreshOrderState();
    }

    /**
     * Run the removal action and render its refusals; the pending state is already cleared by the caller.
     */
    private function removeLine(string $itemId, bool $adjustStock, RemoveOrderItem $removeOrderItem): void
    {
        try {
            $removeOrderItem($this->order, $itemId, $adjustStock);
        } catch (OrderNotEditableException $exception) {
            $this->refreshOrderState();
            $this->addError('lineItems', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->refreshOrderState();
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (ModelNotFoundException|LogicException) {
            $this->refreshOrderState();
            $this->addError('lineItems', __('orders.stock.changed_meanwhile'));

            return;
        }

        $this->refreshOrderState();
    }

    private function clearPendingStock(): void
    {
        $this->pendingStockAction = '';
        $this->pendingItemId = '';
        $this->pendingQuantity = 0;
        $this->pendingFromQuantity = 0;
        $this->showStockConfirm = false;
        unset($this->stockPreview);
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
        Gate::authorize('transitionStatus', $this->order);
        $this->resetErrorBag();

        $target = $this->selectedStatus;

        // Validated against the OFFERED options before any `from()` / `isBackwardFrom()` call:
        // `rank()` has no Cancelled arm, so a forged 'cancelled' would otherwise reach an
        // UnhandledMatchError, and `selectedStatus` is client-writable.
        if (! $this->isOfferedStatus($target)) {
            $this->rejectStatusSelection($target);

            return;
        }

        // A target the payment state forbids skips the backward dialog: confirming would only reach the
        // action's refusal, so go straight there and render it.
        if ($this->isBackwardTransition($target) && $this->order->payment_status->allowsOrderStatus(OrderStatus::from($target))) {
            $this->pendingStatus = $target;
            $this->pendingFromStatus = $this->order->status->value;
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
        Gate::authorize('transitionStatus', $this->order);
        $this->resetErrorBag();

        $confirmed = $this->pendingStatus !== '';
        $target = $confirmed ? $this->pendingStatus : $this->selectedStatus;

        if ($confirmed && $this->pendingFromStatus !== $this->order->status->value) {
            // The order moved while the dialog was open: the confirmation was for a different move.
            $this->clearPendingConfirmation();
            $this->addError('selectedStatus', __('orders.transitions.stale_confirmation'));

            return;
        }

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
        $this->clearPendingConfirmation();
        $this->resetErrorBag();
    }

    // ---------------------------------------------------------------------
    // Manual cancellation (0050)
    // ---------------------------------------------------------------------

    /**
     * Opens the three-way cancel dialog and records the order's current status as the stale guard.
     */
    public function confirmCancel(): void
    {
        Gate::authorize('cancel', $this->order);

        $this->clearPendingStock();
        $this->pendingCancelFromStatus = $this->order->status->value;
        unset($this->stockPreview);
        $this->showCancelConfirm = true;
    }

    #[Renderless]
    public function dismissCancelConfirm(): void
    {
        $this->clearPendingCancel();
    }

    /**
     * The cancel dialog's choice: `$adjust` true returns the order's units to stock, false cancels only.
     * Re-authorizes, then refuses an empty guard (no prior `confirmCancel()`) or a changed status like a
     * stale request; the guard is cleared on every exit path.
     */
    public function cancelOrder(bool $adjust, CancelOrder $cancelOrder): void
    {
        Gate::authorize('cancel', $this->order);
        $this->resetErrorBag();

        $fromStatus = $this->pendingCancelFromStatus;
        $this->clearPendingCancel();

        if ($fromStatus === '' || $fromStatus !== $this->order->status->value) {
            $this->refreshOrderState();
            $this->addError('cancel', __('orders.stock.cancel_changed_meanwhile'));

            return;
        }

        try {
            $cancelOrder($this->order, $adjust);
        } catch (OrderCancellationBlockedException $exception) {
            // Reachable without tampering only for the one actor the policy's state clause cannot
            // bind -- a Super Admin (Gate::before) -- or through a race; rendered, never a 500.
            $this->refreshOrderState();
            $this->addError('cancel', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->refreshOrderState();
            $this->addError('cancel', $this->firstMessage($exception));

            return;
        } catch (ModelNotFoundException|LogicException) {
            $this->refreshOrderState();
            $this->addError('cancel', __('orders.stock.cancel_changed_meanwhile'));

            return;
        }

        $this->refreshOrderState();
    }

    private function clearPendingCancel(): void
    {
        $this->pendingCancelFromStatus = '';
        $this->showCancelConfirm = false;
        unset($this->stockPreview);
    }

    // ---------------------------------------------------------------------
    // Refunds (0051 / 0052)
    // ---------------------------------------------------------------------

    public function openRefundModal(): void
    {
        Gate::authorize('orders.refund');

        if (! $this->order->isRefundable()) {
            return;
        }

        $this->showRefundModal = true;
    }

    public function closeRefundModal(): void
    {
        $this->showRefundModal = false;
        $this->resetErrorBag('refund');
        $this->syncRefundInputs();
    }

    public function recordRefund(RecordRefund $recordRefund): void
    {
        Gate::authorize('orders.refund');
        $this->resetErrorBag();

        $items = collect($this->refundQuantities)
            ->map(fn ($quantity): int => (int) $quantity)
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();

        if ($items === []) {
            $this->addError('refund', __('orders.refunds.nothing_selected'));

            return;
        }

        try {
            $recordRefund($this->order, $items);
        } catch (ValidationException $exception) {
            $this->addError('refund', $this->firstMessage($exception));

            return;
        }

        $this->showRefundModal = false;
        $this->refreshOrderState();
    }

    // ---------------------------------------------------------------------
    // Mark as paid (0085)
    // ---------------------------------------------------------------------

    /**
     * A silent no-op when the control does not apply: no Gate throw, no logging. A forged call is
     * caught where it matters, in `markAsPaid()`.
     */
    public function confirmMarkAsPaid(): void
    {
        if (! $this->canMarkPaid) {
            return;
        }

        $this->showMarkPaidConfirm = true;
    }

    /**
     * Renderless on purpose: a refused `markAsPaid()` closes the dialog server-side, the modal's `@close`
     * then calls this method, and Livewire does not persist the error bag between requests -- a re-render
     * here would wipe the `payment` error the refusal just added. The property still syncs to the client.
     */
    #[Renderless]
    public function dismissMarkAsPaid(): void
    {
        $this->showMarkPaidConfirm = false;
    }

    /**
     * Calls the action unconditionally -- NOT behind a bare `Gate::authorize`, which would skip the
     * action's own refusal logging. An AuthorizationException propagates (already logged). The payment
     * method is resolved server-side, never from client input.
     */
    public function markAsPaid(MarkOrderAsPaid $markOrderAsPaid): void
    {
        $this->resetErrorBag('payment');

        $paymentMethod = PaymentMethod::query()->where('code', PaymentMethodCode::BankTransfer)->firstOrFail();

        try {
            $markOrderAsPaid($this->order, $paymentMethod, OrderPaymentType::Transfer);
        } catch (ValidationException $exception) {
            $this->showMarkPaidConfirm = false;
            $this->refreshOrderState();
            $this->addError('payment', $this->firstMessage($exception));

            return;
        }

        $number = $this->order->order_number;

        $this->showMarkPaidConfirm = false;
        $this->refreshOrderState();

        Flux::toast(variant: 'success', text: __('orders.payment.marked', ['number' => $number]));
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function transitionTo(string $target, bool $confirmed, TransitionOrderStatus $transitionOrderStatus): void
    {
        try {
            $transitionOrderStatus($this->order, OrderStatus::from($target), $confirmed);
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
        return collect($this->statusOptions)->contains('value', $target);
    }

    private function isOfferedProduct(string $productId): bool
    {
        return $productId !== '' && collect($this->productOptions)->contains('id', $productId);
    }

    /**
     * Whether moving the order to `$target` is a regression. Only ever called with a value already
     * validated by `isOfferedStatus()`, so `OrderStatus::from()` cannot throw and `rank()` never sees
     * `Cancelled`.
     */
    private function isBackwardTransition(string $target): bool
    {
        return OrderStatus::from($target)->isBackwardFrom($this->order->status);
    }

    /**
     * Render why a selection was refused and put the select back on the order's real status.
     */
    private function rejectStatusSelection(string $target): void
    {
        $current = $this->order->status;

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
        $this->pendingFromStatus = '';
        $this->showBackwardConfirm = false;
        $this->selectedStatus = $resetSelectionTo ?? $this->order->status->value;
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
            $this->canMarkPaid,
            $this->statusOptions,
            $this->productOptions,
            $this->productCatalogTruncated,
            $this->variantOptions,
            $this->taxBasis,
            $this->shippingAddressLines,
        );

        $this->selectedStatus = $this->order->status->value;
        $this->syncQuantityInputs();
    }

    private function syncQuantityInputs(): void
    {
        $items = $this->order->items;

        $this->editingQuantities = $items->mapWithKeys(fn (OrderItem $item): array => [$item->id => $item->quantity])->all();

        $this->syncRefundInputs();
    }

    /**
     * Zero every refund input: a failed attempt's numbers must not survive a close and a reopen.
     */
    private function syncRefundInputs(): void
    {
        $this->refundQuantities = $this->order->items->mapWithKeys(fn (OrderItem $item): array => [$item->id => 0])->all();
    }

    private function firstMessage(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }
}
