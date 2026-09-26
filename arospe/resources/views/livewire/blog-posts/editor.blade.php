<?php
/**
 * View for App\Livewire\BlogPosts\Editor (story 0063, layer 2), the routed post create/edit form.
 *
 * Three things here are load-bearing and easy to "tidy" into a bug:
 *  - The WYSIWYG is embedded ONCE, at the top level of its own block, with a stable wire:key and NO
 *    conditional around it: a conditional that re-evaluated mid-edit would remount the editor and
 *    lose unsaved content (D-1). This view embeds no media gallery of its own -- the WYSIWYG mounts
 *    the one it needs (0021 D4) and a second embed would collide with it (D-14).
 *  - The publication-date field is ALWAYS in the DOM and revealed client-side by Alpine's x-show on
 *    the status the browser already holds (D-7); the server stays the authority on whether a date is
 *    accepted. Never turn this into a server-side conditional.
 *  - The chip list renders EVERY tag the post holds -- never filtered, truncated or paginated (see
 *    the component's docblock: a hidden tag is a tag a save silently revokes).
 *
 * Flux/Blaze traps (docs/errors-log.md): no bare directive call inside a `flux:` tag's attribute
 * list; at most one @js() per wire:* attribute value; a Flux field renders its own validation error
 * only when :label / :description sit directly on it, so those fields get no separate error element,
 * while the two non-Flux fields (body, tags) get an explicit one; a disabled control gets its
 * tooltip from a hand-written branch, never a conditionally bound `tooltip` prop.
 */
