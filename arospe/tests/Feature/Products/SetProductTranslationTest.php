<?php

use App\Actions\Products\SetProductTranslation;
use App\Actions\Translations\SetTranslation;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0076b (layer 2 of the two-layer write guard): App\Actions\Products\SetProductTranslation,
// the authorizing and validating per-language write path for one product's five translatable
// fields. DIRECT-CALL ONLY: no Livewire component is mounted, so these tests pin the backend layer
// independently of any caller. The action authorizes BEFORE it validates, so every negative
// validation test acts as an actor holding products.edit first -- otherwise it would pass for the
// wrong reason (AuthorizationException).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
});

/**
 * @param  array<int, string>  $permissions
 */
function setProductTranslationActor(array $permissions = ['products.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

function storedProductTranslation(Product $product, StoreLanguage $language): ?ProductTranslation
{
    return ProductTranslation::query()
        ->where('product_id', $product->id)
        ->where('store_language_id', $language->id)
        ->first();
}

function storedProductTranslationRowCount(Product $product, StoreLanguage $language): int
{
    return ProductTranslation::query()
        ->where('product_id', $product->id)
        ->where('store_language_id', $language->id)
        ->count();
}

/**
 * @return array<string, array<int, string>>
 */
function productTranslationErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function productWithFrenchSlug(StoreLanguage $french, string $slug, string $name = 'Otro'): Product
{
    $product = Product::factory()->named($name)->create();
    ProductTranslation::factory()->forLanguage($french)->create([
        'product_id' => $product->id,
        'name' => $name.' FR',
        'slug' => $slug,
    ]);

    return $product;
}

test('an actor holding only products.view is refused, the refusal is logged and no row is written', function () {
    Log::spy();
    $product = Product::factory()->named('Zapatillas')->create();
    $actor = setProductTranslationActor(['products.view']);
    $this->actingAs($actor);

    expect(fn () => app(SetProductTranslation::class)($product, $this->french, 'Chaussures'))
        ->toThrow(AuthorizationException::class);

    expect(storedProductTranslationRowCount($product, $this->french))->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['target_type'] ?? null) === 'product'
            && ($context['target_id'] ?? null) === $product->id)
        ->once();
});

test('an actor holding products.create but not products.edit is refused', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor(['products.view', 'products.create']));

    expect(fn () => app(SetProductTranslation::class)($product, $this->french, 'Chaussures'))
        ->toThrow(AuthorizationException::class);

    expect(storedProductTranslationRowCount($product, $this->french))->toBe(0);
});

test('an actor holding products.edit and no store-languages permission stores the translation', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor(['products.edit']));

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures de course');

    expect(storedProductTranslation($product, $this->french)->name)->toBe('Chaussures de course');
});

test('a Super Admin stores the translation through Gate::before', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $this->actingAs($actor);

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures de course');

    expect(storedProductTranslationRowCount($product, $this->french))->toBe(1);
});

test('storing a French translation returns a ProductTranslation and leaves the Spanish one untouched', function () {
    $product = Product::factory()->named('Zapatillas', 'Descripcion')->create();
    $this->actingAs(setProductTranslationActor());

    $translation = app(SetProductTranslation::class)($product, $this->french, 'Chaussures de course');

    expect($translation)->toBeInstanceOf(ProductTranslation::class)
        ->and($translation->name)->toBe('Chaussures de course')
        ->and(storedProductTranslation($product, $this->spanish)->name)->toBe('Zapatillas')
        ->and(storedProductTranslation($product, $this->spanish)->description)->toBe('Descripcion')
        ->and(ProductTranslation::query()->where('product_id', $product->id)->count())->toBe(2);
});

test('re-invoking for the same language updates the row instead of inserting a second one', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures');
    app(SetProductTranslation::class)($product, $this->french, 'Baskets');

    expect(storedProductTranslationRowCount($product, $this->french))->toBe(1)
        ->and(storedProductTranslation($product, $this->french)->name)->toBe('Baskets');
});

test('empty optional fields are persisted as NULL, not as empty strings', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', '', '', '', '');

    $row = storedProductTranslation($product, $this->french);

    expect($row->description)->toBeNull()
        ->and($row->slug)->toBeNull()
        ->and($row->meta_title)->toBeNull()
        ->and($row->meta_description)->toBeNull();
});

test('two products may both hold a NULL French slug', function () {
    $first = Product::factory()->named('Uno')->create();
    $second = Product::factory()->named('Dos')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($first, $this->french, 'Un', slug: '');
    app(SetProductTranslation::class)($second, $this->french, 'Deux', slug: '');

    expect(storedProductTranslation($first, $this->french)->slug)->toBeNull()
        ->and(storedProductTranslation($second, $this->french)->slug)->toBeNull();
});

