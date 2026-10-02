<x-dashboard.widget
    icon="newspaper"
    tint="indigo"
    widget="blog"
    :title="__('dashboard.blog.title')"
    :href="route('blog-posts.index')"
    :view-all="__('dashboard.blog.view_all')"
    :empty="$this->posts['rows'] === []"
    :empty-text="__('dashboard.blog.empty')"
>
    <ul class="flex flex-col gap-1">
        @foreach ($this->posts['rows'] as $row)
            <li class="flex items-start justify-between gap-3 rounded-xl px-3 py-3 transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-800/60" data-test="dashboard-blog-row-{{ $row['id'] }}">
                <div class="min-w-0">
                    @if ($this->posts['canEdit'])
                        <a href="{{ route('blog-posts.edit', $row['id']) }}" wire:navigate class="break-words font-medium hover:underline" data-test="dashboard-blog-title-{{ $row['id'] }}">{{ $row['title'] ?? __('dashboard.untitled') }}</a>
                    @else
                        <span class="break-words font-medium" data-test="dashboard-blog-title-{{ $row['id'] }}">{{ $row['title'] ?? __('dashboard.untitled') }}</span>
                    @endif

                    <p class="break-words text-sm text-neutral-500 dark:text-neutral-400" data-test="dashboard-blog-description-{{ $row['id'] }}">{{ $row['description'] }}</p>

                    @if ($row['publishAt'] !== null)
                        <p class="text-xs text-neutral-500 dark:text-neutral-400" data-test="dashboard-blog-date-{{ $row['id'] }}">{{ __('dashboard.blog.scheduled_for', ['date' => $row['publishAt']->setTimezone(config('app.timezone'))->format('d/m/Y H:i')]) }}</p>
                    @endif
                </div>

                <x-blog-status-badge :status="$row['status']" data-test="dashboard-blog-status-{{ $row['id'] }}" />
            </li>
        @endforeach
    </ul>
</x-dashboard.widget>
