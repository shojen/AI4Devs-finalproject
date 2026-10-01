{{--
    Story 0083 D-5 -- the order status badge shared by the orders list and the dashboard. Takes the
    status string value. An unknown value must not throw (a status added to the enum before this map
    is updated): it renders a zinc badge with the escaped raw string.
--}}
@props(['status'])
@php($case = \App\Enums\OrderStatus::tryFrom($status))
<flux:badge
    {{ $attributes }}
    :color="match ($case) {
        \App\Enums\OrderStatus::Pending => 'zinc',
        \App\Enums\OrderStatus::Processing => 'blue',
        \App\Enums\OrderStatus::Shipped => 'amber',
        \App\Enums\OrderStatus::Delivered => 'lime',
        \App\Enums\OrderStatus::Cancelled => 'red',
        default => 'zinc',
    }"
>
    {{ $case?->label() ?? $status }}
</flux:badge>