test('all five fields are stored in the passed language', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, '  Chaussures  ', '<p>Belles</p>', 'chaussures', 'Titre', 'Meta');

    $row = storedProductTranslation($product, $this->french);

    expect($row->name)->toBe('Chaussures')
        ->and($row->description)->toBe('<p>Belles</p>')
        ->and($row->slug)->toBe('chaussures')
        ->and($row->meta_title)->toBe('Titre')
        ->and($row->meta_description)->toBe('Meta');
});

test('an inactive store language is refused on names.{id} with the language_inactive message and writes no row', function (string $locale) {
    app()->setLocale($locale);
    $german = StoreLanguage::factory()->inactive()->create();
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $german, 'Schuhe'));

    expect(array_keys($errors))->toBe(["names.{$german->id}"])
        ->and($errors["names.{$german->id}"][0])->toBe(trans('products.editor.tabs.language_inactive'))
        ->and($errors["names.{$german->id}"][0])->not->toBe('products.editor.tabs.language_inactive')
        ->and(storedProductTranslationRowCount($product, $german))->toBe(0);
})->with(['en', 'es']);

test('a language deactivated after the instance was loaded is refused, never trusting the passed instance', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $stale = StoreLanguage::query()->findOrFail($this->french->id);
    $this->french->forceFill(['is_active' => false])->save();

    expect(fn () => app(SetProductTranslation::class)($product, $stale, 'Chaussures'))
        ->toThrow(ValidationException::class);

    expect(storedProductTranslationRowCount($product, $this->french))->toBe(0);
});

test('authorization runs before the inactive-language check', function () {
    $german = StoreLanguage::factory()->inactive()->create();
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor(['products.view']));

    expect(fn () => app(SetProductTranslation::class)($product, $german, 'Schuhe'))
        ->toThrow(AuthorizationException::class);
});

test('a blank or whitespace-only name is refused on names.{id} and writes no row', function (string $blank) {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $this->french, $blank));

    expect(array_keys($errors))->toBe(["names.{$this->french->id}"])
        ->and(storedProductTranslationRowCount($product, $this->french))->toBe(0);
})->with(['empty' => [''], 'spaces' => ['   ']]);

test('refusal messages use the localized attribute label, never the dotted key', function (string $locale) {
    app()->setLocale($locale);
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $this->french, ''));
    $message = $errors["names.{$this->french->id}"][0];

    expect($message)->toBe(trans('validation.required', ['attribute' => trans('products.attributes.name')]))
        ->and($message)->not->toContain('names.')
        ->and(trans('products.attributes.name'))->not->toBe('products.attributes.name');
})->with(['en', 'es']);

test('an over-long meta title or meta description is refused on its own key and writes no row', function (string $field, int $limit) {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $id = $this->french->id;
    $tooLong = str_repeat('a', $limit + 1);

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)(
        $product,
        $this->french,
        'Chaussures',
        metaTitle: $field === 'metaTitles' ? $tooLong : null,
        metaDescription: $field === 'metaDescriptions' ? $tooLong : null,
    ));

    expect(array_keys($errors))->toBe(["{$field}.{$id}"])
        ->and($errors["{$field}.{$id}"][0])->not->toContain("{$field}.")
        ->and(storedProductTranslationRowCount($product, $this->french))->toBe(0);
})->with([
    'meta title' => ['metaTitles', 160],
    'meta description' => ['metaDescriptions', 500],
]);

test('meta title of 160 and meta description of 500 characters are accepted', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', metaTitle: str_repeat('a', 160), metaDescription: str_repeat('b', 500));

    $row = storedProductTranslation($product, $this->french);

    expect(mb_strlen($row->meta_title))->toBe(160)
        ->and(mb_strlen($row->meta_description))->toBe(500);
});

test('a slug is canonicalised before the unique rule, so a collision after canonicalisation is refused on slugs.{id}', function () {
    productWithFrenchSlug($this->french, 'chaussures-de-course');
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $this->french, 'Chaussures', slug: '  Chaussures De Course  '));

    expect(array_keys($errors))->toBe(["slugs.{$this->french->id}"])
        ->and(storedProductTranslationRowCount($product, $this->french))->toBe(0);
});

test('the stored slug is the canonical form', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', slug: '  Chaussures De Course  ');

    expect(storedProductTranslation($product, $this->french)->slug)->toBe('chaussures-de-course');
});

test('the same slug on two products in one language is refused', function () {
    productWithFrenchSlug($this->french, 'chaussures-de-course');
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $this->french, 'Chaussures', slug: 'chaussures-de-course'));

    expect(array_keys($errors))->toBe(["slugs.{$this->french->id}"]);
});

