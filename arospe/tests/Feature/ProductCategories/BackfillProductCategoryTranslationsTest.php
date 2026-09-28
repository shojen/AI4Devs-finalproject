<?php

use App\Actions\ProductCategories\BackfillProductCategoryTranslations;
use App\Models\ProductCategory;
use App\Models\StoreLanguage;
use Illuminate\Support\Facades\DB;

// Story 0070 (D-16), the DATABASE-facing half of the backfill: assertCanBackfill()/__invoke(),
// exercised against the REAL, already-migrated schema -- both migrations have already run before
// every RefreshDatabase test body, so product_categories no longer carries a `name` column at all.
//
// The zero-categories cases below are expected to PASS already, since database-expert's shipped
// class already implements this branch correctly -- it is D-16's "the branch every fresh install
// and every RefreshDatabase test run takes", and the first draft of this story made it throw,
// which would have aborted the entire suite at migration time (Phase 2 finding 1). The
// "categories exist and no default store language does" cases are what this file exists to pin as
// a genuine, loud refusal that writes nothing.

test('with existing categories and no default store language, assertCanBackfill() refuses loudly and writes nothing', function () {
    ProductCategory::factory()->withoutTranslations()->create();

    // withoutTranslations() provisions no store language either -- confirmed here so a factory
    // regression fails on THIS line rather than making the refusal assertion below pass for the
    // wrong reason.
    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => app(BackfillProductCategoryTranslations::class)->assertCanBackfill())
        ->toThrow(RuntimeException::class);

    expect(DB::table('product_category_translations')->count())->toBe(0);
});

test('with existing categories and no default store language, __invoke() refuses loudly and writes nothing', function () {
    ProductCategory::factory()->withoutTranslations()->create();

    expect(fn () => app(BackfillProductCategoryTranslations::class)())
        ->toThrow(RuntimeException::class);

    expect(DB::table('product_category_translations')->count())->toBe(0);
});

// The most important backfill test (Phase 2 finding 1): every fresh install and every
// RefreshDatabase run takes exactly this branch.
test('with zero categories and no store language at all, assertCanBackfill() does not throw and __invoke() is a no-op returning 0', function () {
    expect(ProductCategory::query()->count())->toBe(0)
        ->and(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => app(BackfillProductCategoryTranslations::class)->assertCanBackfill())
        ->not->toThrow(Throwable::class);

    expect(app(BackfillProductCategoryTranslations::class)())->toBe(0);
    expect(DB::table('product_category_translations')->count())->toBe(0);
});

test('with zero categories and a default store language present, the backfill is a no-op returning 0', function () {
    StoreLanguage::factory()->default()->create();

    expect(ProductCategory::query()->count())->toBe(0);

    expect(app(BackfillProductCategoryTranslations::class)())->toBe(0);
    expect(DB::table('product_category_translations')->count())->toBe(0);
});
