<?php

namespace App\Livewire\ProductCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\ProductCategories\CreateProductCategory;
use App\Actions\ProductCategories\DeleteProductCategory;
use App\Actions\ProductCategories\RenameProductCategory;
use App\Actions\ProductCategories\SetProductCategoryTranslation;
use App\Actions\Translations\CompareTranslatedNames;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Product categories management screen: list, create/edit modal, blocked
 * delete (story 0025), with the name authored per active store language
 * through language tabs (story 0071). This is the first and only call site of
 * ProductCategoryPolicy and the App\Actions\ProductCategories\* actions -- it
 * owns the whole client surface (component, route, view, sidebar entry,
 * copy), consuming 0023's model/actions/policy and 0024b's delete guard
 * exactly as App\Livewire\Users\Index consumes app/Actions/Users/*.
 *
 * Access is gated on `products.view` (route middleware, `mount()`), with
 * per-action checks for `products.create` / `products.edit` /
 * `products.delete` re-checked inside every mutating AND disclosing
 * method, since Livewire 4's `PersistentMiddleware` allowlist does not
 * carry Spatie's `permission:` middleware -- see
 * docs/architecture/authorization.md. Every gate is defence in depth on
 * top of the identical gate each of the actions performs as its own first
 * statement.
 *
 * Writing a non-default language's name goes ONLY through
 * SetProductCategoryTranslation, which authorizes and validates on its own (D-4);
 * the unguarded SetTranslation primitive must never be imported here.
 *
 * @property-read Collection<int, StoreLanguage> $languages
 */
#[Title('Product categories')]
class Index extends Component
{
    use ProductCategoryValidationRules;

    /**
     * @var array<int, array{id: string, name: ?string, productCount: int, canEdit: bool, canDelete: bool}>
     *
     * Deliberately unlocked, unlike every id-carrying property below (D-4):
     * every method that mutates re-reads its target with findOrFail() and
     * re-authorizes against that fresh row, so nothing here is ever read
     * for a decision -- only for display. See
     * docs/security/blade-livewire-output-encoding.md, which records the
     * identical rationale for App\Livewire\Users\Index::$users.
     */
    public array $productCategories = [];

    /**
     * Written only from $target->id, never the raw method argument
     * (R-3) -- this is what makes the id fed to
     * ProductCategoryValidationRules::uniqueNormalisedName()'s ->ignore()
     * server-authoritative rather than client-controlled. See
     * docs/security/livewire-authorization.md.
     */
    #[Locked]
    public ?string $editingCategoryId = null;

    public bool $showModal = false;

    /**
     * One name per active store language, keyed by store_language_id (story 0071). '' means
     * "not typed" -- never null, so each bound text input holds the type the DOM expects.
     * Deliberately unlocked (D-3): nothing reads it for a decision -- save() iterates the active
     * languages queried from the database, never the keys of this array, so a forged key is
     * ignored. Typed `mixed` for the analyser only: a client can forge a non-string value, which
     * save() normalises to '' before anything reads it.
     *
     * @var array<string, mixed>
     */
    public array $names = [];

    /**
     * The language ids this category already held a translation in when the modal opened. Locked
     * because it feeds D-7's conditional-requiredness branch: a forged value would let an actor
     * blank an existing translation without tripping the blank-is-refused rule.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $originalTranslatedLanguageIds = [];

    /**
     * The tab currently shown; overwritten to a real language id when the modal opens. Never
     * binds a <select> -- it only drives an x-show comparison.
     */
    public string $activeLanguageId = '';

    /**
     * UI hint only (B-1): false in create mode for an actor lacking products.edit, so the
     * non-default name inputs render disabled. Enforcement is save()'s own logged check.
     */
    #[Locked]
    public bool $canAuthorTranslations = true;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?string $deletingCategoryId = null;

    #[Locked]
    public string $deletingCategoryName = '';

