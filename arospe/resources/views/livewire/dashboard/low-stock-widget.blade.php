<x-dashboard.widget
    icon="exclamation-triangle"
    tint="amber"
    widget="stock"
    :title="__('dashboard.stock.title')"
    :href="route('products.index')"
    :view-all="__('dashboard.stock.view_all')"
    :empty="$this->products['rows'] === []"
    :empty-text="__('dashboard.stock.empty')"
>
    <ul class="flex flex-col gap-1">
        @foreach ($this->products['rows'] as $row)
            <li class="flex items-start justify-between gap-3 rounded-xl px-3 py-3 transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-800/60" data-test="dashboard-stock-row-{{ $row['id'] }}">
                <div class="min-w-0">
                    @if ($this->products['canEdit'])
                        <a href="{{ route('products.edit', $row['id']) }}" wire:navigate class="break-words font-medium hover:underline" data-test="dashboard-stock-name-{{ $row['id'] }}">{{ $row['name'] ?? $row['sku'] }}</a>
                    @else
                        <span class="break-words font-medium" data-test="dashboard-stock-name-{{ $row['id'] }}">{{ $row['name'] ?? $row['sku'] }}</span>
                    @endif

                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        <span data-test="dashboard-stock-units-{{ $row['id'] }}">{{ trans_choice('dashboard.stock.units', $row['effectiveStock']) }}</span>
                    </p>

                    @if ($row['hasVariants'] && $row['lowVariantCount'] > 0)
                        <p class="text-xs text-neutral-500 dark:text-neutral-400" data-test="dashboard-stock-variants-{{ $row['id'] }}">{{ trans_choice('dashboard.stock.variants_low', $row['lowVariantCount']) }}</p>
                    @endif
                </div>

                @if ($row['isOutOfStock'])
                    <flux:badge color="red" data-test="dashboard-stock-badge-{{ $row['id'] }}">{{ __('dashboard.stock.out_of_stock') }}</flux:badge>
                @else
                    <flux:badge color="amber" data-test="dashboard-stock-badge-{{ $row['id'] }}">{{ __('dashboard.stock.low_stock') }}</flux:badge>
                @endif
            </li>
        @endforeach
    </ul>
</x-dashboard.widget>
