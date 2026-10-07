{{--
    The language tab STRIP (story 0071, D-1): headers, active state and per-tab error markers only.
    The panels are the consumer's own markup and carry two obligations: each is always mounted and
    hidden with x-show (never @if, D-2), and each carries data-test="language-panel-{id}".

    Props:
      languages        Collection of StoreLanguage, already in tab order (default first, D-14)
      active           the active language id
      errorLanguageIds language ids whose tab carries a validation error

    The consuming Livewire component MUST expose setActiveLanguageTab(string $languageId).
    The id is rendered with Js::from() inside this template (never @js(), D-8).
--}}
@props(['languages', 'active', 'errorLanguageIds' => []])

<div role="tablist" class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700" data-test="language-tab-strip">
    @foreach ($languages as $language)
        @php
            $isActive = $language->id === $active;
            $hasError = in_array($language->id, $errorLanguageIds, true);
        @endphp

        <button
            type="button"
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            data-test="language-tab-{{ $language->id }}"
            wire:click="setActiveLanguageTab({{ Js::from($language->id) }})"
            @class([
                '-mb-px flex cursor-pointer items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium',
                'border-zinc-800 text-zinc-800 dark:border-white dark:text-white' => $isActive,
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' => ! $isActive,
            ])
        >
            <span>{{ $language->name }}</span>

            @if ($hasError)
                <span
                    role="img"
                    aria-label="{{ __('products.categories.index.tabs.error_marker') }}"
                    data-test="language-tab-error-{{ $language->id }}"
                    class="inline-flex text-red-500"
                >
                    <flux:icon.exclamation-circle variant="mini" class="size-4" />
                </span>
            @endif
        </button>
    @endforeach
</div>
