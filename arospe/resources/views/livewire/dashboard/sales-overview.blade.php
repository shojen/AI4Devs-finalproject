<?php
/**
 * View for App\Livewire\Dashboard\SalesOverview (story 0086). Everything the charts show is also
 * printed as plain text in two sr-only tables OUTSIDE the wire:ignore canvas wrappers, so Livewire
 * keeps them current and they are the assertion surface of the feature tests. The canvases are drawn
 * by resources/js/sales-chart.js; only their wrappers are wire:ignore (never an @if around a canvas:
 * that would destroy the chart instance). Every value is rendered through {{ }} or @js(): no x-html,
 * no {!! !!}. The hook names are the contract in tests/Support/Dashboard/SalesOverviewUi.php.
 */
$chipClasses = [
    'pending' => 'border-zinc-400 bg-zinc-100 text-zinc-800 dark:border-zinc-500 dark:bg-zinc-500/20 dark:text-zinc-200',
    'processing' => 'border-blue-400 bg-blue-100 text-blue-800 dark:border-blue-500 dark:bg-blue-500/20 dark:text-blue-300',
    'shipped' => 'border-amber-400 bg-amber-100 text-amber-800 dark:border-amber-500 dark:bg-amber-500/20 dark:text-amber-300',
    'delivered' => 'border-lime-400 bg-lime-100 text-lime-800 dark:border-lime-500 dark:bg-lime-500/20 dark:text-lime-300',
    'cancelled' => 'border-red-400 bg-red-100 text-red-800 dark:border-red-500 dark:bg-red-500/20 dark:text-red-300',
];
$loadingTargets = 'granularity,from,to,statuses,applyPreset,toggleStatus,resetStatuses';
?>
<div>
    @if ($isAllowed)
        <section class="flex min-w-0 flex-col gap-5 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-test="sales-overview">
            <header class="flex items-center justify-between gap-3">
                <h3 class="text-base font-semibold" data-test="sales-title">{{ __('dashboard.sales.title') }}</h3>
                <span wire:loading wire:target="{{ $loadingTargets }}" class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('dashboard.sales.updating') }}</span>
            </header>

            {{-- Filter bar: one filter, one URL, one request. --}}
            <div class="flex flex-col gap-4" role="group" aria-label="{{ __('dashboard.sales.filters_label') }}">
                <div class="flex flex-wrap items-end gap-4">
                    <flux:radio.group wire:model.live="granularity" variant="segmented" size="sm" :label="__('dashboard.sales.granularity.label')" data-test="sales-granularity">
                        @foreach (['day', 'month', 'year'] as $option)
                            <flux:radio :value="$option" :label="__('dashboard.sales.granularity.'.$option)" />
                        @endforeach
                    </flux:radio.group>

                    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('dashboard.sales.presets.label') }}">
                        @foreach ($presets as $preset)
                            <flux:button
                                size="sm"
                                wire:click="applyPreset('{{ $preset }}')"
                                :variant="$activePreset === $preset ? 'primary' : 'filled'"
                                aria-pressed="{{ $activePreset === $preset ? 'true' : 'false' }}"
                                data-test="sales-preset-{{ $preset }}"
                            >{{ __('dashboard.sales.presets.'.$preset) }}</flux:button>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-end gap-3">
                        <flux:input type="date" size="sm" wire:model.live.blur="from" :max="$today" :label="__('dashboard.sales.from')" data-test="sales-from" />
                        <flux:input type="date" size="sm" wire:model.live.blur="to" :max="$today" :label="__('dashboard.sales.to')" data-test="sales-to" />
                    </div>
                </div>

                <flux:error name="range" data-test="sales-error-range" />
                <flux:error name="granularity" data-test="sales-error-granularity" />

                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium">{{ __('dashboard.sales.statuses') }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="resetStatuses" data-test="sales-reset-statuses">{{ __('dashboard.sales.reset') }}</flux:button>
                    </div>

                    <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="{{ __('dashboard.sales.statuses') }}">
                        @foreach ($statusCases as $status)
                            @php($isSelected = in_array($status, $selectedStatuses, true))
                            <button
                                type="button"
                                wire:click="toggleStatus('{{ $status->value }}')"
                                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                data-test="sales-chip-{{ $status->value }}"
                                @class([
                                    'shrink-0 cursor-pointer whitespace-nowrap rounded-full border px-3 py-1 text-sm font-medium transition-colors',
                                    $chipClasses[$status->value] => $isSelected,
                                    'border-neutral-300 bg-transparent text-neutral-500 hover:bg-neutral-100 dark:border-neutral-600 dark:text-neutral-400 dark:hover:bg-neutral-800' => ! $isSelected,
                                ])
                            >{{ $status->label() }}</button>
                        @endforeach
                    </div>

                    <flux:error name="statuses" data-test="sales-error-statuses" />
                </div>
            </div>

            {{-- KPI strip. --}}
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (['sales', 'income', 'orders'] as $tile)
                    <div class="flex min-w-0 flex-col gap-1 rounded-xl border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                        <div class="flex items-center gap-1 text-sm text-neutral-500 dark:text-neutral-400">
                            <span>{{ __('dashboard.sales.kpi.'.$tile) }}</span>
                            <flux:tooltip position="top">
                                <button type="button" class="cursor-help rounded-full" aria-label="{{ __('dashboard.sales.kpi.'.$tile.'_definition') }}">
                                    <flux:icon.information-circle class="size-4" />
                                </button>
                                <flux:tooltip.content data-test="sales-kpi-{{ $tile }}-definition">{{ __('dashboard.sales.kpi.'.$tile.'_definition') }}</flux:tooltip.content>
                            </flux:tooltip>
                        </div>

                        @if ($tile === 'sales')
                            <x-money :amount="$totalSales" class="break-words font-mono text-2xl font-semibold" data-test="sales-kpi-sales" />
                        @elseif ($tile === 'income')
                            <x-money :amount="$totalIncome" class="break-words font-mono text-2xl font-semibold" data-test="sales-kpi-income" />

                            @if ($collectedPercent !== null)
                                <p class="text-xs text-neutral-500 dark:text-neutral-400" data-test="sales-kpi-collected">{{ __('dashboard.sales.kpi.collected', ['percent' => $collectedPercent]) }}</p>
                            @elseif ($showIncomeHint)
                                <p class="text-xs text-neutral-500 dark:text-neutral-400" data-test="sales-kpi-income-hint">{{ __('dashboard.sales.kpi.income_hint') }}</p>
                            @endif
                        @else
                            <span class="font-mono text-2xl font-semibold" data-test="sales-kpi-orders">{{ $totalOrders }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="sr-only" aria-live="polite" data-test="sales-summary">{{ __('dashboard.sales.summary', [
                'from' => $summaryFrom,
                'to' => $summaryTo,
                'sales' => '€ '.$totalSales,
                'income' => '€ '.$totalIncome,
                'orders' => $totalOrders,
            ]) }}</p>

            {{-- The two charts: money and counts have different units, so they never share an axis. --}}
            <div class="grid gap-4 xl:grid-cols-2">
                <div class="relative flex min-w-0 flex-col gap-2" x-data="moneyChart">
                    <div hidden x-ref="config" data-config="{{ json_encode(['payload' => $payload, 'strings' => ['sales' => __('dashboard.sales.kpi.sales'), 'income' => __('dashboard.sales.kpi.income'), 'difference' => __('dashboard.sales.difference')]], JSON_THROW_ON_ERROR) }}"></div>
                    <h4 class="text-sm font-semibold">{{ __('dashboard.sales.chart_a_title') }}</h4>

                    <div wire:ignore x-show="! isEmpty" class="relative h-64 w-full">
                        <canvas x-ref="canvas" aria-hidden="true" data-test="money-chart-canvas"></canvas>
                    </div>

                    @if ($isEmpty)
                        <p wire:key="sales-empty" class="flex h-64 items-center justify-center rounded-xl border border-dashed border-neutral-300 px-4 text-center text-sm text-neutral-500 dark:border-neutral-600 dark:text-neutral-400" data-test="sales-empty">{{ __('dashboard.sales.empty') }}</p>
                    @endif

                    <div wire:loading.flex wire:target="{{ $loadingTargets }}" class="absolute inset-0 items-center justify-center rounded-xl bg-white/60 dark:bg-neutral-900/60" aria-hidden="true">
                        <flux:icon.arrow-path class="size-6 animate-spin text-neutral-500" />
                    </div>
                </div>

                <div class="relative flex min-w-0 flex-col gap-2" x-data="ordersChart">
                    <div hidden x-ref="config" data-config="{{ json_encode(['payload' => $payload, 'strings' => ['total' => __('dashboard.sales.total')]], JSON_THROW_ON_ERROR) }}"></div>
                    <h4 class="text-sm font-semibold">{{ __('dashboard.sales.chart_b_title') }}</h4>

                    <div wire:ignore x-show="! isEmpty" class="relative h-64 w-full">
                        <canvas x-ref="canvas" aria-hidden="true" data-test="orders-chart-canvas"></canvas>
                    </div>

                    @if ($isEmpty)
                        <p wire:key="sales-empty-orders" class="flex h-64 items-center justify-center rounded-xl border border-dashed border-neutral-300 px-4 text-center text-sm text-neutral-500 dark:border-neutral-600 dark:text-neutral-400" data-test="sales-empty-orders">{{ __('dashboard.sales.empty') }}</p>
                    @endif

                    <p wire:key="sales-legend-help" class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('dashboard.sales.legend_help') }}</p>

                    <div wire:loading.flex wire:target="{{ $loadingTargets }}" class="absolute inset-0 items-center justify-center rounded-xl bg-white/60 dark:bg-neutral-900/60" aria-hidden="true">
                        <flux:icon.arrow-path class="size-6 animate-spin text-neutral-500" />
                    </div>
                </div>
            </div>

            {{-- Text alternatives, outside wire:ignore: Livewire keeps them in step with the charts. --}}
            {{-- The sr-only class sits on a wrapper: a display:table element ignores width/overflow and would widen the page. --}}
            <div class="sr-only">
            <table data-test="sales-money-table">
                <caption>{{ __('dashboard.sales.money_caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('dashboard.sales.period') }}</th>
                        <th scope="col">{{ __('dashboard.sales.kpi.sales') }}</th>
                        <th scope="col">{{ __('dashboard.sales.kpi.income') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($moneyRows as $row)
                        <tr wire:key="money-{{ $row['bucket'] }}" data-test="sales-money-row-{{ $row['bucket'] }}">
                            <th scope="row" data-test="sales-money-label-{{ $row['bucket'] }}">{{ $row['label'] }}</th>
                            <td data-test="sales-money-sales-{{ $row['bucket'] }}">€ {{ $row['sales'] }}</td>
                            <td data-test="sales-money-income-{{ $row['bucket'] }}">€ {{ $row['income'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table data-test="sales-orders-table">
                <caption>{{ __('dashboard.sales.orders_caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('dashboard.sales.period') }}</th>
                        @foreach ($selectedStatuses as $status)
                            <th scope="col" data-test="sales-orders-th-{{ $status->value }}">{{ $status->label() }}</th>
                        @endforeach
                        <th scope="col">{{ __('dashboard.sales.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orderRows as $row)
                        <tr wire:key="orders-{{ $row['bucket'] }}" data-test="sales-orders-row-{{ $row['bucket'] }}">
                            <th scope="row" data-test="sales-orders-label-{{ $row['bucket'] }}">{{ $row['label'] }}</th>
                            @foreach ($selectedStatuses as $status)
                                <td data-test="sales-orders-cell-{{ $row['bucket'] }}-{{ $status->value }}">{{ $row['byStatus'][$status->value] }}</td>
                            @endforeach
                            <td data-test="sales-orders-total-{{ $row['bucket'] }}">{{ $row['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </section>
    @endif
</div>