    /**
     * Mount the component.
     *
     * `viewAny` is authorized here in addition to the route's `can:`
     * middleware because Livewire's `/livewire/update` endpoint is a
     * separate entry point that never runs route middleware -- mounting
     * the component directly (as every `Livewire::test()` call does) must
     * be denied on its own.
     *
     * Deliberately left unlogged, matching App\Livewire\Users\Index's
     * identical mount() precedent: the route's own `can:products.view`
     * gate checks the identical ability, and `can:` -- unlike `permission:`
     * -- IS on Livewire's PersistentMiddleware allow-list, so a real HTTP
     * actor who would fail this check is refused by the route before ever
     * reaching mount(). A refusal here is therefore unreachable over HTTP.
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', ProductCategory::class);

        $this->loadProductCategories();
    }

    /**
     * The active store languages, one tab each (D-14): the store default first, then the rest by
     * name. Queried once per request.
     *
     * @return Collection<int, StoreLanguage>
     */
    #[Computed]
    public function languages(): Collection
    {
        return StoreLanguage::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Switch the visible language tab.
     *
     * Deliberately no Gate check (D-4): switching a tab discloses nothing the open modal does not
     * already hold. The id is resolved against the ACTIVE languages with findOrFail(), so a
     * forged unknown or inactive id fails and leaves the current tab unchanged.
     */
    public function setActiveLanguageTab(string $languageId): void
    {
        $language = StoreLanguage::query()->active()->findOrFail($languageId);

        $this->activeLanguageId = $language->id;
    }

    /**
     * Map every `names.<id>` key to the localized "name" (N-1), so validation messages never
     * show the internal key.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['names.*' => __('products.categories.index.tabs.name_attribute')];
    }

    /**
     * Open the create-category form with one empty field per active language.
     *
     * Authorizes as its first statement -- a disclosure/UI-opening path,
     * not only the mutating save(), per
     * docs/security/livewire-authorization.md's "gate every method that
     * mutates *or discloses*" rule.
     */
    public function openCreateModal(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize('create', ProductCategory::class, targetType: 'product_category');

        $this->reset(['editingCategoryId', 'names', 'originalTranslatedLanguageIds']);
        $this->resetValidation();

        $this->names = $this->languages
            ->mapWithKeys(fn (StoreLanguage $language): array => [$language->id => ''])
            ->all();
        $this->activeLanguageId = (string) $this->languages->first()?->id;
        $this->canAuthorTranslations = Gate::allows('update', new ProductCategory);
        $this->showModal = true;
    }

    /**
     * Open the edit form prefilled with the target category's own name in every active language.
     *
     * This is a Livewire method call, not route-model binding, so
     * HasUuids::resolveRouteBindingQuery()'s Str::isUuid() short-circuit
     * does not apply here -- a malformed or unknown id must fail on its
     * own, which ProductCategory::findOrFail() already does by raising
     * ModelNotFoundException when the query returns no row.
     *
     * $editingCategoryId is assigned from $target->id, never the raw
     * $categoryId argument (R-3) -- the server-authoritative id the
     * ->ignore() uniqueness rule relies on.
     *
     * Each field reads the RAW translation row of its own language (D-6), never translated():
     * the fallback would silently pre-fill an untranslated tab with another language's name.
     */
    public function openEditModal(string $categoryId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = ProductCategory::query()->findOrFail($categoryId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'product_category', targetId: $target->id);

        /** @var array<string, string> $ownNames */
        $ownNames = ProductCategoryTranslation::query()
            ->where('product_category_id', $target->id)
            ->whereIn('store_language_id', $this->languages->modelKeys())
            ->pluck('name', 'store_language_id')
            ->all();

        $this->resetValidation();
        $this->editingCategoryId = $target->id;
        $this->names = $this->languages
            ->mapWithKeys(fn (StoreLanguage $language): array => [$language->id => $ownNames[$language->id] ?? ''])
            ->all();
        $this->originalTranslatedLanguageIds = array_map('strval', array_keys($ownNames));
        $this->activeLanguageId = (string) $this->languages->first()?->id;
        $this->canAuthorTranslations = true;
        $this->showModal = true;
    }

    /**
     * Validate and persist the create or edit form, one name per active store language.
     *
     * Authorization is the first statement of each branch: `create` when
     * no category is being edited, `update` (against a freshly re-resolved
     * target) otherwise -- re-checked here even though openCreateModal()/
     * openEditModal() already authorized the same operation, since a
     * permission can be revoked between opening the modal and submitting
     * it. On the create branch, a non-empty non-default name additionally requires `update`
     * (B-1), checked here -- logged, before validation and before the transaction opens -- so a
     * forged value is refused with nothing written.
     *
     * Layer 1 of the two-layer write guard (D-4): this method authorizes and validates the whole
     * batch; SetProductCategoryTranslation then re-authorizes and re-validates each non-default
     * row independently. Every value is trimmed and written back into $names (N-6), so the
     * trimmed text is what is validated and written.
     *
     * Every write of the batch runs in one transaction (Q-5). A refusal from any write rolls the
     * whole click back, switches to the refused tab and keeps every typed value. The default
     * language's refusals arrive keyed `name` (0023's actions) and are re-keyed to
     * `names.{defaultId}`, since Livewire drops an error whose first key segment is not a
     * component property.
     */
    public function save(
        CreateProductCategory $createProductCategory,
        RenameProductCategory $renameProductCategory,
        SetProductCategoryTranslation $setProductCategoryTranslation,
        NormalizeForSearch $normalizeForSearch,
        LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ): void {
        $target = null;

        if ($this->editingCategoryId === null) {
            $logRefusedPrivilegedAttempt->authorize('create', ProductCategory::class, targetType: 'product_category');
        } else {
            $target = ProductCategory::findOrFail($this->editingCategoryId);
            $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'product_category', targetId: $target->id);
        }

        $languages = $this->languages;
        $defaultId = (string) $languages->first(fn (StoreLanguage $language): bool => (bool) $language->is_default)?->id;

        foreach ($languages as $language) {
            $value = $this->names[$language->id] ?? '';

            $this->names[$language->id] = is_string($value) ? trim($value) : '';
        }

        if ($target === null && $languages->contains(fn (StoreLanguage $language): bool => $language->id !== $defaultId && $this->names[$language->id] !== '')) {
            $logRefusedPrivilegedAttempt->authorize('update', new ProductCategory, targetType: 'product_category');
        }

        $rules = [];

        foreach ($languages as $language) {
            $languageRules = $this->nameRules($normalizeForSearch, $language->id, $this->editingCategoryId);

            if ($language->id !== $defaultId && ! in_array($language->id, $this->originalTranslatedLanguageIds, true)) {
                $languageRules = ['nullable', ...array_values(array_filter($languageRules, fn (mixed $rule): bool => $rule !== 'required'))];
            }

            $rules['names.'.$language->id] = $languageRules;
        }

        try {
            $this->validate($rules);
        } catch (ValidationException $exception) {
            $this->activeLanguageId = $this->firstErroringLanguageId($exception, $this->activeLanguageId);

            throw $exception;
        }

        try {
            DB::transaction(function () use ($languages, $defaultId, $target, $createProductCategory, $renameProductCategory, $setProductCategoryTranslation): void {
                $defaultName = $this->names[$defaultId] ?? '';

                if ($target === null) {
                    $target = $createProductCategory($defaultName);
                } else {
                    $currentName = ProductCategoryTranslation::query()
                        ->where('product_category_id', $target->id)
                        ->where('store_language_id', $defaultId)
                        ->value('name');

                    if ($currentName !== $defaultName) {
                        $renameProductCategory($target, $defaultName);
                    }
                }

                foreach ($languages as $language) {
                    if ($language->id === $defaultId || $this->names[$language->id] === '') {
                        continue;
                    }

                    $setProductCategoryTranslation($target, $language, $this->names[$language->id]);
                }
            });
        } catch (ValidationException $exception) {
            $rekeyed = ValidationException::withMessages($this->rekeyDefaultLanguageErrors($exception, $defaultId));
            $this->activeLanguageId = $this->firstErroringLanguageId($rekeyed, $defaultId);

            throw $rekeyed;
        }

        $this->loadProductCategories();
        $this->closeModal();
    }

