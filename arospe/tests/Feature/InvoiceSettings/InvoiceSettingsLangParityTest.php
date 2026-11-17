<?php

// Story 0100 (T-34) -- lang/{en,es}/invoice-settings.php and roles.modules.invoice_settings.

use Illuminate\Support\Arr;

test('lang/en/invoice-settings.php and lang/es/invoice-settings.php carry exactly the same keys', function () {
    $en = array_keys(Arr::dot(require lang_path('en/invoice-settings.php')));
    $es = array_keys(Arr::dot(require lang_path('es/invoice-settings.php')));

    expect($en)->not->toBeEmpty()
        ->and(array_values(array_diff($en, $es)))->toBe([])
        ->and(array_values(array_diff($es, $en)))->toBe([]);
});

test('the FD-13 validation messages exist, are non-empty and differ between the two locales', function (string $key) {
    $en = Arr::get(require lang_path('en/invoice-settings.php'), $key);
    $es = Arr::get(require lang_path('es/invoice-settings.php'), $key);

    expect($en)->toBeString()->not->toBe('')
        ->and($es)->toBeString()->not->toBe('')
        ->and($es)->not->toBe($en);
})->with([
    'validation.year_required_when_reset',
    'validation.prefixes_must_differ',
    'validation.auto_issue_requires_issuer',
]);

test('every settings field has an attribute name in both locales', function (string $locale) {
    $messages = require lang_path($locale.'/invoice-settings.php');

    foreach (['issuer_legal_name', 'issuer_tax_id', 'standard_prefix', 'corrective_year_format', 'primary_color', 'auto_issue_on_order'] as $field) {
        expect(Arr::get($messages, "attributes.{$field}"))->toBeString()->not->toBe('');
    }
})->with(['en', 'es']);

test('the roles matrix has the invoice_settings module leaf in both locales', function (string $locale) {
    $modules = (require lang_path($locale.'/roles.php'))['modules'];

    expect($modules)->toHaveKey('invoice_settings')
        ->and($modules['invoice_settings'])->toBeString()->not->toBe('');
})->with(['en', 'es']);

test('lang/en/roles.php and lang/es/roles.php keep the same keys', function () {
    $en = array_keys(Arr::dot(require lang_path('en/roles.php')));
    $es = array_keys(Arr::dot(require lang_path('es/roles.php')));

    expect(array_values(array_diff($en, $es)))->toBe([])
        ->and(array_values(array_diff($es, $en)))->toBe([]);
});
