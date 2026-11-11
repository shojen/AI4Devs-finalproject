<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\RestoreBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Actions\Products\SanitizeProductDescription;
use App\Actions\Translations\SetTranslation;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0078 (D-4, D-5, D-8, D-11, D-12, D-16, D-18): CreateBlogPost / UpdateBlogPost keep their
// signatures and now write the DEFAULT store language's title, derived slug and sanitized body into
// blog_post_translations, inside their existing transactions and below their self-authorization.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();
    StoreLanguage::factory()->default()->create(['code' => 'es']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function bpaCreate(array $overrides = []): BlogPost
{
    // Lazy on purpose: an eager factory default would recreate a default StoreLanguage even in tests
    // that deleted it (the category factory writes a default-language translation).
    $overrides['blogCategoryId'] ??= BlogCategory::factory()->create()->id;

    return app(CreateBlogPost::class)(...array_merge([
        'title' => 'Botas de invierno',
        'body' => '<p>Contenido</p>',
        'status' => 'draft',
        'publishedAt' => null,
        'tagNames' => [],
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bpaUpdate(BlogPost $post, array $overrides = []): BlogPost
{
    return app(UpdateBlogPost::class)(...array_merge([
        'blogPost' => $post,
        'title' => 'Botas de invierno',
        'body' => '<p>Contenido</p>',
        'blogCategoryId' => $post->blog_category_id,
        'status' => 'draft',
        'publishedAt' => null,
        'tagNames' => [],
    ], $overrides));
}

function bpaDefaultLanguage(): StoreLanguage
{
    return StoreLanguage::query()->where('is_default', true)->sole();
}

/**
 * @return array<string, int>
 */
function bpaSnapshot(): array
{
    return [
        'blog_posts' => DB::table('blog_posts')->count(),
        'blog_post_translations' => DB::table('blog_post_translations')->count(),
        'blog_tags' => DB::table('blog_tags')->count(),
        'blog_post_tag' => DB::table('blog_post_tag')->count(),
    ];
}

/**
 * The raw translation row of a post in one language.
 */
function bpaRow(BlogPost $post, StoreLanguage $language): ?array
{
    $row = DB::table('blog_post_translations')
        ->where('blog_post_id', $post->id)
        ->where('store_language_id', $language->id)
        ->first();

    return $row === null ? null : (array) $row;
}

function bpaFrench(): StoreLanguage
{
    return StoreLanguage::query()->where('code', 'fr')->first() ?? StoreLanguage::factory()->create(['code' => 'fr']);
}

/**
 * Removes every store language so no default exists. Called before anything else is created.
 */
function bpaWithoutDefaultLanguage(): void
{
    DB::table('store_languages')->delete();
    StoreLanguage::flushDefaultStoreLanguage();
}

// --- CreateBlogPost ------------------------------------------------------------------------

test('creating a post leaves one blog_posts row and exactly one default-language translation with the trimmed title, derived slug and body', function () {
    $post = bpaCreate(['title' => '  Botas de invierno  ', 'body' => '<p>Contenido</p>']);

    $translations = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->get();

    expect(DB::table('blog_posts')->count())->toBe(1)
        ->and(DB::table('blog_post_translations')->count())->toBe(1)
        ->and($translations)->toHaveCount(1)
        ->and($translations[0]->store_language_id)->toBe(bpaDefaultLanguage()->id)
        ->and($translations[0]->title)->toBe('Botas de invierno')
        ->and($translations[0]->slug)->toBe('botas-de-invierno')
        ->and($translations[0]->body)->toBe('<p>Contenido</p>');
});

test('creating a draft without a body stores a null body', function () {
    $post = bpaCreate(['body' => null]);

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('body'))->toBeNull();
});

test('a body that sanitizes to nothing is stored as null for a draft', function () {
    $post = bpaCreate(['body' => '<p></p><script>alert(1)</script>']);

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('body'))->toBeNull();
});

test('a body that sanitizes to nothing is refused on body when the post is published, and nothing is written', function () {
    $before = bpaSnapshot();
    $caught = null;

    try {
        bpaCreate(['body' => '<p></p>', 'status' => 'published']);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('body')
        ->and(bpaSnapshot())->toBe($before);
});

test('layer 1: a script tag in a body saved through the create action is stored sanitized', function () {
    $post = bpaCreate(['body' => '<p>Hola</p><script>alert(1)</script>']);

    $stored = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('body');

    expect($stored)->not->toContain('script')->and($stored)->toContain('<p>Hola</p>');
});

test('with no default store language, CreateBlogPost throws RuntimeException after authorization and writes nothing', function () {
    bpaWithoutDefaultLanguage();
    $categoryId = BlogCategory::factory()->withoutTranslations()->create()->id;

    expect(fn () => bpaCreate(['blogCategoryId' => $categoryId]))->toThrow(RuntimeException::class);

    expect(DB::table('blog_posts')->count())->toBe(0)
        ->and(DB::table('blog_post_translations')->count())->toBe(0);
});

test('with no default store language, an unauthorized actor is refused on authorization first, not on the missing language', function () {
    bpaWithoutDefaultLanguage();
    $categoryId = BlogCategory::factory()->withoutTranslations()->create()->id;
    $this->actingAs(User::factory()->create());

    expect(fn () => bpaCreate(['blogCategoryId' => $categoryId]))->toThrow(AuthorizationException::class);
});

test('an actor without blog.create is refused and nothing is written', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $this->actingAs(User::factory()->create()->givePermissionTo(['blog.view', 'blog.edit']));
    $before = bpaSnapshot();

    expect(fn () => bpaCreate(['blogCategoryId' => $categoryId]))->toThrow(AuthorizationException::class);

    expect(bpaSnapshot())->toBe($before);
});

test('a tag set that cannot be saved rolls back the post and its translation and every tag', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $before = bpaSnapshot();

    // Minting the tag fails AFTER the post and its translation were already written inside the
    // transaction (blog.create is enough to mint tags, so the failure is injected at the write).
    BlogTagTranslation::saving(function (): void {
        throw new RuntimeException('bpa injected tag failure');
    });

    expect(fn () => bpaCreate(['blogCategoryId' => $categoryId, 'tagNames' => ['running']]))
        ->toThrow(RuntimeException::class, 'bpa injected tag failure');

    expect(bpaSnapshot())->toBe($before);
});

test('a failing translation write rolls back the post', function () {
    $categoryId = BlogCategory::factory()->create()->id;

    BlogPostTranslation::saving(function (): void {
        throw new RuntimeException('bpa injected translation failure');
    });

    expect(fn () => bpaCreate(['blogCategoryId' => $categoryId]))->toThrow(RuntimeException::class, 'bpa injected translation failure');

    expect(DB::table('blog_posts')->count())->toBe(0)
        ->and(DB::table('blog_post_translations')->count())->toBe(0);
});

test('a title with no usable slug is refused on title and nothing is written', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $before = bpaSnapshot();
    $caught = null;

    try {
        bpaCreate(['title' => '!!!', 'blogCategoryId' => $categoryId]);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('title')
        ->and(bpaSnapshot())->toBe($before);
});

// --- Slug uniqueness per language ----------------------------------------------------------

// Three assertions in one scenario so a rule that rejects everything cannot pass: the same language
// is refused, a different title is accepted, and the same slug held only in ANOTHER language is accepted.
test('a slug is unique within one language: the same slug is refused, a different title is accepted and the same slug held only in French is accepted', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $fr = bpaFrench();

    // A decoy holding "resumen-semanal" ONLY in French.
    $decoy = BlogPost::factory()->withoutTranslations()->create(['blog_category_id' => $categoryId]);
    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $decoy->id, 'title' => 'Resumen semanal']);

    // A post holding it in the default language.
    bpaCreate(['title' => 'Resumen diario', 'blogCategoryId' => $categoryId]);

    // (1) same language: refused on title
    $caught = null;

    try {
        bpaCreate(['title' => 'Resumen diario!!', 'blogCategoryId' => $categoryId]);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('title');

    // (2) a different title: accepted
    $other = bpaCreate(['title' => 'Otro titulo', 'blogCategoryId' => $categoryId]);

    // (3) the slug held only in French: accepted in the default language
    $sameAsFrench = bpaCreate(['title' => 'Resumen semanal', 'blogCategoryId' => $categoryId]);

    expect(bpaRow($other, bpaDefaultLanguage())['slug'])->toBe('otro-titulo')
        ->and(bpaRow($sameAsFrench, bpaDefaultLanguage())['slug'])->toBe('resumen-semanal');
});