?>
<div class="w-full max-w-5xl" data-test="blog-post-editor">
    <x-slot:heading>{{ $blogPostId === null ? __('blog-posts.editor.title_create') : __('blog-posts.editor.title_edit') }}</x-slot:heading>
    <x-slot:subheading>{{ __('topbar.blog_post_editor.subtitle') }}</x-slot:subheading>

    <div class="mt-6 max-w-3xl space-y-6">
        <flux:input
            wire:model="title"
            data-test="blog-post-title"
            maxlength="{{ \App\Models\BlogPost::TITLE_MAX_LENGTH }}"
            :label="__('blog-posts.editor.title_label')"
            required
        />

        <div>
            @if (count($this->categoryOptions) === 0)
                <flux:callout
                    icon="exclamation-triangle"
                    variant="warning"
                    class="mb-4"
                    data-test="blog-post-no-categories"
                >
                    <flux:callout.heading>{{ __('blog-posts.editor.no_categories_heading') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('blog-posts.editor.no_categories_text') }}
                        <flux:callout.link :href="route('blog-categories.index')" wire:navigate>{{ __('blog-posts.editor.no_categories_link') }}</flux:callout.link>
                    </flux:callout.text>
                </flux:callout>
            @endif

            {{-- The placeholder MUST be genuinely disabled AND selected, value="": blog_category_id is
            NOT NULL and there is no "none" option, so "no category" is a transient state that can
            never be persisted. Flux's own :placeholder renders exactly
            `<option value="" disabled selected>`. --}}
            <flux:select
                wire:model="blogCategoryId"
                data-test="blog-post-category"
                :label="__('blog-posts.editor.category_label')"
                :placeholder="__('blog-posts.editor.category_placeholder')"
            >
                @foreach ($this->categoryOptions as $category)
                    <flux:select.option value="{{ $category['id'] }}">{{ $category['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
            {{-- Fed from the PERSISTED enum's cases, labelled through label(); no placeholder, the
            status always has a real value. --}}
            <flux:select wire:model="status" data-test="blog-post-status" :label="__('blog-posts.editor.status_label')">
                @foreach ($this->statusOptions as $statusCase)
                    <flux:select.option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div
                data-test="blog-post-published-at"
                x-cloak
                x-show="$wire.status === '{{ \App\Enums\BlogPostStatus::Scheduled->value }}'"
            >
                <flux:input
                    type="datetime-local"
                    step="1"
                    wire:model="publishedAt"
                    data-test="blog-post-published-at-input"
                    :label="__('blog-posts.editor.published_at_label')"
                    :description="__('blog-posts.editor.published_at_hint')"
                />
            </div>
        </div>

        <div>
            <livewire:components.wysiwyg-editor
                wire:model="body"
                wire:key="blog-post-body-editor"
                :label="__('blog-posts.editor.body_label')"
            />

            <flux:error name="body" />
        </div>

        {{-- The tag chip field. Chips for EVERY tag; the typed input drives debounced suggestions. --}}
        <div data-test="blog-post-tag-field">
            <flux:label>{{ __('blog-posts.editor.tags_label') }}</flux:label>
            <flux:description>{{ __('blog-posts.editor.tags_hint') }}</flux:description>

            <div class="mt-2 flex flex-wrap gap-2" data-test="blog-post-tag-chips">
                @foreach ($tagNames as $tagName)
                    <span
                        wire:key="tag-chip-{{ $tagName }}"
                        data-test="tag-chip-{{ $tagName }}"
                        class="inline-flex items-center gap-1 rounded-full bg-zinc-100 py-1 pl-3 pr-1 text-sm text-zinc-800 dark:bg-zinc-700 dark:text-white"
                    >
                        {{ $tagName }}
                        <button
                            type="button"
                            data-test="tag-chip-remove-{{ $tagName }}"
                            aria-label="{{ __('blog-posts.editor.tag_remove_aria', ['name' => $tagName]) }}"
                            class="rounded-full p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-800 dark:hover:bg-zinc-600 dark:hover:text-white"
                            wire:click="removeTag(@js($tagName))"
                        >
                            <flux:icon.x-mark variant="micro" />
                        </button>
                    </span>
                @endforeach
            </div>

            <div class="mt-3 flex items-start gap-2">
                <div class="flex-1">
                    <flux:input
                        wire:model.live.debounce.300ms="tagInput"
                        wire:keydown.enter.prevent="addTypedTag"
                        data-test="blog-post-tag-input"
                        maxlength="{{ \App\Models\BlogTag::NAME_MAX_LENGTH }}"
                        autocomplete="off"
                        :placeholder="__('blog-posts.editor.tag_input_placeholder')"
                    />
                </div>

                @if (trim($tagInput) !== '')
                    @if ($this->tagNameIsNew && ! $this->canCreateTags)
                        {{-- A hint over the server's own refusal: existing tags stay attachable from the
                        suggestions below; only minting a new one is unavailable. --}}
                        <flux:tooltip :content="__('blog-posts.editor.tag_create_not_allowed')" class="cursor-not-allowed!">
                            <flux:button data-test="blog-post-tag-add" disabled>{{ __('blog-posts.editor.tag_add') }}</flux:button>
                        </flux:tooltip>
                    @else
                        <flux:button data-test="blog-post-tag-add" wire:click="addTypedTag">{{ __('blog-posts.editor.tag_add') }}</flux:button>
                    @endif
                @endif
            </div>

            @if (count($this->tagSuggestions) > 0)
                <ul
                    class="mt-2 divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700"
                    aria-label="{{ __('blog-posts.editor.tag_suggestions_label') }}"
                    data-test="blog-post-tag-suggestions"
                >
                    @foreach ($this->tagSuggestions as $suggestion)
                        <li wire:key="tag-suggestion-{{ $suggestion }}">
                            <button
                                type="button"
                                data-test="blog-post-tag-suggestion-{{ $suggestion }}"
                                class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                wire:click="addTag(@js($suggestion))"
                            >
                                {{ $suggestion }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <flux:error name="tagNames" />
        </div>

        <div class="flex items-center gap-3">
            <flux:button variant="primary" data-test="blog-post-save" wire:click="save">
                {{ __('blog-posts.editor.save') }}
            </flux:button>

            <flux:button variant="outline" data-test="blog-post-cancel" :href="route('blog-posts.index')" wire:navigate>
                {{ __('blog-posts.editor.cancel') }}
            </flux:button>
        </div>
    </div>
</div>