    /**
     * Close the create/edit modal and reset its form state.
     *
     * Also clears EVERY `names.*` validation error (Phase 4 audit finding N-3): Livewire
     * persists the error bag across round trips, so without this a refused save's inline message
     * would leak into the next time the create/edit modal opens.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingCategoryId', 'names', 'originalTranslatedLanguageIds', 'activeLanguageId']);
        $this->resetValidation();
    }

    /**
     * Open the delete-confirmation modal for the target category.
     *
     * Authorizes as its first statement -- a disclosure/UI-opening path,
     * not only the mutating deleteProductCategory().
     */
    public function confirmDelete(string $categoryId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = ProductCategory::query()->withTranslationsFor()->findOrFail($categoryId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'product_category', targetId: $target->id);

        $this->deletingCategoryId = $target->id;
        $this->deletingCategoryName = $target->translated('name') ?? '—';
        $this->showDeleteModal = true;
    }

    /**
     * Authorize and delete the confirmed category.
     *
     * The `$this->deletingCategoryId === null` guard below is a no-op
     * short-circuit (nothing has been confirmed for deletion), not an
     * ungated path -- it precedes the gate because there is no target yet
     * to authorize against, and it neither mutates nor discloses anything
     * (Phase 5 review finding N-5).
     *
     * Resolves a FRESH ProductCategory::findOrFail($this->deletingCategoryId)
     * immediately before authorizing and calling DeleteProductCategory --
     * never an instance hydrated earlier in the request lifecycle or
     * carried in component state -- per
     * docs/security/model-instance-trust.md.
     *
     * No try/catch around the DeleteProductCategory() call (D-2): the
     * ValidationException it throws on a blocked delete is the one
     * exception Livewire already routes into this component's error bag
     * with no plumbing at the call site, keyed on 'productCategoryId'. The
     * throw aborts this method before loadProductCategories()/
     * closeDeleteModal() ever run, which is what keeps the modal open by
     * construction rather than by an explicit flag.
     */
    public function deleteProductCategory(DeleteProductCategory $deleteProductCategory, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->deletingCategoryId === null) {
            return;
        }

