<?php

use App\Actions\ProductCategories\TranslateProductCategoryNameUniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

// Story 0070 (D-7 (ii)). Unit, with uses(TestCase::class) for trans() -- no RefreshDatabase, no
// database touched. Every exception is constructed directly, following
// tests/Feature/Products/SyncProductAttributeValuesInUseTest.php's own construction pattern: a
// PDOException carrying errorInfo, wrapped in a QueryException whose formatted message embeds the
// (constructed) previous exception's message.
//
// The two real index names below were verified against the LIVE, migrated schema (`php artisan
// db:table product_category_translations`, 2026-09-28): MySQL named the
// (product_category_id, store_language_id) pair EXPLICITLY --
// product_category_translations_category_language_unique, since the default 74-character name
// exceeds MySQL's 64-character identifier limit -- while the (store_language_id, name) pair kept
// its default Laravel-generated name, product_category_translations_store_language_id_name_unique.
//
// Phase 4 security audit finding F-1: the discriminator must match on `$e->errorInfo[1]` (the
// driver error code) and `$e->errorInfo[2]` (the driver's native message), never on
// `$e->getMessage()` -- that string embeds the already-interpolated SQL, including whatever the
// user typed as the category name, so `errorInfo[2]` below is written to mirror what MySQL's PDO
// driver actually reports (the "Duplicate entry '...' for key '...'" text), independently of the
// unrelated, free-form $mysqlMessage/bindings arguments used to build the previous exception and
// the outer QueryException::getMessage().

function constructedQueryExceptionFor(string $mysqlMessage, ?array $errorInfo, array $bindings = []): QueryException
{
    $previous = new PDOException($mysqlMessage);

    if ($errorInfo !== null) {
        $previous->errorInfo = $errorInfo;
    }

    return new QueryException('mysql', 'insert into `product_category_translations` (`name`) values (?)', $bindings, $previous);
}

test('a 1062 violation naming the (store_language_id, name) index returns a ValidationException keyed on the default "name" key', function () {
    $e = constructedQueryExceptionFor(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key "
            ."'product_category_translations.product_category_translations_store_language_id_name_unique'",
        ['23000', 1062, "Duplicate entry 'x' for key 'product_category_translations.product_category_translations_store_language_id_name_unique'"],
    );

    $result = app(TranslateProductCategoryNameUniqueViolation::class)($e);

    expect($result)->toBeInstanceOf(ValidationException::class)
        ->and($result->errors())->toHaveKey('name')
        ->and($result->errors()['name'][0])->toBe(trans('validation.unique', ['attribute' => 'name']));
});

// 0071's SetProductCategoryTranslation passes a derived "names.{$language->id}" key -- proven
// generically here with a representative custom key, since this story ships the translator but no
// caller that uses anything other than the default.
test('a custom $errorKey is honoured on the returned ValidationException, instead of the default "name"', function () {
    $e = constructedQueryExceptionFor(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key "
            ."'product_category_translations.product_category_translations_store_language_id_name_unique'",
        ['23000', 1062, "Duplicate entry 'x' for key 'product_category_translations.product_category_translations_store_language_id_name_unique'"],
    );

    $result = app(TranslateProductCategoryNameUniqueViolation::class)($e, 'names.fr-language-id');

    expect($result)->toBeInstanceOf(ValidationException::class)
        ->and($result->errors())->toHaveKey('names.fr-language-id')
        ->and($result->errors())->not->toHaveKey('name');
});

test('the message uses the default "name" label unless a custom attribute label is passed', function () {
    $e = constructedQueryExceptionFor(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key "
            ."'product_category_translations.product_category_translations_store_language_id_name_unique'",
        ['23000', 1062, "Duplicate entry 'x' for key 'product_category_translations.product_category_translations_store_language_id_name_unique'"],
    );

    $default = app(TranslateProductCategoryNameUniqueViolation::class)($e, 'names.fr-language-id');
    $custom = app(TranslateProductCategoryNameUniqueViolation::class)($e, 'names.fr-language-id', 'nombre');

    expect($default->errors()['names.fr-language-id'][0])->toBe(trans('validation.unique', ['attribute' => 'name']))
        ->and($custom->errors()['names.fr-language-id'][0])->toBe(trans('validation.unique', ['attribute' => 'nombre']))
        ->and($custom->errors()['names.fr-language-id'][0])->not->toContain('names.');
});

// Unreachable through Create/Rename, which always pass the id of an EXISTING default language --
// this is the branch that would ship untested if the discrimination were left inline instead of
// extracted (D-7 (ii)).
test('a 1062 violation on the (product_category_id, store_language_id) index rethrows the same exception instance', function () {
    $e = constructedQueryExceptionFor(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key "
            ."'product_category_translations.product_category_translations_category_language_unique'",
        ['23000', 1062, "Duplicate entry 'x' for key 'product_category_translations.product_category_translations_category_language_unique'"],
    );

    $caught = null;

    try {
        app(TranslateProductCategoryNameUniqueViolation::class)($e);
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($e);
});

// 0023's original blanket 23000 -> "name taken" catch would have misreported this as a duplicate
// name. This table has two FKs, so the narrower, extracted discrimination must let it through.
test('a 1452 foreign key violation rethrows the same exception instance', function () {
    $e = constructedQueryExceptionFor(
        'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a '
            .'foreign key constraint fails (`product_category_translations`, CONSTRAINT '
            .'`product_category_translations_store_language_id_foreign`)',
        ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails (`product_category_translations`, CONSTRAINT `product_category_translations_store_language_id_foreign`)'],
    );

    $caught = null;

    try {
        app(TranslateProductCategoryNameUniqueViolation::class)($e);
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($e);
});

test('a QueryException with no errorInfo at all rethrows the same exception instance', function () {
    $e = constructedQueryExceptionFor('A generic database error unrelated to either unique index', null);

    expect($e->errorInfo)->toBeNull();

    $caught = null;

    try {
        app(TranslateProductCategoryNameUniqueViolation::class)($e);
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($e);
});

// Security audit F-1 regression: QueryException::getMessage() embeds the already-interpolated
// SQL (vendor/laravel/framework/src/Illuminate/Database/QueryException.php:87), including
// user-supplied bindings. A category renamed to text that happens to contain the unique index
// name must NOT be misread as a name collision when the real failure -- reported via errorInfo --
// is an unrelated 1452 FK violation. The discriminator must key off errorInfo, never getMessage().
test('a 1452 foreign key violation whose interpolated SQL happens to mention the unique index name still rethrows the same exception instance', function () {
    $e = constructedQueryExceptionFor(
        'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a '
            .'foreign key constraint fails (`product_category_translations`, CONSTRAINT '
            .'`product_category_translations_store_language_id_foreign`)',
        ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails (`product_category_translations`, CONSTRAINT `product_category_translations_store_language_id_foreign`)'],
        ['x product_category_translations_store_language_id_name_unique'],
    );

    // Sanity check: the bug this test guards against is only exercisable if getMessage() really
    // does contain the index-name substring despite the real failure being a 1452, not a 1062.
    expect($e->getMessage())->toContain('product_category_translations_store_language_id_name_unique');

    $caught = null;

    try {
        app(TranslateProductCategoryNameUniqueViolation::class)($e);
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($e);
});
