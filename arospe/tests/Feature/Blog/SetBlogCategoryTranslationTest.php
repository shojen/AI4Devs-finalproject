<?php

use App\Actions\Blog\SetBlogCategoryTranslation;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0073 (layer 2), Phase 3 TDD "red" step: App\Actions\Blog\SetBlogCategoryTranslation does not
// exist yet, so every case below is expected to fail with a "Target class does not exist" error
// until backend-expert ships it. That is the correct, intended red outcome.
//
// DIRECT-CALL ONLY: no Livewire component is mounted anywhere in this file. A Livewire::test()
// exercises the action THROUGH its caller, so it would pass whether the action or the component
// authorizes and validates; these tests pin the backend layer independently of any caller. The
// action is resolved from the container in every test, never `new`-ed.
//
// 0058 D-13 / 0072 R-8: the action authorizes BEFORE it validates, so every negative-validation
// test below runs actingAs() an actor holding blog.edit first -- otherwise it would throw
// AuthorizationException and pass for the wrong reason.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
});

/**
 * @param  array<int, string>  $permissions
 */
function setBlogCategoryTranslationActor(array $permissions = ['blog.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

function storedBlogTranslationName(BlogCategory $category, StoreLanguage $language): ?string
{
    return BlogCategoryTranslation::query()
        ->where('blog_category_id', $category->id)
        ->where('store_language_id', $language->id)
        ->value('name');
}

function storedBlogTranslationRowCount(BlogCategory $category, StoreLanguage $language): int
{
    return BlogCategoryTranslation::query()
        ->where('blog_category_id', $category->id)
        ->where('store_language_id', $language->id)
        ->count();
}

function blogCategoryTranslationQueryException(int $driverCode, string $nativeMessage): QueryException
{
    $pdoException = new PDOException($nativeMessage);
    $pdoException->errorInfo = ['23000', $driverCode, $nativeMessage];

    return new QueryException('mysql', 'insert into `blog_category_translations` ...', [], $pdoException);
}

function bindThrowingBlogSetTranslation(QueryException $exception): void
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

/**
 * @return array<int, string>
 */
function blogCategoryTranslationErrorKeys(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

test('an actor holding blog.edit stores a French name and receives the translation row back', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $translation = app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');

    expect($translation)->toBeInstanceOf(BlogCategoryTranslation::class)
        ->and($translation->name)->toBe('Guides')
        ->and(storedBlogTranslationName($category, $this->french))->toBe('Guides');
});

// Regression canary for the flat-data-key bug 0071 found: a flat ["names.{id}" => $name] data key
// never reaches a nested rule key, so `required` would refuse every valid name.
test('a valid name for a fresh category and language pair is not refused by the required rule', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $keys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides'));

    expect($keys)->toBe([])
        ->and(storedBlogTranslationRowCount($category, $this->french))->toBe(1);
});

test('an actor holding only blog.view is refused and no translation row is written', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor(['blog.view']));

    $caught = null;
    try {
        app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class)
        ->and(BlogCategoryTranslation::query()->where('store_language_id', $this->french->id)->count())->toBe(0);
});

test('an actor holding blog.edit and no store-languages permission succeeds', function () {
    $actor = setBlogCategoryTranslationActor(['blog.edit']);
    $this->actingAs($actor);
    $category = BlogCategory::factory()->named('Guias')->create();

    expect($actor->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'store-languages.')))->toBeEmpty();

    app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');

    expect(storedBlogTranslationName($category, $this->french))->toBe('Guides');
});

test('a Super Admin holding zero permission rows succeeds through Gate::before', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');

    expect(storedBlogTranslationName($category, $this->french))->toBe('Guides');
});

test('authorization precedes validation: an unpermitted actor submitting a blank name gets AuthorizationException', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor(['blog.view']));

    $caught = null;
    try {
        app(SetBlogCategoryTranslation::class)($category, $this->french, '');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
});

test('a refusal is logged once with target_type blog_category and the target category id (A-5)', function () {
    Log::spy();
    $category = BlogCategory::factory()->named('Guias')->create();
    $actor = setBlogCategoryTranslationActor(['blog.view']);
    $this->actingAs($actor);

    try {
        app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'blog_category'
            && ($context['target_id'] ?? null) === $category->id)
        ->once();
});

test('a blank, whitespace-only or over-length name is refused and no translation row is written', function (string $badName) {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $caught = null;
    try {
        app(SetBlogCategoryTranslation::class)($category, $this->french, $badName);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and(storedBlogTranslationRowCount($category, $this->french))->toBe(0);
})->with([
    'empty' => '',
    'spaces only' => '   ',
    'tabs and newlines' => "\t\n ",
    'non-breaking and zero-width space only' => "\u{00A0}\u{200B}",
    'over the max length' => [str_repeat('a', 256)],
]);

test('a name whose folded form no longer fits is refused', function () {
    // 200 sharp-s characters pass max:255 raw but fold to 400 characters.
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $keys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $this->french, str_repeat("\u{00DF}", 200)));

    expect($keys)->toBe(['names.'.$this->french->id])
        ->and(storedBlogTranslationRowCount($category, $this->french))->toBe(0);
});

test('the error key is names.{language id}, derived internally, for two different languages', function () {
    $german = StoreLanguage::factory()->create();
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $frenchKeys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $this->french, ''));
    $germanKeys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $german, ''));

    expect($frenchKeys)->toBe(['names.'.$this->french->id])
        ->and($germanKeys)->toBe(['names.'.$german->id]);
});

