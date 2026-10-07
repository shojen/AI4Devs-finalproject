<?php

use App\Actions\ProductCategories\SetProductCategoryTranslation;
use App\Actions\Translations\SetTranslation;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0071 (D-4), Phase 3 TDD "red" step: App\Actions\ProductCategories\SetProductCategoryTranslation
// does not exist yet, so every case below is expected to fail with a "Target class does not exist"
// error until backend-expert ships it. That is the correct, intended red outcome.
//
// DIRECT-CALL ONLY: no Livewire component is mounted anywhere in this file. A Livewire::test()
// exercises the action THROUGH its caller, so it would pass whether the action or the component
// authorizes/validates -- these tests pin the backend layer of the 2026-08-30 defence-in-depth
// decision independently of any caller. The action is resolved from the container in every test,
// never `new`-ed. The refusal-LOG assertion lives in RefusalLoggingTest.php.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
});

/**
 * @param  array<int, string>  $permissions
 */
function setProductCategoryTranslationActor(array $permissions = ['products.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

function storedTranslationName(ProductCategory $category, StoreLanguage $language): ?string
{
    return ProductCategoryTranslation::query()
        ->where('product_category_id', $category->id)
        ->where('store_language_id', $language->id)
        ->value('name');
}

function storedTranslationRowCount(ProductCategory $category, StoreLanguage $language): int
{
    return ProductCategoryTranslation::query()
        ->where('product_category_id', $category->id)
        ->where('store_language_id', $language->id)
        ->count();
}

/**
 * Builds the QueryException a MySQL violation produces: the driver error code sits in
 * errorInfo[1] and the native message in errorInfo[2].
 */
function productCategoryTranslationQueryException(int $driverCode, string $nativeMessage): QueryException
{
    $pdoException = new PDOException($nativeMessage);
    $pdoException->errorInfo = ['23000', $driverCode, $nativeMessage];

    return new QueryException('mysql', 'insert into `product_category_translations` ...', [], $pdoException);
}

function bindThrowingSetTranslation(QueryException $exception): void
{
    app()->instance(SetTranslation::class, new class($exception) extends SetTranslation
    {
        public function __construct(private readonly QueryException $exception) {}

        /**
         * @param  array<string, string|null>  $attributes
         */
        public function __invoke(Model $translatable, StoreLanguage $language, array $attributes): Model
        {
            throw $this->exception;
        }
    });
}

test('an actor holding products.edit stores a French name and receives the translation row back', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $translation = app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');

    expect($translation)->toBeInstanceOf(ProductCategoryTranslation::class)
        ->and($translation->name)->toBe('Chaussures')
        ->and(storedTranslationName($category, $this->french))->toBe('Chaussures');
});

// Regression canary for the flat-data-key bug found at Phase 2: a flat ["names.{id}" => $name] data
// key never reaches the rule, so `required` refused every valid name.
test('a valid name for a fresh category and language pair is not refused by the required rule', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeNull()
        ->and(storedTranslationRowCount($category, $this->french))->toBe(1);
});

test('an actor lacking products.edit is refused and no translation row is written', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor(['products.view', 'products.create']));

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class)
        ->and(storedTranslationRowCount($category, $this->french))->toBe(0);
});

test('a Super Admin holding zero permission rows succeeds through Gate::before', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');

    expect(storedTranslationName($category, $this->french))->toBe('Chaussures');
});

test('a blank or whitespace-only name is refused and no translation row is written', function (string $blankName) {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, $blankName);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(storedTranslationRowCount($category, $this->french))->toBe(0);
})->with([
    'empty' => '',
    'spaces only' => '   ',
    'tabs and newlines' => "\t\n ",
]);

test('an over-length name is refused and no translation row is written', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, str_repeat('a', 256));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(storedTranslationRowCount($category, $this->french))->toBe(0);
});

