{{--
    Story 0055 D-2 -- a small reusable ANONYMOUS confirmation dialog (this repo has no
    app/View/Components/, and a class-based component would be the first). It guards a
    BUSINESS-INTENT decision ("did you mean this?"), unlike the Users/Roles delete modals, which are
    each welded to one irreversible action and their own locked target id.

    It holds no state and makes no decision: `show` is the parent's bool, `model` is the NAME of that
    parent property (bound with wire:model so the modal's own open/close stays in sync), and
    `confirm-action` / `dismiss-action` are method names the parent owns. A dialog that decided
    WHETHER to appear would be a second implementation of a rule an action already owns.

    Two constraints that bind here:
      1. `@close` runs the parent's dismiss METHOD, so Esc / backdrop / the X reach the server the
         same way the Cancel button does -- with only the bool, a client-side close would leave the
         parent flag true and the status select showing a value the server never accepted.
      2. The inner content is wrapped in @if ($show) so only one dismiss control is ever in the
         DOM, and the data-test hooks live on that INNER content (never on <flux:modal>) -- the
         two Flux/Blaze markup rules from users.blade.php / roles.blade.php.

    Story 0093 -- optional second choice: `secondary-action` + `secondary-label` (both required for it
    to render, labels always supplied by the parent). `@close` still runs the DISMISS method, never the
    secondary one. Three-button dialogs stack below `sm` (confirm first, dismiss last); two-button
    dialogs keep their original markup.

    Hooks: confirm-dialog-{test-prefix}, confirm-dialog-{test-prefix}-dismiss,
    confirm-dialog-{test-prefix}-confirm, confirm-dialog-{test-prefix}-secondary (only when rendered).
--}}
@props([
    'show',
    'model',
    'heading',
    'body',
    'confirmLabel',
    'dismissLabel',
    'confirmAction',
    'dismissAction',
    'testPrefix',
    'variant' => 'primary',
    'secondaryAction' => null,
    'secondaryLabel' => null,
])

@php
    $hasSecondary = $secondaryAction !== null && $secondaryLabel !== null;
    $buttonClass = $hasSecondary ? 'w-full min-h-11 sm:w-auto' : null;
    $confirmTarget = $hasSecondary ? $confirmAction.','.$secondaryAction : $confirmAction;
@endphp

<flux:modal :name="$testPrefix.'-modal'" class="max-w-md md:min-w-md" wire:model="{{ $model }}" @close="{{ $dismissAction }}">
    @if ($show)
        <div class="space-y-6" data-test="confirm-dialog-{{ $testPrefix }}">
            <div class="space-y-2">
                <flux:heading size="lg">{{ $heading }}</flux:heading>
                <flux:text>{{ $body }}</flux:text>
                {{ $slot }}
            </div>

            <div @class(['flex gap-3 justify-end' => ! $hasSecondary, 'flex flex-col-reverse gap-3 sm:flex-row sm:justify-end' => $hasSecondary])>
                <flux:button :class="$buttonClass" variant="outline" wire:click="{{ $dismissAction }}" data-test="confirm-dialog-{{ $testPrefix }}-dismiss">
                    {{ $dismissLabel }}
                </flux:button>

                @if ($hasSecondary)
                    <flux:button
                        :class="$buttonClass"
                        variant="outline"
                        wire:click="{{ $secondaryAction }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $confirmTarget }}"
                        data-test="confirm-dialog-{{ $testPrefix }}-secondary"
                    >
                        {{ $secondaryLabel }}
                    </flux:button>
                @endif

                <flux:button
                    :class="$buttonClass"
                    :variant="$variant"
                    wire:click="{{ $confirmAction }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $confirmTarget }}"
                    data-test="confirm-dialog-{{ $testPrefix }}-confirm"
                >
                    {{ $confirmLabel }}
                </flux:button>
            </div>
        </div>
    @endif
</flux:modal>
