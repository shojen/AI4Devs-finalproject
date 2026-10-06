<?php

namespace App\Livewire\Settings;

use App\Actions\Users\SetUserUiLocale;
use App\Concerns\InteractsWithUiLocale;
use App\Concerns\UserValidationRules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/**
 * Story 0067: the interface-language switcher rendered inside both account menus (desktop sidebar
 * and mobile topbar). Routeless; stays on the page it was used from.
 */
class LanguageSwitcher extends Component
{
    use InteractsWithUiLocale;
    use UserValidationRules;

    /**
     * Validates BEFORE UiLocale::from() runs, so a forged value never reaches the enum (D-19).
     */
    public function setLocale(string $locale, SetUserUiLocale $setUserUiLocale): void
    {
        Validator::make(
            ['ui_locale' => $locale],
            ['ui_locale' => $this->uiLocaleRules()],
        )->validate();

        $this->applyUiLocale($locale, $setUserUiLocale, $this->sameHostPreviousUrl());
    }

    /**
     * The page the switcher was used from, or the dashboard when the Referer points off this host
     * (the Referer is client-controlled, so it is never trusted as a redirect target).
     */
    private function sameHostPreviousUrl(): string
    {
        $previous = url()->previous(route('dashboard'));

        return parse_url($previous, PHP_URL_HOST) === request()->getHost()
            ? $previous
            : route('dashboard');
    }

    public function render(): View
    {
        return view('livewire.settings.language-switcher');
    }
}
