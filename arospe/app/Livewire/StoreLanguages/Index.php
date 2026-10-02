<?php

namespace App\Livewire\StoreLanguages;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Localization\SetDefaultNotificationLocale;
use App\Actions\Localization\SetDefaultUiLocale;
use App\Actions\StoreLanguages\AddStoreLanguage;
use App\Actions\StoreLanguages\RemoveStoreLanguage;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Concerns\ChecksAbilitiesSafely;
use App\Concerns\LocaleSettingValidationRules;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use App\Models\StoreLanguage;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice Store Languages screen (story 0069): one component, two visually distinct sections.
 *
 * Section A manages the content-language catalog (add from the bundled ISO 639-1 list, set the
 * default, remove) through story 0068's actions. Section B holds the two system-wide dashboard
 * defaults, constrained to the two-value App\Enums\UiLocale set -- a different i18n layer, kept
 * apart on purpose. This is NOT the personal language switcher (InteractsWithUiLocale).
 *
 * Every user-reachable refusal of section A is a ValidationException thrown by 0068's actions,
 * keyed `code` / `languageId`; both are declared as real public properties so Livewire's
 * `SupportValidation::dehydrate()` does not drop the error between round trips. Section B has no
 * such action-side validation (the locale actions take a typed UiLocale), so the component
 * validates the raw strings itself before UiLocale::from().
 *
 * `viewAny` is authorized in mount() in addition to the route's `can:store-languages.view`
 * middleware because Livewire's `/livewire/update` endpoint never runs route middleware.
 * Deliberately left unlogged, mirroring SalesRegions\Index::mount().
 */
#[Title('Store Languages')]
class Index extends Component
{
    use ChecksAbilitiesSafely, LocaleSettingValidationRules;

    /**
     * Active languages only. Locked: a client-writable array of rendered rows is a disclosure
     * risk, and no method reads it back for a decision beyond rendering.
     *
     * @var array<int, array{id: string, code: string, name: string, isDefault: bool, canSetDefault: bool, canRemove: bool}>
     */
    #[Locked]
    public array $languages = [];

    public bool $showAddLanguageModal = false;

    public bool $showRemoveModal = false;

    /**
     * Server-only removal target, bound to no input. Also the error-bag key 0068's actions throw
     * against, so it must stay a declared public property.
     */
    #[Locked]
    public string $languageId = '';

    /**
     * Never bound to an input (the picker is a list of act-now buttons); declared so a `code`
     * ValidationException survives dehydrate().
     */
    public string $code = '';

    /**
     * '' matches the replacement select's placeholder option -- never null, which would stringify
     * to "null" on the bound native select.
     */
    public string $replacementLanguageId = '';

    public string $defaultUiLocale = '';

    public string $defaultNotificationLocale = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', StoreLanguage::class);

