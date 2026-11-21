{{--
    A fixed A4-ratio mini page for the template cards of the invoice settings screen (story 0103).
    Props are server-derived only (FD-7): $primary / $accent are regex-checked hex colours or null,
    $logo is a Media-derived array or null. The page stays white in dark mode inside its frame.
--}}
@props(['template', 'primary' => null, 'accent' => null, 'logo' => null])

@php
    $isModern = $template === 'modern';
    $main = $primary ?? ($isModern ? '#0F766E' : '#1F2937');
    $line = $accent ?? ($isModern ? '#14B8A6' : '#9CA3AF');
@endphp

<div
    {{ $attributes->class('aspect-[210/297] w-full overflow-hidden rounded border border-zinc-200 bg-white p-2 dark:border-zinc-700') }}
    style="background-color: #ffffff"
    aria-hidden="true"
>
    @if ($isModern)
        <div class="flex items-start justify-between gap-1">
            <div class="space-y-1">
                <div class="h-1.5 w-10 rounded-sm" style="background-color: {{ $main }}"></div>
                <div class="h-1 w-14 rounded-sm bg-zinc-200"></div>
            </div>
            @if ($logo)
                <img src="{{ $logo['url'] }}" alt="" class="h-5 w-auto max-w-10 object-contain">
            @endif
        </div>
        <div class="mt-2 h-0.5 w-full" style="background-color: {{ $line }}"></div>
        <div class="mt-2 h-1.5 w-16 rounded-sm bg-zinc-300"></div>
        <div class="mt-3 space-y-1.5">
            @foreach (range(1, 3) as $row)
                <div class="flex gap-1"><div class="h-1 flex-1 rounded-sm bg-zinc-200"></div><div class="h-1 w-4 rounded-sm bg-zinc-200"></div></div>
            @endforeach
        </div>
        <div class="mt-3 ml-auto h-1.5 w-10 rounded-sm" style="background-color: {{ $line }}"></div>
    @else
        <div class="flex items-center justify-between gap-1 rounded-sm px-1.5 py-1.5" style="background-color: {{ $main }}">
            <div class="h-1.5 w-10 rounded-sm bg-white/80"></div>
            @if ($logo)
                <img src="{{ $logo['url'] }}" alt="" class="h-4 w-auto max-w-10 rounded-sm bg-white object-contain">
            @endif
        </div>
        <div class="mt-2 h-1.5 w-16 rounded-sm bg-zinc-300"></div>
        <div class="mt-3 space-y-1.5 border-t pt-1.5" style="border-color: {{ $line }}">
            @foreach (range(1, 3) as $row)
                <div class="flex gap-1"><div class="h-1 flex-1 rounded-sm bg-zinc-200"></div><div class="h-1 w-4 rounded-sm bg-zinc-200"></div></div>
            @endforeach
        </div>
        <div class="mt-3 ml-auto h-1.5 w-10 rounded-sm" style="background-color: {{ $main }}"></div>
    @endif
</div>
