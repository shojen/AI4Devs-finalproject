<?php

use App\Actions\Blog\SetBlogCategoryTranslation;
use App\Actions\Blog\SetBlogPostTranslation;
use App\Actions\ProductCategories\SetProductCategoryTranslation;
use App\Actions\Translations\ResolveActiveStoreLanguage;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\BlogPost;
use App\Models\ProductCategory;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0087, QA cases 8, 9 and 12. The helper is the one place a store language is re-read as
// ACTIVE before a Set*Translation action writes into it. It authorizes nothing (its callers are
// already authorized), so it is called directly here, resolved from the container.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->german = StoreLanguage::factory()->create();
});

/**
 * @return array<string, array<int, string>>
 */
function resolveActiveLanguageErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// =====================================================================
// Case 12: the helper contract
// =====================================================================

test('an active language is returned as a fresh instance, never the object that was passed in', function () {
    $passed = StoreLanguage::query()->findOrFail($this->german->id);

    $resolved = app(ResolveActiveStoreLanguage::class)($passed, 'names.'.$passed->id, 'Refused');

    expect($resolved)->toBeInstanceOf(StoreLanguage::class)
        ->and($resolved->id)->toBe($passed->id)
        ->and($resolved)->not->toBe($passed);
});

test('an inactive language is refused with a ValidationException carrying exactly the given key and message', function () {
    $inactive = StoreLanguage::factory()->inactive()->create();

    $errors = resolveActiveLanguageErrors(
        fn () => app(ResolveActiveStoreLanguage::class)($inactive, 'any.key', 'The given message')
    );

    expect($errors)->toBe(['any.key' => ['The given message']]);
});

test('a language that was loaded active and deactivated afterwards is refused, the stale in-memory flag is not trusted', function () {
    $stale = StoreLanguage::query()->findOrFail($this->german->id);
    expect($stale->is_active)->toBeTrue();

    StoreLanguage::query()->whereKey($this->german->id)->update(['is_active' => false]);
    StoreLanguage::flushDefaultStoreLanguage();

    $errors = resolveActiveLanguageErrors(
        fn () => app(ResolveActiveStoreLanguage::class)($stale, 'translation', 'Stale refused')
    );

    expect($errors)->toBe(['translation' => ['Stale refused']]);
});

test('a language id that no longer exists is refused the same way as an inactive one', function () {
    $gone = StoreLanguage::query()->findOrFail($this->german->id);
    StoreLanguage::query()->whereKey($this->german->id)->delete();

    $errors = resolveActiveLanguageErrors(
        fn () => app(ResolveActiveStoreLanguage::class)($gone, 'names.'.$gone->id, 'Gone refused')
    );

    expect($errors)->toBe(['names.'.$gone->id => ['Gone refused']]);
});

test('the helper is not final, so a test double can extend it and stand in for it in the container', function () {
    expect((new ReflectionClass(ResolveActiveStoreLanguage::class))->isFinal())->toBeFalse();
});

// =====================================================================
// Case 8: the refusal message is localized and leaks no internal identifier
// =====================================================================

dataset('inactive language refusal messages', [
    'blog category en' => ['blog-category', 'en', 'This language is no longer active for the store.'],
    'blog category es' => ['blog-category', 'es', 'Este idioma ya no está activo en la tienda.'],
    'product category en' => ['product-category', 'en', 'This language is no longer active for the store.'],
    'product category es' => ['product-category', 'es', 'Este idioma ya no está activo en la tienda.'],
    'blog post en' => ['blog-post', 'en', "One of this post's languages is no longer active for the store. Reload the page and save again."],
    'blog post es' => ['blog-post', 'es', 'Uno de los idiomas de esta entrada ya no está activo en la tienda. Recarga la página y vuelve a guardar.'],
]);

test('the refusal message is the localized text, never the lang key, the error key or the language id', function (string $record, string $locale, string $expected) {
    app()->setLocale($locale);
    $inactive = StoreLanguage::factory()->inactive()->create();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    if ($record === 'blog-category') {
        $actor->givePermissionTo('blog.edit');
        $call = fn () => app(SetBlogCategoryTranslation::class)(BlogCategory::factory()->named('Guias')->create(), $inactive, 'Kategorie');
        $key = 'names.'.$inactive->id;
    } elseif ($record === 'product-category') {
        $actor->givePermissionTo('products.edit');
        $call = fn () => app(SetProductCategoryTranslation::class)(ProductCategory::factory()->named('Calzado')->create(), $inactive, 'Kategorie');
        $key = 'names.'.$inactive->id;
    } else {
        $actor->givePermissionTo('blog.edit');
        $call = fn () => app(SetBlogPostTranslation::class)(BlogPost::factory()->titled('Botas', '<p>x</p>')->create(), $inactive, 'Stiefel');
        $key = 'translation';
    }

    $errors = resolveActiveLanguageErrors($call);

    expect(array_keys($errors))->toBe([$key])
        ->and($errors[$key][0])->toBe($expected)
        ->and($errors[$key][0])->not->toContain('names.')
        ->and($errors[$key][0])->not->toContain('translation.')
        ->and($errors[$key][0])->not->toContain($inactive->id)
        ->and($errors[$key][0])->not->toContain('language_inactive');
})->with('inactive language refusal messages');

// =====================================================================
// Case 9: reading a removed language's stored translation is unchanged
// =====================================================================

test('translated() still returns the stored name of a language that was deactivated afterwards', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->german)->create([
        'blog_category_id' => $category->id,
        'name' => 'Alt',
    ]);

    StoreLanguage::query()->whereKey($this->german->id)->update(['is_active' => false]);
    StoreLanguage::flushDefaultStoreLanguage();

    expect($category->fresh()->translated('name', $this->german->id))->toBe('Alt');
});
