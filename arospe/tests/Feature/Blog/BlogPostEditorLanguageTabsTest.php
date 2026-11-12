<?php

// Component-level language-tab behaviour for App\Livewire\BlogPosts\Editor (story 0079): the tab
// set, per-field hydration of titles/bodies, the no-fallback-leak rule, tab switching, the payload
// narrowing and the locked state. Written at TDD Phase 3 step 1 (red), before the tabbed Editor
// exists.
//
// D-15: no assertion matches a language name or a two-letter code; every language is addressed by
// its id. Language names are generated words nobody asserts on.

use App\Livewire\BlogPosts\Editor;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // By-name tab order is default, french, german; the default is NOT alphabetically first.
    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($actor);
});

/**
 * A post holding exactly the given rows, keyed by store language id; no other row exists.
 *
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function blogTabsPost(array $rowsByLanguageId): BlogPost
{
    $post = BlogPost::factory()->withoutTranslations()->create();

    foreach ($rowsByLanguageId as $languageId => $attributes) {
        BlogPostTranslation::factory()->create(array_merge([
            'blog_post_id' => $post->id,
            'store_language_id' => $languageId,
        ], $attributes));
    }

    return $post;
}

/**
 * @return array<string, string>
 */
function blogTabsRow(string $suffix): array
{
    return [
        'title' => 'Title '.$suffix,
        'body' => '<p>Body '.$suffix.'</p>',
    ];
}

/**
 * @return list<string>
 */
function blogTabsPersistedLanguageIds(BlogPost $post): array
{
    return BlogPostTranslation::query()
        ->where('blog_post_id', $post->id)
        ->pluck('store_language_id')
        ->sort()
        ->values()
        ->all();
}

// =====================================================================
// Tab set and hydration
// =====================================================================

test('the tab set equals the active languages, default first then by name', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('defaultLanguageId', $this->spanish->id)
        ->assertSet('activeLanguageId', $this->spanish->id);

    expect(array_column($component->get('languages'), 'id'))->toBe([$this->spanish->id, $this->french->id, $this->german->id]);
});

test('a store with a single active language renders one tab and saves', function () {
    $this->french->forceFill(['is_active' => false])->save();
    $this->german->forceFill(['is_active' => false])->save();
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);

    expect($component->get('languages'))->toHaveCount(1);

    $component->set('titles.'.$this->spanish->id, 'Renamed')->call('save')->assertHasNoErrors();

    expect(BlogPostTranslation::query()->where('blog_post_id', $post->id)->value('title'))->toBe('Renamed');
});

test('loading hydrates title and body for every active language from its own row, field by field', function () {
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->french->id => blogTabsRow('fr'),
        $this->german->id => blogTabsRow('de'),
    ]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);

    foreach (['es' => $this->spanish, 'fr' => $this->french, 'de' => $this->german] as $suffix => $language) {
        $component
            ->assertSet('titles.'.$language->id, 'Title '.$suffix)
            ->assertSet('bodies.'.$language->id, '<p>Body '.$suffix.'</p>');
    }
});

test('an inactive language with a stored translation gets no tab and no hydrated value', function () {
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->retired->id => blogTabsRow('retired'),
    ]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);

    expect(array_keys($component->get('titles')))->not->toContain($this->retired->id)
        ->and(array_keys($component->get('bodies')))->not->toContain($this->retired->id);
});

test('the fallback never leaks into either edit field of an untranslated language', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('titles.'.$this->french->id, '')
        ->assertSet('bodies.'.$this->french->id, '');
});

test('hydration is per field, not per row: a French title with no French body leaves the body empty, never the Spanish one', function () {
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->french->id => ['title' => 'Titre seul', 'body' => null],
    ]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('titles.'.$this->french->id, 'Titre seul')
        ->assertSet('bodies.'.$this->french->id, '');
});

test('no hydrated value is null, including on the create route', function () {
    $post = blogTabsPost([$this->spanish->id => ['title' => 'Only a title', 'body' => null]]);

    foreach ([Livewire::test(Editor::class, ['blogPost' => $post]), Livewire::test(Editor::class)] as $component) {
        foreach (['titles', 'bodies'] as $property) {
            $values = $component->get($property);

            expect($values)->not->toBeEmpty();

            foreach ($values as $value) {
                expect($value)->toBeString();
            }
        }
    }
});

test('when the store default changes under an existing catalog the new default tab opens blank and the old one keeps its content', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);
    $this->spanish->forceFill(['is_default' => false])->save();
    $this->french->forceFill(['is_default' => true])->save();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('defaultLanguageId', $this->french->id)
        ->assertSet('titles.'.$this->french->id, '')
        ->assertSet('titles.'.$this->spanish->id, 'Title es');
});

// =====================================================================
// Switching
// =====================================================================

test('setActiveLanguageTab switches to an active language id', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('setActiveLanguageTab', $this->french->id)
        ->assertSet('activeLanguageId', $this->french->id);
});

test('setActiveLanguageTab refuses an inactive, unknown or malformed id as not found, keeps the tab and never reaches SetBlogPostTranslation', function (string $kind) {
    $this->withoutExceptionHandling();
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);
    $badId = match ($kind) {
        'inactive' => $this->retired->id,
        'unknown' => (string) fake()->uuid(),
        'malformed' => "not-a-uuid'; DROP TABLE blog_post_translations; --",
    };

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);
    $rowsBefore = DB::table('blog_post_translations')->count();

    expect(fn () => $component->call('setActiveLanguageTab', $badId))->toThrow(ModelNotFoundException::class);

    $component->assertSet('activeLanguageId', $this->spanish->id);
    expect(DB::table('blog_post_translations')->count())->toBe($rowsBefore);
})->with(['inactive', 'unknown', 'malformed']);

