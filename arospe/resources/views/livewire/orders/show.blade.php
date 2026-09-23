<?php
/**
 * View for App\Livewire\Orders\Show (story 0055). Nested path -- NOT the Index-in-a-subfolder
 * exception, since the class is not named Index: the ordinary component <-> view mirror, one level
 * deeper than its sibling resources/views/livewire/orders.blade.php (which flattens because
 * Orders\Index IS named Index). See
 * docs/conventions/naming.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 *
 * Five sections: header, customer (read-only plain text -- the addresses are a frozen historical
 * snapshot, so no input and no form), line items, status & lifecycle, totals & tax. Each has a
 * `data-test="*-section"` hook so a test can scope an absence assertion to one section.
 *
 * Markup rules inherited rather than invented (docs/errors-log-archive.md):
 *   1. @js(...) around every wire:click argument -- three controls here pass a line-item id.
 *   2. A disabled control is a separate @if/@else branch wrapped in an explicit <flux:tooltip>, never
 *      a conditionally-bound :tooltip prop (under livewire/blaze a Flux prop that decides whether a
 *      wrapper renders counts as PRESENT whenever the attribute is written on the tag at all).
 *   3. cursor-not-allowed! lives on that <flux:tooltip> wrapper, never on the disabled button
 *      (Flux's disabled:pointer-events-none takes the button out of hit-testing).
 *   4. Every wire:model-bound property has a real non-null value in the type the DOM expects.
 *   5. data-test hooks on BOTH branches of every gated control.
 *
 * The line-item section contains NO confirmation control of any kind -- 0048's hard block has no
 * confirmation path by design (PRD §3.2), and the reusable confirm-dialog below is used only by the
 * backward status transition and the cancellation.
 */
?>
@php
    $order = $this->order;
    $basis = $this->taxBasis;
    $hasTaxBasis = $basis['regionName'] !== null || $basis['rate'] !== null;
    $canEditLines = $this->canEditLineItems;
    $notAllowed = __('orders.detail.action_not_allowed');
