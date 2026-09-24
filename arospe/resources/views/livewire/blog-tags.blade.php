<?php
/**
 * View for App\Livewire\BlogTags\Index (story 0060). Flat path, not blog-tags/index.blade.php --
 * Livewire's Finder strips a trailing ".index" segment for an Index component in a subfolder, the
 * same exception App\Livewire\SalesRegions\Index relies on; see
 * docs/conventions/naming/livewire-components-and-views.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 *
 * The simplest list screen in the repo: one data column, no status badge, no count column. Above
 * all there is NO blocked-delete state -- deleting a tag is unconditional (D-2), so the delete
 * modal below is a plain confirmation with no usage count, no conditional disabling and no error
 * outlet. Do not "complete" it from the product categories markup.
 *
 * Every wire:click here carries at most ONE @js() argument -- the shape roles.blade.php ships and
 * the shape that compiles correctly inside a `flux:` tag's attribute string. A multi-argument
 * signature would silently fail to compile there (docs/errors-log.md, 2026-08-26); do not add one
 * "for consistency".
 */
?>
<div class="w-full">
    <x-slot:heading>{{ __('blog-tags.index.title') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.blog_tags.subtitle') }}</x-slot:subheading>

    <div class="flex justify-end">
        {{-- A UI hint from the same policy method openCreateModal() authorizes against; a separate
        branch with a hand-written tooltip, never `:tooltip="$cond ? … : null"`, which under
        livewire/blaze renders an empty bubble on every enabled control. --}}
        @can('create', \App\Models\BlogTag::class)
            <flux:button
                variant="primary"
                icon="plus"
                data-test="create-blog-tag-button"
                wire:click="openCreateModal"
                class="cursor-pointer!"
            >
                {{ __('blog-tags.index.new') }}
            </flux:button>
        @else
            <flux:tooltip :content="__('blog-tags.index.action_not_allowed')" class="cursor-not-allowed!">
                <flux:button variant="primary" icon="plus" data-test="create-blog-tag-button" disabled>
                    {{ __('blog-tags.index.new') }}
                </flux:button>
            </flux:tooltip>
        @endcan
    </div>

    <div class="mt-6">
        @if (count($tags) > 0)
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('blog-tags.index.column_name') }}</flux:table.column>
                    <flux:table.column>{{ __('blog-tags.index.column_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($tags as $tag)
                        <flux:table.row :key="$tag['id']">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $tag['name'] }}</div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    {{-- Icon-only actions keep the SAME data-test hook on both branches so a
                                    test selects one control whether or not it is enabled. `cursor-not-allowed!`
                                    sits on the tooltip wrapper, not the button: Flux's own
                                    `disabled:pointer-events-none` takes the button out of hit-testing. --}}
                                    @if ($tag['canEdit'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            aria-label="{{ __('blog-tags.index.edit_aria', ['name' => $tag['name']]) }}"
                                            data-test="edit-blog-tag-{{ $tag['id'] }}"
                                            wire:click="openEditModal(@js($tag['id']))"
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog-tags.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil-square"
                                                aria-label="{{ __('blog-tags.index.edit_aria', ['name' => $tag['name']]) }}"
                                                data-test="edit-blog-tag-{{ $tag['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif

                                    @if ($tag['canDelete'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            aria-label="{{ __('blog-tags.index.delete_aria', ['name' => $tag['name']]) }}"
                                            data-test="delete-blog-tag-{{ $tag['id'] }}"
                                            wire:click="confirmDelete(@js($tag['id']))"
                                            class="cursor-pointer! text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog-tags.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                aria-label="{{ __('blog-tags.index.delete_aria', ['name' => $tag['name']]) }}"
                                                data-test="delete-blog-tag-{{ $tag['id'] }}"
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
                <flux:text>{{ __('blog-tags.index.empty') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Create / edit modal. Its content is gated on $showModal so its "Cancel" never collides
    with another modal's, the pattern users/roles/sales-regions all use. --}}
    <flux:modal name="blog-tag-modal" class="max-w-md md:min-w-md" @close="closeModal" wire:model="showModal">
        @if ($showModal)
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingTagId === null ? __('blog-tags.index.create_heading') : __('blog-tags.index.edit_heading') }}
                </flux:heading>

                <flux:input
                    wire:model="name"
                    data-test="blog-tag-name-input"
                    :label="__('blog-tags.index.name_label')"
                    autofocus
                />

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeModal">
                        {{ __('blog-tags.index.cancel') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        {{ __('blog-tags.index.save') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Delete confirmation modal: PLAIN, on purpose (D-2). It names the target and says what
    deleting means; it has no error outlet because deleteTag() has no refusal to render, and its
    destructive button is never conditionally disabled. --}}
    <flux:modal name="delete-blog-tag-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
        @if ($showDeleteModal)
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('blog-tags.index.delete_heading') }}</flux:heading>

                    <flux:text>
                        {{ __('blog-tags.index.delete_body', ['name' => $deletingTagName]) }}
                    </flux:text>
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeDeleteModal">
                        {{ __('blog-tags.index.cancel') }}
                    </flux:button>

                    <flux:button
                        variant="danger"
                        data-test="confirm-delete-blog-tag"
                        wire:click="deleteTag"
                        wire:loading.attr="disabled"
                        wire:target="deleteTag"
                    >
                        {{ __('blog-tags.index.delete_confirm', ['name' => $deletingTagName]) }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
