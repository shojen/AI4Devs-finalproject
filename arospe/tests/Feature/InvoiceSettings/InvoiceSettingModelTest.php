<?php

// Story 0100 -- App\Models\InvoiceSetting: the singleton, current() defaults (FD-1), the factory
// and hasCompleteIssuerData().

use App\Enums\InvoiceNumberYear;
use App\Enums\InvoiceTemplate;
use App\Models\InvoiceSetting;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

const INVOICE_SETTING_DEFAULT_KEYS = [
    'template', 'standard_prefix', 'corrective_prefix', 'standard_year_format', 'corrective_year_format',
    'standard_separator', 'corrective_separator', 'standard_padding', 'corrective_padding',
    'reset_numbering_yearly', 'auto_issue_on_order',
];

test('current() with no row returns an unsaved instance carrying every documented default and writes nothing', function () {
    $settings = InvoiceSetting::current();

    expect($settings->exists)->toBeFalse()
        ->and($settings->id)->toBe(InvoiceSetting::SINGLETON_ID)
        ->and($settings->template)->toBe(InvoiceTemplate::Classic)
        ->and($settings->standard_prefix)->toBe('F')
        ->and($settings->corrective_prefix)->toBe('R')
        ->and($settings->standard_year_format)->toBe(InvoiceNumberYear::Full)
        ->and($settings->corrective_year_format)->toBe(InvoiceNumberYear::Full)
        ->and($settings->standard_separator)->toBe('-')
        ->and($settings->corrective_separator)->toBe('-')
        ->and($settings->standard_padding)->toBe(5)
        ->and($settings->corrective_padding)->toBe(5)
        ->and($settings->reset_numbering_yearly)->toBeTrue()
        ->and($settings->auto_issue_on_order)->toBeFalse()
        ->and($settings->logo_media_id)->toBeNull()
        ->and($settings->primary_color)->toBeNull()
        ->and($settings->accent_color)->toBeNull()
        ->and($settings->issuer_legal_name)->toBeNull()
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

test('current() with a row returns that row', function () {
    InvoiceSetting::factory()->create(['standard_prefix' => 'ZZ']);

    $settings = InvoiceSetting::current();

    expect($settings->exists)->toBeTrue()
        ->and($settings->standard_prefix)->toBe('ZZ');
});

test('the model default constants equal the migrated column defaults', function () {
    $defaults = InvoiceSetting::current();

    DB::table('invoice_settings')->insert(['id' => InvoiceSetting::SINGLETON_ID]);
    $persisted = InvoiceSetting::query()->findOrFail(InvoiceSetting::SINGLETON_ID);

    foreach (INVOICE_SETTING_DEFAULT_KEYS as $key) {
        expect($defaults->{$key})->toEqual($persisted->{$key}, "default of {$key} differs from its column default");
    }
});

test('the factory pins the singleton id, and the model is not mass-assignable', function () {
    $row = InvoiceSetting::factory()->create();

    expect($row->id)->toBe(InvoiceSetting::SINGLETON_ID)
        ->and($row->getFillable())->toBe([])
        ->and($row->getIncrementing())->toBeFalse();
});

test('hasCompleteIssuerData is false for empty issuer data and true with legal name, tax ID and address line 1 only', function () {
    expect(InvoiceSetting::factory()->create()->hasCompleteIssuerData())->toBeFalse();

    InvoiceSetting::query()->delete();
    $complete = InvoiceSetting::factory()->withIssuer()->create(['issuer_city' => null, 'issuer_country' => null]);

    expect($complete->hasCompleteIssuerData())->toBeTrue();
});

test('hasCompleteIssuerData is false when any one required field is empty', function (string $field) {
    $settings = InvoiceSetting::factory()->withIssuer()->create([$field => null]);

    expect($settings->hasCompleteIssuerData())->toBeFalse();
})->with(['issuer_legal_name', 'issuer_tax_id', 'issuer_address_line1']);

test('the autoIssue factory state switches automatic invoicing on', function () {
    expect(InvoiceSetting::factory()->withIssuer()->autoIssue()->create()->auto_issue_on_order)->toBeTrue();
});

test('deleting the logo media row keeps the settings row and nulls logo_media_id (T-20)', function () {
    $media = Media::factory()->create();
    $settings = InvoiceSetting::factory()->create(['logo_media_id' => $media->id]);

    expect($settings->logo->is($media))->toBeTrue();

    $media->delete();

    expect(InvoiceSetting::query()->count())->toBe(1)
        ->and($settings->fresh()->logo_media_id)->toBeNull();
});
