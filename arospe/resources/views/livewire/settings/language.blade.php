<section class="w-full">
    <x-slot:heading>{{ __('topbar.settings.language') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.settings.language_subtitle') }}</x-slot:subheading>
    <x-settings.layout>
        <div data-test="settings-language-switcher">
            <flux:radio.group variant="segmented">
                @foreach (\App\Enums\UiLocale::cases() as $uiLocale)
                    <flux:radio
                        wire:key="settings-language-option-{{ $uiLocale->value }}"
                        :value="$uiLocale->value"
                        :checked="$this->currentLocale === $uiLocale->value"
                        :label="$uiLocale->label()"
                        wire:click="setLocale('{{ $uiLocale->value }}')"
                        data-test="settings-language-option-{{ $uiLocale->value }}"
                    />
                @endforeach
            </flux:radio.group>
        </div>
    </x-settings.layout>
</section>
