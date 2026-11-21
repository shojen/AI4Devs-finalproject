{{--
    View for App\Livewire\InvoiceSettings\Index (story 0103). Flat path: the Index-in-a-subfolder
    exception, see docs/conventions/naming.md.

    One form, one Save (FD-2). Read-only viewers get the same markup inside a disabled fieldset and no
    write controls (FD-9). The Media Gallery sits outside the form so its buttons never submit it.
    Only server-derived values reach `style` and `img` (FD-7): safe*Color and the logo computed.
--}}
@php
    $canManage = $this->canManage;
    $numberingErrorKeys = ['standardPrefix', 'standardYearFormat', 'standardSeparator', 'standardPadding', 'correctivePrefix', 'correctiveYearFormat', 'correctiveSeparator', 'correctivePadding', 'isNumberingResetYearly'];
    $numberingErrors = collect($numberingErrorKeys)->flatMap(fn (string $key): array => $errors->get($key))->unique()->values();
    $series = ['standard', 'corrective'];
@endphp

<div
    class="space-y-6"
    x-data
    x-on:focus-first-error.window="$nextTick(() => document.querySelector('[data-flux-error]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
>
    <x-slot:heading>{{ __('invoice-settings.screen.title') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.invoice_settings.subtitle') }}</x-slot:subheading>

    <form wire:submit="save" class="mt-6 space-y-6" data-test="invoice-settings-form">
        @unless ($canManage)
            <flux:callout variant="secondary" icon="information-circle" data-test="invoice-settings-readonly-notice" :heading="__('invoice-settings.screen.readonly_notice')" />
        @endunless

        <fieldset @disabled(! $canManage) class="min-w-0 space-y-6">
            {{-- ============ Issuer fiscal data ============ --}}
            <flux:card class="space-y-6" data-test="invoice-settings-issuer">
                <flux:heading size="lg">{{ __('invoice-settings.screen.sections.issuer') }}</flux:heading>

                {{-- A read-only viewer has no wire:model round trip to fill the inputs, so the stored value is rendered server-side. --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input wire:model.blur="issuerLegalName" :value="$canManage ? null : $issuerLegalName" :label="__('invoice-settings.screen.fields.legal_name')" maxlength="255" />
                    <flux:input wire:model.blur="issuerTaxId" :value="$canManage ? null : $issuerTaxId" :label="__('invoice-settings.screen.fields.tax_id')" :description="__('invoice-settings.screen.hints.tax_id')" maxlength="40" />
                    <flux:input wire:model.blur="issuerAddressLine1" :value="$canManage ? null : $issuerAddressLine1" :label="__('invoice-settings.screen.fields.address_line1')" maxlength="255" />
                    <flux:input wire:model.blur="issuerAddressLine2" :value="$canManage ? null : $issuerAddressLine2" :label="__('invoice-settings.screen.fields.address_line2')" maxlength="255" />
                    <flux:input wire:model.blur="issuerCity" :value="$canManage ? null : $issuerCity" :label="__('invoice-settings.screen.fields.city')" maxlength="100" />
                    <flux:input wire:model.blur="issuerPostalCode" :value="$canManage ? null : $issuerPostalCode" :label="__('invoice-settings.screen.fields.postal_code')" maxlength="20" />
                    <flux:input wire:model.blur="issuerProvince" :value="$canManage ? null : $issuerProvince" :label="__('invoice-settings.screen.fields.province')" maxlength="100" />
                    <flux:input wire:model.blur="issuerCountry" :value="$canManage ? null : $issuerCountry" :label="__('invoice-settings.screen.fields.country')" :description="__('invoice-settings.screen.hints.country')" maxlength="2" />
                </div>
            </flux:card>

            {{-- ============ Logo, colours and template ============ --}}
            <flux:card class="space-y-6" data-test="invoice-settings-look">
                <flux:heading size="lg">{{ __('invoice-settings.screen.sections.look') }}</flux:heading>

                <div class="space-y-2">
                    <flux:label>{{ __('invoice-settings.screen.fields.logo') }}</flux:label>

                    <div class="flex flex-wrap items-center gap-4">
                        @if ($this->logo)
                            <img
                                src="{{ $this->logo['url'] }}"
                                alt="{{ $this->logo['title'] }}"
                                class="h-16 w-auto max-w-40 rounded border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-700"
                                data-test="logo-preview"
                            >
                        @else
                            <flux:text>{{ __('invoice-settings.screen.no_logo') }}</flux:text>
                        @endif

                        @if ($this->canChooseLogo)
                            <flux:button type="button" wire:click="$set('showLogoGallery', true)" data-test="choose-logo">
                                {{ __('invoice-settings.screen.choose_logo') }}
                            </flux:button>

                            @if ($this->logo)
                                <flux:button type="button" variant="ghost" wire:click="removeLogo" data-test="remove-logo">
                                    {{ __('invoice-settings.screen.remove_logo') }}
                                </flux:button>
                            @endif
                        @endif
                    </div>

                    <flux:error name="logoMediaId" />
                    <flux:text size="sm">{{ __('invoice-settings.screen.hints.logo') }}</flux:text>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach (['primary' => 'primaryColor', 'accent' => 'accentColor'] as $kind => $property)
                        <div class="space-y-2">
                            <div class="flex items-end gap-2">
                                <div class="flex-1">
                                    <flux:input
                                        wire:model.live.blur="{{ $property }}"
                                        :label="__('invoice-settings.screen.fields.'.$kind.'_color')"
                                        :description="__('invoice-settings.screen.hints.color')"
                                        placeholder="#1A2B3C"
                                        class="font-mono"
                                        maxlength="7"
                                        data-test="{{ $kind }}-color-input"
                                    />
                                </div>

                                <input
                                    type="color"
                                    x-data
                                    x-on:input="$wire.set('{{ $property }}', $event.target.value.toUpperCase())"
                                    value="{{ $kind === 'primary' ? ($this->safePrimaryColor ?? '#000000') : ($this->safeAccentColor ?? '#000000') }}"
                                    class="mb-0.5 h-9 w-10 shrink-0 cursor-pointer rounded border border-zinc-200 bg-white p-0.5 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700"
                                    aria-label="{{ __('invoice-settings.screen.fields.'.$kind.'_color') }}"
                                    data-test="{{ $kind }}-color-swatch"
                                >
                            </div>

                            @if ($canManage)
                                <flux:button type="button" size="sm" variant="ghost" wire:click="$set('{{ $property }}', '')" data-test="{{ $kind }}-color-default">
                                    {{ __('invoice-settings.screen.use_template_default') }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>

                <flux:radio.group wire:model.live="template" variant="cards" :label="__('invoice-settings.screen.fields.template')" class="grid! grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ($this->templateOptions as $option)
                        <div class="space-y-2" wire:key="template-{{ $option['value'] }}">
                            <flux:radio value="{{ $option['value'] }}" :disabled="! $canManage" data-test="template-option-{{ $option['value'] }}" class="flex-col">
                                <div class="space-y-3">
                                    <div>
                                        <flux:heading>{{ $option['name'] }}</flux:heading>
                                        <flux:subheading size="sm">{{ $option['description'] }}</flux:subheading>
                                    </div>

                                    <x-invoice-template-thumbnail
                                        :template="$option['value']"
                                        :primary="$this->safePrimaryColor"
                                        :accent="$this->safeAccentColor"
                                        :logo="$this->logo"
                                        class="mx-auto max-w-40"
                                        data-test="template-thumbnail-{{ $option['value'] }}"
                                    />
                                </div>
                            </flux:radio>

                            <flux:link
                                href="{{ route('invoice-settings.preview', ['template' => $option['value']]) }}"
                                target="_blank"
                                rel="noopener"
                                class="text-sm"
                                data-test="template-preview-{{ $option['value'] }}"
                            >{{ __('invoice-settings.screen.preview_pdf') }}</flux:link>
                        </div>
                    @endforeach
                </flux:radio.group>
            </flux:card>

            {{-- ============ Numbering ============ --}}
            <flux:card class="space-y-6" data-test="invoice-settings-numbering">
                <flux:heading size="lg">{{ __('invoice-settings.screen.sections.numbering') }}</flux:heading>

                @if ($numberingErrors->isNotEmpty())
                    <flux:callout variant="danger" icon="x-circle" data-test="numbering-refused" :heading="__('invoice-settings.screen.numbering_refused')">
                        <flux:callout.text>
                            <ul class="list-disc ps-4">
                                @foreach ($numberingErrors as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                @foreach ($series as $key)
                    <div class="space-y-4" data-test="numbering-series-{{ $key }}">
                        <flux:heading>{{ __('invoice-settings.screen.series.'.$key) }}</flux:heading>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:input wire:model.live.debounce.400ms="{{ $key }}Prefix" :label="__('invoice-settings.screen.fields.prefix')" maxlength="10" />

                            <flux:select wire:model.live="{{ $key }}YearFormat" :label="__('invoice-settings.screen.fields.year')">
                                @foreach ($this->yearOptions as $option)
                                    <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model.live.debounce.400ms="{{ $key }}Separator" :label="__('invoice-settings.screen.fields.separator')" maxlength="3" />

                            <flux:select wire:model.live="{{ $key }}Padding" :label="__('invoice-settings.screen.fields.digits')">
                                @foreach (range(1, 10) as $digits)
                                    <flux:select.option value="{{ $digits }}">{{ $digits }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div class="space-y-1">
                            <flux:text>
                                {{ __('invoice-settings.screen.next_number') }}:
                                <code class="font-mono" data-test="next-number-{{ $key }}">{{ $this->nextNumbers[$key] ?? __('invoice-settings.screen.next_number_invalid') }}</code>
                            </flux:text>

                            @if ($this->sequenceState[$key]['hasIssued'])
                                <flux:text size="sm" data-test="start-locked-{{ $key }}">{{ __('invoice-settings.screen.start.locked') }}</flux:text>
                            @elseif ($canManage)
                                <flux:button type="button" size="sm" variant="ghost" wire:click="openStartModal('{{ $key }}')" data-test="set-start-{{ $key }}">
                                    {{ __('invoice-settings.screen.start.button') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach

                <flux:checkbox name="isNumberingResetYearly" wire:model.live="isNumberingResetYearly" :label="__('invoice-settings.screen.reset_yearly')" :disabled="! $canManage" />
            </flux:card>

            {{-- ============ Automation ============ --}}
            <flux:card class="space-y-6" data-test="invoice-settings-automation">
                <flux:heading size="lg">{{ __('invoice-settings.screen.sections.automation') }}</flux:heading>

                <flux:checkbox
                    name="isAutoIssueEnabled"
                    wire:model="isAutoIssueEnabled"
                    :label="__('invoice-settings.screen.auto_issue')"
                    :description="__('invoice-settings.screen.auto_issue_hint')"
                    :disabled="! $canManage"
                />
            </flux:card>
        </fieldset>

        @if ($canManage)
            <div class="sticky bottom-0 z-10 -mx-4 flex justify-end border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 dark:border-zinc-700 dark:bg-zinc-900/95 sm:dark:bg-transparent">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save" data-test="invoice-settings-save">
                    {{ __('invoice-settings.screen.save') }}
                </flux:button>
            </div>
        @endif
    </form>

    @if ($this->canChooseLogo)
        <livewire:media.gallery wire:model="showLogoGallery" wire:key="invoice-logo-gallery" :multi="false" select-event="invoice-logo-selected" />
    @endif

    @if ($canManage)
        <flux:modal name="start-number-modal" class="max-w-md md:min-w-md" wire:model="showStartModal">
            @if ($showStartModal)
            <form wire:submit="setStart" class="space-y-4" data-test="start-number-modal">
                <flux:heading size="lg">{{ __('invoice-settings.screen.start.heading') }}</flux:heading>
                <flux:text>{{ __('invoice-settings.screen.start.warning') }}</flux:text>

                <flux:input
                    type="text"
                    inputmode="numeric"
                    wire:model="startAt"
                    :label="__('invoice-settings.screen.start.input')"
                    data-test="start-number-input"
                />

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" data-test="start-number-confirm">
                        {{ __('invoice-settings.screen.start.confirm') }}
                    </flux:button>
                </div>
            </form>
            @endif
        </flux:modal>
    @endif
</div>