test('a post keeps its own slug when re-saved with the same title', function () {
    $post = bpaCreate(['title' => 'Resumen semanal']);

    $updated = bpaUpdate($post, ['title' => 'Resumen semanal', 'body' => '<p>Nuevo cuerpo</p>']);

    $row = bpaRow($updated, bpaDefaultLanguage());

    expect($row['slug'])->toBe('resumen-semanal')
        ->and($row['body'])->toBe('<p>Nuevo cuerpo</p>')
        ->and(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(1);
});

test('updating a post to a title whose slug another post holds in the same language is refused on title and nothing changes', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    bpaCreate(['title' => 'Resumen semanal', 'blogCategoryId' => $categoryId]);
    $post = bpaCreate(['title' => 'Otro', 'blogCategoryId' => $categoryId]);
    $before = bpaRow($post, bpaDefaultLanguage());

    $caught = null;

    try {
        bpaUpdate($post, ['title' => 'Resumen semanal']);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('title')
        ->and(bpaRow($post, bpaDefaultLanguage()))->toBe($before);
});

test('a trashed post keeps its slug reserved in the language it was written in', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $trashed = bpaCreate(['title' => 'Resumen semanal', 'blogCategoryId' => $categoryId]);
    app(DeleteBlogPost::class)($trashed);

    $caught = null;

    try {
        bpaCreate(['title' => 'Resumen semanal', 'blogCategoryId' => $categoryId]);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('title');
});

test('a trashed post slug is still free in a language it was never translated into', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $fr = bpaFrench();
    $trashed = bpaCreate(['title' => 'Resumen semanal', 'blogCategoryId' => $categoryId]);
    app(DeleteBlogPost::class)($trashed);
    $other = BlogPost::factory()->withoutTranslations()->create(['blog_category_id' => $categoryId]);

    app(SetTranslation::class)($other, $fr, ['title' => 'Resumen semanal', 'body' => null]);

    expect(bpaRow($other, $fr)['slug'])->toBe('resumen-semanal');
});

