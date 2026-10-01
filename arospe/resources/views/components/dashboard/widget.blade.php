{{--
    Story 0083 D-1 -- the shell shared by the three dashboard widgets: a bordered card with a title,
    the body (the slot, or the empty-state text when `empty` is true) and the "view all" footer link.
    `widget` names the widget in its data-test hooks (dashboard-widget-{widget}, dashboard-empty-{widget},
    dashboard-view-all-{widget}).
--}}
@props(['widget', 'title', 'href', 'viewAll', 'empty' => false, 'emptyText' => ''])
<section {{ $attributes->class('flex min-w-0 flex-col rounded-xl border border-neutral-200 dark:border-neutral-700') }} data-test="dashboard-widget-{{ $widget }}">
    <h3 class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-700">{{ $title }}</h3>

    <div class="flex-1 px-4 py-2">
        @if ($empty)
            <p class="py-4 text-sm text-neutral-500 dark:text-neutral-400" data-test="dashboard-empty-{{ $widget }}">{{ $emptyText }}</p>
        @else
            {{ $slot }}
        @endif
    </div>

    <div class="border-t border-neutral-200 px-4 py-3 dark:border-neutral-700">
        <a href="{{ $href }}" wire:navigate class="text-sm font-medium hover:underline" data-test="dashboard-view-all-{{ $widget }}">{{ $viewAll }}</a>
    </div>
</section>