test('a name already used by another category within the same store language is refused', function () {
    $other = ProductCategory::factory()->named('Otra')->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $other->id,
        'name' => 'Chaussures',
    ]);
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'chaussures');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(storedTranslationRowCount($category, $this->french))->toBe(0);
});

test('the same name already used in a different store language is accepted', function () {
    ProductCategory::factory()->named('Chaussures')->create();
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');

    expect(storedTranslationName($category, $this->french))->toBe('Chaussures');
});

test('re-setting a category\'s own name in the same language is accepted', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $category->id,
        'name' => 'Chaussures',
    ]);
    $this->actingAs(setProductCategoryTranslationActor());

    $translation = app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');

    expect($translation->name)->toBe('Chaussures')
        ->and(storedTranslationRowCount($category, $this->french))->toBe(1);
});

test('a blank name is refused under the literal key names.{language id}', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, '');
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($caught->errors()))->toBe(['names.'.$this->french->id]);
});

test('a duplicate refused by validation is also keyed names.{language id}', function () {
    $other = ProductCategory::factory()->named('Otra')->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $other->id,
        'name' => 'Chaussures',
    ]);
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($caught->errors()))->toBe(['names.'.$this->french->id]);
});

test('a 1062 name-index race is re-keyed to names.{language id}', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    bindThrowingSetTranslation(productCategoryTranslationQueryException(
        1062,
        "Duplicate entry '019e-Chaussures' for key 'product_category_translations.product_category_translations_store_language_id_name_unique'",
    ));

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($caught->errors()))->toBe(['names.'.$this->french->id]);
});

test('duplicate messages never expose the names.{language id} key, on the validator and the 1062 paths, in en and es', function (string $locale, string $label) {
    app()->setLocale($locale);
    $other = ProductCategory::factory()->named('Otra')->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $other->id,
        'name' => 'Chaussures',
    ]);
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());
    $key = 'names.'.$this->french->id;

    $validatorMessage = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    } catch (ValidationException $e) {
        $validatorMessage = $e->errors()[$key][0];
    }

    bindThrowingSetTranslation(productCategoryTranslationQueryException(
        1062,
        "Duplicate entry '019e-Bottes' for key 'product_category_translations.product_category_translations_store_language_id_name_unique'",
    ));

    $raceMessage = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Bottes');
    } catch (ValidationException $e) {
        $raceMessage = $e->errors()[$key][0];
    }

    foreach ([$validatorMessage, $raceMessage] as $message) {
        expect($message)->toBeString()
            ->not->toContain('names.')
            ->not->toContain($this->french->id)
            ->toContain($label);
    }
})->with([
    'en' => ['en', 'name'],
    'es' => ['es', 'nombre'],
]);

test('a database error that is not a name collision propagates unchanged', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $exception = productCategoryTranslationQueryException(1213, 'Deadlock found when trying to get lock; try restarting transaction');
    bindThrowingSetTranslation($exception);

    $caught = null;
    try {
        app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($exception);
});

test('the name is trimmed before it is persisted', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    $translation = app(SetProductCategoryTranslation::class)($category, $this->french, '  Chaussures  ');

    expect($translation->name)->toBe('Chaussures')
        ->and(storedTranslationName($category, $this->french))->toBe('Chaussures');
});

test('calling twice for the same category and language updates the row rather than duplicating it', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    app(SetProductCategoryTranslation::class)($category, $this->french, 'Chaussures');
    app(SetProductCategoryTranslation::class)($category, $this->french, 'Souliers');

    expect(storedTranslationRowCount($category, $this->french))->toBe(1)
        ->and(storedTranslationName($category, $this->french))->toBe('Souliers');
});

test('an inactive store language is still writable through the action', function () {
    $inactive = StoreLanguage::factory()->inactive()->create();
    $category = ProductCategory::factory()->named('Calzado')->create();
    $this->actingAs(setProductCategoryTranslationActor());

    app(SetProductCategoryTranslation::class)($category, $inactive, 'Kategorie');

    expect(storedTranslationName($category, $inactive))->toBe('Kategorie');
});
