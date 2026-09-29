<?php

namespace App\Livewire\BlogTags;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\RenameBlogTag;
use App\Models\BlogTag;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice blog tag management screen: a permission-gated list, a create/edit modal carrying a
 * single `name` field, and a plain delete-confirmation modal -- story 0060.
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
 * Every public method except mount() and the two close*() resets routes its authorization through
 * LogRefusedPrivilegedAttempt with `target_type: 'blog_tag'` passed explicitly (it auto-resolves
 * only User and Role targets). The actions authorize again themselves; the component's own check
 * is defence in depth (0059's D-12) and fails fast before anything opens.
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

    public function openCreateModal(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

        $this->reset(['editingTagId', 'name']);
        $this->resetValidation('name');
        $this->showModal = true;
    }

    /**
     * A disclosure path, not only a mutation, so it authorizes independently of save().
     */
    public function openEditModal(string $tagId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogTag::query()->findOrFail($tagId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_tag', targetId: $target->id);

        $this->editingTagId = $target->id;
        $this->name = $target->name;
        $this->resetValidation('name');
        $this->showModal = true;
    }

    /**
     * No `$this->validate()` and no manual trim: CreateBlogTag / RenameBlogTag do both, and a
     * refusal propagates as a ValidationException keyed `name`, which aborts this method -- that
     * is what keeps the modal open by construction (D-1).
     *
     * Only these two actions are injected, never FindOrCreateBlogTag: reaching for the
     * find-or-create action would turn every duplicate-name refusal into a silent success (R-7).
     */
    public function save(CreateBlogTag $createBlogTag, RenameBlogTag $renameBlogTag, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->editingTagId === null) {
            $logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

            $createBlogTag($this->name);
        } else {
            $target = BlogTag::query()->findOrFail($this->editingTagId);

            $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_tag', targetId: $target->id);

            $renameBlogTag($target, $this->name);
        }

        $this->loadTags();
        $this->closeModal();
    }

    /**
     * Also clears the `name` error: Livewire persists the error bag across requests, so without
     * this a refused create, then Cancel, then an edit on an unrelated row would render a stale
     * message beside a field that never triggered it.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingTagId', 'name']);
        $this->resetValidation('name');
    }

    public function confirmDelete(string $tagId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogTag::query()->findOrFail($tagId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_tag', targetId: $target->id);

        $this->deletingTagId = $target->id;
        $this->deletingTagName = $target->name;
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
     * Ordered `name ASC, id ASC` (D-9): a small backoffice lookup table, so no pagination, search
     * or sort picker. `Gate::allows()`, never `Gate::authorize()`, which would throw while
     * rendering a list.
     */
    private function loadTags(): void
    {
        $this->tags = BlogTag::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (BlogTag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'canEdit' => Gate::allows('update', $tag),
                'canDelete' => Gate::allows('delete', $tag),
            ])
            ->all();
    }
}
