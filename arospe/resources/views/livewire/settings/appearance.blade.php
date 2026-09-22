<section class="w-full">
    <x-slot:heading>{{ __('topbar.settings.appearance') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.settings.appearance_subtitle') }}</x-slot:subheading>
    <x-settings.layout>
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</section>
