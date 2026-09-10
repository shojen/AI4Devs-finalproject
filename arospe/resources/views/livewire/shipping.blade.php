<div class="w-full">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">{{ __('shipping.carriers.index.heading') }}</flux:heading>

        @if ($this->canCreateRate)
            <flux:button variant="primary" icon="plus" wire:click="openCreateRateModal" data-test="new-rate-button">
                {{ __('shipping.rates.index.new_rate') }}
            </flux:button>
        @else
            {{-- Same tooltip-presence trap as every other disabled row action in this app: an
            explicit flux:tooltip wrapper only on the disabled branch, never a conditionally-bound
            `tooltip` prop -- see docs/errors-log.md. --}}
            <flux:tooltip :content="__('shipping.rates.index.action_not_allowed')" class="cursor-not-allowed!">
                <flux:button variant="primary" icon="plus" disabled data-test="new-rate-button">
                    {{ __('shipping.rates.index.new_rate') }}
                </flux:button>
            </flux:tooltip>
        @endif
    </div>

    {{-- Carrier cards (story 0035/0037). D-4: every carrier renders here, disabled included. --}}
    <div class="grid grid-cols-1 gap-4 mt-6 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($carriers as $carrier)
            <div
                class="p-4 border rounded-lg border-zinc-200 dark:border-zinc-700"
                data-test="carrier-card-{{ $carrier['id'] }}"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <flux:badge size="sm" color="zinc">{{ $carrier['code'] }}</flux:badge>
                        <div class="mt-2 font-medium text-zinc-800 dark:text-white">{{ $carrier['name'] }}</div>
                        @if ($carrier['description'])
                            <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $carrier['description'] }}</div>
                        @endif
                    </div>

                    <flux:badge
                        :color="$carrier['isActive'] ? 'lime' : 'zinc'"
                        data-test="carrier-status-{{ $carrier['id'] }}"
                    >
                        {{ $carrier['isActive']
                            ? __('shipping.carriers.statuses.active')
                            : __('shipping.carriers.statuses.inactive') }}
                    </flux:badge>
                </div>

                <div class="flex items-center justify-between mt-4">
                    <flux:text size="sm">{{ __('shipping.carriers.index.toggle_label') }}</flux:text>

                    {{-- wire:click passes both explicit args via Illuminate\Support\Js::from(),
                    never two @js() calls in one component-tag attribute string -- see
                    docs/errors-log.md's "two @directive() calls silently fail to compile" entry.
                    No wire:model on this control at all, so it is unaffected by that same page's
                    ui-switch/wire:model desync trap. --}}
                    @if ($this->canEditShipping)
                        <flux:switch
                            wire:click="toggleCarrier({{ \Illuminate\Support\Js::from($carrier['id']) }}, {{ \Illuminate\Support\Js::from(! $carrier['isActive']) }})"
                            :checked="$carrier['isActive']"
                            data-test="toggle-carrier-{{ $carrier['id'] }}"
                            aria-label="{{ __('shipping.carriers.index.toggle_aria', ['name' => $carrier['name']]) }}"
                            class="cursor-pointer!"
                        />
                    @else
                        <flux:tooltip :content="__('shipping.carriers.index.action_not_allowed')" class="cursor-not-allowed!">
                            <flux:switch
                                :checked="$carrier['isActive']"
                                data-test="toggle-carrier-{{ $carrier['id'] }}"
                                aria-label="{{ __('shipping.carriers.index.toggle_aria', ['name' => $carrier['name']]) }}"
                                disabled
                            />
                        </flux:tooltip>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Grouped rate table (story 0036/0037). One section per carrier, including carriers with
    zero rates and disabled carriers (D-4) -- $ratesByCarrier is built from
    ListShippingRatesByCarrier's carrier-first query, so an empty group is never silently
    dropped the way a rate-driven ->groupBy() would drop it. --}}
    <div class="mt-8 space-y-8">
        @php $totalRates = collect($ratesByCarrier)->sum(fn (array $group): int => count($group['rates'])); @endphp

        @if ($totalRates === 0)
            <div class="p-8 text-center border rounded-lg border-zinc-200 dark:border-zinc-700" data-test="rates-empty-state">
                <flux:text>{{ __('shipping.rates.index.empty') }}</flux:text>
            </div>
        @else
            @foreach ($ratesByCarrier as $group)
                <div data-test="rate-group-{{ $group['carrierId'] }}">
                    <div class="flex items-center gap-2 mb-2">
                        <flux:heading size="lg">{{ $group['carrierName'] }}</flux:heading>

                        @unless ($group['carrierIsActive'])
                            <flux:badge size="sm" color="zinc">{{ __('shipping.carriers.statuses.inactive') }}</flux:badge>
                        @endunless
                    </div>

                    @if (count($group['rates']) > 0)
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('shipping.rates.index.column_name') }}</flux:table.column>
                                <flux:table.column>{{ __('shipping.rates.index.column_zone') }}</flux:table.column>
                                <flux:table.column>{{ __('shipping.rates.index.column_weight') }}</flux:table.column>
                                <flux:table.column>{{ __('shipping.rates.index.column_price') }}</flux:table.column>
                                <flux:table.column>{{ __('shipping.rates.index.column_delivery') }}</flux:table.column>
                                <flux:table.column>{{ __('shipping.rates.index.column_actions') }}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($group['rates'] as $rate)
                                    <flux:table.row :key="$rate['id']">
                                        <flux:table.cell>
                                            <div class="font-medium text-zinc-800 dark:text-white">{{ $rate['name'] }}</div>
                                        </flux:table.cell>

                                        <flux:table.cell>
                                            {{-- D-8: a single, neutral zinc badge for every zone -- there is no
                                            fixed, agreed-upon per-zone colour mapping, unlike UserStatus. --}}
                                            <flux:badge size="sm" color="zinc" data-test="rate-zone-{{ $rate['id'] }}">
                                                {{ $rate['zoneName'] }}
                                            </flux:badge>
                                        </flux:table.cell>

                                        <flux:table.cell data-test="rate-weight-{{ $rate['id'] }}">
                                            {{-- D-8: never string-concatenate "{min}-{max}" -- an open-ended
                                            bracket must render "N kg and above", never "N-null". Display-only
                                            trimming via Index::trimTrailingZeros() (Phase 5 finding L4 -- the
                                            decimal-point-guarded form, never a bare rtrim(), which silently
                                            mistreats a plain "10" as trailing zeros to strip), then comma-swapped
                                            for locale display -- never done at persistence time, since the
                                            stored value keeps its full precision. --}}
                                            @if ($rate['maxWeightKg'] === null)
                                                {{ __('shipping.rates.index.weight_open_ended', ['min' => str_replace('.', ',', \App\Livewire\Shipping\Index::trimTrailingZeros($rate['minWeightKg']))]) }}
                                            @else
                                                {{ __('shipping.rates.index.weight_range', [
                                                    'min' => str_replace('.', ',', \App\Livewire\Shipping\Index::trimTrailingZeros($rate['minWeightKg'])),
                                                    'max' => str_replace('.', ',', \App\Livewire\Shipping\Index::trimTrailingZeros($rate['maxWeightKg'])),
                                                ]) }}
                                            @endif
                                        </flux:table.cell>

                                        <flux:table.cell class="tabular-nums" data-test="rate-price-{{ $rate['id'] }}">
                                            {{-- D-8: `price` is a STRING (the decimal:2 cast, 0036 R-7 <- 0024
                                            R-4) -- interpolated directly, never (float)-cast and never compared
                                            numerically. A '0.00' rate is a legal free-shipping rate and must
                                            render as a real price, never as a blank via a truthiness check. Only
                                            the decimal separator is swapped for display -- never trimmed, since
                                            a price always shows both decimal places. --}}
                                            {{ __('shipping.rates.index.price_format', ['price' => str_replace('.', ',', $rate['price'])]) }}
                                        </flux:table.cell>

                                        <flux:table.cell>{{ $rate['deliveryEstimate'] }}</flux:table.cell>

                                        <flux:table.cell>
                                            <div class="flex items-center gap-2">
                                                {{-- Row actions are icon-only, so both branches carry the SAME
                                                data-test hook -- a browser test selects the same control
                                                regardless of whether it is enabled (D-8). --}}
                                                @if ($this->canEditShipping)
                                                    <flux:button
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="pencil-square"
                                                        aria-label="{{ __('shipping.rates.index.edit_rate', ['name' => $rate['name']]) }}"
                                                        data-test="edit-rate-{{ $rate['id'] }}"
                                                        wire:click="openEditRateModal(@js($rate['id']))"
                                                        class="cursor-pointer!"
                                                    />
                                                @else
                                                    <flux:tooltip :content="__('shipping.rates.index.action_not_allowed')" class="cursor-not-allowed!">
                                                        <flux:button
                                                            variant="ghost"
                                                            size="sm"
                                                            icon="pencil-square"
                                                            aria-label="{{ __('shipping.rates.index.edit_rate', ['name' => $rate['name']]) }}"
                                                            data-test="edit-rate-{{ $rate['id'] }}"
                                                            disabled
                                                        />
                                                    </flux:tooltip>
                                                @endif

                                                @if ($this->canDeleteRate)
                                                    <flux:button
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="trash"
                                                        aria-label="{{ __('shipping.rates.index.delete_rate', ['name' => $rate['name']]) }}"
                                                        data-test="delete-rate-{{ $rate['id'] }}"
                                                        wire:click="confirmDeleteRate(@js($rate['id']))"
                                                        class="cursor-pointer! text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                                    />
                                                @else
                                                    <flux:tooltip :content="__('shipping.rates.index.action_not_allowed')" class="cursor-not-allowed!">
                                                        <flux:button
                                                            variant="ghost"
                                                            size="sm"
                                                            icon="trash"
                                                            aria-label="{{ __('shipping.rates.index.delete_rate', ['name' => $rate['name']]) }}"
                                                            data-test="delete-rate-{{ $rate['id'] }}"
                                                            disabled
                                                            class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                                        />
                                                    </flux:tooltip>
                                                @endif
                                            </div>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @else
                        <div
                            class="p-6 text-center border rounded-lg border-zinc-200 dark:border-zinc-700"
                            data-test="rate-group-empty-{{ $group['carrierId'] }}"
                        >
                            <flux:text>{{ __('shipping.rates.index.no_rates_yet') }}</flux:text>
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>

    {{-- Create / edit rate modal. Wrapped in @if ($showRateModal) so only one "Cancel" control is
    ever in the DOM, matching users.blade.php/zones.blade.php. --}}
    <flux:modal name="shipping-rate-modal" class="max-w-2xl" @close="closeRateModal" wire:model="showRateModal">
        @if ($showRateModal)
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingRateId === null
                        ? __('shipping.rates.editor.create_title')
                        : __('shipping.rates.editor.edit_title') }}
                </flux:heading>

                {{-- saveRate() validates against an explicit snake_case data array (see the
                method's own docblock for why -- ShippingRateValidationRules' gte:min_weight_kg
                cross-field rule requires it), but rethrowMappedValidationException() re-keys the
                resulting error bag onto these fields' own camelCase wire:model names before it
                ever reaches Livewire, so Flux's ordinary automatic per-field error display (via
                each field's own :label prop) applies here with no explicit <flux:error> needed --
                unlike App\Livewire\Shipping\Zones's genuinely un-mappable nested-picker error. --}}
                <div class="space-y-4">
                    <flux:input
                        wire:model="rateName"
                        :label="__('shipping.rates.editor.name_label')"
                        required
                        autofocus
                        data-test="rate-name-input"
                    />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- D-4: every carrier, disabled included -- OQ-B (adopted): an inline
                        "(Inactive)" suffix on a disabled carrier's own option label. --}}
                        <flux:select
                            wire:model="shippingCarrierId"
                            :label="__('shipping.rates.editor.carrier_label')"
                            :placeholder="__('shipping.rates.editor.carrier_placeholder')"
                            data-test="rate-carrier-select"
                        >
                            @foreach ($this->carrierOptions as $option)
                                <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div>
                            {{-- D-3: a plain bounded dropdown over the existing zone catalog --
                            never 0022's searchable multi-select, which exists for the
                            ~8,100-row geography catalog and is a multi-select, not a
                            single-zone-per-rate picker. --}}
                            <flux:select
                                wire:model="shippingZoneId"
                                :label="__('shipping.rates.editor.zone_label')"
                                :placeholder="__('shipping.rates.editor.zone_placeholder')"
                                data-test="rate-zone-select"
                            >
                                @foreach ($this->zoneOptions as $zone)
                                    <flux:select.option value="{{ $zone['id'] }}">{{ $zone['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            {{-- D-10/OQ-C (adopted): a secondary link to the zone catalog right
                            beside the field that needs it, so a half-filled rate form is never
                            lost just to go create a missing zone. --}}
                            <flux:link href="{{ route('shipping.zones.index') }}" class="text-sm" data-test="manage-zones-link">
                                {{ __('shipping.rates.editor.manage_zones_link') }}
                            </flux:link>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {{-- D-5: type="text" inputmode="decimal", never type="number" -- a native
                        number input performs its own client-side parsing and a Spanish-locale
                        decimal comma never reaches the wire payload. --}}
                        <flux:input
                            type="text"
                            inputmode="decimal"
                            wire:model="minWeightKg"
                            :label="__('shipping.rates.editor.min_weight_label')"
                            data-test="rate-min-weight-input"
                        />

                        <flux:input
                            type="text"
                            inputmode="decimal"
                            wire:model="maxWeightKg"
                            :label="__('shipping.rates.editor.max_weight_label')"
                            :description="__('shipping.rates.editor.max_weight_help')"
                            data-test="rate-max-weight-input"
                        />

                        <flux:input
                            type="text"
                            inputmode="decimal"
                            wire:model="price"
                            :label="__('shipping.rates.editor.price_label')"
                            data-test="rate-price-input"
                        />
                    </div>

                    <flux:input
                        wire:model="deliveryEstimate"
                        :label="__('shipping.rates.editor.delivery_estimate_label')"
                        data-test="rate-delivery-estimate-input"
                    />
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeRateModal">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        wire:click="saveRate"
                        wire:loading.attr="disabled"
                        wire:target="saveRate"
                        data-test="save-rate-button"
                    >
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Delete confirmation modal. --}}
    <flux:modal name="delete-shipping-rate-modal" class="max-w-md md:min-w-md" @close="closeDeleteRateModal" wire:model="showDeleteRateModal">
        @if ($showDeleteRateModal)
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('shipping.rates.index.delete_confirm_title') }}</flux:heading>
                    <flux:text>
                        {{ __('shipping.rates.index.delete_confirm_text', ['name' => $deletingRateName]) }}
                    </flux:text>
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeDeleteRateModal">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button
                        variant="danger"
                        wire:click="deleteRate"
                        wire:loading.attr="disabled"
                        wire:target="deleteRate"
                        data-test="confirm-delete-rate-button"
                    >
                        {{ __('shipping.rates.index.delete_rate', ['name' => $deletingRateName]) }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
