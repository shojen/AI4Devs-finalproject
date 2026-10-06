<?php

namespace App\Concerns;

use App\Actions\Users\SetUserUiLocale;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 0067 (D-17): the single implementation of the interface-language surfaces' shared
 * behaviour -- resolving the current value and persisting a choice -- consumed by both
 * App\Livewire\Settings\LanguageSwitcher (chrome) and App\Livewire\Settings\Language (settings tab).
 *
 * @mixin Component
 */
trait InteractsWithUiLocale
{
    /**
     * The locale in effect for the signed-in user, resolved exactly as the SetUiLocale middleware does.
     */
    #[Computed]
    public function currentLocale(): string
    {
        return UiLocale::tryFrom((string) Auth::user()?->ui_locale)->value
            ?? LocaleSetting::defaultUiLocale()->value;
    }

    /**
     * Persist the (already validated) choice, then redirect so the next render runs under it.
     */
    protected function applyUiLocale(string $locale, SetUserUiLocale $setUserUiLocale, string $redirectTo): void
    {
        $setUserUiLocale(UiLocale::from($locale));

        $this->redirect($redirectTo);
    }
}