test('the same slug in two languages of one product is accepted, inserting the French row', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    ProductTranslation::query()
        ->where('product_id', $product->id)
        ->where('store_language_id', $this->spanish->id)
        ->update(['slug' => 'chaussures-de-course']);
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', slug: 'chaussures-de-course');

    expect(storedProductTranslation($product, $this->french)->slug)->toBe('chaussures-de-course');
});

test('a product keeps its own slug when re-saved, with another product holding a different slug', function () {
    $product = productWithFrenchSlug($this->french, 'chaussures-de-course', 'Propio');
    productWithFrenchSlug($this->french, 'baskets', 'Otro');
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', slug: 'chaussures-de-course');

    expect(storedProductTranslation($product, $this->french)->slug)->toBe('chaussures-de-course');
});

test('a script tag in the description is stored sanitised', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', '<p>Belles</p><script>alert(1)</script>');

    $description = storedProductTranslation($product, $this->french)->description;

    expect($description)->not->toContain('<script')
        ->and($description)->toContain('Belles');
});

test('a description whose raw markup exceeds 65535 characters but whose sanitised form fits is accepted', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $raw = '<p>ok</p>'.str_repeat('<script>x</script>', 4000);

    expect(strlen($raw))->toBeGreaterThan(65535);

    app(SetProductTranslation::class)($product, $this->french, 'Chaussures', $raw);

    expect(storedProductTranslation($product, $this->french)->description)->toContain('ok');
});

test('a slug taken by a concurrent save between validation and storage is reported on slugs.{id}', function () {
    $rival = Product::factory()->named('Rival')->create();
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $frenchId = $this->french->id;

    ProductTranslation::saving(function () use ($rival, $frenchId): void {
        if (DB::table('product_translations')->where('product_id', $rival->id)->where('store_language_id', $frenchId)->exists()) {
            return;
        }

        DB::table('product_translations')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $rival->id,
            'store_language_id' => $frenchId,
            'name' => 'Rival FR',
            'slug' => 'chaussures',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $this->french, 'Chaussures', slug: 'chaussures'));

    expect(array_keys($errors))->toBe(["slugs.{$frenchId}"]);
});

test('a natural-key race is reported on names.{id} with the concurrent-save retry message', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $frenchId = $this->french->id;

    ProductTranslation::saving(function () use ($product, $frenchId): void {
        if (DB::table('product_translations')->where('product_id', $product->id)->where('store_language_id', $frenchId)->exists()) {
            return;
        }

        DB::table('product_translations')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $product->id,
            'store_language_id' => $frenchId,
            'name' => 'Rival FR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $errors = productTranslationErrors(fn () => app(SetProductTranslation::class)($product, $this->french, 'Chaussures'));

    expect(array_keys($errors))->toBe(["names.{$frenchId}"])
        ->and($errors["names.{$frenchId}"][0])->toBe(trans('products.editor.concurrent_save_retry'));
});

test('any other QueryException from the primitive is rethrown unchanged', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $pdo = new PDOException('deadlock');
    $pdo->errorInfo = ['40001', 1213, 'Deadlock found'];
    $exception = new QueryException('mysql', 'insert into `product_translations` ...', [], $pdo);

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

    $caught = null;

    try {
        app(SetProductTranslation::class)($product, $this->french, 'Chaussures');
    } catch (Throwable $t) {
        $caught = $t;
    }

    expect($caught)->toBe($exception);
});

test('a product deleted after the caller loaded it raises ModelNotFoundException, not a foreign key error', function () {
    $product = Product::factory()->named('Zapatillas')->create();
    $this->actingAs(setProductTranslationActor());
    $stale = Product::query()->findOrFail($product->id);
    $product->delete();

    expect(fn () => app(SetProductTranslation::class)($stale, $this->french, 'Chaussures'))
        ->toThrow(ModelNotFoundException::class);
});

// Phase 4 audit finding F-5 (shared with ProductAuthorizationTest): strip comments/docblocks via
// token_get_all() so a prose mention of SetTranslation is never counted as a reference.
function fileReferencesSetTranslationOutsideComments(string $path): bool
{
    $contents = file_get_contents($path);

    if ($contents === false || ! str_contains($contents, 'SetTranslation')) {
        return false;
    }

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $text = is_array($token) ? $token[1] : $token;

        if (str_contains($text, 'SetTranslation')) {
            return true;
        }
    }

    return false;
}

test('SetTranslation is referenced in App\Actions\Products only by CreateProduct, UpdateProduct and SetProductTranslation', function () {
    $referencing = [];

    foreach (File::allFiles(app_path('Actions/Products')) as $file) {
        if ($file->getExtension() === 'php' && fileReferencesSetTranslationOutsideComments($file->getRealPath())) {
            $referencing[] = $file->getFilename();
        }
    }

    sort($referencing);

    expect($referencing)->toBe(['CreateProduct.php', 'SetProductTranslation.php', 'UpdateProduct.php']);
});
