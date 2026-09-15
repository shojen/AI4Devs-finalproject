<?php
/**
 * View for App\Livewire\Customers\Show (story 0047). Nested path -- NOT the
 * Index-in-a-subfolder exception, since the class is not named Index; this is
 * the ordinary mirror rule, one level deeper than its sibling
 * resources/views/livewire/customers.blade.php (which flattens because
 * Customers\Index IS named Index). See
 * docs/conventions/naming.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 *
 * No form, no modal, no wire:click anywhere on this page -- the screen writes
 * nothing (the story's central claim). The order-history section is wrapped
 * in @if ($this->canViewOrderHistory()) and, when it renders at all, contains
 * no row action and no row link (D-5): order management belongs to story
 * 0055's own screens.
 */
?>
<div class="w-full">
    <div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('customers.index')" wire:navigate>
            {{ __('customers.detail.back_to_list') }}
        </flux:button>
    </div>

    <div class="mt-4" data-test="customer-detail-header">
        <flux:heading size="xl">{{ $this->customer->name }}</flux:heading>

        <div class="mt-2 space-y-1">
            <flux:text>
                <span class="font-medium">{{ __('Email') }}:</span> {{ $this->customer->email }}
            </flux:text>
            <flux:text>
                <span class="font-medium">{{ __('Phone') }}:</span> {{ $this->customer->phone ?? '—' }}
            </flux:text>
        </div>
    </div>

    @if ($this->canViewOrderHistory())
        <div class="mt-8" data-test="customer-order-history">
            <flux:heading size="lg">{{ __('customers.detail.order_history_heading') }}</flux:heading>

            <div class="mt-4">
                @if (count($this->orders) > 0)
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Order') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Total') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->orders as $order)
                                <flux:table.row :key="$order['id']" data-test="customer-order-{{ $order['id'] }}">
                                    <flux:table.cell>{{ $order['orderNumber'] }}</flux:table.cell>

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

                                    <flux:table.cell>€ {{ $order['total'] }}</flux:table.cell>

                                    <flux:table.cell>{{ $order['createdAt'] }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @else
                    <div class="p-8 text-center border rounded-lg border-zinc-200 dark:border-zinc-700">
                        <flux:text>{{ __('customers.detail.no_orders') }}</flux:text>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