test('restoring a deleted post returns every language content unchanged', function () {
    $post = bpaCreate(['title' => 'Botas de invierno', 'body' => '<p>es</p>']);
    $fr = bpaFrench();
    app(SetTranslation::class)($post, $fr, ['title' => "Bottes d'hiver", 'body' => '<p>fr</p>']);
    $before = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->orderBy('store_language_id')->get()->map(fn ($r) => (array) $r)->all();

    app(DeleteBlogPost::class)($post);
    app(RestoreBlogPost::class)(BlogPost::withTrashed()->findOrFail($post->id));

    $after = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->orderBy('store_language_id')->get()->map(fn ($r) => (array) $r)->all();

    expect($after)->toHaveCount(2)->and($after)->toBe($before);
});

// --- The 1062 race ---------------------------------------------------------------------------

test('a slug race lost at the unique index is re-keyed to title by index name', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $rival = BlogPost::factory()->withoutTranslations()->create(['blog_category_id' => $categoryId]);
    $language = bpaDefaultLanguage();

    // The rival claims the slug between the pre-flight check and the INSERT, inside the same save.
    BlogPostTranslation::saving(function (BlogPostTranslation $translation) use ($rival, $language): void {
        if (DB::table('blog_post_translations')->where('blog_post_id', $rival->id)->exists()) {
            return;
        }

        DB::table('blog_post_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_post_id' => $rival->id,
            'store_language_id' => $language->id,
            'title' => 'Carrera',
            'slug' => 'carrera',
            'body' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = null;

    try {
        bpaCreate(['title' => 'Carrera', 'blogCategoryId' => $categoryId]);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('title')
        ->and(DB::table('blog_posts')->where('id', '!=', $rival->id)->count())->toBe(0);
});

test('a non-slug integrity error (the category foreign key) is not swallowed as a title error on create', function () {
    $category = BlogCategory::factory()->create();
    $categoryId = $category->id;

    BlogPost::creating(function () use ($categoryId): void {
        DB::table('blog_categories')->where('id', $categoryId)->delete();
    });

    $caught = null;

    try {
        bpaCreate(['blogCategoryId' => $categoryId]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1452)
        ->and(DB::table('blog_posts')->count())->toBe(0);
});

test('a non-slug integrity error (the category foreign key) is not swallowed as a title error on update', function () {
    $post = bpaCreate();
    $newCategoryId = BlogCategory::factory()->create()->id;

    BlogPost::updating(function () use ($newCategoryId): void {
        DB::table('blog_categories')->where('id', $newCategoryId)->delete();
    });

    $caught = null;

    try {
        bpaUpdate($post, ['blogCategoryId' => $newCategoryId]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1452);
});

// The pair-unique 1062 (two concurrent FIRST translations of one post) is deliberately rethrown, not
// re-keyed to title: only the language-slug index maps to a title error.
test('a lost race on the post-and-language pair unique is rethrown as a QueryException, not reported as a title error', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();
    $language = bpaDefaultLanguage();

    BlogPostTranslation::saving(function (BlogPostTranslation $translation) use ($post, $language): void {
        if (DB::table('blog_post_translations')->where('blog_post_id', $post->id)->exists()) {
            return;
        }

        DB::table('blog_post_translations')->insert([
            'id' => (string) Str::uuid7(),
            'blog_post_id' => $post->id,
            'store_language_id' => $language->id,
            'title' => 'Rival',
            'slug' => 'rival',
            'body' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = null;

    try {
        bpaUpdate($post, ['title' => 'Nuevo titulo']);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1062)
        ->and($caught->getMessage())->toContain('blog_post_translations_post_language_unique');
});

// --- UpdateBlogPost ------------------------------------------------------------------------

test('updating replaces the default-language row in place instead of adding one, re-deriving only that language slug', function () {
    $post = bpaCreate(['title' => 'Botas de invierno']);
    $fr = bpaFrench();
    app(SetTranslation::class)($post, $fr, ['title' => "Bottes d'hiver", 'body' => '<p>fr</p>']);

    $defaultRowId = bpaRow($post, bpaDefaultLanguage())['id'];
    $frenchBefore = bpaRow($post, $fr);

    bpaUpdate($post, ['title' => 'Botas de nieve', 'body' => '<p>Nuevo</p>']);

    $defaultRow = bpaRow($post, bpaDefaultLanguage());

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(2)
        ->and($defaultRow['id'])->toBe($defaultRowId)
        ->and($defaultRow['title'])->toBe('Botas de nieve')
        ->and($defaultRow['slug'])->toBe('botas-de-nieve')
        ->and($defaultRow['body'])->toBe('<p>Nuevo</p>')
        ->and(bpaRow($post, $fr))->toBe($frenchBefore);
});

test('updating a post leaves another post translations untouched', function () {
    $post = bpaCreate(['title' => 'Primero']);
    $decoy = bpaCreate(['title' => 'Segundo']);
    app(SetTranslation::class)($decoy, bpaFrench(), ['title' => 'Deuxieme', 'body' => '<p>fr</p>']);
    $decoyBefore = DB::table('blog_post_translations')->where('blog_post_id', $decoy->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    bpaUpdate($post, ['title' => 'Primero retitulado']);

    $decoyAfter = DB::table('blog_post_translations')->where('blog_post_id', $decoy->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    expect($decoyAfter)->toBe($decoyBefore);
});

test('updating a post that has no default-language row inserts one and keeps its French row', function () {
    $fr = bpaFrench();
    $post = BlogPost::factory()->withoutTranslations()->create();
    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $post->id, 'title' => 'Solo francais']);
    $frenchBefore = bpaRow($post, $fr);

    bpaUpdate($post, ['title' => 'Titulo nuevo', 'body' => '<p>Cuerpo</p>']);

    $default = bpaRow($post, bpaDefaultLanguage());

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(2)
        ->and($default['title'])->toBe('Titulo nuevo')
        ->and($default['body'])->toBe('<p>Cuerpo</p>')
        ->and(bpaRow($post, $fr))->toBe($frenchBefore);
});

test('with no default store language, UpdateBlogPost throws RuntimeException after authorization and writes nothing', function () {
    bpaWithoutDefaultLanguage();
    $post = BlogPost::factory()->withoutTranslations()->create([
        'blog_category_id' => BlogCategory::factory()->withoutTranslations()->create()->id,
    ]);

    expect(fn () => bpaUpdate($post, ['title' => 'Nuevo']))->toThrow(RuntimeException::class);

    expect(DB::table('blog_post_translations')->count())->toBe(0);
});

test('a blank title on update is refused and the translation row is unchanged', function () {
    $post = bpaCreate(['title' => 'Botas de invierno']);
    $before = bpaRow($post, bpaDefaultLanguage());

    expect(fn () => bpaUpdate($post, ['title' => '   ']))->toThrow(ValidationException::class);

    expect(bpaRow($post, bpaDefaultLanguage()))->toBe($before);
});

test('layer 1: a script tag in a body saved through the update action is stored sanitized', function () {
    $post = bpaCreate();

    bpaUpdate($post, ['body' => '<p>Hola</p><script>alert(1)</script>']);

    $stored = bpaRow($post, bpaDefaultLanguage())['body'];

    expect($stored)->not->toContain('script')->and($stored)->toContain('<p>Hola</p>');
});

test('a body that sanitizes to nothing is stored as null on update for a draft and refused when publishing', function () {
    $post = bpaCreate();

    bpaUpdate($post, ['body' => '<p></p>']);

    expect(bpaRow($post, bpaDefaultLanguage())['body'])->toBeNull();

    $caught = null;

    try {
        bpaUpdate($post, ['body' => '<p></p>', 'status' => 'published']);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('body');
});

test('a failing translation write rolls back the parent update', function () {
    $post = bpaCreate(['title' => 'Original']);
    $otherCategoryId = BlogCategory::factory()->create()->id;
    $originalCategoryId = $post->blog_category_id;

    BlogPostTranslation::saving(function (): void {
        throw new RuntimeException('bpa injected translation failure');
    });

    expect(fn () => bpaUpdate($post, ['title' => 'Cambiado', 'blogCategoryId' => $otherCategoryId, 'status' => 'published']))
        ->toThrow(RuntimeException::class, 'bpa injected translation failure');

    $fresh = $post->fresh();

    expect($fresh->blog_category_id)->toBe($originalCategoryId)
        ->and($fresh->status->value)->toBe('draft')
        ->and(bpaRow($post, bpaDefaultLanguage())['title'])->toBe('Original');
});

test('a tag set that cannot be saved on update rolls back the translation write too', function () {
    $post = bpaCreate(['title' => 'Original']);
    $this->actingAs(User::factory()->create()->givePermissionTo(['blog.edit']));

    expect(fn () => bpaUpdate($post, ['title' => 'Cambiado', 'tagNames' => ['running']]))
        ->toThrow(AuthorizationException::class);

    expect(bpaRow($post, bpaDefaultLanguage())['title'])->toBe('Original')
        ->and(BlogTag::count())->toBe(0);
});

test('UpdateBlogPost by an actor without blog.edit is refused and logged and the translation is unchanged', function () {
    $post = bpaCreate(['title' => 'Original']);
    $actor = User::factory()->create();
    $this->actingAs($actor);

    Log::spy();

    expect(fn () => bpaUpdate($post, ['title' => 'Should Not Persist']))->toThrow(AuthorizationException::class);

    expect(bpaRow($post, bpaDefaultLanguage())['title'])->toBe('Original');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_id'] ?? null) === $post->id)
        ->once();
});

// --- D-18 ------------------------------------------------------------------------------------

test('an editor holding blog.edit and no store-languages permission updates a post title', function () {
    $post = bpaCreate(['title' => 'Original']);
    $editor = User::factory()->create()->givePermissionTo(['blog.view', 'blog.edit']);
    $this->actingAs($editor);

    expect($editor->getAllPermissions()->pluck('name')->filter(fn (string $name) => str_starts_with($name, 'store-languages')))->toBeEmpty();

    bpaUpdate($post, ['title' => 'Retitulado']);

    expect(bpaRow($post, bpaDefaultLanguage())['title'])->toBe('Retitulado');
});

test('an editor holding blog.create and no store-languages permission creates a post', function () {
    $categoryId = BlogCategory::factory()->create()->id;
    $creator = User::factory()->create()->givePermissionTo(['blog.create']);
    $this->actingAs($creator);

    $post = bpaCreate(['blogCategoryId' => $categoryId]);

    expect(bpaRow($post, bpaDefaultLanguage()))->not->toBeNull();
});

// --- Sanitized-body size limit (audit F-1) -------------------------------------------------
// Pass 1 (the action's cleanBody) can ENLARGE a body: `=`, `+`, `@` and the backtick become 5-byte
// entities. Pass 2 (the model hook) would then truncate or blank it, so the actions must measure the
// SANITIZED body too and refuse it with a ValidationException on `body`, writing nothing.

/**
 * Raw bodies under the 262144-byte limit whose sanitized form is over it.
 *
 * @return array<string, array{string}>
 */
function bpaEnlargingBodies(): array
{
    return [
        'ascii entities cut mid-body' => ['<p>'.str_repeat('a=b ', 60000).'</p>'],
        'multibyte cut that would blank the body' => ['<p>xxxx'.str_repeat('é=', 80000).'</p>'],
    ];
}

dataset('bpaEnlargingBodies', fn () => bpaEnlargingBodies());

test('creating a post whose body only exceeds the limit once sanitized is refused on body and writes nothing', function (string $body, string $status) {
    $categoryId = BlogCategory::factory()->create()->id;
    $publishedAt = $status === 'scheduled' ? now()->addDay()->toDateTimeString() : null;
    $before = bpaSnapshot();

    expect(strlen($body))->toBeLessThan((int) config('html-sanitizer.max_input_length'));

    try {
        bpaCreate(['blogCategoryId' => $categoryId, 'body' => $body, 'status' => $status, 'publishedAt' => $publishedAt]);
        $this->fail('Expected a ValidationException on body.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('body');
    }

    expect(bpaSnapshot())->toBe($before);
})->with('bpaEnlargingBodies')->with(['draft', 'published', 'scheduled']);

test('updating a post with a body that only exceeds the limit once sanitized is refused on body and changes nothing', function (string $body, string $status) {
    $post = bpaCreate(['title' => 'Original', 'body' => '<p>original</p>']);
    $publishedAt = $status === 'scheduled' ? now()->addDay()->toDateTimeString() : null;
    $parentBefore = DB::table('blog_posts')->where('id', $post->id)->first();
    $rowBefore = bpaRow($post, bpaDefaultLanguage());

    try {
        bpaUpdate($post, ['title' => 'Cambiado', 'body' => $body, 'status' => $status, 'publishedAt' => $publishedAt]);
        $this->fail('Expected a ValidationException on body.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('body');
    }

    expect(DB::table('blog_posts')->where('id', $post->id)->first())->toEqual($parentBefore)
        ->and(bpaRow($post, bpaDefaultLanguage()))->toBe($rowBefore);
})->with('bpaEnlargingBodies')->with(['draft', 'published', 'scheduled']);

test('a body that stays just under the limit after sanitizing still saves, on create and on update', function () {
    $max = (int) config('html-sanitizer.max_input_length');
    $body = '<p>'.str_repeat('a=', 43000).'</p>';

    $post = bpaCreate(['body' => $body, 'status' => 'published']);
    $stored = bpaRow($post, bpaDefaultLanguage())['body'];

    expect(strlen($stored))->toBeLessThanOrEqual($max)->toBeGreaterThan(strlen($body))
        ->and($stored)->toEndWith('a&#61;</p>');

    bpaUpdate($post, ['body' => $body, 'status' => 'published']);

    expect(bpaRow($post, bpaDefaultLanguage())['body'])->toBe($stored);
});

// --- Trailing whitespace left by a dropped element (audit R-1) -----------------------------
// cleanBody() trims the RAW input, but the sanitized output can end in a space (a dropped trailing
// <script> leaves the space before it). The hook measures that stored body untrimmed, so the action
// must too, or a boundary body becomes a LogicException (500) instead of a `body` validation error.

/**
 * A raw body (trimmed length under the limit) that sanitizes to exactly $sanitizedBytes bytes,
 * the last of which is the space left behind by a dropped trailing <script>.
 */
function bpaBodyEndingInDroppedScriptSpace(int $sanitizedBytes): string
{
    // 100 `=` become 5-byte entities, which makes the sanitized form longer than the raw one.
    $entities = 100;
    $filler = $sanitizedBytes - 8 - ($entities * 5);

    return '<p>'.str_repeat('a', $filler).str_repeat('=', $entities).'</p> <script>x</script>';
}

test('a body that exceeds the limit by one byte only through a trailing space is refused on body on create, not a LogicException', function () {
    $max = (int) config('html-sanitizer.max_input_length');
    $body = bpaBodyEndingInDroppedScriptSpace($max + 1);
    $categoryId = BlogCategory::factory()->create()->id;
    $before = bpaSnapshot();

    expect(strlen(trim($body)))->toBeLessThanOrEqual($max)
        ->and(strlen(app(SanitizeProductDescription::class)(trim($body))))->toBe($max + 1);

    try {
        bpaCreate(['blogCategoryId' => $categoryId, 'body' => $body, 'status' => 'published']);
        $this->fail('Expected a ValidationException on body.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('body');
    }

    expect(bpaSnapshot())->toBe($before);
});

test('a body that exceeds the limit by one byte only through a trailing space is refused on body on update, not a LogicException', function () {
    $max = (int) config('html-sanitizer.max_input_length');
    $body = bpaBodyEndingInDroppedScriptSpace($max + 1);
    $post = bpaCreate(['title' => 'Original', 'body' => '<p>original</p>']);
    $parentBefore = DB::table('blog_posts')->where('id', $post->id)->first();
    $rowBefore = bpaRow($post, bpaDefaultLanguage());
    $before = bpaSnapshot();

    try {
        bpaUpdate($post, ['title' => 'Cambiado', 'body' => $body, 'status' => 'published']);
        $this->fail('Expected a ValidationException on body.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('body');
    }

    expect(bpaSnapshot())->toBe($before)
        ->and(DB::table('blog_posts')->where('id', $post->id)->first())->toEqual($parentBefore)
        ->and(bpaRow($post, bpaDefaultLanguage()))->toBe($rowBefore);
});

test('a body whose sanitized output, trailing space included, is exactly the limit still saves on create and update', function () {
    $max = (int) config('html-sanitizer.max_input_length');
    $body = bpaBodyEndingInDroppedScriptSpace($max);

    $post = bpaCreate(['body' => $body, 'status' => 'published']);

    expect(strlen(bpaRow($post, bpaDefaultLanguage())['body']))->toBe($max);

    bpaUpdate($post, ['title' => 'Otro', 'body' => $body, 'status' => 'published']);

    expect(strlen(bpaRow($post, bpaDefaultLanguage())['body']))->toBe($max);
});
