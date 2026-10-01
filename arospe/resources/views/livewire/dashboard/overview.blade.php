<?php
/**
 * View for App\Livewire\Dashboard\Overview (story 0083). A counter renders whenever its value is
 * not null -- never `@if($count)`, because 0 is a value worth showing. The widgets mount only for
 * actors holding the module's view ability (each widget re-checks it). The empty full-width slot
 * between the hero and the grid is reserved for the sales card of story 0086.
 */
?>
<div class="flex w-full flex-col gap-6">
    <x-slot:heading>{{ __('topbar.dashboard.title') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.dashboard.subtitle') }}</x-slot:subheading>

    @php($counters = $this->counters)
    @php($visibleCounters = array_filter($counters, fn (?int $count): bool => $count !== null))

    <section class="flex flex-col gap-6 rounded-[18px] bg-gradient-to-br from-[#4f46e5] to-[#6d5ef0] p-6 text-white md:flex-row md:items-center md:justify-between">
        <div class="min-w-0">
            <h2 class="text-2xl font-semibold" data-test="dashboard-greeting">{{ $this->greeting }}</h2>
            <p class="mt-1 text-white/80">{{ __('dashboard.hero.tagline') }}</p>
        </div>

        @if (count($visibleCounters) > 0)
            <div class="flex flex-wrap gap-3" data-test="dashboard-counters">
                @foreach (['users', 'products', 'images'] as $counter)
                    @if ($counters[$counter] !== null)
                        <div class="min-w-28 rounded-xl bg-white/15 px-4 py-3" data-test="dashboard-counter-{{ $counter }}">
                            <span class="block font-mono text-2xl font-semibold">{{ $counters[$counter] }}</span>
                            <span class="block text-sm text-white/80">{{ __('dashboard.counters.'.$counter) }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    {{-- Reserved, full width: story 0086 mounts the sales overview card here. --}}

    @php($showBlog = $this->widgets['blog'])
    @php($showStock = $this->widgets['stock'])
    @php($showOrders = $this->widgets['orders'])

    @if ($showBlog || $showStock || $showOrders)
        <div class="grid gap-6 lg:grid-cols-2 [&>:last-child:nth-child(odd)]:lg:col-span-2">
            @if ($showBlog)
                <livewire:dashboard.blog-widget />
            @endif

            @if ($showStock)
                <livewire:dashboard.low-stock-widget />
            @endif

            @if ($showOrders)
                <livewire:dashboard.latest-orders-widget />
            @endif
        </div>
    @elseif (count($visibleCounters) === 0)
        <p class="text-neutral-500 dark:text-neutral-400" data-test="dashboard-no-widgets">{{ __('dashboard.no_widgets') }}</p>
    @endif
</div>
