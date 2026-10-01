{{--
    Story 0083 D-5 -- the blog post status badge shared by the blog list and the dashboard. Takes the
    BlogPostStatus enum; attributes (e.g. the row's data-test hook) are forwarded to the badge.
--}}
@props(['status'])
<flux:badge
    {{ $attributes }}
    :color="match ($status) {
        \App\Enums\BlogPostStatus::Draft => 'zinc',
        \App\Enums\BlogPostStatus::Scheduled => 'amber',
        \App\Enums\BlogPostStatus::Published => 'lime',
    }"
>
    {{ $status->label() }}
</flux:badge>
