@props(['title' => null, 'heading' => null, 'subheading' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900" data-test="sidebar">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                {{-- Story 0013: entries and group headings are driven by the declarative
                registry in config/modules.php, gated per-entry through the Gate -- see
                resources/views/components/sidebar-nav.blade.php. --}}
                <x-sidebar-nav />
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        {{-- Story 0057a: ONE persistent topbar at every breakpoint (replacing the mobile-only
        header): page title + subtitle on the left, the notification bell once at the right.
        Title/subtitle are declared per screen as named slots (`heading` / `subheading`) and
        forwarded by layouts/app.blade.php; a screen without a heading falls back to `<title>`'s
        value, and without a subheading renders no subtitle element at all. --}}
        <flux:header sticky class="min-h-[65px] gap-4 border-b border-zinc-200 bg-white/85 backdrop-blur dark:border-zinc-700 dark:bg-zinc-800/85" data-test="topbar">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" data-test="sidebar-toggle" />

            <div class="min-w-0">
                <h1 class="truncate text-[19px] font-semibold leading-tight text-zinc-900 dark:text-white" data-test="topbar-title">
                    @if ($heading?->isNotEmpty())
                        {{ $heading }}
                    @else
                        {{ $title }}
                    @endif
                </h1>
                @if ($subheading?->isNotEmpty())
                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400" data-test="topbar-subtitle">
                        {{ $subheading }}
                    </div>
                @endif
            </div>

            <flux:spacer />

            <livewire:notifications.bell />

            {{-- The desktop user menu lives at the bottom of the sidebar; this one is mobile only. --}}
            <div class="lg:hidden" data-test="topbar-mobile-profile">
                <flux:dropdown position="top" align="end">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />

                    <flux:menu>
                        <flux:menu.radio.group>
                            <div class="p-0 text-sm font-normal">
                                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                    <flux:avatar
                                        :name="auth()->user()->name"
                                        :initials="auth()->user()->initials()"
                                    />

                                    <div class="grid flex-1 text-start text-sm leading-tight">
                                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                    </div>
                                </div>
                            </div>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <flux:menu.radio.group>
                            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                {{ __('Settings') }}
                            </flux:menu.item>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
