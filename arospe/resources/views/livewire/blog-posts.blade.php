<?php
/**
 * View for App\Livewire\BlogPosts\Index (story 0063). Flat path, not blog-posts/index.blade.php --
 * Livewire's Finder strips a trailing ".index" segment for an Index component in a subfolder, the
 * same exception App\Livewire\BlogTags\Index and BlogCategories\Index rely on; see
 * docs/conventions/naming/livewire-components-and-views.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 * (The editor's view, an ordinary mirror, lives at blog-posts/editor.blade.php.)
 *
 * Every wire:click here carries at most ONE @js() argument -- the shape roles.blade.php ships and the
 * shape that compiles correctly inside a `flux:` tag's attribute string. A multi-argument signature
 * would silently fail to compile there (docs/errors-log.md, 2026-08-26); do not add one "for
 * consistency". `deleteBlogPost` takes its target from `$deletingBlogPostId`, not an argument.
 *
 * There is deliberately NO force-delete control anywhere on this screen (0061 D-20): restore is the
 * only exit for a deleted post.
 */
?>
<div class="w-full">
    <x-slot:heading>{{ __('blog-posts.index.title') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.blog_posts.subtitle') }}</x-slot:subheading>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        {{-- Both filters are live: instant filtering is what a list filter is for. `wire:model.live`
        here, never a plain `wire:model` plus an Apply button. --}}
        <div class="flex flex-col gap-4 sm:flex-row">
            <flux:select wire:model.live="categoryFilter" data-test="category-filter" :label="__('blog-posts.index.filter_category_label')" class="sm:min-w-48">
                <flux:select.option value="">{{ __('blog-posts.index.filter_all_categories') }}</flux:select.option>
                @foreach ($this->categoryOptions as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="tagFilter" data-test="tag-filter" :label="__('blog-posts.index.filter_tag_label')" class="sm:min-w-48">
                <flux:select.option value="">{{ __('blog-posts.index.filter_all_tags') }}</flux:select.option>
                @foreach ($this->tagOptions as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- A UI hint from the same policy ability the editor authorizes against; a separate branch
        with a hand-written tooltip, never `:tooltip="$cond ? … : null"`, which under livewire/blaze
        renders an empty bubble on every enabled control. --}}
        @can('create', \App\Models\BlogPost::class)
            <flux:button
                variant="primary"
                icon="plus"
                data-test="create-blog-post-button"
                :href="route('blog-posts.create')"
                wire:navigate
            >
                {{ __('blog-posts.index.new') }}
            </flux:button>
        @else
            <flux:tooltip :content="__('blog-posts.index.action_not_allowed')" class="cursor-not-allowed!">
                <flux:button variant="primary" icon="plus" data-test="create-blog-post-button" disabled>
                    {{ __('blog-posts.index.new') }}
                </flux:button>
            </flux:tooltip>
        @endcan
    </div>

    <div class="mt-6">
        @if ($this->posts->total() > 0)
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('blog-posts.index.column_title') }}</flux:table.column>
                    <flux:table.column>{{ __('blog-posts.index.column_category') }}</flux:table.column>
                    <flux:table.column>{{ __('blog-posts.index.column_status') }}</flux:table.column>
                    <flux:table.column>{{ __('blog-posts.index.column_date') }}</flux:table.column>
                    <flux:table.column>{{ __('blog-posts.index.column_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->posts as $post)
                        <flux:table.row :key="$post['id']">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white" data-test="title-blog-post-{{ $post['id'] }}">{{ $post['title'] }}</div>

                                @if (count($post['tags']) > 0)
                                    <ul class="mt-1 flex flex-wrap gap-1" data-test="tags-blog-post-{{ $post['id'] }}">
                                        @foreach ($post['tags'] as $tagName)
                                            <li><flux:badge size="sm" color="zinc">{{ $tagName }}</flux:badge></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <span data-test="category-blog-post-{{ $post['id'] }}">{{ $post['categoryName'] }}</span>
                            </flux:table.cell>

                            {{-- Asserted through this row-scoped hook, never a page-global status word: on a blog
                            list almost every page contains some row with any given status. --}}
                            <flux:table.cell>
                                <flux:badge
                                    data-test="status-badge-blog-post-{{ $post['id'] }}"
                                    :color="match ($post['status']) {
                                        \App\Enums\BlogPostStatus::Draft => 'zinc',
                                        \App\Enums\BlogPostStatus::Scheduled => 'amber',
                                        \App\Enums\BlogPostStatus::Published => 'lime',
                                    }"
                                >
                                    {{ $post['status']->label() }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                <span data-test="date-blog-post-{{ $post['id'] }}">{{ $post['date'] }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    {{-- Icon-only actions keep the SAME data-test hook on both branches so a test
                                    selects one control whether or not it is enabled. `cursor-not-allowed!` sits on
                                    the tooltip wrapper, not the button: Flux's own `disabled:pointer-events-none`
                                    takes the button out of hit-testing. --}}
                                    @if ($post['canEdit'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            aria-label="{{ __('blog-posts.index.edit_aria', ['title' => $post['title']]) }}"
                                            data-test="edit-blog-post-{{ $post['id'] }}"
                                            :href="route('blog-posts.edit', $post['id'])"
                                            wire:navigate
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog-posts.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil-square"
                                                aria-label="{{ __('blog-posts.index.edit_aria', ['title' => $post['title']]) }}"
                                                data-test="edit-blog-post-{{ $post['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif

                                    @if ($post['canDelete'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            aria-label="{{ __('blog-posts.index.delete_aria', ['title' => $post['title']]) }}"
                                            data-test="delete-blog-post-{{ $post['id'] }}"
                                            wire:click="confirmDelete(@js($post['id']))"
                                            class="cursor-pointer! text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog-posts.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                aria-label="{{ __('blog-posts.index.delete_aria', ['title' => $post['title']]) }}"
                                                data-test="delete-blog-post-{{ $post['id'] }}"
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

            <div class="mt-4">
                {{ $this->posts->links() }}
            </div>
        @else
            <div class="p-8 text-center border rounded-lg border-zinc-200 dark:border-zinc-700" data-test="blog-posts-empty-state">
                <flux:text>{{ $categoryFilter !== '' || $tagFilter !== '' ? __('blog-posts.index.empty_filtered') : __('blog-posts.index.empty') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Deleted posts (0061 D-7d's exit): a small, rarely-touched block, rendered ONLY when trashed
    posts exist, closed on first paint and independent of the filters -- the Sales Regions "Show all
    countries" precedent. `open` lives on this single, un-looped element, which Livewire's morph
    preserves across a round trip, so the disclosure survives a restore. The panel is hidden
    server-side (`display: none`, which Alpine's x-show then takes over) so there is no flash of open
    content before Alpine boots. Restore only: there is no force-delete control (0061 D-20). --}}
    @if ($this->trashedPostsTotal > 0)
        <div class="mt-8" x-data="{ open: false }" data-test="trashed-posts-section">
            <flux:button
                variant="ghost"
                data-test="trashed-posts-toggle"
                @click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                class="cursor-pointer!"
            >
                <flux:icon name="chevron-down" variant="micro" class="size-4 transition-transform" x-bind:class="{ '-rotate-90': ! open }" />
                {{ __('blog-posts.index.trashed_heading') }} (<span data-test="trashed-posts-count">{{ $this->trashedPostsTotal }}</span>)
            </flux:button>

            <div x-show="open" style="display: none" class="mt-3" data-test="trashed-posts-list">
                @if ($this->trashedPostsTotal > count($this->trashedPosts))
                    <flux:text class="mb-2" data-test="trashed-posts-truncated">
                        {{ __('blog-posts.index.trashed_truncated', ['shown' => count($this->trashedPosts), 'total' => $this->trashedPostsTotal]) }}
                    </flux:text>
                @endif

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('blog-posts.index.column_title') }}</flux:table.column>
                        <flux:table.column>{{ __('blog-posts.index.column_category') }}</flux:table.column>
                        <flux:table.column>{{ __('blog-posts.index.trashed_column_deleted') }}</flux:table.column>
                        <flux:table.column>{{ __('blog-posts.index.column_actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->trashedPosts as $trashed)
                            <flux:table.row :key="$trashed['id']" data-test="trashed-blog-post-{{ $trashed['id'] }}">
                                <flux:table.cell>{{ $trashed['title'] }}</flux:table.cell>
                                <flux:table.cell>{{ $trashed['categoryName'] }}</flux:table.cell>
                                <flux:table.cell>{{ $trashed['deletedAt'] }}</flux:table.cell>
                                <flux:table.cell>
                                    {{-- Gated on `restore` (blog.edit), NOT `delete`: an actor holding blog.delete
                                    but not blog.edit sees this disabled. --}}
                                    @if ($trashed['canRestore'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrow-uturn-left"
                                            aria-label="{{ __('blog-posts.index.restore_aria', ['title' => $trashed['title']]) }}"
                                            data-test="restore-blog-post-{{ $trashed['id'] }}"
                                            wire:click="restoreBlogPost(@js($trashed['id']))"
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip :content="__('blog-posts.index.restore_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="arrow-uturn-left"
                                                aria-label="{{ __('blog-posts.index.restore_aria', ['title' => $trashed['title']]) }}"
                                                data-test="restore-blog-post-{{ $trashed['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    {{-- Delete confirmation modal. Its content is gated on $showDeleteModal so only one "Cancel"
    control is ever in the DOM. --}}
    <flux:modal name="delete-blog-post-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
        @if ($showDeleteModal)
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('blog-posts.index.delete_heading') }}</flux:heading>

                    <flux:text>
                        {{ __('blog-posts.index.delete_body', ['title' => $deletingBlogPostTitle]) }}
                    </flux:text>
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" data-test="cancel-delete-blog-post" wire:click="closeDeleteModal">
                        {{ __('blog-posts.index.cancel') }}
                    </flux:button>

                    <flux:button
                        variant="danger"
                        data-test="confirm-delete-blog-post"
                        wire:click="deleteBlogPost"
                        wire:loading.attr="disabled"
                        wire:target="deleteBlogPost"
                    >
                        {{ __('blog-posts.index.delete_confirm', ['title' => $deletingBlogPostTitle]) }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
