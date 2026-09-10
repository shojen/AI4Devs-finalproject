{{--
    View for App\Livewire\PaymentMethods\Index (story 0038's component; this
    story, 0039, replaces the placeholder markup). Flat path, not
    payment-methods/index.blade.php -- the Index-in-a-subfolder exception,
    see docs/conventions/naming.md.

    Bank transfer is the only payment method this phase (PRD §2.5), so this
    screen renders no create/delete affordance of any kind -- there is no
    action here to hide behind a permission check, because neither exists:
    PaymentMethodPolicy::create()/delete() both return false unconditionally.
--}}
<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">{{ __('payment-methods.index.heading') }}</flux:heading>
    </div>

    <div class="grid grid-cols-1 gap-4 mt-6 sm:grid-cols-2">
        @foreach ($paymentMethods as $method)
            @php
                $isConfigured = $method['iban'] !== null;
                $actionLabel = $isConfigured
                    ? __('payment-methods.index.edit_action')
                    : __('payment-methods.index.configure_action');
            @endphp

            <flux:card data-test="payment-method-card-{{ $method['id'] }}" class="space-y-4">
                <div class="flex items-start justify-between gap-2">
                    <flux:heading size="lg">{{ $method['code']->label() }}</flux:heading>

                    <flux:badge :color="$isConfigured ? 'lime' : 'zinc'">
                        {{ $isConfigured
                            ? __('payment-methods.index.configured_label')
                            : __('payment-methods.index.not_configured_label') }}
                    </flux:badge>
                </div>

                {{-- D4 (decision 3): shown in full, ungrouped -- no masking. D4 (decision 4):
                grouped into 4-character blocks at RENDER time only, via implode(str_split(...)),
                never by mutating $method['iban'] itself, which is the persisted row's own
                value read straight off $paymentMethods -- never $this->iban, whose value after a
                rejected save holds the rejected attempt rather than what is actually stored. --}}
                @if ($isConfigured)
                    <div
                        data-test="payment-method-configured-iban"
                        class="font-mono text-sm break-all text-zinc-700 dark:text-zinc-300"
                    >
                        {{ implode(' ', str_split($method['iban'], 4)) }}
                    </div>
                @else
                    <div
                        data-test="payment-method-not-configured"
                        class="text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        {{ __('payment-methods.iban.not_configured') }}
                    </div>
                @endif

                <div>
                    {{-- Row action is a labelled text button (its visible label is its
                    accessible name, no aria-label needed -- unlike Users' icon-only row
                    actions). Both branches carry the SAME data-test hook, matching every other
                    disabled-row-action convention in this app -- see docs/errors-log.md's
                    tooltip-presence and cursor-not-allowed! placement traps, both avoided here
                    verbatim: an explicit flux:tooltip wrapper only on the disabled branch, and
                    cursor-not-allowed! on that wrapper rather than on the disabled button
                    (Flux's own disabled:pointer-events-none takes the button out of
                    hit-testing). --}}
                    @if ($method['canEdit'])
                        <flux:button
                            variant="primary"
                            size="sm"
                            wire:click="openEditModal(@js($method['id']))"
                            data-test="configure-bank-transfer"
                            class="cursor-pointer!"
                        >
                            {{ $actionLabel }}
                        </flux:button>
                    @else
                        <flux:tooltip :content="__('payment-methods.index.action_not_allowed')" class="cursor-not-allowed!">
                            <flux:button variant="primary" size="sm" disabled data-test="configure-bank-transfer">
                                {{ $actionLabel }}
                            </flux:button>
                        </flux:tooltip>
                    @endif
                </div>
            </flux:card>
        @endforeach
    </div>

    {{-- Edit modal. Wrapped in @if ($showModal) so only one "Cancel" control is ever in the
    DOM, matching users.blade.php/zones.blade.php/shipping.blade.php.

    data-test="payment-method-modal" lives on the INNER div, not the outer <flux:modal> --
    Flux renders <flux:modal>'s own root unconditionally (open or closed), so a hook on
    that tag would always be present in the DOM and make "the modal remains open" assertions
    (the highest-risk leg of the checksum-rejection scenario) vacuously true regardless of
    $showModal's real value. --}}
    <flux:modal
        name="payment-method-modal"
        class="max-w-md"
        @close="closeModal"
        wire:model="showModal"
    >
        @if ($showModal)
            @php
                $editingMethod = collect($paymentMethods)->firstWhere('id', $editingMethodId);
            @endphp

            {{-- $editingMethod can be null: $showModal is a plain wire:model-bound property, so an
            actor holding payment-methods.view can force this branch open (e.g. via the Livewire
            devtools or a crafted request) while #[Locked] $editingMethodId is still at its default
            null, with no matching row in $paymentMethods. Guard rather than dereference -- the
            fallback renders no method name at all instead of a 500. --}}
            <div class="space-y-6" data-test="payment-method-modal">
                <flux:heading size="lg">
                    {{ $editingMethod !== null
                        ? __('payment-methods.editor.title', ['method' => $editingMethod['code']->label()])
                        : __('payment-methods.index.heading') }}
                </flux:heading>

                {{-- wire:model binds to the RAW property -- the 4-character display grouping
                above must never leak into this input. save()'s validation error lands in
                Livewire's standard $errors bag keyed `iban`, which this field's own :label prop
                picks up automatically with no explicit <flux:error> needed. --}}
                <flux:input
                    wire:model="iban"
                    :label="__('payment-methods.editor.iban_label')"
                    :description="__('payment-methods.editor.iban_description')"
                    autofocus
                    data-test="payment-method-iban-input"
                />

                <div class="flex justify-end gap-3">
                    <flux:button variant="outline" wire:click="closeModal">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        data-test="save-payment-method-button"
                    >
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
