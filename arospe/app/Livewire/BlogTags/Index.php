<?php

namespace App\Livewire\BlogTags;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\RenameBlogTag;
use App\Actions\Blog\SetBlogTagTranslation;
use App\Actions\Translations\CompareTranslatedNames;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
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
 * Backoffice blog tag management screen: a permission-gated list, a create modal carrying a single
 * `name` field, an edit modal carrying one `name` per active store language behind language tabs,
 * and a plain delete-confirmation modal -- stories 0060 and 0075.
 *
 * This is the first component call site of App\Policies\BlogTagPolicy and of the three
 * App\Actions\Blog tag actions story 0059 shipped with none.
 *
 * Deliberately does NOT compose BlogTagValidationRules and never calls `$this->validate()`: the
 * actions trim, validate (`nameRules()`) and map the unique-index race to a `name`-keyed
 * ValidationException themselves, so this component calls them and lets that exception propagate
 * into Livewire's error bag. Validating here too would duplicate a rule 0059 put inside the action
 * on purpose, so that a non-dashboard caller inherits it (D-1).
 *
 * Deleting is UNCONDITIONAL, by design (D-2): PRD Epic 4 says a deleted tag is removed from every
 * post that used it, and DeleteBlogTag has no in-use guard, so there is no usage count, no blocked
 * state and no error to catch anywhere in this class -- the deliberate inverse of the product
 * categories screen.
 *
 * Story 0075: the edit form's default-language name is written by RenameBlogTag, every other
 * language's ONLY through SetBlogTagTranslation, which authorizes and validates on its own account
 * -- the unguarded SetTranslation primitive must never be imported here. This component is layer 1
 * of that two-layer guard: it authorizes the whole batch, and still never validates (D-1, D-3).
 *
 * Every public method except mount() and the two close*() resets routes its authorization through
 * LogRefusedPrivilegedAttempt with `target_type: 'blog_tag'` passed explicitly (it auto-resolves
 * only User and Role targets). The actions authorize again themselves; the component's own check
 * is defence in depth (0059's D-12) and fails fast before anything opens.
 *
 * @property-read Collection<int, StoreLanguage> $languages
 */
#[Title('Blog tags')]
class Index extends Component
{
    /**
     * `#[Locked]` like every id-carrying property here: rows are rebuilt from the database on
     * every mutation, and nothing a client sends may replace them (the newer Sales Regions
     * precedent, D-6). `canEdit`/`canDelete` are UI hints from the same policy methods the
     * mutating methods authorize against -- never a replacement for those checks. There is no
     * post or usage-count key: BlogTag has no `posts()` relation yet (D-5).
     *
     * @var array<int, array{id: string, name: string, canEdit: bool, canDelete: bool}>
     */
    #[Locked]
    public array $tags = [];

    /**
     * The id feeding RenameBlogTag's uniqueness exclusion, so it must stay server-authoritative:
     * `#[Locked]`, and assigned only from `$tag->id` of a row just read back in openEditModal(),
     * never from the raw argument. save() re-reads the row with findOrFail() and hands the model
     * to the action; without the lock, a forged value between opening the modal and saving would
     * turn a uniqueness check into a rename-any-tag primitive (D-6).
     */
    #[Locked]
    public ?string $editingTagId = null;

    public bool $showModal = false;

    /**
     * Never null -- a null wire:model-bound property desyncs its native control.
     */
    public string $name = '';

    /**
     * The edit form's one name per active store language, keyed by store_language_id. '' means
     * "not typed" -- never null, so each bound text input holds the type the DOM expects, and
     * never omitted, or Livewire's array dehydration desyncs. Deliberately unlocked: nothing reads
     * it for a decision -- save() iterates the active languages queried from the database, never
     * the keys of this array, so a forged key is ignored. Typed `mixed` for the analyser only: a
     * client can forge a non-string value, which save() normalises to ''.
     *
     * @var array<string, mixed>
     */
    public array $names = [];

    /**
     * The language ids this tag already held a translation in when the edit modal opened. Drives
     * only the "untranslated" hint and the default-name placeholder in the view; save() decides
     * what to write from the stored rows it re-reads itself, never from this property. Locked
     * anyway, as everything the client has no business setting.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $originalTranslatedLanguageIds = [];

    /**
     * The tab currently shown. Locked: only setActiveLanguageTab() and save() move it, both from
     * a language id resolved server-side, so a forged id never reaches a write (D-7).
     */
    #[Locked]
    public string $activeLanguageId = '';

    public bool $showDeleteModal = false;

    #[Locked]
    public ?string $deletingTagId = null;

    #[Locked]
    public string $deletingTagName = '';

    /**
     * `viewAny` is authorized here in addition to the route's `can:` middleware because Livewire's
     * `/livewire/update` endpoint never runs route middleware -- mounting the component directly
     * (as every `Livewire::test()` call does) must be denied on its own.
     *
     * Deliberately left unlogged, like Users\Index::mount() and Roles\Index::mount(): the route's
     * own `can:blog.view` checks the identical ability, and `can:` IS on Livewire's
     * PersistentMiddleware allow-list, so a refusal here is unreachable over HTTP. Do not "fix"
     * this into a logged call.
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', BlogTag::class);

        $this->loadTags();
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
     * current tab unchanged. Called by the shared <x-language-tab-strip>.
     */
    public function setActiveLanguageTab(string $languageId): void
    {
        $language = StoreLanguage::query()->active()->findOrFail($languageId);

        $this->activeLanguageId = $language->id;
    }

    /**
     * Creating stays single-language (D-4): CreateBlogTag writes the store default only, so no tab
     * state is touched here.
     */
    public function openCreateModal(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

        $this->reset(['editingTagId', 'name', 'names', 'originalTranslatedLanguageIds', 'activeLanguageId']);
        $this->resetValidation();
        $this->showModal = true;
    }

    /**
     * A disclosure path, not only a mutation, so it authorizes independently of save().
     *
     * Populated from the tag's OWN translation rows, never translated(): its fallback would
     * silently pre-fill an untranslated tab with the default language's name, and the next Save
     * would then write that string into every language (D-2). `scopeWithTranslationsFor()` is the
     * wrong tool here too -- it narrows to two languages and the form needs every active one (R-8).
     */
    public function openEditModal(string $tagId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogTag::query()->findOrFail($tagId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_tag', targetId: $target->id);

        /** @var array<string, string> $ownNames */
        $ownNames = BlogTagTranslation::query()
            ->where('blog_tag_id', $target->id)
            ->whereIn('store_language_id', $this->languages->modelKeys())
            ->pluck('name', 'store_language_id')
            ->all();

        $this->resetValidation();
        $this->reset('name');
        $this->editingTagId = $target->id;
        $this->names = $this->languages
            ->mapWithKeys(fn (StoreLanguage $language): array => [$language->id => $ownNames[$language->id] ?? ''])
            ->all();
        $this->originalTranslatedLanguageIds = array_map('strval', array_keys($ownNames));
        $this->activeLanguageId = (string) $this->languages->first()?->id;
        $this->showModal = true;
    }

    /**
     * No `$this->validate()` and no manual trim: CreateBlogTag, RenameBlogTag and
     * SetBlogTagTranslation each trim and validate on their own account, and a refusal propagates
     * as a ValidationException, which aborts this method -- that is what keeps the modal open by
     * construction (D-1). Only these three actions are injected, never FindOrCreateBlogTag:
     * reaching for the find-or-create action would turn every duplicate-name refusal into a silent
     * success (R-7).
     *
     * Edit mode authorizes the whole batch up front (layer 1), then writes only what changed, all
     * in ONE transaction so a refusal in any language discards the whole save (D-9). A blanked
     * default or previously-translated language counts as changed and is therefore refused by the
     * action; a blanked untranslated one is skipped, so it creates no row (D-8).
     *
     * The default language's refusals arrive keyed `name` (RenameBlogTag's frozen contract) and
     * are re-keyed to `names.{defaultId}`, the key its field renders under -- on this screen that
     * is the ordinary path for every default-language refusal, not a race backstop (D-12).
     */
    public function save(
        CreateBlogTag $createBlogTag,
        RenameBlogTag $renameBlogTag,
        SetBlogTagTranslation $setBlogTagTranslation,
        LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ): void {
        if ($this->editingTagId === null) {
            $logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

            $createBlogTag($this->name);

            $this->loadTags();
            $this->closeModal();

            return;
        }

        $target = BlogTag::query()->findOrFail($this->editingTagId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_tag', targetId: $target->id);

        $languages = $this->languages;
        $defaultId = (string) $languages->first(fn (StoreLanguage $language): bool => (bool) $language->is_default)?->id;

        /** @var array<string, string> $storedNames */
        $storedNames = BlogTagTranslation::query()
            ->where('blog_tag_id', $target->id)
            ->whereIn('store_language_id', $languages->modelKeys())
            ->pluck('name', 'store_language_id')
            ->all();

        try {
            DB::transaction(function () use ($languages, $defaultId, $target, $storedNames, $renameBlogTag, $setBlogTagTranslation): void {
                foreach ($languages as $language) {
                    $typed = $this->names[$language->id] ?? '';
                    $typed = is_string($typed) ? $this->trimTypedName($typed) : '';
                    $this->names[$language->id] = $typed;

                    $stored = $storedNames[$language->id] ?? null;
                    $wasTranslated = $stored !== null;

                    if ($language->id !== $defaultId && ! $wasTranslated && $typed === '') {
                        continue;
                    }

                    if ($typed === $stored) {
                        continue;
                    }

                    if ($language->id === $defaultId) {
                        $renameBlogTag($target, $typed);
                    } else {
                        $setBlogTagTranslation($target, $language, $typed);
                    }
                }
            });
        } catch (ValidationException $exception) {
            $rekeyed = ValidationException::withMessages($this->rekeyDefaultLanguageErrors($exception, $defaultId));
            $this->activeLanguageId = $this->firstErroringLanguageId($rekeyed, $this->activeLanguageId);

            throw $rekeyed;
        }

        $this->loadTags();
        $this->closeModal();
    }

    /**
     * Also clears every validation error: Livewire persists the error bag across requests, so
     * without this a refused save, then Cancel, then an edit on an unrelated row would render a
     * stale message beside a field that never triggered it.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingTagId', 'name', 'names', 'originalTranslatedLanguageIds', 'activeLanguageId']);
        $this->resetValidation();
    }

    public function confirmDelete(string $tagId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogTag::query()->withTranslationsFor()->findOrFail($tagId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_tag', targetId: $target->id);

        $this->deletingTagId = $target->id;
        $this->deletingTagName = $target->translated('name') ?? '—';
        $this->showDeleteModal = true;
    }

    /**
     * No try/catch and no branch that keeps the modal open: DeleteBlogTag throws nothing to catch
     * (D-2). It closes in one round trip.
     */
    public function deleteTag(DeleteBlogTag $deleteBlogTag, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->deletingTagId === null) {
            return;
        }

        $target = BlogTag::query()->findOrFail($this->deletingTagId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_tag', targetId: $target->id);

        $deleteBlogTag($target);

        $this->loadTags();
        $this->closeDeleteModal();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset(['deletingTagId', 'deletingTagName']);
    }

    /**
     * Only decides whether a typed value differs from the stored one or is blank; the actions
     * re-trim and validate every value they write. Mirrors their Unicode-aware trim (a
     * non-breaking space is not "content") without composing the validation trait, which this
     * component deliberately does not use (D-1).
     */
    private function trimTypedName(string $name): string
    {
        return preg_replace('/\A[\s\p{Z}\p{Cf}]++|(?<![\s\p{Z}\p{Cf}])[\s\p{Z}\p{Cf}]++\z/u', '', $name) ?? '';
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
     * Re-key a `name` error from RenameBlogTag to `names.{defaultId}`: Livewire drops an error
     * whose first key segment is not a component property, and the default tab's field binds to
     * `names.{defaultId}`.
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
     * Ordered `name ASC, id ASC` (D-9): a small backoffice lookup table, so no pagination, search
     * or sort picker. `Gate::allows()`, never `Gate::authorize()`, which would throw while
     * rendering a list.
     */
    private function loadTags(): void
    {
        $compareTranslatedNames = app(CompareTranslatedNames::class);

        $this->tags = BlogTag::query()
            ->withTranslationsFor()
            ->get()
            ->sort(fn (BlogTag $a, BlogTag $b): int => $compareTranslatedNames(
                $a->translated('name'),
                $a->id,
                $b->translated('name'),
                $b->id,
            ))
            ->values()
            ->map(fn (BlogTag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->translated('name') ?? '—',
                'canEdit' => Gate::allows('update', $tag),
                'canDelete' => Gate::allows('delete', $tag),
            ])
            ->all();
    }
}
