<?php

use App\Actions\Blog\SetBlogTagTranslation;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0075 (layer 2 of the two-layer write guard): App\Actions\Blog\SetBlogTagTranslation, the
// authorizing and validating per-language write path for one blog tag name.
//
// DIRECT-CALL ONLY: no Livewire component is mounted anywhere in this file. A Livewire::test()
// exercises the action THROUGH its caller, so it would pass whether the action or the component
// authorizes and validates; these tests pin the backend layer independently of any caller.
//
// 0058 D-13 / 0072 R-8: the action authorizes BEFORE it validates, so every negative-validation
// test runs actingAs() an actor holding blog.edit first -- otherwise it would throw
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
function setBlogTagTranslationActor(array $permissions = ['blog.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

function storedBlogTagTranslationName(BlogTag $tag, StoreLanguage $language): ?string
{
    return BlogTagTranslation::query()
        ->where('blog_tag_id', $tag->id)
        ->where('store_language_id', $language->id)
        ->value('name');
}

function storedBlogTagTranslationRowCount(BlogTag $tag, StoreLanguage $language): int
{
    return BlogTagTranslation::query()
        ->where('blog_tag_id', $tag->id)
        ->where('store_language_id', $language->id)
        ->count();
}

function blogTagTranslationQueryException(int $driverCode, string $nativeMessage): QueryException
{
    $pdoException = new PDOException($nativeMessage);
    $pdoException->errorInfo = ['23000', $driverCode, $nativeMessage];

    return new QueryException('mysql', 'insert into `blog_tag_translations` ...', [], $pdoException);
}

function bindThrowingBlogTagSetTranslation(QueryException $exception): void
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
function blogTagTranslationErrorKeys(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

test('the action is self-sufficient: called directly it refuses an unauthorized actor and an invalid name', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $this->actingAs(setBlogTagTranslationActor(['blog.view']));
    expect(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied'))
        ->toThrow(AuthorizationException::class);

    $this->actingAs(setBlogTagTranslationActor());
    expect(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, '   '))
        ->toThrow(ValidationException::class);

    expect(storedBlogTagTranslationRowCount($tag, $this->french))->toBe(0);
});

test('an actor holding blog.edit stores a French name, receives the row back and leaves other languages untouched', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $translation = app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied');

    expect($translation)->toBeInstanceOf(BlogTagTranslation::class)
        ->and($translation->name)->toBe('course à pied')
        ->and(storedBlogTagTranslationName($tag, $this->french))->toBe('course à pied')
        ->and(storedBlogTagTranslationName($tag, $this->spanish))->toBe('running')
        ->and(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->count())->toBe(2);
});

test('re-invoking for the same language updates the row instead of inserting a second one', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied');
    app(SetBlogTagTranslation::class)($tag, $this->french, 'trail');

    $row = BlogTagTranslation::query()
        ->where('blog_tag_id', $tag->id)
        ->where('store_language_id', $this->french->id)
        ->sole();

    expect($row->name)->toBe('trail')
        ->and($row->normalized_name)->toBe(app(NormalizeForSearch::class)('trail'));
});

test('an actor holding only blog.view is refused and no translation row is written', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor(['blog.view']));

    expect(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied'))
        ->toThrow(AuthorizationException::class);

    expect(BlogTagTranslation::query()->where('store_language_id', $this->french->id)->count())->toBe(0);
});

test('a Super Admin holding zero permission rows succeeds through Gate::before', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied');

    expect(storedBlogTagTranslationName($tag, $this->french))->toBe('course à pied');
});

test('an actor holding blog.edit and no store-languages permission succeeds', function () {
    $actor = setBlogTagTranslationActor(['blog.edit']);
    $this->actingAs($actor);
    $tag = BlogTag::factory()->named('running')->create();

    expect($actor->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'store-languages.')))->toBeEmpty();

    app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied');

    expect(storedBlogTagTranslationName($tag, $this->french))->toBe('course à pied');
});

test('authorization precedes validation: an unpermitted actor submitting a blank name gets AuthorizationException', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor(['blog.view']));

    expect(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, ''))
        ->toThrow(AuthorizationException::class);
});

test('every refusal is logged exactly once with target_type blog_tag and the target tag id', function () {
    Log::spy();
    $tag = BlogTag::factory()->named('running')->create();
    $actor = setBlogTagTranslationActor(['blog.view']);
    $this->actingAs($actor);

    try {
        app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'blog_tag'
            && ($context['target_id'] ?? null) === $tag->id)
        ->once();
});

test('an inactive store language is refused and nothing is written to it', function () {
    $german = StoreLanguage::factory()->inactive()->create();
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $keys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tag, $german, 'Laufen'));

    expect($keys)->toBe(['names.'.$german->id])
        ->and(storedBlogTagTranslationRowCount($tag, $german))->toBe(0);
});

test('a language deactivated after the instance was loaded is refused, never trusting the passed instance', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());
    $stale = StoreLanguage::query()->findOrFail($this->french->id);

    $this->french->forceFill(['is_active' => false])->save();

    $keys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tag, $stale, 'course à pied'));

    expect($keys)->toBe(['names.'.$this->french->id])
        ->and(storedBlogTagTranslationRowCount($tag, $this->french))->toBe(0);
});

