<?php

// Story 0103 (T-28) -- en/es parity of everything the invoice settings screen and its sample PDF translate.

use Illuminate\Support\Arr;

/**
 * The dotted `screen.*` leaves of a locale's invoice-settings file.
 *
 * @return array<string, mixed>
 */
function invoiceSettingsScreenLeaves(string $locale): array
{
    return array_filter(
        Arr::dot(require lang_path($locale.'/invoice-settings.php')),
        fn (mixed $value, string $key): bool => str_starts_with($key, 'screen.'),
        ARRAY_FILTER_USE_BOTH,
    );
}

test('the screen group of lang/en and lang/es carries the same non-empty leaves', function () {
    $en = invoiceSettingsScreenLeaves('en');
    $es = invoiceSettingsScreenLeaves('es');

    expect($en)->not->toBeEmpty()
        ->and(array_values(array_diff(array_keys($en), array_keys($es))))->toBe([])
        ->and(array_values(array_diff(array_keys($es), array_keys($en))))->toBe([])
        ->and(collect($en)->filter(fn ($value): bool => ! is_string($value) || $value === ''))->toBeEmpty()
        ->and(collect($es)->filter(fn ($value): bool => ! is_string($value) || $value === ''))->toBeEmpty();
});

test('every key the screen needs exists in both locales', function (string $key) {
    foreach (['en', 'es'] as $locale) {
        $value = Arr::get(require lang_path($locale.'/invoice-settings.php'), $key);

        expect($value)->toBeString()->not->toBe('');
    }
})->with([
    'screen.title', 'screen.sections.issuer', 'screen.sections.look', 'screen.sections.numbering', 'screen.sections.automation',
    'screen.series.standard', 'screen.series.corrective', 'screen.next_number', 'screen.next_number_invalid',
    'screen.reset_yearly', 'screen.auto_issue', 'screen.auto_issue_hint', 'screen.no_logo', 'screen.use_template_default',
    'screen.preview_pdf', 'screen.start.button', 'screen.start.locked', 'screen.start.warning', 'screen.start.heading',
    'screen.start.confirm', 'screen.start.input', 'screen.readonly_notice', 'screen.saved', 'screen.start_saved',
    'screen.choose_logo', 'screen.remove_logo', 'screen.numbering_refused',
    'screen.fields.legal_name', 'screen.fields.tax_id', 'screen.fields.address_line1', 'screen.fields.address_line2',
    'screen.fields.city', 'screen.fields.postal_code', 'screen.fields.province', 'screen.fields.country',
    'screen.fields.logo', 'screen.fields.primary_color', 'screen.fields.accent_color', 'screen.fields.template',
    'screen.fields.prefix', 'screen.fields.year', 'screen.fields.separator', 'screen.fields.digits',
    'screen.hints.tax_id', 'screen.hints.country', 'screen.hints.color', 'screen.hints.logo',
    'screen.year_options.none', 'screen.year_options.YYYY', 'screen.year_options.YY',
    'screen.templates.classic.name', 'screen.templates.classic.description',
    'screen.templates.modern.name', 'screen.templates.modern.description',
]);

test('the sidebar, topbar and sample PDF keys resolve in both locales without falling back to the key', function (string $key) {
    foreach (['en', 'es'] as $locale) {
        expect(trans($key, [], $locale))->toBeString()->not->toBe($key)->not->toBe('');
    }
})->with([
    'navigation.items.invoice_settings',
    'topbar.invoice_settings.subtitle',
    'invoices.pdf.sample_customer',
    'invoices.pdf.sample_item',
]);

test('the sidebar label and the sample strings differ between the locales', function (string $key) {
    expect(trans($key, [], 'es'))->not->toBe(trans($key, [], 'en'));
})->with([
    'navigation.items.invoice_settings',
    'topbar.invoice_settings.subtitle',
    'invoices.pdf.sample_customer',
    'invoices.pdf.sample_item',
]);

test('invoices.pdf.sample is still a plain string, not a group', function (string $locale) {
    expect(trans('invoices.pdf.sample', [], $locale))->toBeString()->not->toBe('invoices.pdf.sample');
    expect(Arr::get(require lang_path($locale.'/invoices.php'), 'pdf.sample'))->toBeString();
})->with(['en', 'es']);

test('lang/en/invoices.php and lang/es/invoices.php keep the same keys', function () {
    $en = array_keys(Arr::dot(require lang_path('en/invoices.php')));
    $es = array_keys(Arr::dot(require lang_path('es/invoices.php')));

    expect(array_values(array_diff($en, $es)))->toBe([])
        ->and(array_values(array_diff($es, $en)))->toBe([]);
});
