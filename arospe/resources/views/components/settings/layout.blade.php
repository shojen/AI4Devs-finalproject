<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}" data-test="settings-navlist">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('topbar.settings.profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('topbar.settings.security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('topbar.settings.appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('language.edit')" wire:navigate>{{ __('topbar.settings.language') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        @if (filled($heading ?? null))
            <flux:heading>{{ $heading }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>
        @endif

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
