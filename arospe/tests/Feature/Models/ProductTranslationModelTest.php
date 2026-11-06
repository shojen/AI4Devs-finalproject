<?php

use App\Actions\Products\SanitizeProductDescription;
use App\Actions\Translations\SetTranslation;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

// Story 0076 (D-8, D-10): ProductTranslation's second sanitizer layer (a `saving` hook on a dirty
// description), its mass-assignment guard, and its relations.

test('a description written through a bare SetTranslation call is persisted sanitized, with no product action in the path', function () {
    $language = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();

    app(SetTranslation::class)($product, $language, [
        'name' => 'Directo',
        'description' => '<p>kept</p><script>alert(1)</script>',
    ]);

    $stored = DB::table('product_translations')->where('product_id', $product->id)->value('description');

    expect($stored)->not->toContain('script')
        ->and($stored)->not->toContain('alert(1)')
        ->and($stored)->toContain('<p>kept</p>');
});

test('a description assigned directly on the model is sanitized on insert and again on update', function () {
    $translation = ProductTranslation::factory()->create(['description' => '<p>ok</p><script>x()</script>']);

    expect($translation->fresh()->description)->not->toContain('script');

    $translation->update(['description' => '<img src="javascript:alert(1)"><p>again</p><script>y()</script>']);

    expect($translation->fresh()->description)->not->toContain('script')
        ->and($translation->fresh()->description)->not->toContain('javascript:')
        ->and($translation->fresh()->description)->toContain('<p>again</p>');
});

test('a null description stays null through the hook', function () {
    $translation = ProductTranslation::factory()->create(['description' => null]);

    expect($translation->fresh()->description)->toBeNull();
});

test('sanitizing is idempotent across the action layer and the hook layer', function () {
    $sanitize = app(SanitizeProductDescription::class);
    $dirty = '<p onclick="x()">a <strong>b</strong></p><script>z()</script><a href="javascript:q()">l</a>';

    $once = $sanitize($dirty);

    expect($sanitize($once))->toBe($once);

    $translation = ProductTranslation::factory()->create(['description' => $once]);

    expect($translation->fresh()->description)->toBe($once);
});

test('saving without touching description does not rewrite it (the isDirty guard)', function () {
    $translation = ProductTranslation::factory()->create(['description' => '<p>clean</p>']);

    // A value no sanitizer would keep: if the hook re-sanitized on this unrelated save it would vanish.
    DB::table('product_translations')->where('id', $translation->id)->update(['description' => '<script>sentinel</script>']);

    $fresh = $translation->fresh();
    $fresh->name = 'Renamed only';
    $fresh->save();

    expect($fresh->fresh()->name)->toBe('Renamed only')
        ->and($fresh->fresh()->description)->toBe('<script>sentinel</script>');
});

test('the factory default description is plain text that needs no sanitizing', function () {
    $translation = ProductTranslation::factory()->create();

    expect($translation->description === null || app(SanitizeProductDescription::class)($translation->description) === $translation->description)->toBeTrue();
});

test('only name and description are fillable, and a forged product_id or store_language_id is ignored', function () {
    $product = Product::factory()->withoutTranslations()->create();
    $victim = Product::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();
    $otherLanguage = StoreLanguage::factory()->create();

    expect((new ProductTranslation)->getFillable())->toBe(['name', 'description']);

    $translation = new ProductTranslation([
        'name' => 'Forjado',
        'product_id' => $victim->id,
        'store_language_id' => $otherLanguage->id,
    ]);

    expect($translation->product_id)->toBeNull()
        ->and($translation->store_language_id)->toBeNull()
        ->and($translation->name)->toBe('Forjado');

    $translation->forceFill(['product_id' => $product->id, 'store_language_id' => $language->id])->save();

    expect($translation->fresh()->product_id)->toBe($product->id)
        ->and($translation->fresh()->store_language_id)->toBe($language->id);
});

test('a translation belongs to its product and its store language and is not soft-deletable', function () {
    $language = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();
    $translation = ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $product->id]);

    expect($translation->product())->toBeInstanceOf(BelongsTo::class)
        ->and($translation->product->id)->toBe($product->id)
        ->and($translation->storeLanguage())->toBeInstanceOf(BelongsTo::class)
        ->and($translation->storeLanguage->id)->toBe($language->id)
        ->and(class_uses_recursive(ProductTranslation::class))->not->toContain(SoftDeletes::class);
});
