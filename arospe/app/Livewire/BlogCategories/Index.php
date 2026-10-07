<?php

namespace App\Livewire\BlogCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Actions\Blog\RenameBlogCategory;
use App\Actions\Blog\SetBlogCategoryTranslation;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\CompareTranslatedNames;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
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
 * Backoffice blog category management screen: a permission-gated list with each category's post
 * count, a create/edit modal carrying one `name` field per active store language, and a delete-confirmation modal that
 * renders the hard-block-with-count refusal -- story 0062.
 *
 * This is the first component call site of App\Policies\BlogCategoryPolicy and of the three
 * App\Actions\Blog category actions story 0058 shipped with none, and the screen story 0061's
 * `blogCategoryId` error-bag contract was built for.
 *
 * Story 0073: the name is authored per active store language through language tabs. The default
 * language's name is written by CreateBlogCategory / RenameBlogCategory, every other language's
 * ONLY through SetBlogCategoryTranslation, which authorizes and validates on its own account; the
 * unguarded SetTranslation primitive must never be imported here. This component is layer 1 of
 * that two-layer guard: it authorizes and validates the whole batch before any write.
 *
 * Deleting a category still used by any post is HARD-BLOCKED with a count, at every privilege level
 * (0061 D-18). That refusal is a domain invariant, not an authorization rule -- a Super Admin is
 * refused identically -- and it arrives as a ValidationException keyed `blogCategoryId`, which
 * Livewire routes into the error bag with no plumbing here. deleteCategory() therefore catches
 * nothing: the throw aborts the method before the modal closes, which keeps it open by
 * construction (D-2). There is no force, confirm-and-proceed or reassign path anywhere on this
 * screen; reassigning belongs to the post editor (0063).
 *
 * Every public method except mount() and the two close*() resets routes its authorization through
 * LogRefusedPrivilegedAttempt with `target_type: 'blog_category'` passed explicitly (it
 * auto-resolves only User and Role targets). The actions authorize again themselves; the
 * component's own check is defence in depth and fails fast before anything opens (D-9).
 *
 * @property-read Collection<int, StoreLanguage> $languages
 */
#[Title('Blog categories')]
class Index extends Component
{
    use BlogCategoryValidationRules;

    /**
     * `#[Locked]` like every id-carrying property here: rows are rebuilt from the database on
     * every mutation and nothing a client sends may replace them (the newer Sales Regions
     * precedent, D-8). It is belt-and-braces -- no method reads it for a decision, because every
     * mutating method re-reads its target with findOrFail() and re-authorizes.
     * `canEdit`/`canDelete` are UI hints from the same policy methods those methods authorize
     * against, never a replacement for them. `postCount` is display-only and is NEVER used to
     * disable the delete action (D-12), nor as a delete-eligibility gate (D-13).
     *
     * @var array<int, array{id: string, name: string, postCount: int, canEdit: bool, canDelete: bool}>
     */
    #[Locked]
    public array $categories = [];

    /**
     * The id feeding RenameBlogCategory's uniqueness exclusion, so it must stay
     * server-authoritative: `#[Locked]`, and assigned only from `$category->id` of a row just read
     * back in openEditModal(), never from the raw argument. save() re-reads the row with
     * findOrFail() and hands the model to the action; without the lock, a forged value between
     * opening the modal and saving would turn a uniqueness check into a rename-any-category
     * primitive (R-4).
     */
    #[Locked]
    public ?string $editingCategoryId = null;

    public bool $showModal = false;

    /**
     * One name per active store language, keyed by store_language_id. '' means "not typed" --
     * never null, so each bound text input holds the type the DOM expects. Deliberately unlocked:
     * nothing reads it for a decision -- save() iterates the active languages queried from the
     * database, never the keys of this array, so a forged key is ignored. Typed `mixed` for the
     * analyser only: a client can forge a non-string value, which save() normalises to ''.
     *
     * @var array<string, mixed>
     */
    public array $names = [];