test('a blank, whitespace-only or over-length name is refused on a non-default language and writes no row', function (string $badName) {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    expect(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, $badName))
        ->toThrow(ValidationException::class);

    expect(storedBlogTagTranslationRowCount($tag, $this->french))->toBe(0);
})->with([
    'empty' => '',
    'spaces only' => '   ',
    'tabs and newlines' => "\t\n ",
    'non-breaking and zero-width space only' => "\u{00A0}\u{200B}",
    'over the max length' => [str_repeat('a', 256)],
]);

test('the error key is names.{language id}, derived from the language passed, for two different languages', function () {
    $german = StoreLanguage::factory()->create();
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $frenchKeys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, ''));
    $germanKeys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tag, $german, ''));

    expect($frenchKeys)->toBe(['names.'.$this->french->id])
        ->and($germanKeys)->toBe(['names.'.$german->id]);
});

test('the action accepts exactly three parameters: the error key cannot be supplied by a caller', function () {
    expect((new ReflectionMethod(SetBlogTagTranslation::class, '__invoke'))->getNumberOfParameters())->toBe(3);
});

test('a name already used by another tag in the same language is refused, including a case-only and an accent-only variant', function (string $candidate) {
    $other = BlogTag::factory()->named('autre')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $other->id, 'name' => 'course à pied']);
    $tag = BlogTag::factory()->named('randonnée')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $keys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, $candidate));

    expect($keys)->toBe(['names.'.$this->french->id])
        ->and(storedBlogTagTranslationRowCount($tag, $this->french))->toBe(0);
})->with([
    'byte-identical' => 'course à pied',
    'case-only variant' => 'Course à pied',
    'accent-only variant' => 'course a pied',
]);

test('the byte-identical name in a different store language is accepted', function () {
    $other = BlogTag::factory()->named('running')->create();
    $tag = BlogTag::factory()->named('correr')->create();
    $this->actingAs(setBlogTagTranslationActor());

    app(SetBlogTagTranslation::class)($tag, $this->french, 'running');

    expect(storedBlogTagTranslationName($tag, $this->french))->toBe('running')
        ->and(storedBlogTagTranslationName($other, $this->spanish))->toBe('running');
});

test('re-saving a tag\'s own name in the same language is accepted', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tag->id, 'name' => 'course à pied']);
    $this->actingAs(setBlogTagTranslationActor());

    $translation = app(SetBlogTagTranslation::class)($tag, $this->french, 'course à pied');

    expect($translation->name)->toBe('course à pied')
        ->and(storedBlogTagTranslationRowCount($tag, $this->french))->toBe(1);
});

test('the wrong-id canary: with two tags named in French, re-saving the first one\'s own name is not a collision with itself', function () {
    $tagA = BlogTag::factory()->named('a')->create();
    $tagB = BlogTag::factory()->named('b')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tagA->id, 'name' => 'course à pied']);
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tagB->id, 'name' => 'randonnée']);
    $this->actingAs(setBlogTagTranslationActor());

    $keys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tagA, $this->french, 'course à pied'));

    expect($keys)->toBe([])
        ->and(storedBlogTagTranslationName($tagA, $this->french))->toBe('course à pied')
        ->and(storedBlogTagTranslationName($tagB, $this->french))->toBe('randonnée');
});

test('a 1062 on the name index is re-keyed to names.{language id}', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    bindThrowingBlogTagSetTranslation(blogTagTranslationQueryException(
        1062,
        "Duplicate entry '019e-trail' for key 'blog_tag_translations.blog_tag_translations_language_normalized_name_unique'",
    ));

    $keys = blogTagTranslationErrorKeys(fn () => app(SetBlogTagTranslation::class)($tag, $this->french, 'trail'));

    expect($keys)->toBe(['names.'.$this->french->id]);
});

test('a foreign-key violation is not misattributed as a duplicate name', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $exception = blogTagTranslationQueryException(
        1452,
        'Cannot add or update a child row: a foreign key constraint fails (`blog_tag_translations`, CONSTRAINT `blog_tag_translations_store_language_id_foreign`)',
    );
    bindThrowingBlogTagSetTranslation($exception);

    $caught = null;
    try {
        app(SetBlogTagTranslation::class)($tag, $this->french, 'trail');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($exception);
});

test('the name is trimmed before it is persisted', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $translation = app(SetBlogTagTranslation::class)($tag, $this->french, "  \u{00A0}trail \u{200B} ");

    expect($translation->name)->toBe('trail')
        ->and(storedBlogTagTranslationName($tag, $this->french))->toBe('trail');
});

test('refusal messages name the field, never the internal names.{language id} key, in en and es', function (string $locale) {
    app()->setLocale($locale);
    $other = BlogTag::factory()->named('autre')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $other->id, 'name' => 'trail']);
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());
    $key = 'names.'.$this->french->id;

    $messages = [];

    foreach (['trail', ''] as $candidate) {
        try {
            app(SetBlogTagTranslation::class)($tag, $this->french, $candidate);
        } catch (ValidationException $e) {
            $messages[] = $e->errors()[$key][0];
        }
    }

    expect($messages)->toHaveCount(2);

    foreach ($messages as $message) {
        expect($message)->not->toContain('names.')
            ->not->toContain($this->french->id)
            ->toContain(__('blog-tags.index.tabs.name_attribute'));
    }
})->with(['en' => ['en'], 'es' => ['es']]);
