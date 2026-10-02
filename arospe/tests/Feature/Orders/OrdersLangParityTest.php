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

// Story 0084 (Phase 3 red step): the two refusal messages of MarkOrderAsPaid and the three
// OrderPaymentType labels. The refusals must NOT interpolate the order number -- an
// unauthorized-then-state-refused actor must learn nothing, and a static string cannot leak an
// identifier. The type labels are plain nouns and interpolate nothing either.
test('the orders.payment keys exist in both locales, are non-empty and interpolate nothing', function (string $locale, string $key) {
    $messages = require lang_path($locale.'/orders.php');
    $message = Arr::get($messages, $key);

    expect($message)->toBeString()->not->toBe('')
        ->and($message)->not->toContain(':');
})->with(function () {
    foreach (['en', 'es'] as $locale) {
        foreach (['payment.already_paid', 'payment.cancelled_blocked', 'payment.types.transfer', 'payment.types.card', 'payment.types.paypal'] as $key) {
            yield "$locale $key" => [$locale, $key];
        }
    }
});

test('the two orders.payment refusal messages differ within each locale', function (string $locale) {
    $messages = require lang_path($locale.'/orders.php');

    expect(Arr::get($messages, 'payment.already_paid'))->not->toBe(Arr::get($messages, 'payment.cancelled_blocked'));
})->with(['en', 'es']);

test('the three orders.payment.types labels differ from each other within each locale', function (string $locale) {
    $types = Arr::get(require lang_path($locale.'/orders.php'), 'payment.types');

    expect(array_keys($types))->toContain('transfer', 'card', 'paypal')
        ->and(array_unique(array_values($types)))->toHaveCount(count($types));
})->with(['en', 'es']);
