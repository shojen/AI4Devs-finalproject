<?php

namespace App\Livewire\Settings;

use App\Actions\Users\SetUserUiLocale;
use App\Concerns\InteractsWithUiLocale;
use App\Concerns\UserValidationRules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 0067: the Settings > Language tab. Same trait and action as the chrome switcher; differs
 * only in that it redirects back to itself (D-18).
 */
#[Title('Language settings')]
class Language extends Component
{
    use InteractsWithUiLocale;
    use UserValidationRules;

    public function setLocale(string $locale, SetUserUiLocale $setUserUiLocale): void
    {
        Validator::make(
            ['ui_locale' => $locale],
            ['ui_locale' => $this->uiLocaleRules()],
        )->validate();

        $this->applyUiLocale($locale, $setUserUiLocale, route('language.edit'));
    }

    public function render(): View
    {
        return view('livewire.settings.language');
    }
}
