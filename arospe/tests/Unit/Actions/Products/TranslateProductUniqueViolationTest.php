<?php

use App\Actions\Products\TranslateProductUniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

// Story 0076a (D-31, R-22): every 1062 on the product indexes is attributed by INDEX NAME, matched
// on errorInfo[2] with the anchored /for key '(?:[^']+\.)?<index>'$/ pattern from
// docs/security/constraint-violation-discrimination.md -- never on getMessage(), which embeds the
// interpolated SQL and so the user's own bindings. Unit: no database; every QueryException is
// constructed with a hand-set errorInfo, following TranslateProductCategoryNameUniqueViolationTest.

/**
 * @param  array{0: string, 1: int, 2: string}|null  $errorInfo
 * @param  list<string>  $bindings
 */
function s76aQueryException(?array $errorInfo, array $bindings = []): QueryException
{
    $previous = new PDOException('constructed driver failure');

    if ($errorInfo !== null) {
        $previous->errorInfo = $errorInfo;
    }

    return new QueryException('mysql', 'insert into `product_translations` (`name`) values (?)', $bindings, $previous);
}

/**
 * @return array{0: string, 1: int, 2: string}
 */
function s76aDuplicate(string $key): array
{
    return ['23000', 1062, "Duplicate entry 'x' for key '".$key."'"];
}

test('a 1062 on the products sku index maps to the sku field only, with or without the table prefix', function (string $key) {
    $result = app(TranslateProductUniqueViolation::class)(s76aQueryException(s76aDuplicate($key)));

    expect($result)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($result->errors()))->toBe(['sku'])
        ->and($result->errors()['sku'][0])->toBe(trans('validation.unique', ['attribute' => 'sku']));
})->with([
    'prefixed' => ['products.products_sku_unique'],
    'bare' => ['products_sku_unique'],
]);

test('a 1062 on the per-language slug index maps to the slug field only, with or without the table prefix', function (string $key) {
    $result = app(TranslateProductUniqueViolation::class)(s76aQueryException(s76aDuplicate($key)));

    expect($result)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($result->errors()))->toBe(['slug'])
        ->and($result->errors()['slug'][0])->toBe(trans('validation.unique', ['attribute' => trans('products.attributes.slug')]));
})->with([
    'prefixed' => ['product_translations.product_translations_language_slug_unique'],
    'bare' => ['product_translations_language_slug_unique'],
]);

test('a 1062 on the natural-key index maps to the name field with the retry message, never sku or slug', function (string $key) {
    $result = app(TranslateProductUniqueViolation::class)(s76aQueryException(s76aDuplicate($key)));

    expect($result)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($result->errors()))->toBe(['name'])
        ->and($result->errors()['name'][0])->toBe(trans('products.editor.concurrent_save_retry'))
        ->and($result->errors()['name'][0])->not->toBe('products.editor.concurrent_save_retry');
})->with([
    'prefixed' => ['product_translations.product_translations_product_language_unique'],
    'bare' => ['product_translations_product_language_unique'],
]);

test('anything that is not a 1062 on a product index is rethrown as the same instance', function (QueryException $e) {
    $caught = null;

    try {
        app(TranslateProductUniqueViolation::class)($e);
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($e);
})->with([
    'bindings mention the slug index but errorInfo names another index' => fn () => s76aQueryException(
        s76aDuplicate('products.some_other_unique'),
        ['product_translations_language_slug_unique'],
    ),
    'index name appears mid-string, not before the closing quote' => fn () => s76aQueryException(
        ['23000', 1062, "Duplicate entry 'product_translations_language_slug_unique' for key 'products.some_other_unique'"],
    ),
    'index name is only a suffix of a longer bare name' => fn () => s76aQueryException(
        s76aDuplicate('xproducts_sku_unique'),
    ),
    'index name is followed by more text' => fn () => s76aQueryException(
        s76aDuplicate('products.products_sku_unique_old'),
    ),
    'product_variants sku index' => fn () => s76aQueryException(
        s76aDuplicate('product_variants.product_variants_sku_unique'),
    ),
    'product_variants combination hash index' => fn () => s76aQueryException(
        s76aDuplicate('product_variants.product_variants_product_id_combination_hash_unique'),
    ),
    'a 1452 foreign key violation naming a product index in its bindings' => fn () => s76aQueryException(
        ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails (`product_translations`, CONSTRAINT `product_translations_store_language_id_foreign`)'],
        ['products_sku_unique'],
    ),
    'a 1213 deadlock' => fn () => s76aQueryException(
        ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
    ),
    'no errorInfo at all' => fn () => s76aQueryException(null),
]);

// Story 0076b (D-4): an optional trailing language id re-keys the slug and natural-key indexes to
// the nested "{field}s.{id}" form used by SetProductTranslation; the SKU branch and the default
// (no language id) mapping stay as they were.

test('with a language id the slug index maps to slugs.{id} with the slug attribute label', function () {
    $result = app(TranslateProductUniqueViolation::class)(
        s76aQueryException(s76aDuplicate('product_translations.product_translations_language_slug_unique')),
        'lang-1',
    );

    expect(array_keys($result->errors()))->toBe(['slugs.lang-1'])
        ->and($result->errors()['slugs.lang-1'][0])->toBe(trans('validation.unique', ['attribute' => trans('products.attributes.slug')]));
});

test('with a language id the natural-key index maps to names.{id} with the retry message', function () {
    $result = app(TranslateProductUniqueViolation::class)(
        s76aQueryException(s76aDuplicate('product_translations_product_language_unique')),
        'lang-1',
    );

    expect(array_keys($result->errors()))->toBe(['names.lang-1'])
        ->and($result->errors()['names.lang-1'][0])->toBe(trans('products.editor.concurrent_save_retry'));
});

test('with a language id the sku index still maps to the bare sku key', function () {
    $result = app(TranslateProductUniqueViolation::class)(
        s76aQueryException(s76aDuplicate('products.products_sku_unique')),
        'lang-1',
    );

    expect(array_keys($result->errors()))->toBe(['sku']);
});

test('with a language id an unrelated QueryException is still rethrown as the same instance', function () {
    $e = s76aQueryException(s76aDuplicate('product_variants.product_variants_sku_unique'));
    $caught = null;

    try {
        app(TranslateProductUniqueViolation::class)($e, 'lang-1');
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($e);
});

test('with an explicit null language id the bare slug and name keys are unchanged', function (string $key, string $expected) {
    $result = app(TranslateProductUniqueViolation::class)(s76aQueryException(s76aDuplicate($key)), null);

    expect(array_keys($result->errors()))->toBe([$expected]);
})->with([
    'slug' => ['product_translations_language_slug_unique', 'slug'],
    'name' => ['product_translations_product_language_unique', 'name'],
]);
