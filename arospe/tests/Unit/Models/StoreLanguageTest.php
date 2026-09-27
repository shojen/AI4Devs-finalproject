<?php

// Story 0068 -- App\Models\StoreLanguage's pure, DB-free surface: the UUID key shape, the
// zero-fillable-columns guard (D15, the same #[Fillable([])] shape App\Models\GeographyEntry
// already carries), the boolean casts, and translationUsageCount() against the shipped EMPTY
// config/store-languages.php registry (D8) -- a registered-relation count that reads a real
// table is a Feature test instead (tests/Feature/StoreLanguages/TranslationUsageCountTest.php),
// since that one needs the database.
//
// uses(TestCase::class), no RefreshDatabase: translationUsageCount() reads config(), which needs
// a booted Application container -- tests/Unit is NOT bound to Tests\TestCase in tests/Pest.php
// (RefreshDatabase is scoped to Feature/Browser only), matching
// tests/Unit/Actions/Media/GenerateImageConversionsTest.php's own per-file opt-in.

use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

test('the store language model reports a non-incrementing string key type', function () {
    $storeLanguage = new StoreLanguage;

    expect($storeLanguage->getKeyType())->toBe('string')
        ->and($storeLanguage->getIncrementing())->toBeFalse();
});

test('is_default casts to a real bool, not 0/1', function () {
    $storeLanguage = new StoreLanguage;
    $storeLanguage->setRawAttributes(['is_default' => 1], true);

    expect($storeLanguage->is_default)->toBeTrue()->toBeBool();

    $storeLanguage->setRawAttributes(['is_default' => 0], true);

    expect($storeLanguage->is_default)->toBeFalse()->toBeBool();
});

test('is_active casts to a real bool, not 0/1', function () {
    $storeLanguage = new StoreLanguage;
    $storeLanguage->setRawAttributes(['is_active' => 1], true);

    expect($storeLanguage->is_active)->toBeTrue()->toBeBool();

    $storeLanguage->setRawAttributes(['is_active' => 0], true);

    expect($storeLanguage->is_active)->toBeFalse()->toBeBool();
});

// D15 -- #[Fillable([])]: zero of five columns mass-assignable. A caller cannot supply a name,
// a code, or either flag through fill(); AddStoreLanguage/RemoveStoreLanguage/
// SetDefaultStoreLanguage must all write with forceFill()/forceCreate() instead.
//
// Phase 3 correction: the original assertion here (isDirty() stays false, the column stays null)
// assumed fill() silently discards a non-fillable attribute. Verified by execution against the
// installed framework instead of assumed: Eloquent's Model::fill() calls totallyGuarded(), which
// is true whenever $fillable is empty AND $guarded is its class default (['*']) -- exactly what
// #[Fillable([])] produces here, with no override to $guarded. In that combination fill() THROWS
// Illuminate\Database\Eloquent\MassAssignmentException unconditionally, regardless of Laravel's
// "prevent silently discarding attributes" opt-in flag -- it is not read at all on this branch.
// This is arguably a STRONGER guarantee than a silent no-op (a caller cannot even accidentally
// half-populate the model without an immediate, loud failure), and it is the real, uniform
// behavior of every #[Fillable([])] model in this codebase, including the existing
// App\Models\GeographyEntry this story cites as precedent -- not a defect specific to
// StoreLanguage's own implementation.

test('code is not mass-assignable', function () {
    $storeLanguage = new StoreLanguage;

    expect(fn () => $storeLanguage->fill(['code' => 'fr']))
        ->toThrow(MassAssignmentException::class);
});

test('name is not mass-assignable', function () {
    $storeLanguage = new StoreLanguage;

    expect(fn () => $storeLanguage->fill(['name' => 'Invented Language']))
        ->toThrow(MassAssignmentException::class);
});

test('is_default is not mass-assignable', function () {
    $storeLanguage = new StoreLanguage;

    expect(fn () => $storeLanguage->fill(['is_default' => true]))
        ->toThrow(MassAssignmentException::class);
});

test('is_active is not mass-assignable', function () {
    $storeLanguage = new StoreLanguage;

    expect(fn () => $storeLanguage->fill(['is_active' => true]))
        ->toThrow(MassAssignmentException::class);
});

test('id is not mass-assignable', function () {
    $storeLanguage = new StoreLanguage;

    expect(fn () => $storeLanguage->fill(['id' => (string) Str::uuid()]))
        ->toThrow(MassAssignmentException::class);
});

// D8/R-7 -- the extension point, against the shipped EMPTY translation_relations registry. This
// specific case needs no database at all: an empty registry means the summing loop never runs a
// query. The mandatory, non-vacuous counterpart (a real registered relation returning a real
// count) is a Feature test -- a negative assertion against an empty registry alone is exactly
// the vacuous-coverage shape the task file's own R-7 warns against, so it must never stand alone.
test('translationUsageCount returns 0 against the shipped empty registry', function () {
    config(['store-languages.translation_relations' => []]);

    expect(StoreLanguage::translationUsageCount((string) Str::uuid()))->toBe(0);
});