    /**
     * The language ids this category already held a translation in when the modal opened. Locked
     * because it feeds the conditional-requiredness branch: a forged value would let an actor
     * blank an existing translation without tripping the blank-is-refused rule.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $originalTranslatedLanguageIds = [];

    /**
     * The tab currently shown; overwritten to a real language id when the modal opens. It only
     * drives an x-show comparison.
     */
    public string $activeLanguageId = '';

    /**
     * UI hint only: false in create mode for an actor lacking blog.edit, so the non-default name
     * inputs render disabled. Enforcement is save()'s own logged check.
     */
    #[Locked]
    public bool $canAuthorTranslations = true;

    public bool $showDeleteModal = false;

    /**
     * The delete target, NAMED FOR THE ERROR KEY (D-3). 0061 fixes the refusal's key as
     * `blogCategoryId`, and Livewire's SupportValidation::dehydrate() drops an error keyed on a
     * name the component does not declare on the next round-trip -- so the block message would
     * vanish. Do not rename this to `$deletingCategoryId`.
     */
    #[Locked]
    public string $blogCategoryId = '';

    #[Locked]
    public string $deletingCategoryName = '';

    /**
     * `viewAny` is authorized here in addition to the route's `can:` middleware because Livewire's
     * `/livewire/update` endpoint never runs route middleware -- mounting the component directly
     * (as every `Livewire::test()` call does) must be denied on its own.
     *
     * Deliberately left unlogged, like BlogTags\Index::mount() and Roles\Index::mount(): the
     * route's own `can:blog.view` checks the identical ability, and `can:` IS on Livewire's
     * PersistentMiddleware allow-list, so a refusal here is unreachable over HTTP. Do not "fix"
     * this into a logged call.
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', BlogCategory::class);

        $this->loadCategories();
    }

    /**
     * The active store languages, one tab each: the store default first, then the rest by name.
     * Queried once per request.
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
     * Switch the visible language tab. Deliberately no Gate check: switching a tab discloses
     * nothing the open modal does not already hold. The id is resolved against the ACTIVE
     * languages with findOrFail(), so a forged unknown or inactive id fails and leaves the
     * current tab unchanged.
     */
    public function setActiveLanguageTab(string $languageId): void
    {
        $language = StoreLanguage::query()->active()->findOrFail($languageId);

        $this->activeLanguageId = $language->id;
    }

    /**
     * Map every `names.<id>` key to the localized "name", so validation messages never show the
     * internal key.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['names.*' => __('blog.categories.index.tabs.name_attribute')];
    }

    /**
     * Open the create form with one empty field per active language.
     */
    public function openCreateModal(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize('create', BlogCategory::class, targetType: 'blog_category');

        $this->reset(['editingCategoryId', 'names', 'originalTranslatedLanguageIds']);
        $this->resetNameErrors();

        $this->names = $this->languages
            ->mapWithKeys(fn (StoreLanguage $language): array => [$language->id => ''])
            ->all();
        $this->activeLanguageId = (string) $this->languages->first()?->id;
        $this->canAuthorTranslations = Gate::allows('update', new BlogCategory);
        $this->showModal = true;
    }