@endphp
<div class="w-full space-y-8">
    <x-slot:heading>{{ $order->order_number }}</x-slot:heading>

    {{-- 1. Header --}}
    <div data-test="header-section" class="space-y-4">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('orders.index')" wire:navigate data-test="back-to-orders">
                {{ __('orders.detail.back_to_list') }}
            </flux:button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:badge
                :color="match ($order->status->value) {
                    'pending' => 'zinc',
                    'processing' => 'blue',
                    'shipped' => 'amber',
                    'delivered' => 'lime',
                    'cancelled' => 'red',
                    default => 'zinc',
                }"
                data-test="order-status-badge"
            >
                {{ $order->status->label() }}
            </flux:badge>

            <flux:badge
                :color="match ($order->payment_status->value) {
                    'pending_payment' => 'amber',
                    'paid' => 'lime',
                    'partially_refunded' => 'blue',
                    'refunded' => 'zinc',
                    default => 'zinc',
                }"
                data-test="order-payment-badge"
            >
                {{ __('orders.payment_statuses.'.$order->payment_status->value) }}
            </flux:badge>

            <flux:text>{{ __('orders.detail.placed_at', ['date' => $order->created_at?->format('d/m/Y H:i') ?? '']) }}</flux:text>
        </div>

        @if ($basis['isFlagged'])
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="order-flag-callout">
                <flux:callout.heading>{{ __('orders.detail.flag_callout_heading') }}</flux:callout.heading>
                <flux:callout.text>{{ $basis['flagLabel'] }}</flux:callout.text>
            </flux:callout>
        @endif
    </div>

    {{-- 2. Customer summary: read-only plain text, never a disabled input --}}
    <div data-test="customer-section" class="space-y-2">
        <flux:heading size="lg">{{ __('orders.detail.customer_heading') }}</flux:heading>

        <flux:text>
            @if ($this->canLinkCustomer)
                <a href="{{ route('customers.show', $order->customer_id) }}" wire:navigate class="font-medium hover:underline" data-test="order-customer-link">{{ $order->customer->name }}</a>
            @else
                <span class="font-medium" data-test="order-customer-name">{{ $order->customer->name }}</span>
            @endif
        </flux:text>

        <div>
            <flux:subheading>{{ __('orders.detail.shipping_address') }}</flux:subheading>
            @forelse ($this->shippingAddressLines as $line)
                <flux:text>{{ $line }}</flux:text>
            @empty
                <flux:text>{{ __('orders.detail.no_address') }}</flux:text>
            @endforelse
        </div>
    </div>

    {{-- 3. Line items --}}
    <div data-test="line-items-section" class="space-y-4">
        <flux:heading size="lg">{{ __('orders.detail.line_items_heading') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('orders.line_items.product') }}</flux:table.column>
                <flux:table.column>{{ __('orders.line_items.quantity') }}</flux:table.column>
                <flux:table.column>{{ __('orders.line_items.unit_price') }}</flux:table.column>
                <flux:table.column>{{ __('orders.line_items.line_total') }}</flux:table.column>
                <flux:table.column>{{ __('orders.line_items.refunded') }}</flux:table.column>
                <flux:table.column>{{ __('orders.index.columns.actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->lineItems as $item)
                    <flux:table.row :key="$item['id']" data-test="line-item-{{ $item['id'] }}">
                        <flux:table.cell>
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $item['productName'] }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $item['productSku'] }}</div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($canEditLines)
                                <flux:input
                                    type="number"
                                    min="1"
                                    size="sm"
                                    class="w-24"
                                    wire:model="editingQuantities.{{ $item['id'] }}"
                                    data-test="quantity-input-{{ $item['id'] }}"
                                />
                            @else
                                {{ $item['quantity'] }}
                            @endif
                        </flux:table.cell>

                        <flux:table.cell><x-money :amount="$item['unitPrice']" /></flux:table.cell>
                        <flux:table.cell><x-money :amount="$item['lineTotal']" /></flux:table.cell>
                        <flux:table.cell>{{ $item['refundedQuantity'] }}</flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                @if ($canEditLines)
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        data-test="save-quantity-{{ $item['id'] }}"
                                        wire:click="updateLineItemQuantity(@js($item['id']))"
                                        class="cursor-pointer!"
                                    >
                                        {{ __('orders.line_items.save_quantity') }}
                                    </flux:button>
                                @else
                                    <flux:tooltip :content="$notAllowed" class="cursor-not-allowed!">
                                        <flux:button size="sm" variant="outline" data-test="save-quantity-{{ $item['id'] }}" disabled>
                                            {{ __('orders.line_items.save_quantity') }}
                                        </flux:button>
                                    </flux:tooltip>
                                @endif

                                @if ($canEditLines && $item['canRemove'])
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        aria-label="{{ __('orders.line_items.remove') }}"
                                        data-test="remove-line-item-{{ $item['id'] }}"
                                        wire:click="removeLineItem(@js($item['id']))"
                                        class="cursor-pointer! text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                    />
                                @else
                                    <flux:tooltip :content="$canEditLines ? __('orders.line_items.remove_refunded_hint') : $notAllowed" class="cursor-not-allowed!">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="trash"
                                            aria-label="{{ __('orders.line_items.remove') }}"
                                            data-test="remove-line-item-{{ $item['id'] }}"
                                            disabled
                                            class="text-red-500"
                                        />
                                    </flux:tooltip>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{-- The add-line-item row (D-1): the interim plain-select picker, until story 0022's shared
             searchable multi-select replaces it. The whole form goes disabled as a unit. --}}
        <div class="grid gap-3 sm:grid-cols-[1fr_1fr_8rem_auto] sm:items-end" data-test="add-line-item-form">
            <flux:select
                wire:model.live="newProductId"
                :label="__('orders.line_items.product')"
                data-test="new-product-id"
                :disabled="! $canEditLines"
            >
                <flux:select.option value="">{{ __('orders.line_items.select_product') }}</flux:select.option>
                @foreach ($this->productOptions as $product)
                    <flux:select.option :value="$product['id']">{{ $product['name'] }} ({{ $product['sku'] }})</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->variantOptions !== [])
                <flux:select
                    wire:model="newProductVariantId"
                    :label="__('orders.line_items.variant')"
                    data-test="new-variant-id"
                    :disabled="! $canEditLines"
                >
                    <flux:select.option value="">{{ __('orders.line_items.select_variant') }}</flux:select.option>
                    @foreach ($this->variantOptions as $variant)
                        <flux:select.option :value="$variant['id']">{{ $variant['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <div></div>
            @endif

            <flux:input
                type="number"
                min="1"
                :label="__('orders.line_items.quantity')"
                wire:model="newQuantity"
                data-test="new-quantity"
                :disabled="! $canEditLines"
            />

            @if ($canEditLines)
                <flux:button variant="primary" icon="plus" data-test="add-line-item" wire:click="addLineItem" class="cursor-pointer!">
                    {{ __('orders.line_items.add') }}
                </flux:button>
            @else
                <flux:tooltip :content="$notAllowed" class="cursor-not-allowed!">
                    <flux:button variant="primary" icon="plus" data-test="add-line-item" disabled>
                        {{ __('orders.line_items.add') }}
                    </flux:button>
                </flux:tooltip>
            @endif
        </div>

        @if ($canEditLines && $this->productOptions === [])
            <flux:text data-test="catalog-empty">{{ __('orders.line_items.catalog_empty') }}</flux:text>
        @endif

        @if ($this->productCatalogTruncated)
            <flux:text data-test="catalog-truncated">{{ __('orders.line_items.catalog_truncated', ['max' => \App\Livewire\Orders\Show::PRODUCT_PICKER_LIMIT]) }}</flux:text>
        @endif

        <div data-test="line-items-errors" class="space-y-1">
            @foreach (['lineItems', 'order_item_id', 'quantity', 'items', 'product_id', 'product_variant_id', 'newProductId', 'newProductVariantId'] as $errorKey)
                @error($errorKey)
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror
            @endforeach
        </div>
    </div>

    {{-- 4. Status & lifecycle --}}
    <div data-test="lifecycle-section" class="space-y-4">
        <flux:heading size="lg">{{ __('orders.detail.lifecycle_heading') }}</flux:heading>

        <div class="flex flex-wrap items-end gap-3">
            {{-- The select binds a real backing-value STRING. The current status is rendered as a
                 disabled option so the native <select> starts on the value the property holds; only
                 statusOptions() (never Cancelled, never the current status) are selectable. --}}
            <flux:select
                wire:model="selectedStatus"
                :label="__('orders.lifecycle.status')"
                class="max-w-xs"
                data-test="status-select"
                :disabled="! $this->canTransitionStatus || $this->statusOptions === []"
            >
                <flux:select.option :value="$order->status->value" disabled>{{ $order->status->label() }}</flux:select.option>
                @foreach ($this->statusOptions as $option)
                    <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->canTransitionStatus && $this->statusOptions !== [])
                <flux:button variant="primary" data-test="apply-status" wire:click="requestStatusChange" class="cursor-pointer!">
                    {{ __('orders.lifecycle.apply') }}
                </flux:button>
            @else
                <flux:tooltip :content="$notAllowed" class="cursor-not-allowed!">
                    <flux:button variant="primary" data-test="apply-status" disabled>
                        {{ __('orders.lifecycle.apply') }}
                    </flux:button>
                </flux:tooltip>
            @endif

            @if ($this->canCancel)
                <flux:button variant="danger" data-test="cancel-order" wire:click="confirmCancel" class="cursor-pointer!">
                    {{ __('orders.lifecycle.cancel') }}
                </flux:button>
            @else
                <flux:tooltip :content="$notAllowed" class="cursor-not-allowed!">
                    <flux:button variant="danger" data-test="cancel-order" disabled>
                        {{ __('orders.lifecycle.cancel') }}
                    </flux:button>
                </flux:tooltip>
            @endif
        </div>

        <flux:error name="selectedStatus" />
        <flux:error name="cancel" />
    </div>

    {{-- 5. Totals & tax --}}
    <div data-test="totals-section" class="space-y-6">
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('orders.detail.totals_heading') }}</flux:heading>

            <dl class="grid max-w-sm grid-cols-2 gap-x-6 gap-y-1">
                <dt>{{ __('orders.detail.subtotal') }}</dt>
                <dd class="text-right"><x-money :amount="$order->subtotal" /></dd>
                <dt>{{ __('orders.detail.tax_amount') }}</dt>
                <dd class="text-right"><x-money :amount="$order->tax_amount" /></dd>
                <dt>{{ __('orders.detail.shipping_amount') }}</dt>
                <dd class="text-right"><x-money :amount="$order->shipping_amount" /></dd>
                <dt class="font-semibold">{{ __('orders.detail.total') }}</dt>
                <dd class="text-right font-semibold"><x-money :amount="$order->total" /></dd>
                <dt>{{ __('orders.detail.refunded_amount') }}</dt>
                <dd class="text-right"><x-money :amount="$order->refunded_amount" /></dd>
            </dl>

            {{-- The refund control lives beside refunded_amount (D-10). Its payment STATE hides it
                 entirely (PRD §3.2: "the refund action does not render"); its PERMISSION disables it.
                 Two dimensions, two treatments, and they must not be collapsed into one flag. --}}
            @if ($this->isRefundable)
                @if ($this->canRefund)
                    <flux:button variant="outline" icon="arrow-uturn-left" data-test="record-refund" wire:click="openRefundModal" class="cursor-pointer!">
                        {{ __('orders.refunds.action') }}
                    </flux:button>
                @else
                    <flux:tooltip :content="$notAllowed" class="cursor-not-allowed!">
                        <flux:button variant="outline" icon="arrow-uturn-left" data-test="record-refund" disabled>
                            {{ __('orders.refunds.action') }}
                        </flux:button>
                    </flux:tooltip>
                @endif
            @endif

            {{-- Outside the isRefundable branch on purpose: a refusal of a forged/raced call (the
                 order stopped being refundable meanwhile) must still be visible. --}}
            @unless ($showRefundModal)
                <flux:error name="refund" />
            @endunless
        </div>

        {{-- The tax basis (D-16): branched on sales_region_id and tax_rate, NEVER on tax_amount. --}}
        <div class="space-y-2" data-test="order-tax-panel">
            <flux:heading size="lg">{{ __('orders.detail.tax_heading') }}</flux:heading>

            @if ($basis['isFlagged'])
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="tax-flag-notice">
                    <flux:callout.text>{{ $basis['flagLabel'] }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($hasTaxBasis)
                <dl class="grid max-w-sm grid-cols-2 gap-x-6 gap-y-1">
                    @if ($basis['regionName'] !== null)
                        <dt>{{ __('orders.detail.tax_region') }}</dt>
                        <dd class="text-right" data-test="tax-region">{{ $basis['regionName'] }}</dd>
                    @endif

                    <dt>{{ __('orders.detail.tax_rate') }}</dt>
                    @if ($basis['rate'] !== null)
                        <dd class="text-right" data-test="tax-rate">{{ $basis['rate'] }}%</dd>
                    @else
                        <dd class="text-right" data-test="tax-unresolved">{{ __('orders.detail.tax_unresolved') }}</dd>
                    @endif
                </dl>

                @if ($basis['isFlagged'])
                    <flux:text data-test="tax-provisional">{{ __('orders.detail.tax_provisional') }}</flux:text>
                @endif
            @elseif (! $basis['isFlagged'])
                <flux:text data-test="tax-unresolved">{{ __('orders.detail.tax_unresolved') }}</flux:text>
            @endif
        </div>
    </div>

    {{-- Dialogs. Both use the shared anonymous confirm-dialog (D-2) -- and NEITHER guards a
         line-item action: 0048's hard block has no confirmation path around it. --}}
    <x-confirm-dialog
        :show="$showBackwardConfirm"
        model="showBackwardConfirm"
        :heading="__('orders.transitions.confirm_backward_heading')"
        :body="__('orders.transitions.confirm_backward_body')"
        :confirm-label="__('orders.transitions.confirm_backward_action')"
        :dismiss-label="__('orders.lifecycle.dialog_dismiss')"
        confirm-action="applyStatusChange"
        dismiss-action="dismissBackwardConfirm"
        test-prefix="backward-transition"
    />

    <x-confirm-dialog
        :show="$showCancelConfirm"
        model="showCancelConfirm"
        :heading="__('orders.lifecycle.cancel_dialog_heading')"
        :body="__('orders.lifecycle.cancel_dialog_body')"
        :confirm-label="__('orders.lifecycle.cancel_dialog_confirm')"
        :dismiss-label="__('orders.lifecycle.dialog_dismiss')"
        confirm-action="cancelOrder"
        dismiss-action="dismissCancelConfirm"
        test-prefix="cancel-order"
        variant="danger"
    />

    {{-- The refund modal is not a confirm-dialog: it collects per-line units. Its inner content is
         wrapped in @if so only one dismiss control is ever in the DOM. --}}
    <flux:modal name="refund-modal" class="max-w-lg md:min-w-lg" wire:model="showRefundModal" @close="closeRefundModal">
        @if ($showRefundModal)
            <div class="space-y-6" data-test="refund-modal">
                <flux:heading size="lg">{{ __('orders.refunds.modal_title') }}</flux:heading>

                <div class="space-y-3">
                    @foreach ($this->lineItems as $item)
                        @php($outstanding = $item['quantity'] - $item['refundedQuantity'])
                        @if ($outstanding > 0)
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <div class="font-medium">{{ $item['productName'] }}</div>
                                    <flux:text size="sm">{{ __('orders.refunds.outstanding', ['count' => $outstanding]) }}</flux:text>
                                </div>
                                <flux:input
                                    type="number"
                                    min="0"
                                    :max="$outstanding"
                                    class="w-24"
                                    wire:model="refundQuantities.{{ $item['id'] }}"
                                    aria-label="{{ __('orders.refunds.units_to_refund') }}"
                                    data-test="refund-quantity-{{ $item['id'] }}"
                                />
                            </div>
                        @endif
                    @endforeach

                    <flux:error name="refund" />
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeRefundModal" data-test="refund-dismiss">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="primary" wire:click="recordRefund" wire:loading.attr="disabled" wire:target="recordRefund" data-test="refund-confirm">
                        {{ __('orders.refunds.confirm') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