test('a name already used by another category within the same store language is refused, including an accent-only variant', function (string $candidate) {
    $other = BlogCategory::factory()->named('Otra')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create([
        'blog_category_id' => $other->id,
        'name' => 'Guías',
    ]);
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $keys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $this->french, $candidate));

    expect($keys)->toBe(['names.'.$this->french->id])
        ->and(storedBlogTranslationRowCount($category, $this->french))->toBe(0);
})->with([
    'byte-identical' => 'Guías',
    'accent-only variant' => 'Guias',
]);

test('a leading non-breaking space cannot fold past uniqueness (A-4: trimName, not trim)', function () {
    $other = BlogCategory::factory()->named('Otra')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create([
        'blog_category_id' => $other->id,
        'name' => 'Guías',
    ]);
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $keys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $this->french, "\u{00A0}Guías"));

    expect($keys)->toBe(['names.'.$this->french->id]);
});

test('the same name already used in a different store language is accepted (byte-identical fixture)', function () {
    // The other category holds 'Guides' in the DEFAULT language; the same string is free in French.
    $other = BlogCategory::factory()->named('Guides')->create();
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');

    expect(storedBlogTranslationName($category, $this->french))->toBe('Guides')
        ->and(storedBlogTranslationName($other, $this->spanish))->toBe('Guides');
});

test('re-setting a category\'s own name in the same language is accepted (FK-scoped ignore, 0072 D-4)', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create([
        'blog_category_id' => $category->id,
        'name' => 'Guides',
    ]);
    $this->actingAs(setBlogCategoryTranslationActor());

    $translation = app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');

    expect($translation->name)->toBe('Guides')
        ->and(storedBlogTranslationRowCount($category, $this->french))->toBe(1);
});

test('a 1062 on the name index is re-keyed to names.{language id}', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    bindThrowingBlogSetTranslation(blogCategoryTranslationQueryException(
        1062,
        "Duplicate entry '019e-guides' for key 'blog_category_translations.blog_category_translations_language_normalized_name_unique'",
    ));

    $keys = blogCategoryTranslationErrorKeys(fn () => app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides'));

    expect($keys)->toBe(['names.'.$this->french->id]);
});

test('a database error that is not a name collision propagates unchanged', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $exception = blogCategoryTranslationQueryException(1213, 'Deadlock found when trying to get lock; try restarting transaction');
    bindThrowingBlogSetTranslation($exception);

    $caught = null;
    try {
        app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($exception);
});

test('duplicate and over-length messages never expose the names.{language id} key, on the validator and 1062 paths, in en and es (A-1, A-5)', function (string $locale) {
    app()->setLocale($locale);
    $other = BlogCategory::factory()->named('Otra')->create();
    BlogCategoryTranslation::factory()->forLanguage($this->french)->create([
        'blog_category_id' => $other->id,
        'name' => 'Guides',
    ]);
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());
    $key = 'names.'.$this->french->id;
    $label = __('blog.categories.index.tabs.name_attribute');

    $messages = [];

    foreach (['Guides', str_repeat("\u{00DF}", 200)] as $candidate) {
        try {
            app(SetBlogCategoryTranslation::class)($category, $this->french, $candidate);
        } catch (ValidationException $e) {
            $messages[] = $e->errors()[$key][0];
        }
    }

    bindThrowingBlogSetTranslation(blogCategoryTranslationQueryException(
        1062,
        "Duplicate entry '019e-bottes' for key 'blog_category_translations.blog_category_translations_language_normalized_name_unique'",
    ));

    try {
        app(SetBlogCategoryTranslation::class)($category, $this->french, 'Bottes');
    } catch (ValidationException $e) {
        $messages[] = $e->errors()[$key][0];
    }

    expect($messages)->toHaveCount(3);

    foreach ($messages as $message) {
        expect($message)->toBeString()
            ->not->toContain('names.')
            ->not->toContain($this->french->id)
            ->toContain($label);
    }
})->with(['en' => ['en'], 'es' => ['es']]);

test('the name is trimmed before it is persisted', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    $translation = app(SetBlogCategoryTranslation::class)($category, $this->french, "  \u{00A0}Guides \u{200B} ");

    expect($translation->name)->toBe('Guides')
        ->and(storedBlogTranslationName($category, $this->french))->toBe('Guides');
});

test('calling twice for the same category and language replaces the row and re-derives the fold from the second value', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    app(SetBlogCategoryTranslation::class)($category, $this->french, 'Guides');
    app(SetBlogCategoryTranslation::class)($category, $this->french, 'Souliers');

    $row = BlogCategoryTranslation::query()
        ->where('blog_category_id', $category->id)
        ->where('store_language_id', $this->french->id)
        ->sole();

    expect($row->name)->toBe('Souliers')
        ->and($row->normalized_name)->toBe(app(NormalizeForSearch::class)('Souliers'))
        ->and($row->normalized_name)->not->toBe(app(NormalizeForSearch::class)('Guides'));
});

test('an inactive store language is still writable through the action', function () {
    $inactive = StoreLanguage::factory()->inactive()->create();
    $category = BlogCategory::factory()->named('Guias')->create();
    $this->actingAs(setBlogCategoryTranslationActor());

    app(SetBlogCategoryTranslation::class)($category, $inactive, 'Kategorie');

    expect(storedBlogTranslationName($category, $inactive))->toBe('Kategorie');
});
