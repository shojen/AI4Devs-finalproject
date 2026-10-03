{{--
    View for App\Livewire\StoreLanguages\Index (story 0069). Flat path, not
    store-languages/index.blade.php -- the Index-in-a-subfolder exception, see
    docs/conventions/naming.md.

    Two visually distinct sections on purpose: the CONTENT languages (any ISO 639-1 language, about
    the store's content) and the DASHBOARD defaults (en/es only, about the admin interface). The
    same word -- "English" -- means different things in each, so they never share markup, state or
    option lists. Both modals live inside the first section so the second section's slice of the
    page never contains catalog markup.

    Disabled controls follow both documented Flux/Blaze traps (docs/errors-log.md): an explicit
    @if/@else with a written-out <flux:tooltip> wrapper (a conditionally-bound :tooltip prop renders
    on every row), and cursor-not-allowed! on that wrapper, never on the disabled button.
--}}
<div class="space-y-8">
    <x-slot:heading>{{ __('store-languages.index.heading') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.store_languages.subtitle') }}</x-slot:subheading>

    {{-- ============ Section A: content languages ============ --}}
    <flux:card class="space-y-6 mt-6" data-test="store-languages-section">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('store-languages.index.section_heading') }}</flux:heading>
                <flux:subheading>{{ __('store-languages.index.section_description') }}</flux:subheading>
            </div>

            @if ($this->canAddLanguage)
                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="openAddLanguageModal"
                    data-test="add-language-button"
                    class="cursor-pointer!"
                >
                    {{ __('store-languages.index.add_button') }}
                </flux:button>
            @else
                <flux:tooltip :content="__('store-languages.index.action_not_allowed')" class="cursor-not-allowed!">
                    <flux:button variant="primary" icon="plus" disabled data-test="add-language-button">
                        {{ __('store-languages.index.add_button') }}
                    </flux:button>
                </flux:tooltip>
            @endif
        </div>

        @if (! $showRemoveModal)
            @error('languageId')
                <flux:text class="text-red-600 dark:text-red-400" data-test="language-error">{{ $message }}</flux:text>
            @enderror
        @endif

        @if (count($languages) === 0)
            <flux:text>{{ __('store-languages.index.empty') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('store-languages.index.column_language') }}</flux:table.column>
                    <flux:table.column>{{ __('store-languages.index.column_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($languages as $language)
                        <flux:table.row :key="$language['id']" data-test="language-row-{{ $language['id'] }}">
                            <flux:table.cell>
                                <div class="flex flex-wrap items-center gap-2" data-test="language-name-{{ $language['id'] }}">
                                    <span class="font-medium text-zinc-800 dark:text-white">{{ $language['name'] }}</span>
                                    <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-0.5 font-mono text-xs text-zinc-700 dark:bg-white/10 dark:text-zinc-300">{{ $language['code'] }}</span>
                                    @if ($language['isDefault'])
                                        <flux:badge color="lime" data-test="default-badge-language-{{ $language['id'] }}">
                                            {{ __('store-languages.index.default_badge') }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    {{-- Set default: permission refusal and "already the default" are
                                    different reasons with different copy. --}}
                                    @if ($language['canSetDefault'] && ! $language['isDefault'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="star"
                                            aria-label="{{ __('store-languages.index.set_default_label', ['name' => $language['name']]) }}"
                                            data-test="set-default-language-{{ $language['id'] }}"
                                            wire:click="setDefaultLanguage(@js($language['id']))"
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip
                                            :content="! $language['canSetDefault'] ? __('store-languages.index.action_not_allowed') : __('store-languages.index.already_default_tooltip')"
                                            class="cursor-not-allowed!"
                                        >
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="star"
                                                aria-label="{{ __('store-languages.index.set_default_label', ['name' => $language['name']]) }}"
                                                data-test="set-default-language-{{ $language['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif

                                    {{-- Remove: permission first, then the more specific domain state
                                    (the only active language beats "is the default"). --}}
                                    @if ($language['canRemove'] && ! $language['isDefault'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            aria-label="{{ __('store-languages.index.remove_label', ['name' => $language['name']]) }}"
                                            data-test="remove-language-{{ $language['id'] }}"
                                            wire:click="confirmRemoveLanguage(@js($language['id']))"
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip
                                            :content="match (true) {
                                                ! $language['canRemove'] => __('store-languages.index.action_not_allowed'),
                                                $this->isOnlyActiveLanguage => __('store-languages.index.remove_last_language_tooltip'),
                                                default => __('store-languages.index.remove_default_tooltip'),
                                            }"
                                            class="cursor-not-allowed!"
                                        >
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                aria-label="{{ __('store-languages.index.remove_label', ['name' => $language['name']]) }}"
                                                data-test="remove-language-{{ $language['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        {{-- Picker modal. Content only when open, so the ~184 option buttons are never in the
        DOM (or the payload) otherwise. The search is client-side Alpine: bound to NO Livewire
        property, and each option is an act-now button submitting the fixture's canonical code, so
        there is no free-text code path and no bound property to desync. --}}
        <flux:modal name="add-language-modal" class="max-w-md md:min-w-md" @close="closeAddLanguageModal" wire:model="showAddLanguageModal">
            @if ($showAddLanguageModal)
                <div
                    class="space-y-4"
                    x-data="{
                        filterText: '',
                        matchesFilter(haystack) {
                            const query = this.filterText.trim().toLowerCase();

                            return query === '' || haystack.includes(query);
                        },
                    }"
                >
                    <div class="space-y-1">
                        <flux:heading size="lg">{{ __('store-languages.index.add_modal_heading') }}</flux:heading>
                        <flux:subheading>{{ __('store-languages.index.add_modal_description') }}</flux:subheading>
                    </div>

                    @error('code')
                        <flux:text class="text-red-600 dark:text-red-400" data-test="add-language-error">{{ $message }}</flux:text>
                    @enderror

                    <flux:input
                        type="text"
                        data-test="language-picker-search"
                        x-model="filterText"
                        :placeholder="__('store-languages.index.search_placeholder')"
                    />

                    <ul class="max-h-80 space-y-1 overflow-y-auto">
                        @foreach ($this->availableLanguageOptions as $optionCode => $optionName)
                            <li
                                x-show="matchesFilter($el.dataset.search)"
                                data-search="{{ \Illuminate\Support\Str::lower($optionName.' '.$optionCode) }}"
                            >
                                <button
                                    type="button"
                                    data-test="language-option-{{ $optionCode }}"
                                    wire:click="addLanguage(@js($optionCode))"
                                    class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-white/10"
                                >
                                    <span class="font-medium text-zinc-800 dark:text-white">{{ $optionName }}</span>
                                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $optionCode }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex justify-end">
                        <flux:button variant="outline" wire:click="closeAddLanguageModal">
                            {{ __('store-languages.index.cancel') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>

        {{-- Removal confirmation: three states, the more specific reason wins. Plain confirm; a
        required replacement while removing the default; and, for the only active language, no
        replacement select at all and a disabled Confirm. --}}
        <flux:modal name="remove-language-modal" class="max-w-md md:min-w-md" @close="closeRemoveModal" wire:model="showRemoveModal">
            @if ($showRemoveModal && $languageId !== '' && $this->canRemoveSelectedLanguage)
                @php
                    $removalTarget = collect($languages)->firstWhere('id', $languageId);
                    $removalIsDefault = $removalTarget !== null && $removalTarget['isDefault'];
                    $removalIsBlocked = $removalIsDefault && $this->isOnlyActiveLanguage;
                    $removalNeedsReplacement = $removalIsDefault && ! $removalIsBlocked;
                    $usageCount = \App\Models\StoreLanguage::translationUsageCount($languageId);
                @endphp

                <div class="space-y-4" data-test="remove-language-modal">
                    <flux:heading size="lg">
                        {{ __('store-languages.index.remove_modal_heading', ['name' => $removalTarget['name'] ?? '']) }}
                    </flux:heading>

                    <flux:text>{{ __('store-languages.index.remove_modal_body') }}</flux:text>

                    @if ($usageCount > 0)
                        <flux:text data-test="remove-modal-usage-line">{{ trans_choice('store-languages.index.remove_usage', $usageCount) }}</flux:text>
                    @endif

                    @error('languageId')
                        <flux:text class="text-red-600 dark:text-red-400" data-test="remove-language-error">{{ $message }}</flux:text>
                    @enderror

                    @if ($removalIsBlocked)
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('store-languages.index.remove_last_language_notice') }}</flux:text>
                    @elseif ($removalNeedsReplacement)
                        <flux:select
                            wire:model.live="replacementLanguageId"
                            :label="__('store-languages.index.remove_replacement_label')"
                            :description="__('store-languages.index.remove_replacement_description')"
                            data-test="remove-modal-replacement-select"
                        >
                            <flux:select.option value="">{{ __('store-languages.index.remove_replacement_placeholder') }}</flux:select.option>
                            @foreach ($this->replacementCandidates as $candidate)
                                <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <div class="flex justify-end gap-3">
                        <flux:button variant="outline" wire:click="closeRemoveModal" data-test="cancel-remove-language">
                            {{ __('store-languages.index.cancel') }}
                        </flux:button>

                        @if ($removalIsBlocked || ($removalNeedsReplacement && $replacementLanguageId === ''))
                            <flux:button variant="danger" disabled data-test="confirm-remove-language">
                                {{ __('store-languages.index.confirm_remove') }}
                            </flux:button>
                        @else
                            <flux:button variant="danger" wire:click="removeLanguage" data-test="confirm-remove-language">
                                {{ __('store-languages.index.confirm_remove') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endif
        </flux:modal>
    </flux:card>

    {{-- ============ Section B: dashboard defaults ============ --}}
    <flux:card class="space-y-6" data-test="locale-settings-section">
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('localization.settings.heading') }}</flux:heading>
            <flux:subheading>{{ __('localization.settings.description') }}</flux:subheading>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div class="space-y-3">
                <flux:select
                    wire:model="defaultUiLocale"
                    :label="__('localization.settings.default_ui_locale_label')"
                    :disabled="! $this->canEditLocaleSettings"
                    data-test="default-ui-locale-select"
                >
                    @foreach (\App\Enums\UiLocale::cases() as $uiLocale)
                        <flux:select.option value="{{ $uiLocale->value }}">{{ $uiLocale->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->canEditLocaleSettings)
                    <flux:button variant="primary" wire:click="saveDefaultUiLocale" data-test="save-default-ui-locale" class="cursor-pointer!">
                        {{ __('localization.settings.save') }}
                    </flux:button>
                @else
                    <flux:tooltip :content="__('localization.settings.action_not_allowed')" class="cursor-not-allowed!">
                        <flux:button variant="primary" disabled data-test="save-default-ui-locale">
                            {{ __('localization.settings.save') }}
                        </flux:button>
                    </flux:tooltip>
                @endif
            </div>

            <div class="space-y-3">
                <flux:select
                    wire:model="defaultNotificationLocale"
                    :label="__('localization.settings.default_notification_locale_label')"
                    :description="__('localization.settings.default_notification_locale_description')"
                    :disabled="! $this->canEditLocaleSettings"
                    data-test="default-notification-locale-select"
                >
                    @foreach (\App\Enums\UiLocale::cases() as $uiLocale)
                        <flux:select.option value="{{ $uiLocale->value }}">{{ $uiLocale->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->canEditLocaleSettings)
                    <flux:button variant="primary" wire:click="saveDefaultNotificationLocale" data-test="save-default-notification-locale" class="cursor-pointer!">
                        {{ __('localization.settings.save') }}
                    </flux:button>
                @else
                    <flux:tooltip :content="__('localization.settings.action_not_allowed')" class="cursor-not-allowed!">
                        <flux:button variant="primary" disabled data-test="save-default-notification-locale">
                            {{ __('localization.settings.save') }}
                        </flux:button>
                    </flux:tooltip>
                @endif
            </div>
        </div>
    </flux:card>
</div>
