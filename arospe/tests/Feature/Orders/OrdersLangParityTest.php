<?php

use Illuminate\Support\Arr;

// Story 0055 amendment 6 -- no generic en/es parity test existed for lang/*/orders.php (only the
// topbar one did), and this story appends four key groups to a file five sibling stories also write.

test('lang/en/orders.php and lang/es/orders.php carry exactly the same keys', function () {
    $en = array_keys(Arr::dot(require lang_path('en/orders.php')));
    $es = array_keys(Arr::dot(require lang_path('es/orders.php')));

    expect(array_values(array_diff($en, $es)))->toBe([])
        ->and(array_values(array_diff($es, $en)))->toBe([]);
});

test('the screen-copy groups this story owns exist and no shipped group was renamed or removed', function () {
    $keys = array_keys(Arr::dot(require lang_path('en/orders.php')));

    foreach (['statuses.pending', 'payment_statuses.paid', 'transitions.same_status', 'cancellation.blocked', 'flag_reasons.mixed_basket', 'refunds.invalid_payment_state', 'errors.order_not_editable'] as $shipped) {
        expect($keys)->toContain($shipped);
    }

    foreach (['index.summary', 'index.empty', 'index.flagged', 'index.flagged_generic', 'detail.back_to_list', 'detail.tax_unresolved', 'detail.action_not_allowed'] as $owned) {
        expect($keys)->toContain($owned);
    }
});