        $this->loadLanguages();
        $this->defaultUiLocale = LocaleSetting::defaultUiLocale()->value;
        $this->defaultNotificationLocale = LocaleSetting::defaultNotificationLocale()->value;
    }

    /**
     * The bundled list minus the codes already held by an ACTIVE row (a removed language is
     * offered again). A client-side nicety only: AddStoreLanguage's own guard is the enforcement.
     *
     * @return array<string, string> code => endonym
     */
    #[Computed]
    public function availableLanguageOptions(): array
    {
        $activeCodes = StoreLanguage::query()->active()->pluck('code')->all();

        return array_diff_key(StoreLanguage::availableLanguages(), array_flip($activeCodes));
    }

    #[Computed]
    public function canAddLanguage(): bool
    {
        return $this->allowsSafely('create', StoreLanguage::class);
    }

    #[Computed]
    public function canEditLocaleSettings(): bool
    {
        return $this->allowsSafely('update', LocaleSetting::class);
    }

    /**
     * Whether the current viewer may still see the removal modal body for the targeted language;
     * a revoked permission must not leave the usage count readable in an already-open modal.
     */
    #[Computed]
    public function canRemoveSelectedLanguage(): bool
    {
        $target = $this->languageId === '' ? null : StoreLanguage::query()->find($this->languageId);

        return $target !== null && $this->allowsSafely('delete', $target);
    }

    #[Computed]
    public function isOnlyActiveLanguage(): bool
    {
        return count($this->languages) === 1;
    }

    /**
     * The other active languages a removed default can hand its role to.
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function replacementCandidates(): array
    {
        return collect($this->languages)
            ->reject(fn (array $language): bool => $language['id'] === $this->languageId)
            ->map(fn (array $language): array => ['id' => $language['id'], 'name' => $language['name']])
            ->values()
            ->all();
    }

    /**
     * Open the picker. A disclosure path, so it authorizes independently of addLanguage().
     */
    public function openAddLanguageModal(LogRefusedPrivilegedAttempt $log): void
    {
        $log->authorize('create', StoreLanguage::class, targetType: 'store_language', targetId: null);

        $this->resetValidation('code');
        $this->showAddLanguageModal = true;
    }

    public function addLanguage(string $code, AddStoreLanguage $addStoreLanguage, LogRefusedPrivilegedAttempt $log): void
    {
        $log->authorize('create', StoreLanguage::class, targetType: 'store_language', targetId: null);

        $this->resetValidation('code');
        $this->code = $code;

        $addStoreLanguage($code);

        $this->code = '';
        $this->showAddLanguageModal = false;
        $this->loadLanguages();
    }

    public function closeAddLanguageModal(): void
    {
        $this->resetValidation('code');
        $this->code = '';
        $this->showAddLanguageModal = false;
    }

    /**
     * Open the removal confirmation. A disclosure path (it reveals the usage count), so it
     * authorizes independently of removeLanguage(); the target is resolved with findOrFail() so a
     * forged or stale id is a clean 404 rather than a null handed to the policy.
     */
    public function confirmRemoveLanguage(string $languageId, LogRefusedPrivilegedAttempt $log): void
    {
        $target = StoreLanguage::findOrFail($languageId);

        $log->authorize('delete', $target, targetType: 'store_language', targetId: $target->id);

        $this->resetValidation('languageId');
        $this->languageId = $target->id;
        $this->replacementLanguageId = '';
        $this->showRemoveModal = true;
    }

    /**
     * Removal is a two-call backend contract presented as one click: when the target is the
     * current default, the chosen replacement is promoted first, then the old default removed.
     */
    public function removeLanguage(SetDefaultStoreLanguage $setDefaultStoreLanguage, RemoveStoreLanguage $removeStoreLanguage, LogRefusedPrivilegedAttempt $log): void
    {
        $target = StoreLanguage::findOrFail($this->languageId);

        $log->authorize('delete', $target, targetType: 'store_language', targetId: $target->id);

        $this->resetValidation('languageId');

        if ($target->is_default && $this->replacementLanguageId !== '') {
            $replacement = StoreLanguage::findOrFail($this->replacementLanguageId);

            if ($replacement->is($target)) {
                throw ValidationException::withMessages([
                    'languageId' => __('store-languages.errors.cannot_remove_default'),
                ]);
            }

            $log->authorize('update', $replacement, targetType: 'store_language', targetId: $replacement->id);

            $setDefaultStoreLanguage($replacement);
        }

        $removeStoreLanguage($target);

        $this->closeRemoveModal();
        $this->loadLanguages();
    }

    public function closeRemoveModal(): void
    {
        $this->resetValidation('languageId');
        $this->showRemoveModal = false;
        $this->languageId = '';
        $this->replacementLanguageId = '';
    }

    public function setDefaultLanguage(string $languageId, SetDefaultStoreLanguage $setDefaultStoreLanguage, LogRefusedPrivilegedAttempt $log): void
    {
        $target = StoreLanguage::findOrFail($languageId);

        $log->authorize('update', $target, targetType: 'store_language', targetId: $target->id);

        $this->resetValidation('languageId');
        $this->languageId = $target->id;

        $setDefaultStoreLanguage($target);

        $this->languageId = '';
        $this->loadLanguages();
    }

    public function saveDefaultUiLocale(SetDefaultUiLocale $setDefaultUiLocale, LogRefusedPrivilegedAttempt $log): void
    {
        $log->authorize('update', LocaleSetting::class, targetType: 'locale_setting', targetId: LocaleSetting::SINGLETON_ID);

        $this->validate(
            ['defaultUiLocale' => $this->defaultUiLocaleRules()],
            attributes: ['defaultUiLocale' => __('localization.attributes.defaultUiLocale')],
        );

        $setDefaultUiLocale(UiLocale::from($this->defaultUiLocale));

        Flux::toast(variant: 'success', text: __('localization.settings.saved'));
    }

    public function saveDefaultNotificationLocale(SetDefaultNotificationLocale $setDefaultNotificationLocale, LogRefusedPrivilegedAttempt $log): void
    {
        $log->authorize('update', LocaleSetting::class, targetType: 'locale_setting', targetId: LocaleSetting::SINGLETON_ID);

        $this->validate(
            ['defaultNotificationLocale' => $this->defaultNotificationLocaleRules()],
            attributes: ['defaultNotificationLocale' => __('localization.attributes.defaultNotificationLocale')],
        );

        $setDefaultNotificationLocale(UiLocale::from($this->defaultNotificationLocale));

        Flux::toast(variant: 'success', text: __('localization.settings.saved'));
    }

    /**
     * Rebuild the rendered rows from the database. Per-row hints use Gate::allows() -- never
     * authorize(), which would throw while rendering a list -- and mirror the policy without
     * replacing the authorization each method performs itself.
     */
    private function loadLanguages(): void
    {
        $this->languages = StoreLanguage::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreLanguage $language): array => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->name,
                'isDefault' => $language->is_default,
                'canSetDefault' => $this->allowsSafely('update', $language),
                'canRemove' => $this->allowsSafely('delete', $language),
            ])
            ->all();
    }
}
