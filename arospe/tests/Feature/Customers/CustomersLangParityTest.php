<?php

use Illuminate\Support\Arr;

// Story 0100a (T-17d) -- adds the `attributes` block to lang/{en,es}/customers.php, so the two
// locales must keep carrying exactly the same keys (modelled on OrdersLangParityTest).

test('lang/en/customers.php and lang/es/customers.php carry exactly the same keys', function () {
    $en = array_keys(Arr::dot(require lang_path('en/customers.php')));
    $es = array_keys(Arr::dot(require lang_path('es/customers.php')));

    expect(array_values(array_diff($en, $es)))->toBe([])
        ->and(array_values(array_diff($es, $en)))->toBe([]);
});

test('the attributes.tax_id leaf exists in both locales as a non-empty translated string', function () {
    $en = Arr::get(require lang_path('en/customers.php'), 'attributes.tax_id');
    $es = Arr::get(require lang_path('es/customers.php'), 'attributes.tax_id');

    expect($en)->toBeString()->not->toBe('')
        ->and($es)->toBeString()->not->toBe('')
        ->and($es)->not->toBe($en);
});
