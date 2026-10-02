<x-dashboard.widget
    icon="shopping-bag"
    tint="emerald"
    widget="orders"
    :title="__('dashboard.orders.title')"
    :href="route('orders.index')"
    :view-all="__('dashboard.orders.view_all')"
    :empty="$this->orders === []"
    :empty-text="__('dashboard.orders.empty')"
>
    <ul class="flex flex-col gap-1">
        @foreach ($this->orders as $row)
            <li class="flex items-start justify-between gap-3 rounded-xl px-3 py-3 transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-800/60" data-test="dashboard-order-row-{{ $row['id'] }}">
                <div class="min-w-0">
                    <a href="{{ route('orders.show', $row['id']) }}" wire:navigate class="break-words font-medium hover:underline" data-test="dashboard-order-link-{{ $row['id'] }}">{{ $row['orderNumber'] }}</a>

                    <p class="break-words text-sm text-neutral-500 dark:text-neutral-400" data-test="dashboard-order-customer-{{ $row['id'] }}">{{ $row['customerName'] ?? __('dashboard.deleted_customer') }}</p>

                    <x-money :amount="$row['total']" class="text-sm font-medium" data-test="dashboard-order-total-{{ $row['id'] }}" />
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1">
                    <x-order-status-badge :status="$row['status']->value" data-test="dashboard-order-status-{{ $row['id'] }}" />

                    <flux:badge
                        data-test="dashboard-order-payment-{{ $row['id'] }}"
                        :color="match ($row['paymentStatus']->value) {
                            'pending_payment' => 'amber',
                            'paid' => 'lime',
                            'partially_refunded' => 'blue',
                            default => 'zinc',
                        }"
                    >{{ __('orders.payment_statuses.'.$row['paymentStatus']->value) }}</flux:badge>
                </div>
            </li>
        @endforeach
    </ul>
</x-dashboard.widget>