test('the tab state properties are locked against client writes', function (string $property, mixed $value) {
    $this->withoutExceptionHandling();
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);

    expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'languages' => ['languages', []],
    'defaultLanguageId' => ['defaultLanguageId', 'anything'],
    'originalTranslatedLanguageIds' => ['originalTranslatedLanguageIds', []],
]);

test('switching tabs writes nothing: row set, count and updated_at are unchanged', function () {
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->french->id => blogTabsRow('fr'),
    ]);
    $postsBefore = BlogPost::count();
    $rowsBefore = DB::table('blog_post_translations')->orderBy('id')->get()->all();
    $updatedAtBefore = $post->fresh()->updated_at;

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('setActiveLanguageTab', $this->french->id)
        ->call('setActiveLanguageTab', $this->spanish->id);

    expect(BlogPost::count())->toBe($postsBefore)
        ->and(DB::table('blog_post_translations')->orderBy('id')->get()->all())->toEqual($rowsBefore)
        ->and($post->fresh()->updated_at->equalTo($updatedAtBefore))->toBeTrue();
});

test('the non-translatable properties are identical across a switch, compared as one array', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);
    $shared = ['blogCategoryId', 'status', 'publishedAt', 'tagNames', 'blogPostId'];

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);
    $before = collect($shared)->mapWithKeys(fn (string $property): array => [$property => $component->get($property)])->all();

    $component->call('setActiveLanguageTab', $this->french->id);

    $after = collect($shared)->mapWithKeys(fn (string $property): array => [$property => $component->get($property)])->all();

    expect($after)->toBe($before);
});

test('unsaved input in the tab being left is still there after switching away and back', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->spanish->id, 'Typed but unsaved')
        ->call('setActiveLanguageTab', $this->french->id)
        ->call('setActiveLanguageTab', $this->spanish->id)
        ->assertSet('titles.'.$this->spanish->id, 'Typed but unsaved');
});

// =====================================================================
// Save composition
// =====================================================================

test('opening an untranslated tab and saving with no input there creates no row, so no slug is reserved in that language', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('setActiveLanguageTab', $this->french->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(blogTabsPersistedLanguageIds($post))->toBe([$this->spanish->id])
        ->and(DB::table('blog_post_translations')->where('store_language_id', $this->french->id)->count())->toBe(0);
});

test('editing only the French tab writes French and does not rewrite the default row', function () {
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->french->id => blogTabsRow('fr'),
    ]);
    $spanishRowBefore = DB::table('blog_post_translations')
        ->where('store_language_id', $this->spanish->id)
        ->first();

    $this->travel(5)->minutes();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, 'Titre modifie')
        ->call('save')
        ->assertHasNoErrors();

    $spanishRowAfter = DB::table('blog_post_translations')->where('store_language_id', $this->spanish->id)->first();

    expect(BlogPostTranslation::query()->where('store_language_id', $this->french->id)->value('title'))->toBe('Titre modifie')
        ->and($spanishRowAfter->title)->toBe('Title es')
        ->and($spanishRowAfter->updated_at)->toBe($spanishRowBefore->updated_at);
});

test('saving the default and a non-default tab together writes each language from its own arguments', function () {
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->french->id => blogTabsRow('fr'),
    ]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->spanish->id, 'Titulo nuevo')
        ->set('titles.'.$this->french->id, 'Titre nouveau')
        ->call('save')
        ->assertHasNoErrors();

    expect(BlogPostTranslation::query()->where('store_language_id', $this->spanish->id)->value('title'))->toBe('Titulo nuevo')
        ->and(BlogPostTranslation::query()->where('store_language_id', $this->french->id)->value('title'))->toBe('Titre nouveau');
});

// =====================================================================
// Tamper
// =====================================================================

test('array entries for an inactive, nonexistent or never-rendered language write no row, and the persisted set equals the active set', function () {
    $post = blogTabsPost([$this->spanish->id => blogTabsRow('es')]);
    $nonexistentId = (string) fake()->uuid();
    $expected = collect([$this->spanish->id, $this->french->id])->sort()->values()->all();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, 'Souliers')
        ->set('titles.'.$this->retired->id, 'Smuggled retired')
        ->set('bodies.'.$this->retired->id, '<p>smuggled</p>')
        ->set('titles.'.$nonexistentId, 'Smuggled ghost')
        ->call('save')
        ->assertHasNoErrors();

    expect(blogTabsPersistedLanguageIds($post))->toBe($expected);
});

test('a forged originalTranslatedLanguageIds cannot be written, so a blanked tab cannot become an untouched one', function () {
    $this->withoutExceptionHandling();
    $post = blogTabsPost([
        $this->spanish->id => blogTabsRow('es'),
        $this->french->id => blogTabsRow('fr'),
    ]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, '')
        ->set('bodies.'.$this->french->id, '');

    expect(fn () => $component->set('originalTranslatedLanguageIds', []))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $component->call('save')->assertHasErrors(['titles.'.$this->french->id]);

    expect(BlogPostTranslation::query()->where('store_language_id', $this->french->id)->value('title'))->toBe('Title fr');
});
