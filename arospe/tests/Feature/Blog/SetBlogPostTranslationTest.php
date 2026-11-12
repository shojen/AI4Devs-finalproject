<?php

// Story 0079 (layer 2 of the two-layer write guard): App\Actions\Blog\SetBlogPostTranslation, the
// authorizing, sanitizing and validating per-language write path for one post's title and body.
// DIRECT-CALL ONLY: no Livewire component is mounted anywhere in this file, so these tests pin the
// backend layer independently of the editor. The action authorizes BEFORE it validates, so every
// negative validation test acts as an actor holding blog.edit first -- otherwise it would pass for
// the wrong reason (AuthorizationException). The action is always resolved from the container.
//
// Written at TDD Phase 3 step 1 (red), before the action exists.

use App\Actions\Blog\SetBlogPostTranslation;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
});

/**
 * @param  array<int, string>  $permissions
 */
function setBlogPostTranslationActor(array $permissions = ['blog.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

function storedBlogPostTranslation(BlogPost $post, StoreLanguage $language): ?BlogPostTranslation
{
    return BlogPostTranslation::query()
        ->where('blog_post_id', $post->id)
        ->where('store_language_id', $language->id)
        ->first();
}

function storedBlogPostTranslationCount(BlogPost $post, StoreLanguage $language): int
{
    return BlogPostTranslation::query()
        ->where('blog_post_id', $post->id)
        ->where('store_language_id', $language->id)
        ->count();
}

/**
 * @return array<string, array<int, string>>
 */
function setBlogPostTranslationErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function blogPostHoldingFrenchTitle(StoreLanguage $french, string $title): BlogPost
{
    $post = BlogPost::factory()->titled('Otro '.$title, '<p>otro</p>')->create();
    BlogPostTranslation::factory()->forLanguage($french)->create([
        'blog_post_id' => $post->id,
        'title' => $title,
    ]);

    return $post;
}

test('an actor holding only blog.view is refused, the refusal is logged and no row is written', function () {
    Log::spy();
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $actor = setBlogPostTranslationActor(['blog.view']);
    $this->actingAs($actor);

    expect(fn () => app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes'))
        ->toThrow(AuthorizationException::class);

    expect(storedBlogPostTranslationCount($post, $this->french))->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['target_type'] ?? null) === 'blog_post'
            && ($context['target_id'] ?? null) === $post->id)
        ->once();
});

test('authorization runs before validation: a blank title from an unpermitted actor is an AuthorizationException', function () {
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor(['blog.view']));

    expect(fn () => app(SetBlogPostTranslation::class)($post, $this->french, ''))
        ->toThrow(AuthorizationException::class);
});

test('an actor holding blog.edit and no store-languages permission stores the translation', function () {
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor(['blog.edit']));

    app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes', '<p>Corps</p>');

    expect(storedBlogPostTranslation($post, $this->french))
        ->title->toBe('Bottes')
        ->body->toBe('<p>Corps</p>');
});

test('a Super Admin holding no permission rows stores the translation through Gate::before', function () {
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $this->actingAs($actor);

    app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes');

    expect(storedBlogPostTranslationCount($post, $this->french))->toBe(1);
});

test('re-invoking for the same post and language updates the row and leaves the default language untouched', function () {
    $post = BlogPost::factory()->titled('Botas', '<p>es</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes');
    app(SetBlogPostTranslation::class)($post, $this->french, 'Chaussures');

    expect(storedBlogPostTranslationCount($post, $this->french))->toBe(1)
        ->and(storedBlogPostTranslation($post, $this->french)->title)->toBe('Chaussures')
        ->and(storedBlogPostTranslation($post, $this->spanish)->title)->toBe('Botas');
});

test('a blank or whitespace-only title is refused on titles.{languageId} and writes no row', function (string $blank) {
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    $errors = setBlogPostTranslationErrors(fn () => app(SetBlogPostTranslation::class)($post, $this->french, $blank));

    expect(array_keys($errors))->toBe(['titles.'.$this->french->id])
        ->and(storedBlogPostTranslationCount($post, $this->french))->toBe(0);
})->with(['empty' => [''], 'spaces' => ['   ']]);

