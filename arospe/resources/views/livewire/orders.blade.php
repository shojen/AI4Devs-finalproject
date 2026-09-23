<?php
/**
 * View for App\Livewire\Orders\Index (story 0055). Flat path, not orders/index.blade.php --
 * Livewire's Finder strips a trailing ".index" segment for an Index component in a subfolder, the
 * same exception Users\Index / Customers\Index already rely on; see
 * docs/conventions/naming.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 * Its sibling Orders\Show resolves NESTED (orders/show.blade.php): the two live at different
 * depths, which is expected rather than a mistake.
 *
 * Read-only: seven columns, an explicit empty state and NO create control (D-13 -- an order-creation
 * form is a materially larger surface, backlogged). The needs-attention marker is an inline icon
 * beside the order number, not an eighth column that would be empty on almost every row; its copy
 * reads "needs attention", never "error" (D-15). The detail action is a plain :href link, not a
 * wire:click -- a navigation needs no @js() and no server round trip, and it renders enabled for
 * every actor who can see the list at all, since orders.show gates on the same `orders.view`.
 */
?>
<div class="w-full">
    <x-slot:heading>{{ __('topbar.orders.title') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.orders.subtitle') }}</x-slot:subheading>

    <div>
        <flux:subheading>
            {{ $this->ordersSummary }}
        </flux:subheading>
    </div>

    <div class="mt-6">
        @if (count($this->orders) > 0)
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('orders.index.columns.order') }}</flux:table.column>
                    <flux:table.column>{{ __('orders.index.columns.customer') }}</flux:table.column>
                    <flux:table.column>{{ __('orders.index.columns.status') }}</flux:table.column>
                    <flux:table.column>{{ __('orders.index.columns.payment') }}</flux:table.column>
                    <flux:table.column>{{ __('orders.index.columns.total') }}</flux:table.column>
                    <flux:table.column>{{ __('orders.index.columns.placed') }}</flux:table.column>
                    <flux:table.column>{{ __('orders.index.columns.actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->orders as $order)
                        <flux:table.row :key="$order['id']" data-test="order-row-{{ $order['id'] }}">
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-zinc-800 dark:text-white">{{ $order['orderNumber'] }}</span>

                                    @if ($order['isFlagged'])
                                        <flux:tooltip :content="$order['flagReasonLabel']">
                                            <span
                                                class="inline-flex"
                                                role="img"
                                                tabindex="0"
                                                aria-label="{{ __('orders.index.flagged') }}"
                                                data-test="order-flagged-{{ $order['id'] }}"
                                            >
                                                <flux:icon.exclamation-triangle variant="mini" class="size-4 text-amber-500" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($order['customerLinkable'])
                                    <a
                                        href="{{ route('customers.show', $order['customerId']) }}"
                                        wire:navigate
                                        class="hover:underline"
                                        data-test="order-customer-{{ $order['id'] }}"
                                    >{{ $order['customerName'] }}</a>
                                @else
                                    <span data-test="order-customer-{{ $order['id'] }}">{{ $order['customerName'] }}</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge
                                    :color="match ($order['status']) {
                                        'pending' => 'zinc',
                                        'processing' => 'blue',
                                        'shipped' => 'amber',
                                        'delivered' => 'lime',
                                        'cancelled' => 'red',
                                        default => 'zinc',
                                    }"
                                >
                                    {{ $order['statusLabel'] }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge
                                    :color="match ($order['paymentStatus']) {
                                        'pending_payment' => 'amber',
                                        'paid' => 'lime',
                                        'partially_refunded' => 'blue',
                                        'refunded' => 'zinc',
                                        default => 'zinc',
                                    }"
                                >
                                    {{ $order['paymentStatusLabel'] }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell><x-money :amount="$order['total']" /></flux:table.cell>

                            <flux:table.cell>{{ $order['createdAt'] }}</flux:table.cell>

                            <flux:table.cell>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="eye"
                                    aria-label="{{ __('orders.index.view_order', ['number' => $order['orderNumber']]) }}"
                                    data-test="view-order-{{ $order['id'] }}"
                                    :href="route('orders.show', $order['id'])"
                                    wire:navigate
                                    class="cursor-pointer!"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="p-8 text-center border rounded-lg border-zinc-200 dark:border-zinc-700">
                <flux:text>{{ __('orders.index.empty') }}</flux:text>
            </div>
        @endif
    </div>
</div>
