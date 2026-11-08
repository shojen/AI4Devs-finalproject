<?php

use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;

// Story 0078 (D-10): the per-field fallback proof on REAL fields, mirroring 0076's block in
// tests/Feature/Models/ProductTranslatableTest.php, with a required `title` beside a nullable `body`.

beforeEach(function () {
    StoreLanguage::flushDefaultStoreLanguage();
});

/**
 * @return array{default: StoreLanguage, fr: StoreLanguage}
 */
function bp78FallbackLanguages(): array
{
    return [
        'default' => StoreLanguage::factory()->default()->create(['code' => 'es']),
        'fr' => StoreLanguage::factory()->create(['code' => 'fr']),
    ];
}

function bp78FallbackTranslate(BlogPost $post, StoreLanguage $language, string $title, ?string $body = null): void
{
    BlogPostTranslation::factory()->forLanguage($language)->create([
        'blog_post_id' => $post->id,
        'title' => $title,
        'body' => $body,
    ]);
}

test('a post translated into the requested language returns that language', function () {
    ['default' => $default, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $default, 'Botas de invierno');
    bp78FallbackTranslate($post, $fr, "Bottes d'hiver");

    $post = $post->fresh();

    expect($post->translated('title', $fr->id))->toBe("Bottes d'hiver")
        ->and($post->translated('title'))->toBe('Botas de invierno');
});

test('a missing translation falls back to the default store language', function () {
    ['default' => $default, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $default, 'Botas de invierno');

    expect($post->fresh()->translated('title', $fr->id))->toBe('Botas de invierno');
});

test('a post translated in neither language resolves each field to null without throwing', function () {
    ['fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();

    $post = $post->fresh();

    expect($post->translated('title', $fr->id))->toBeNull()
        ->and($post->translated('body', $fr->id))->toBeNull();
});

test('a half-translated post keeps its French title and falls back only on the body', function () {
    ['default' => $default, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $default, 'Botas de invierno', '<p>Cuerpo por defecto</p>');
    bp78FallbackTranslate($post, $fr, "Bottes d'hiver", null);

    $post = $post->fresh();

    expect($post->translated('body', $fr->id))->toBe('<p>Cuerpo por defecto</p>')
        ->and($post->translated('title', $fr->id))->toBe("Bottes d'hiver");
});

test('the mirror: a French body is kept while an empty French title falls back to the default title', function () {
    ['default' => $default, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $default, 'Botas de invierno', '<p>Por defecto</p>');
    // title is NOT NULL, so "absent" is an empty string, which translated() treats as absent.
    bp78FallbackTranslate($post, $fr, '', '<p>Corps francais</p>');

    $post = $post->fresh();

    expect($post->translated('title', $fr->id))->toBe('Botas de invierno')
        ->and($post->translated('body', $fr->id))->toBe('<p>Corps francais</p>');
});

test('a French body stored as an empty string falls back to the default body', function () {
    ['default' => $default, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $default, 'Botas de invierno', '<p>Por defecto</p>');
    bp78FallbackTranslate($post, $fr, "Bottes d'hiver", '');

    expect($post->fresh()->translated('body', $fr->id))->toBe('<p>Por defecto</p>');
});

test('an inactive requested language still resolves, because removal preserves stored content', function () {
    ['default' => $default, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $default, 'Botas de invierno');
    bp78FallbackTranslate($post, $fr, "Bottes d'hiver");
    $fr->forceFill(['is_active' => false])->save();

    expect($post->fresh()->translated('title', $fr->id))->toBe("Bottes d'hiver");
});

test('after the store default changes, a post translated only into the old default resolves to null without error', function () {
    ['default' => $old, 'fr' => $fr] = bp78FallbackLanguages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78FallbackTranslate($post, $old, 'Botas de invierno', '<p>x</p>');

    expect($post->fresh()->translated('title'))->toBe('Botas de invierno');

    $old->forceFill(['is_default' => false])->save();
    $fr->forceFill(['is_default' => true])->save();
    StoreLanguage::flushDefaultStoreLanguage();

    $post = $post->fresh();

    expect($post->translated('title'))->toBeNull()
        ->and($post->translated('body'))->toBeNull();
});
