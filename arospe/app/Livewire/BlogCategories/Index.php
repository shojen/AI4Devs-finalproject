<?php

namespace App\Livewire\BlogCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Actions\Blog\RenameBlogCategory;
use App\Models\BlogCategory;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice blog category management screen: a permission-gated list with each category's post
 * count, a create/edit modal carrying a single `name` field, and a delete-confirmation modal that
 * renders the hard-block-with-count refusal -- story 0062.
 *
 * This is the first component call site of App\Policies\BlogCategoryPolicy and of the three
 * App\Actions\Blog category actions story 0058 shipped with none, and the screen story 0061's
 * `blogCategoryId` error-bag contract was built for.
 *
 * Deliberately does NOT compose BlogCategoryValidationRules and never calls `$this->validate()`:
 * the actions trim, validate (`nameRules()`) and map the unique-index race to a `name`-keyed
 * ValidationException themselves, so this component calls them and lets that exception propagate
 * into Livewire's error bag. Validating here too would duplicate a rule 0058 put inside the action
 * on purpose, so that a non-dashboard caller inherits it (D-1). For the same reason there is no
 * fold logic here: NormalizeForSearch is reached only through the actions.
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
 */
#[Title('Blog categories')]
class Index extends Component
{
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
     * Never null -- a null wire:model-bound property desyncs its native control.
     */
    public string $name = '';

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

    public function openCreateModal(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize('create', BlogCategory::class, targetType: 'blog_category');

        $this->reset(['editingCategoryId', 'name']);
        $this->resetValidation('name');
        $this->showModal = true;
    }

    /**
     * A disclosure path, not only a mutation, so it authorizes independently of save().
     */
    public function openEditModal(string $categoryId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogCategory::query()->findOrFail($categoryId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_category', targetId: $target->id);

        $this->editingCategoryId = $target->id;
        $this->name = $target->name;
        $this->resetValidation('name');
        $this->showModal = true;
    }

    /**
     * No `$this->validate()` and no manual trim: CreateBlogCategory / RenameBlogCategory do both,
     * and a refusal propagates as a ValidationException keyed `name`, which aborts this method --
     * that is what keeps the modal open by construction (D-1).
     */
    public function save(CreateBlogCategory $createBlogCategory, RenameBlogCategory $renameBlogCategory, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->editingCategoryId === null) {
            $logRefusedPrivilegedAttempt->authorize('create', BlogCategory::class, targetType: 'blog_category');

            $createBlogCategory($this->name);
        } else {
            $target = BlogCategory::query()->findOrFail($this->editingCategoryId);

            $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'blog_category', targetId: $target->id);

            $renameBlogCategory($target, $this->name);
        }

        $this->loadCategories();
        $this->closeModal();
    }

    /**
     * Also clears the `name` error: Livewire persists the error bag across requests, so without
     * this a refused create, then Cancel, then an edit on an unrelated row would render a stale
     * message beside a field that never triggered it.
     *
     * Clears the `name` key ONLY. The delete modal's `blogCategoryId` error is a different modal's
     * state and is cleared by closeDeleteModal() -- do not conflate the two resets.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingCategoryId', 'name']);
        $this->resetValidation('name');
    }

    /**
     * Eligibility is established by this method's own findOrFail() -- "does this row still exist"
     * -- never by matching a row out of the already-loaded array; the action re-counts for the
     * refusal, and the loaded `postCount` is display-only (D-13).
     */
    public function confirmDelete(string $categoryId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogCategory::query()->findOrFail($categoryId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_category', targetId: $target->id);

        $this->blogCategoryId = $target->id;
        $this->deletingCategoryName = $target->name;
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
     * Ordered `name ASC, id ASC`: a small backoffice lookup table, so no pagination, search or
     * sort picker. `Gate::allows()`, never `Gate::authorize()`, which would throw while rendering
     * a list.
     *
     * The count says `withTrashed()` -- the SAME scope DeleteBlogCategory's own guard counts with
     * (0061 D-18). A bare withCount('posts') would apply BlogPost's SoftDeletingScope and
     * undercount, so a row could read "2 posts" while the refusal cites 3. A count rendered beside
     * a guarded action is part of that guard's contract, not decoration (D-5).
     */
    private function loadCategories(): void
    {
        $this->categories = BlogCategory::query()
            ->withCount(['posts' => fn ($query) => $query->withTrashed()])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (BlogCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'postCount' => (int) $category->posts_count,
                'canEdit' => Gate::allows('update', $category),
                'canDelete' => Gate::allows('delete', $category),
            ])
            ->all();
    }
}