    /**
     * Open the edit form prefilled with the category's OWN name in every active language, read
     * from the raw translation rows -- never translated(), whose fallback would silently pre-fill
     * an untranslated tab with another language's name.
     *
     * $editingCategoryId is assigned from $target->id, never the raw argument, so the id feeding
     * the uniqueness exclusion stays server-authoritative.
     */
    public function openEditModal(string $categoryId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogCategory::query()->findOrFail($categoryId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_category', targetId: $target->id);

        /** @var array<string, string> $ownNames */
        $ownNames = BlogCategoryTranslation::query()
            ->where('blog_category_id', $target->id)
            ->whereIn('store_language_id', $this->languages->modelKeys())
            ->pluck('name', 'store_language_id')
            ->all();

        $this->resetNameErrors();
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
     * Layer 1 of the two-layer write guard: authorizes (logged, first statement of each branch;
     * plus `update` on create when a non-default name is typed) and validates the whole batch;
     * SetBlogCategoryTranslation then re-authorizes and re-validates each non-default row on its
     * own. Every value is trimmed with trimName() and written back into $names.
     *
     * Every write of the batch runs in one transaction. A refusal rolls the whole click back,
     * switches to the first refused tab and keeps every typed value. The default language's
     * refusals arrive keyed `name` (from the create/rename actions) and are re-keyed to
     * `names.{defaultId}`, since Livewire drops an error whose first key segment is not a
     * component property.
     */
    public function save(
        CreateBlogCategory $createBlogCategory,
        RenameBlogCategory $renameBlogCategory,
        SetBlogCategoryTranslation $setBlogCategoryTranslation,
        NormalizeForSearch $normalizeForSearch,
        LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ): void {
        $target = null;

        if ($this->editingCategoryId === null) {
            $logRefusedPrivilegedAttempt->authorize('create', BlogCategory::class, targetType: 'blog_category');
        } else {
            $target = BlogCategory::query()->findOrFail($this->editingCategoryId);

            $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_category', targetId: $target->id);
        }

        $languages = $this->languages;
        $defaultId = (string) $languages->first(fn (StoreLanguage $language): bool => (bool) $language->is_default)?->id;

        foreach ($languages as $language) {
            $value = $this->names[$language->id] ?? '';

            $this->names[$language->id] = is_string($value) ? $this->trimName($value) : '';
        }

        if ($target === null && $languages->contains(fn (StoreLanguage $language): bool => $language->id !== $defaultId && $this->names[$language->id] !== '')) {
            $logRefusedPrivilegedAttempt->authorize('update', new BlogCategory, targetType: 'blog_category');
        }

        $rules = [];

        foreach ($languages as $language) {
            $languageRules = $this->nameRules($normalizeForSearch, $language->id, $this->editingCategoryId);

            if ($language->id !== $defaultId && ! in_array($language->id, $this->originalTranslatedLanguageIds, true)) {
                $languageRules = ['bail', 'nullable', ...array_values(array_filter($languageRules, fn (mixed $rule): bool => $rule !== 'required' && $rule !== 'bail'))];
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
            DB::transaction(function () use ($languages, $defaultId, $target, $createBlogCategory, $renameBlogCategory, $setBlogCategoryTranslation): void {
                $defaultName = $this->names[$defaultId] ?? '';

                if ($target === null) {
                    $target = $createBlogCategory($defaultName);
                } else {
                    $currentName = BlogCategoryTranslation::query()
                        ->where('blog_category_id', $target->id)
                        ->where('store_language_id', $defaultId)
                        ->value('name');

                    if ($currentName !== $defaultName) {
                        $renameBlogCategory($target, $defaultName);
                    }
                }

                foreach ($languages as $language) {
                    if ($language->id === $defaultId || $this->names[$language->id] === '') {
                        continue;
                    }

                    $setBlogCategoryTranslation($target, $language, $this->names[$language->id]);
                }
            });
        } catch (ValidationException $exception) {
            $rekeyed = ValidationException::withMessages($this->rekeyDefaultLanguageErrors($exception, $defaultId));
            $this->activeLanguageId = $this->firstErroringLanguageId($rekeyed, $defaultId);

            throw $rekeyed;
        }

        $this->loadCategories();
        $this->closeModal();
    }

    /**
     * Close the create/edit modal and reset its form state, clearing every `names.*` validation
     * error so a refused save's message never leaks into the next open.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingCategoryId', 'names', 'originalTranslatedLanguageIds', 'activeLanguageId']);
        $this->resetNameErrors();
    }

    /**
     * Eligibility is established by this method's own findOrFail() -- "does this row still exist"
     * -- never by matching a row out of the already-loaded array; the action re-counts for the
     * refusal, and the loaded `postCount` is display-only (D-13).
     */
    public function confirmDelete(string $categoryId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogCategory::query()->withTranslationsFor()->findOrFail($categoryId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_category', targetId: $target->id);

        $this->blogCategoryId = $target->id;
        $this->deletingCategoryName = $target->translated('name') ?? '—';
        $this->showDeleteModal = true;
    }

    /**
     * No try/catch (D-2): an in-use category makes DeleteBlogCategory throw a ValidationException
     * keyed `blogCategoryId`, which Livewire routes into the error bag and which aborts this
     * method before closeDeleteModal() runs -- so the modal stays open with the refusal inline.
     * Catching it would defeat the reason 0061 chose that exception type.
     *
     * Authorizes here as well as inside the action without double-logging:
     * LogRefusedPrivilegedAttempt writes a line only on REFUSAL, so a passing gate is silent and
     * the domain-invariant refusal is logged once, by the action (D-9).
     */
    public function deleteCategory(DeleteBlogCategory $deleteBlogCategory, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->blogCategoryId === '') {
            return;
        }

        $target = BlogCategory::query()->findOrFail($this->blogCategoryId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_category', targetId: $target->id);

        $deleteBlogCategory($target);

        $this->loadCategories();
        $this->closeDeleteModal();
    }

    /**
     * Clears the block message too: it lives in the error bag, not in a property reset() would
     * clear, so without this a user blocked on one category who cancels and opens the delete modal
     * of an UNUSED one would be shown the old refusal (R-5).
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset(['blogCategoryId', 'deletingCategoryName']);
        $this->resetValidation('blogCategoryId');
    }

    /**
     * Clear every `names.*` error and nothing else: the delete modal's `blogCategoryId` error is
     * a different modal's state and is cleared only by closeDeleteModal().
     */
    private function resetNameErrors(): void
    {
        $nameKeys = array_values(array_filter(
            $this->getErrorBag()->keys(),
            fn (string $key): bool => str_starts_with($key, 'names.'),
        ));

        // An empty list would clear the WHOLE bag, blogCategoryId included.
        if ($nameKeys !== []) {
            $this->resetValidation($nameKeys);
        }
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
     * Re-key a `name` error from the default-language actions to `names.{defaultId}`.
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
     * Ordered by the default-language name through the shared CompareTranslatedNames (story 0072,
     * the name no longer being a column), `id` as the tiebreak: a small backoffice lookup table, so
     * no pagination, search or sort picker. `Gate::allows()`, never `Gate::authorize()`, which would throw while rendering
     * a list.
     *
     * The count says `withTrashed()` -- the SAME scope DeleteBlogCategory's own guard counts with
     * (0061 D-18). A bare withCount('posts') would apply BlogPost's SoftDeletingScope and
     * undercount, so a row could read "2 posts" while the refusal cites 3. A count rendered beside
     * a guarded action is part of that guard's contract, not decoration (D-5).
     */
    private function loadCategories(): void
    {
        $compareTranslatedNames = app(CompareTranslatedNames::class);

        $this->categories = BlogCategory::query()
            ->withCount(['posts' => fn ($query) => $query->withTrashed()])
            ->withTranslationsFor()
            ->get()
            ->sort(fn (BlogCategory $a, BlogCategory $b): int => $compareTranslatedNames(
                $a->translated('name'),
                $a->id,
                $b->translated('name'),
                $b->id,
            ))
            ->values()
            ->map(fn (BlogCategory $category): array => [
                'id' => $category->id,
                'name' => $category->translated('name') ?? '—',
                'postCount' => (int) $category->posts_count,
                'canEdit' => Gate::allows('update', $category),
                'canDelete' => Gate::allows('delete', $category),
            ])
            ->all();
    }
}
