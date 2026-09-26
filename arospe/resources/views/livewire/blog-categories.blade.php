<?php
/**
 * View for App\Livewire\BlogCategories\Index (story 0062). Flat path, not blog-categories/index.blade.php --
 * Livewire's Finder strips a trailing ".index" segment for an Index component in a subfolder, the
 * same exception App\Livewire\SalesRegions\Index and BlogTags\Index rely on; see
 * docs/conventions/naming/livewire-components-and-views.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 *
 * Sits between its two siblings: the blog tags screen's single-field simplicity, plus the product
 * categories screen's count column and blocked-delete modal. Unlike blog tags, deleting is
 * HARD-BLOCKED while any post uses the category (0061 D-18), so the delete modal carries an error
 * outlet -- and unlike the product categories screen, NO control anywhere proceeds past the block.
 * Do not add a "delete anyway" affordance, and do not pre-disable the delete action on the post
 * count (D-12): the editor attempts the delete and reads the count in the refusal.
 *
 * Every wire:click here carries at most ONE @js() argument -- the shape roles.blade.php ships and
 * the shape that compiles correctly inside a `flux:` tag's attribute string. A multi-argument
 * signature would silently fail to compile there (docs/errors-log.md, 2026-08-26); do not add one
 * "for consistency". `deleteCategory` takes its target from `$blogCategoryId`, not an argument.
 */
?>
<div class="w-full">
    <x-slot:heading>{{ __('blog.categories.index.title') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.blog_categories.subtitle') }}</x-slot:subheading>

    <div class="flex justify-end">
        {{-- A UI hint from the same policy method openCreateModal() authorizes against; a separate
        branch with a hand-written tooltip, never `:tooltip="$cond ? … : null"`, which under
        livewire/blaze renders an empty bubble on every enabled control. --}}
        @can('create', \App\Models\BlogCategory::class)
            <flux:button
                variant="primary"
                icon="plus"
                data-test="create-blog-category-button"
                wire:click="openCreateModal"
                class="cursor-pointer!"
            >
                {{ __('blog.categories.index.new') }}
            </flux:button>
        @else
            <flux:tooltip :content="__('blog.categories.index.action_not_allowed')" class="cursor-not-allowed!">
                <flux:button variant="primary" icon="plus" data-test="create-blog-category-button" disabled>
                    {{ __('blog.categories.index.new') }}
                </flux:button>
            </flux:tooltip>
        @endcan
    </div>

    <div class="mt-6">
        @if (count($categories) > 0)
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('blog.categories.index.column_name') }}</flux:table.column>
                    <flux:table.column>{{ __('blog.categories.index.column_posts') }}</flux:table.column>
                    <flux:table.column>{{ __('blog.categories.index.column_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($categories as $category)
                        <flux:table.row :key="$category['id']">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $category['name'] }}</div>
                            </flux:table.cell>

                            {{-- Informational only, and counted with withTrashed() exactly like the delete
                            guard (D-5), so this number and the refusal's can never disagree. The hook wraps
                            the bare digits: a page-global assertSee('3') would match inside '13' or a decoy
                            row's own count. --}}
                            <flux:table.cell>
                                <span data-test="blog-category-post-count-{{ $category['id'] }}">{{ $category['postCount'] }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    {{-- Icon-only actions keep the SAME data-test hook on both branches so a
                                    test selects one control whether or not it is enabled. `cursor-not-allowed!`
                                    sits on the tooltip wrapper, not the button: Flux's own
                                    `disabled:pointer-events-none` takes the button out of hit-testing. --}}
                                    @if ($category['canEdit'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            aria-label="{{ __('blog.categories.index.edit_aria', ['name' => $category['name']]) }}"
                                            data-test="edit-blog-category-{{ $category['id'] }}"
                                            wire:click="openEditModal(@js($category['id']))"
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog.categories.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil-square"
                                                aria-label="{{ __('blog.categories.index.edit_aria', ['name' => $category['name']]) }}"
                                                data-test="edit-blog-category-{{ $category['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif

                                    {{-- Never disabled on the post count (D-12): only the authorization hint
                                    decides this branch. --}}
                                    @if ($category['canDelete'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            aria-label="{{ __('blog.categories.index.delete_aria', ['name' => $category['name']]) }}"
                                            data-test="delete-blog-category-{{ $category['id'] }}"
                                            wire:click="confirmDelete(@js($category['id']))"
                                            class="cursor-pointer! text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog.categories.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                aria-label="{{ __('blog.categories.index.delete_aria', ['name' => $category['name']]) }}"
                                                data-test="delete-blog-category-{{ $category['id'] }}"
                                                disabled
                                                class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                            />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="p-8 text-center border rounded-lg border-zinc-200 dark:border-zinc-700">
                <flux:text>{{ __('blog.categories.index.empty') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Create / edit modal. Its content is gated on $showModal so its "Cancel" never collides
    with another modal's, the pattern users/roles/sales-regions/blog-tags all use. --}}
    <flux:modal name="blog-category-modal" class="max-w-md md:min-w-md" @close="closeModal" wire:model="showModal">
        @if ($showModal)
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingCategoryId === null ? __('blog.categories.index.create_heading') : __('blog.categories.index.edit_heading') }}
                </flux:heading>

                <flux:input
                    wire:model="name"
                    data-test="blog-category-name-input"
                    :label="__('blog.categories.index.name_label')"
                    autofocus
                />

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeModal">
                        {{ __('blog.categories.index.cancel') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        {{ __('blog.categories.index.save') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Delete confirmation modal. The hard-block refusal renders HERE, inline, in the still-open
    modal (D-2): DeleteBlogCategory throws a ValidationException keyed `blogCategoryId` -- verbatim
    0061 D-18's hand-off contract, backed by the real declared public property so Livewire does not
    drop the error on the next round-trip (D-3) -- and the throw aborts deleteCategory() before
    closeDeleteModal() runs. The message is 0061's own trans_choice() text, never a string composed
    here. The one destructive button is the ordinary confirm: it is never disabled on the count
    (D-12) and there is no second, "proceed anyway" control. --}}
    <flux:modal name="delete-blog-category-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
        @if ($showDeleteModal)
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('blog.categories.index.delete_heading') }}</flux:heading>

                    <flux:text>
                        {{ __('blog.categories.index.delete_body', ['name' => $deletingCategoryName]) }}
                    </flux:text>
                </div>

                @error('blogCategoryId')
                    <flux:callout variant="danger" icon="x-circle" heading="{{ $message }}" data-test="blog-category-delete-blocked" />
                @enderror

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeDeleteModal">
                        {{ __('blog.categories.index.cancel') }}
                    </flux:button>

                    <flux:button
                        variant="danger"
                        data-test="confirm-delete-blog-category"
                        wire:click="deleteCategory"
                        wire:loading.attr="disabled"
                        wire:target="deleteCategory"
                    >
                        {{ __('blog.categories.index.delete_confirm', ['name' => $deletingCategoryName]) }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
