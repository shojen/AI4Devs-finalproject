<div data-test="language-switcher">
    <flux:menu.group :heading="__('localization.switcher.heading')">
        @foreach (\App\Enums\UiLocale::cases() as $uiLocale)
            <flux:menu.item
                wire:key="language-option-{{ $uiLocale->value }}"
                wire:click="setLocale('{{ $uiLocale->value }}')"
                :icon="$this->currentLocale === $uiLocale->value ? 'check' : null"
                :aria-current="$this->currentLocale === $uiLocale->value ? 'true' : null"
                data-test="language-option-{{ $uiLocale->value }}"
            >
                {{ $uiLocale->label() }}
            </flux:menu.item>
        @endforeach
    </flux:menu.group>
</div>
