{{--
    Story 0083 D-1 -- the shell shared by the three dashboard widgets: a card with a tinted icon badge
    and title, the body (the slot, or the empty-state text when `empty` is true) and the "view all"
    footer link. `widget` names the widget in its data-test hooks (dashboard-widget-{widget},
    dashboard-empty-{widget}, dashboard-view-all-{widget}). `icon` is a Flux icon name and `tint`
    one of indigo|amber|emerald (literal classes so Tailwind keeps them).
--}}
@props(['widget', 'title', 'href', 'viewAll', 'icon' => 'squares-2x2', 'tint' => 'indigo', 'empty' => false, 'emptyText' => ''])
@php($tintClasses = match ($tint) {
    'amber' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    'emerald' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
    default => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400',
})
<section {{ $attributes->class('flex min-w-0 flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900') }} data-test="dashboard-widget-{{ $widget }}">
    <header class="flex items-center gap-3 px-5 pt-5 pb-3">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $tintClasses }}">
            <flux:icon :name="$icon" class="size-5" />
        </span>
        <h3 class="text-base font-semibold">{{ $title }}</h3>
    </header>

    <div class="flex-1 px-2 pb-2">
        @if ($empty)
            <div class="flex flex-col items-center gap-2 px-3 py-8 text-center">
                <span class="flex size-12 items-center justify-center rounded-full {{ $tintClasses }}">
                    <flux:icon :name="$icon" class="size-6 opacity-70" />
                </span>
                <p class="text-sm text-neutral-500 dark:text-neutral-400" data-test="dashboard-empty-{{ $widget }}">{{ $emptyText }}</p>
            </div>
        @else
            {{ $slot }}
        @endif
    </div>

    <a href="{{ $href }}" wire:navigate class="group flex items-center justify-between border-t border-neutral-100 bg-neutral-50 px-5 py-3 text-sm font-medium text-neutral-700 transition-colors hover:bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-800/50 dark:text-neutral-200 dark:hover:bg-neutral-800" data-test="dashboard-view-all-{{ $widget }}">
        <span>{{ $viewAll }}</span>
        <flux:icon.arrow-right class="size-4 transition-transform group-hover:translate-x-0.5" />
    </a>
</section>
