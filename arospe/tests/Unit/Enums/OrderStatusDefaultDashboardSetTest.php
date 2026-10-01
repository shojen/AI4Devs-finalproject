<?php

use App\Enums\OrderStatus;

// Story 0082 (D-6/D-8): the single place the dashboard's default status filter lives.
// OrderStatus::defaultDashboardSet() does not exist yet -- red until implemented.

test('the default dashboard set is every status except cancelled, in declaration order', function () {
    expect(OrderStatus::defaultDashboardSet())->toBe([
        OrderStatus::Pending,
        OrderStatus::Processing,
        OrderStatus::Shipped,
        OrderStatus::Delivered,
    ]);
});

test('the default dashboard set is a list that never contains cancelled', function () {
    $set = OrderStatus::defaultDashboardSet();

    expect(array_is_list($set))->toBeTrue()
        ->and($set)->not->toContain(OrderStatus::Cancelled)
        ->and(count($set))->toBe(count(OrderStatus::cases()) - 1);
});