        $target = ProductCategory::findOrFail($this->deletingCategoryId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'product_category', targetId: $target->id);

        $deleteProductCategory($target);

        $this->loadProductCategories();
        $this->closeDeleteModal();
    }

    /**
     * Close the delete-confirmation modal and reset its state.
     *
     * Also resets the 'productCategoryId' error bag key (D-2, R-6 of the
     * story file) -- it lives in the error bag rather than in a component
     * property, so without this an earlier blocked-delete message would
     * leak into the next delete attempt on a different, unblocked category.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset(['deletingCategoryId', 'deletingCategoryName']);
        $this->resetErrorBag('productCategoryId');
    }

    /**
     * The id of the first language (in tab order) carrying an error in the exception, or
     * $fallback when none does.
     */
    private function firstErroringLanguageId(ValidationException $exception, string $fallback): string
    {
        $errors = $exception->errors();

        foreach ($this->languages as $language) {
            if (isset($errors['names.'.$language->id])) {
                return $language->id;
            }
        }

        return $fallback;
    }

    /**
     * Re-key a `name` error from 0023's default-language actions to `names.{defaultId}`.
     *
     * @return array<string, array<int, string>>
     */
    private function rekeyDefaultLanguageErrors(ValidationException $exception, string $defaultId): array
    {
        $rekeyed = [];

        foreach ($exception->errors() as $key => $messages) {
            $rekeyed[$key === 'name' ? 'names.'.$defaultId : $key] = $messages;
        }

        return $rekeyed;
    }

    /**
     * Reload the product categories list from the database.
     *
     * Story 0070 (D-15): ordered by default-language name, then `id` -- sorted in PHP rather than
     * `orderBy('name')`, since the name no longer lives on `product_categories` itself.
     * `withTranslationsFor()` eager-loads only the default language (no argument = the default),
     * so `translated('name')` below reads the hydrated relation rather than re-querying per row
     * (R-4). A category with no default-language translation sorts last and renders `—`
     * (0025's/0071's em-dash convention), rather than throwing. The `id` tiebreak costs nothing
     * and is a meaningful creation-order tiebreak given UUIDv7, even though the
     * normalised-uniqueness rule makes exact name collisions within one language structurally
     * impossible (D-10). No pagination -- a product-category catalog is a smaller lookup table
     * than `users`.
     *
     * Ordering itself runs through the shared App\Actions\Translations\CompareTranslatedNames
     * (Phase 5 round-1 finding 2) rather than a bare `$nameA <=> $nameB` byte comparison, so this
     * list keeps the case-/accent-insensitive ordering `orderBy('name')` gave it under the
     * column's `utf8mb4_unicode_ci` collation before the name moved off the table. Resolved here
     * with `app()` rather than a per-method parameter, matching
     * `App\Livewire\Shipping\Index::loadRates()`'s identical `app(ListShippingRatesByCarrier::class)`
     * call -- `loadProductCategories()` is a private helper Livewire's method-injection never
     * reaches (unlike `openEditModal()`/`save()`, which Livewire dispatches directly).
     *
     * `canEdit`/`canDelete` mirror the same ProductCategoryPolicy methods
     * save()/deleteProductCategory() authorize against
     * (Gate::allows('update'|'delete', $category)), so the disabled state
     * cannot drift from what a click would actually do. The product count
     * (`withCount('products')`) is informational only and is NEVER used to
     * decide `canDelete` -- D-3: pre-disabling delete on `productCount > 0`
     * would conflate the in-use refusal (a domain invariant) with the
     * authorization UI hint.
     */
    private function loadProductCategories(): void
    {
        $compareTranslatedNames = app(CompareTranslatedNames::class);

        $this->productCategories = ProductCategory::query()
            ->withCount('products')
            ->withTranslationsFor()
            ->get()
            ->sort(fn (ProductCategory $a, ProductCategory $b): int => $compareTranslatedNames(
                $a->translated('name'),
                $a->id,
                $b->translated('name'),
                $b->id,
            ))
            ->values()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'name' => $category->translated('name'),
                'productCount' => (int) $category->products_count,
                'canEdit' => Gate::allows('update', $category),
                'canDelete' => Gate::allows('delete', $category),
            ])
            ->all();
    }
}
