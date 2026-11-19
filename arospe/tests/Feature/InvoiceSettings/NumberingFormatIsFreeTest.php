<?php

// Story 0101b (FD-16, FD-19; T-33, T-34) -- SaveInvoiceSettings refuses a numbering format whose
// next number already exists in invoices.number, with the error on that series' prefix. Invoice rows
// are built by the factory with an explicit number: no issuing action is needed.

use App\Actions\Invoices\AssertNumberingFormatIsFree;
use App\Actions\Invoices\SaveInvoiceSettings;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    InvoiceSettingsPayload::actingManager();
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
});

/**
 * The saved row the tests start from: standard prefix G / YYYY / "-" / 5, corrective COR / YYYY / "-" / 4.
 *
 * @param  array<string, mixed>  $overrides
 */
function numberingFreeCurrentSettings(array $overrides = []): InvoiceSetting
{
    return InvoiceSetting::factory()->withIssuer()->create(array_replace([
        'standard_prefix' => 'G',
        'standard_year_format' => 'YYYY',
        'standard_separator' => '-',
        'standard_padding' => 5,
        'corrective_prefix' => 'COR',
        'corrective_year_format' => 'YYYY',
        'corrective_separator' => '-',
        'corrective_padding' => 4,
        'reset_numbering_yearly' => true,
    ], $overrides));
}

/**
 * A payload that keeps the row's format except for the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function numberingFreePayload(array $overrides = []): array
{
    return InvoiceSettingsPayload::valid(array_replace([
        'standard_prefix' => 'G',
        'standard_year_format' => 'YYYY',
        'standard_separator' => '-',
        'standard_padding' => 5,
        'corrective_prefix' => 'COR',
        'corrective_year_format' => 'YYYY',
        'corrective_separator' => '-',
        'corrective_padding' => 4,
    ], $overrides));
}

/**
 * @return array<string, array<int, string>>
 */
