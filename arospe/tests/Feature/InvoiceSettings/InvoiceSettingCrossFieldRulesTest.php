<?php

// Story 0100 -- FD-4 cross-field rules, judged on the state the save would produce, and the FD-13
// lang keys the refusals use.

use App\Actions\Invoices\SaveInvoiceSettings;
use App\Models\InvoiceSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    InvoiceSettingsPayload::actingManager();
});

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, array<int, string>>
 */
function crossFieldErrors(array $payload): array
{
    try {
        app(SaveInvoiceSettings::class)($payload);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('yearly reset on with year none is refused on that series year field (T-22)', function (string $series) {
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['standard_prefix' => 'KEEP']));

    $errors = crossFieldErrors(InvoiceSettingsPayload::valid([
        "{$series}_year_format" => 'none', 'reset_numbering_yearly' => true, 'standard_prefix' => 'NEWX',
    ]));

    expect(array_keys($errors))->toBe(["{$series}_year_format"])
        ->and(InvoiceSetting::current()->standard_prefix)->toBe('KEEP');
})->with(['standard', 'corrective']);

test('reset off accepts year none on either series (T-17)', function () {
    expect(crossFieldErrors(InvoiceSettingsPayload::valid([
        'reset_numbering_yearly' => false, 'standard_year_format' => 'none', 'corrective_year_format' => 'none',
    ])))->toBe([])
        ->and(InvoiceSetting::current()->reset_numbering_yearly)->toBeFalse();
});

test('prefixes that differ only by case are refused on the corrective prefix (T-18, T-23)', function (string $standard, string $corrective) {
    expect(array_keys(crossFieldErrors(InvoiceSettingsPayload::valid([
        'standard_prefix' => $standard, 'corrective_prefix' => $corrective,
    ]))))->toBe(['corrective_prefix']);
})->with([['F', 'F'], ['F', 'f'], ['', ''], ['Ab', 'aB']]);

test('different prefixes are accepted (T-18)', function () {
    expect(crossFieldErrors(InvoiceSettingsPayload::valid(['standard_prefix' => 'F', 'corrective_prefix' => 'FA'])))->toBe([]);
});

test('automatic invoicing on with a required issuer field missing is refused on auto_issue_on_order (T-24)', function (string $missing) {
    $errors = crossFieldErrors(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true, $missing => null]));

    expect(array_keys($errors))->toBe(['auto_issue_on_order'])
        ->and(InvoiceSetting::query()->count())->toBe(0);
})->with(['issuer_legal_name', 'issuer_tax_id', 'issuer_address_line1']);

test('automatic invoicing on with a whitespace-only issuer field is refused (T-24)', function () {
    expect(array_keys(crossFieldErrors(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true, 'issuer_legal_name' => '   ']))))
        ->toBe(['auto_issue_on_order']);
});

test('automatic invoicing on with complete issuer data is accepted, with city and country empty (T-24)', function () {
    expect(crossFieldErrors(InvoiceSettingsPayload::valid([
        'auto_issue_on_order' => true, 'issuer_city' => null, 'issuer_country' => null,
    ])))->toBe([])
        ->and(InvoiceSetting::current()->auto_issue_on_order)->toBeTrue();
});

test('blanking a required issuer field while automatic invoicing stays on is refused (T-19)', function (string $field) {
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true]));
    $before = InvoiceSetting::current()->getAttributes();

    $errors = crossFieldErrors(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true, $field => '']));

    expect(array_keys($errors))->toBe(['auto_issue_on_order'])
        ->and(InvoiceSetting::current()->getAttributes())->toBe($before);
})->with(['issuer_legal_name', 'issuer_tax_id', 'issuer_address_line1']);

test('blanking the issuer data is fine once automatic invoicing is switched off in the same save', function () {
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true]));

    expect(crossFieldErrors(InvoiceSettingsPayload::valid(['auto_issue_on_order' => false, 'issuer_tax_id' => ''])))->toBe([])
        ->and(InvoiceSetting::current()->issuer_tax_id)->toBeNull();
});

test('the three refusals use the FD-13 lang keys, in both locales (T-35)', function (string $locale) {
    app()->setLocale($locale);

    $year = crossFieldErrors(InvoiceSettingsPayload::valid(['corrective_year_format' => 'none']));
    $prefix = crossFieldErrors(InvoiceSettingsPayload::valid(['standard_prefix' => 'F', 'corrective_prefix' => 'f']));
    $auto = crossFieldErrors(InvoiceSettingsPayload::valid(['auto_issue_on_order' => true, 'issuer_tax_id' => null]));

    expect($year['corrective_year_format'])->toContain(__('invoice-settings.validation.year_required_when_reset'))
        ->and($prefix['corrective_prefix'])->toContain(__('invoice-settings.validation.prefixes_must_differ'))
        ->and($auto['auto_issue_on_order'])->toContain(__('invoice-settings.validation.auto_issue_requires_issuer'))
        ->and(__('invoice-settings.validation.year_required_when_reset'))->not->toBe('invoice-settings.validation.year_required_when_reset')
        ->and(__('invoice-settings.validation.prefixes_must_differ'))->not->toBe('invoice-settings.validation.prefixes_must_differ')
        ->and(__('invoice-settings.validation.auto_issue_requires_issuer'))->not->toBe('invoice-settings.validation.auto_issue_requires_issuer');
})->with(['en', 'es']);
