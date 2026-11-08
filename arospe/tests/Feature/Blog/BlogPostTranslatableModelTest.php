<?php

use App\Concerns\HasTranslations;
use App\Concerns\Translatable;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Story 0078 (D-4, D-5, D-9, D-13, D-14): BlogPost as a Translatable, soft-deleting parent. These are
// NEW tests; the existing BlogPostTest.php is ported by a later step.

beforeEach(function () {
    StoreLanguage::flushDefaultStoreLanguage();
});

/**
 * @return array{default: StoreLanguage, fr: StoreLanguage}
 */
function bp78Languages(): array
{
    return [
        'default' => StoreLanguage::factory()->default()->create(['code' => 'es']),
        'fr' => StoreLanguage::factory()->create(['code' => 'fr']),
    ];
}

function bp78Translate(BlogPost $post, StoreLanguage $language, string $title, ?string $body = null, ?string $createdAt = null): BlogPostTranslation
{
    $translation = BlogPostTranslation::factory()->forLanguage($language)->create([
        'blog_post_id' => $post->id,
        'title' => $title,
        'body' => $body,
    ]);

    if ($createdAt !== null) {
        DB::table('blog_post_translations')->where('id', $translation->id)->update(['created_at' => $createdAt]);
    }

    return $translation;
}

test('BlogPost implements Translatable, uses HasTranslations and still soft-deletes', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();

    expect($post)->toBeInstanceOf(Translatable::class)
        ->and(class_uses_recursive(BlogPost::class))->toContain(HasTranslations::class)
        ->and(class_uses_recursive(BlogPost::class))->toContain(SoftDeletes::class)
        ->and($post->translations())->toBeInstanceOf(HasMany::class)
        ->and($post->translations()->getRelated())->toBeInstanceOf(BlogPostTranslation::class);
});

test('make() fills only blog_category_id and status, dropping title, body, slug, published_at and created_by', function () {
    $category = BlogCategory::factory()->create();

    $post = BlogPost::make([
        'title' => 'Forjado',
        'body' => '<p>x</p>',
        'slug' => 'evil',
        'published_at' => now(),
        'created_by' => 'someone',
        'blog_category_id' => $category->id,
        'status' => BlogPostStatus::Published,
    ]);

    expect((new BlogPost)->getFillable())->toBe(['blog_category_id', 'status'])
        ->and($post->getAttributes())->not->toHaveKey('title')
        ->and($post->getAttributes())->not->toHaveKey('body')
        ->and($post->getAttributes())->not->toHaveKey('slug')
        ->and($post->getAttributes())->not->toHaveKey('published_at')
        ->and($post->getAttributes())->not->toHaveKey('created_by')
        ->and($post->blog_category_id)->toBe($category->id)
        ->and($post->status)->toBe(BlogPostStatus::Published);
});

test('BlogPost registers no saving hook and no deleting hook', function () {
    // Models boot lazily: instantiate one so a booted() hook, if any, has registered its listeners.
    new BlogPost;

    $dispatcher = BlogPost::getEventDispatcher();

    expect($dispatcher->hasListeners('eloquent.saving: '.BlogPost::class))->toBeFalse()
        ->and($dispatcher->hasListeners('eloquent.deleting: '.BlogPost::class))->toBeFalse();
});

test('a post trashed and restored keeps all of its translation rows', function () {
    ['default' => $default, 'fr' => $fr] = bp78Languages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78Translate($post, $default, 'Botas de invierno', '<p>es</p>');
    bp78Translate($post, $fr, "Bottes d'hiver", '<p>fr</p>');

    $post->delete();
    $post->restore();

    $rows = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->get()->keyBy('store_language_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$default->id]->title)->toBe('Botas de invierno')
        ->and($rows[$default->id]->slug)->toBe('botas-de-invierno')
        ->and($rows[$default->id]->body)->toBe('<p>es</p>')
        ->and($rows[$fr->id]->title)->toBe("Bottes d'hiver")
        ->and($rows[$fr->id]->slug)->toBe(Str::slug("Bottes d'hiver"));
});

test('displayTitle() prefers the default-language title', function () {
    ['default' => $default, 'fr' => $fr] = bp78Languages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78Translate($post, $fr, "Bottes d'hiver");
    bp78Translate($post, $default, 'Botas de invierno');

    expect($post->fresh()->displayTitle())->toBe('Botas de invierno');
});

test('displayTitle() falls back to any stored translation when the default language has none', function () {
    ['fr' => $fr] = bp78Languages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78Translate($post, $fr, "Bottes d'hiver");

    expect($post->fresh()->displayTitle())->toBe("Bottes d'hiver");
});

test('displayTitle() returns null for a post with no translation at all', function () {
    bp78Languages();
    $post = BlogPost::factory()->withoutTranslations()->create();

    expect($post->fresh()->displayTitle())->toBeNull();
});

test('scopeWithListTranslations selects only blog_post_id, store_language_id and title, never body or slug', function () {
    ['default' => $default] = bp78Languages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78Translate($post, $default, 'Botas de invierno', '<p>cuerpo</p>');
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    BlogPost::query()->withListTranslations()->get();

    $translationQuery = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'from `blog_post_translations`'));

    expect($translationQuery)->not->toBeNull();

    $selectList = substr($translationQuery, 0, strpos($translationQuery, ' from '));

    expect($selectList)->toContain('`blog_post_id`')
        ->and($selectList)->toContain('`store_language_id`')
        ->and($selectList)->toContain('`title`')
        ->and($selectList)->not->toContain('`body`')
        ->and($selectList)->not->toContain('`slug`')
        ->and($selectList)->not->toContain('*');
});

test('scopeWithListTranslations loads only the requested and default languages', function () {
    ['default' => $default, 'fr' => $fr] = bp78Languages();
    $de = StoreLanguage::factory()->create(['code' => 'de']);
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78Translate($post, $default, 'Botas');
    bp78Translate($post, $fr, 'Bottes');
    bp78Translate($post, $de, 'Stiefel');

    $loaded = BlogPost::query()->withListTranslations($fr->id)->findOrFail($post->id);

    expect($loaded->translations->pluck('store_language_id')->all())->toEqualCanonicalizing([$default->id, $fr->id])
        ->and($loaded->translated('title', $fr->id))->toBe('Bottes');
});

// The footgun the scope's docblock records (D-13): the partial relation has no body column.
test('translated(body) on the partial list relation returns null even though a body is stored', function () {
    ['default' => $default] = bp78Languages();
    $post = BlogPost::factory()->withoutTranslations()->create();
    bp78Translate($post, $default, 'Botas de invierno', '<p>cuerpo</p>');

    $loaded = BlogPost::query()->withListTranslations()->findOrFail($post->id);

    expect($loaded->translated('title'))->toBe('Botas de invierno')
        ->and($loaded->translated('body'))->toBeNull();
});