test('a title whose derived slug another post holds in the same language is refused on the title, trashed posts included', function (bool $trashed) {
    $other = blogPostHoldingFrenchTitle($this->french, 'Chaussures de Pluie');

    if ($trashed) {
        $other->delete();
    }

    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    $errors = setBlogPostTranslationErrors(fn () => app(SetBlogPostTranslation::class)($post, $this->french, 'chaussures de pluie'));

    expect(array_keys($errors))->toBe(['titles.'.$this->french->id])
        ->and(storedBlogPostTranslationCount($post, $this->french))->toBe(0);
})->with(['live' => [false], 'trashed' => [true]]);

test('the same title is accepted in a different language, and a post may re-save its own current title', function () {
    $other = BlogPost::factory()->titled('Botas de invierno', '<p>x</p>')->create();
    $post = BlogPost::factory()->titled('Otras', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    // The identical title is held by $other in the DEFAULT language; French is a different language.
    app(SetBlogPostTranslation::class)($post, $this->french, 'Botas de invierno');
    app(SetBlogPostTranslation::class)($post, $this->french, 'Botas de invierno');

    expect(storedBlogPostTranslation($post, $this->french)->title)->toBe('Botas de invierno')
        ->and(storedBlogPostTranslationCount($post, $this->french))->toBe(1)
        ->and(storedBlogPostTranslation($other, $this->spanish)->title)->toBe('Botas de invierno');
});

test('a blank body is accepted for a non-default language even when the post is Published', function () {
    $post = BlogPost::factory()->published()->titled('Botas', '<p>es</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes', null);

    expect(storedBlogPostTranslation($post, $this->french)->body)->toBeNull();
});

test('empty and invisible bodies persist as null, so the language keeps falling back to the default body', function (string $body) {
    $post = BlogPost::factory()->titled('Botas', '<p>cuerpo es</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes', $body);

    expect(storedBlogPostTranslation($post, $this->french)->body)->toBeNull()
        ->and($post->fresh()->translated('body', $this->french->id))->toBe('<p>cuerpo es</p>');
})->with(['empty' => [''], 'empty paragraph' => ['<p></p>'], 'line break only' => ['<p><br></p>']]);

test('a script in the body is stored sanitized, with no component in the loop', function () {
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    app(SetBlogPostTranslation::class)($post, $this->french, 'Bottes', '<p>Corps</p><script>alert(1)</script>');

    $stored = storedBlogPostTranslation($post, $this->french)->body;

    expect($stored)->toContain('Corps')
        ->and($stored)->not->toContain('<script');
});

test('a raw body over the sanitizer input limit is refused on bodies.{languageId} and writes no row', function () {
    config()->set('html-sanitizer.max_input_length', 50);
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    $errors = setBlogPostTranslationErrors(fn () => app(SetBlogPostTranslation::class)(
        $post,
        $this->french,
        'Bottes',
        '<p>'.str_repeat('a', 100).'</p>',
    ));

    expect(array_keys($errors))->toBe(['bodies.'.$this->french->id])
        ->and(storedBlogPostTranslationCount($post, $this->french))->toBe(0);
});

test('the persisted slug is derived from the stored title and the signature carries no slug parameter', function () {
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    app(SetBlogPostTranslation::class)($post, $this->french, '  Chaussures d\'Été  ');

    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(SetBlogPostTranslation::class, '__invoke'))->getParameters(),
    );

    expect(storedBlogPostTranslation($post, $this->french)->slug)->toBe('chaussures-dete')
        ->and($parameters)->toBe(['blogPost', 'language', 'title', 'body']);
});

test('an inactive store language is still writable, since only tab rendering filters on is_active', function () {
    $german = StoreLanguage::factory()->inactive()->create();
    $post = BlogPost::factory()->titled('Botas', '<p>x</p>')->create();
    $this->actingAs(setBlogPostTranslationActor());

    app(SetBlogPostTranslation::class)($post, $german, 'Stiefel');

    expect(storedBlogPostTranslation($post, $german)->title)->toBe('Stiefel');
});
