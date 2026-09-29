<div>
    <flux:dropdown position="bottom" align="end">
        <flux:button
            variant="ghost"
            icon="bell"
            square
            class="relative"
            wire:click="open"
            :aria-label="__('notifications.bell.label')"
            data-test="notification-bell"
        >
            {{-- Its own island, so the 30s poll re-renders the count only and never the
            dropdown contents (D-2); `always` because islands are otherwise skipped on
            every render after mount, and opening the bell must clear the indicator.
            A structural @if: absence is a real DOM omission. --}}
            @island(name: 'unread-count', always: true)
                <span wire:poll.30s>
                    @if ($this->unreadCount > 0)
                        <span
                            class="absolute end-1 top-1 size-2 rounded-full bg-red-500"
                            role="status"
                            aria-label="{{ __('notifications.bell.unread') }}"
                            data-test="notification-bell-unread-indicator"
                        ></span>
                    @endif
                </span>
            @endisland
        </flux:button>

        <flux:menu class="w-80 max-w-[calc(100vw-2rem)] !p-0">
            @if ($opened)
                @if ($this->recentNotifications->isEmpty())
                    <flux:text class="p-4 text-center" data-test="notification-empty-state">
                        {{ __('notifications.bell.empty') }}
                    </flux:text>
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->recentNotifications as $notification)
                            {{-- Presentational branch only (D-3): a recognized arm is an enhancement
                            over the fallback, never a precondition for a type to display. A missing
                            payload key degrades to the fallback rather than raising. --}}
                            @php
                                $customerId = data_get($notification->data, 'customer_id');
                                $customerName = data_get($notification->data, 'customer_name');
                                $orderId = data_get($notification->data, 'order_id');
                                $orderNumber = data_get($notification->data, 'order_number');

                                $summary = null;
                                $href = null;

                                if ($notification->type === \App\Notifications\CustomerCreated::class
                                    && is_string($customerId) && is_string($customerName)) {
                                    $summary = __('notifications.summary.customer_created', ['name' => $customerName]);
                                    $href = route('customers.show', $customerId);
                                } elseif ($notification->type === \App\Notifications\OrderCreated::class
                                    && is_string($orderId) && is_string($orderNumber)) {
                                    $summary = __('notifications.summary.order_created', ['number' => $orderNumber]);
                                    // The order screen (story 0055) does not exist yet: link once it does.
                                    $href = \Illuminate\Support\Facades\Route::has('orders.show')
                                        ? route('orders.show', $orderId)
                                        : null;
                                }
                            @endphp

                            <li wire:key="notification-{{ $notification->id }}" data-test="notification-item-{{ $notification->id }}">
                                @if ($href)
                                    <a href="{{ $href }}" wire:navigate class="block px-4 py-3 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        <flux:text class="text-zinc-800 dark:text-white">{{ $summary }}</flux:text>
                                        <flux:text size="sm">{{ $notification->created_at?->diffForHumans() }}</flux:text>
                                    </a>
                                @else
                                    <div class="px-4 py-3">
                                        <flux:text class="text-zinc-800 dark:text-white">{{ $summary ?? __('notifications.fallback') }}</flux:text>
                                        <flux:text size="sm">{{ $notification->created_at?->diffForHumans() }}</flux:text>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </flux:menu>
    </flux:dropdown>
</div>