function numberingFreeErrors(array $payload): array
{
    try {
        app(SaveInvoiceSettings::class)($payload);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

// T-33
test('going back to a standard prefix whose next number already exists is refused on standard_prefix and the row stays (T-33)', function () {
    numberingFreeCurrentSettings();
    Invoice::factory()->standard()->create(['number' => 'F2026-00001']);
    $before = InvoiceSetting::query()->findOrFail(1)->attributesToArray();

    $errors = numberingFreeErrors(numberingFreePayload(['standard_prefix' => 'F']));

    expect(array_keys($errors))->toBe(['standard_prefix'])
        ->and($errors['standard_prefix'])->toBe([__('invoice-settings.numbering.next_number_exists')])
        ->and(InvoiceSetting::query()->findOrFail(1)->attributesToArray())->toBe($before);
});

test('a corrective format whose next number equals an existing invoice number is refused on corrective_prefix (T-33)', function () {
    numberingFreeCurrentSettings(['corrective_prefix' => 'ZZ']);
    Invoice::factory()->standard()->create(['number' => 'COR2026-0001']);

    $errors = numberingFreeErrors(numberingFreePayload(['corrective_prefix' => 'COR']));

    expect(array_keys($errors))->toBe(['corrective_prefix'])
        ->and($errors['corrective_prefix'])->toBe([__('invoice-settings.numbering.next_number_exists')])
        ->and(InvoiceSetting::query()->findOrFail(1)->corrective_prefix)->toBe('ZZ');
});

test('changing the year token to one that recomposes an existing number is refused on the prefix (T-33)', function () {
    numberingFreeCurrentSettings(['standard_prefix' => 'F', 'standard_year_format' => 'YY', 'standard_separator' => '-']);
    Invoice::factory()->standard()->create(['number' => 'F2026-00001']);

    $errors = numberingFreeErrors(numberingFreePayload(['standard_prefix' => 'F', 'standard_year_format' => 'YYYY']));

    expect(array_keys($errors))->toBe(['standard_prefix'])
        ->and(InvoiceSetting::query()->findOrFail(1)->standard_year_format->value)->toBe('YY');
});

test('a refused save moves no counter and writes no invoice (T-33)', function () {
    numberingFreeCurrentSettings();
    Invoice::factory()->standard()->create(['number' => 'F2026-00001']);
    $sequences = Numbering::snapshot();
    $invoiceCount = Invoice::query()->count();

    numberingFreeErrors(numberingFreePayload(['standard_prefix' => 'F']));

    expect(Numbering::snapshot())->toBe($sequences)
        ->and(Invoice::query()->count())->toBe($invoiceCount);
});

test('a format whose next number is free saves and changes the row (T-33)', function () {
    numberingFreeCurrentSettings();
    Invoice::factory()->standard()->create(['number' => 'F2026-00001']);

    $errors = numberingFreeErrors(numberingFreePayload(['standard_prefix' => 'NEW']));

    expect($errors)->toBe([])
        ->and(InvoiceSetting::query()->findOrFail(1)->standard_prefix)->toBe('NEW');
});

test('an existing number below the next counter does not block the format (T-33)', function () {
    numberingFreeCurrentSettings();
    Numbering::arrange(InvoiceType::Standard, 5, 2026);
    Invoice::factory()->standard()->create(['number' => 'F2026-00001']);

    $errors = numberingFreeErrors(numberingFreePayload(['standard_prefix' => 'F']));

    expect($errors)->toBe([])
        ->and(InvoiceSetting::query()->findOrFail(1)->standard_prefix)->toBe('F');
});

test('a save on an empty invoices table is never blocked by the check (T-33)', function () {
    numberingFreeCurrentSettings();

    expect(numberingFreeErrors(numberingFreePayload(['standard_prefix' => 'F'])))->toBe([])
        ->and(Invoice::query()->count())->toBe(0);
});

// T-34
test('a next number equal to an existing one ignoring case is refused (T-34)', function (string $existing, string $prefix) {
    numberingFreeCurrentSettings();
    Invoice::factory()->standard()->create(['number' => $existing]);

    $errors = numberingFreeErrors(numberingFreePayload(['standard_prefix' => $prefix]));

    expect(array_keys($errors))->toBe(['standard_prefix']);
})->with([
    'existing lower, proposed upper' => ['f2026-00001', 'F'],
    'existing upper, proposed lower' => ['F2026-00001', 'f'],
]);

// FD-16 -- the action itself, as SaveInvoiceSettings calls it.
test('AssertNumberingFormatIsFree throws on <series>_prefix for the proposed model and passes when free (FD-16, FD-19)', function () {
    $current = numberingFreeCurrentSettings()->fresh();
    Invoice::factory()->standard()->create(['number' => 'F2026-00001']);
    $assert = app(AssertNumberingFormatIsFree::class);

    $colliding = $current->replicate()->forceFill(['standard_prefix' => 'F']);
    $free = $current->replicate()->forceFill(['standard_prefix' => 'OTHER']);

    expect(fn () => $assert($colliding, now()))->toThrow(ValidationException::class)
        ->and(fn () => $assert($free, now()))->not->toThrow(ValidationException::class);

    try {
        $assert($colliding, now());
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['standard_prefix'])
            ->and($exception->errors()['standard_prefix'])->toBe([__('invoice-settings.numbering.next_number_exists')]);
    }
});

test('AssertNumberingFormatIsFree uses the issue moment it is given to compose the year (FD-16)', function () {
    $current = numberingFreeCurrentSettings()->fresh();
    Invoice::factory()->standard()->create(['number' => 'F2027-00001']);
    $assert = app(AssertNumberingFormatIsFree::class);
    $proposed = $current->replicate()->forceFill(['standard_prefix' => 'F']);

    expect(fn () => $assert($proposed, Numbering::at('2026-06-15 10:00:00')))->not->toThrow(ValidationException::class);
    // With the yearly reset on and no previous allocation the counter is 1 in any year.
    expect(fn () => $assert($proposed, Numbering::at('2027-02-01 10:00:00')))->toThrow(ValidationException::class);
});

test('the refusal message exists in the English translation file (FD-19)', function () {
    expect(__('invoice-settings.numbering.next_number_exists'))->not->toBe('invoice-settings.numbering.next_number_exists');
});
