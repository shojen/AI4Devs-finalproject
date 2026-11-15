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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

test('a tag deleted after it was loaded is a clean not-found, never a foreign-key error', function () {
    $tag = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());
    $stale = BlogTag::query()->findOrFail($tag->id);

    $tag->delete();

    expect(fn () => app(SetBlogTagTranslation::class)($stale, $this->french, 'course à pied'))
        ->toThrow(ModelNotFoundException::class);
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

afterEach(function () {
    // Story 0099: the race tests register a `creating` listener on BlogTagTranslation. Flush it, then
    // reset the booted state so the model's own `saving` hook is registered again for later tests.
    BlogTagTranslation::flushEventListeners();
    BlogTagTranslation::clearBootedModels();
});

/**
 * Story 0099: raw-deletes the blog tag from inside the translation's `creating` event, i.e. after
 * the action's read and before the INSERT, so MySQL raises the real 1452. FOREIGN_KEY_CHECKS is
 * never disabled.
 */
function deleteBlogTagWhenItsTranslationIsCreated(BlogTag $parent): void
{
    BlogTagTranslation::creating(function () use ($parent): void {
        DB::table('blog_tags')->where('id', $parent->id)->delete();
    });
}

function blogTagTranslationRawRowCount(BlogTag $parent): int
{
    return DB::table('blog_tag_translations')->where('blog_tag_id', $parent->id)->count();
}

test('a blog tag deleted after the caller loaded it raises ModelNotFoundException and leaves no translation row', function () {
    $parent = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());
    $stale = BlogTag::query()->findOrFail($parent->id);
    $parent->delete();

    $caught = null;
    try {
        app(SetBlogTagTranslation::class)($stale, $this->french, 'Guides');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ModelNotFoundException::class)
        ->and($caught->getModel())->toBe(BlogTag::class)
        ->and(blogTagTranslationRawRowCount($parent))->toBe(0);
});

test('a blog tag deleted between the action read and the write raises ModelNotFoundException and leaves no translation row', function () {
    $parent = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());
    deleteBlogTagWhenItsTranslationIsCreated($parent);

    $caught = null;
    try {
        app(SetBlogTagTranslation::class)($parent, $this->french, 'Guides');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ModelNotFoundException::class)
        ->and($caught->getModel())->toBe(BlogTag::class)
        ->and($caught->getIds())->toBe([$parent->id])
        ->and(blogTagTranslationRawRowCount($parent))->toBe(0);
});

test('authorization is checked first for a deleted blog tag: an unpermitted actor is refused as unauthorized and logged once', function () {
    Log::spy();
    $parent = BlogTag::factory()->named('running')->create();
    $stale = BlogTag::query()->findOrFail($parent->id);
    $parent->delete();
    $this->actingAs(setBlogTagTranslationActor(['blog.view']));

    expect(fn () => app(SetBlogTagTranslation::class)($stale, $this->french, 'Guides'))
        ->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'Privileged action refused')
        ->once();
});

test('a deleted blog tag is refused as not found before an inactive language is checked', function () {
    $inactive = StoreLanguage::factory()->inactive()->create();
    $parent = BlogTag::factory()->named('running')->create();
    $stale = BlogTag::query()->findOrFail($parent->id);
    $parent->delete();
    $this->actingAs(setBlogTagTranslationActor());

    $caught = null;
    try {
        app(SetBlogTagTranslation::class)($stale, $inactive, 'Kategorie');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ModelNotFoundException::class)
        ->and($caught->getModel())->toBe(BlogTag::class);
});

test('a QueryException that is not a blog tag foreign key violation is rethrown unchanged', function (int $code, string $native, ?string $pdoMessage) {
    $parent = BlogTag::factory()->named('running')->create();
    $this->actingAs(setBlogTagTranslationActor());

    $pdoException = new PDOException($pdoMessage ?? $native);
    $pdoException->errorInfo = ['23000', $code, $native];
    $exception = new QueryException('mysql', 'insert into `blog_tag_translations` ...', [], $pdoException);
    bindThrowingBlogTagSetTranslation($exception);

    $caught = null;
    try {
        app(SetBlogTagTranslation::class)($parent, $this->french, 'Guides');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($exception);
})->with([
    'missing store language reference' => [1452, 'Cannot add or update a child row: a foreign key constraint fails (`testing`.`blog_tag_translations`, CONSTRAINT `blog_tag_translations_store_language_id_foreign` FOREIGN KEY (`store_language_id`) REFERENCES `store_languages` (`id`))', null],
    'duplicate entry on an unhandled index' => [1062, "Duplicate entry 'x' for key 'blog_tag_translations.some_other_unique'", null],
    'deadlock' => [1213, 'Deadlock found when trying to get lock; try restarting transaction', null],
    'decoy: message names the blog tag foreign key, errorInfo does not' => [
        1452,
        'Cannot add or update a child row: a foreign key constraint fails (`testing`.`blog_tag_translations`, CONSTRAINT `blog_tag_translations_store_language_id_foreign` FOREIGN KEY (`store_language_id`) REFERENCES `store_languages` (`id`))',
        'CONSTRAINT `blog_tag_translations_blog_tag_id_foreign` FOREIGN KEY',
    ],
]);
