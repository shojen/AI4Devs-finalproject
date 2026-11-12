<?php

// Per-language validation, requiredness and error routing through App\Livewire\BlogPosts\Editor
// (story 0079, D-9, D-10, D-11, D-14). Written at TDD Phase 3 step 1 (red), before the tabbed Editor
// and SetBlogPostTranslation exist. Error keys are `titles.{languageId}` / `bodies.{languageId}` for
// every language INCLUDING the default (the post actions' bare `title` / `body` keys are re-keyed).
//
// The actions authorize before they validate, so every test acts as an actor holding the blog.*
// permissions it needs.

use App\Livewire\BlogPosts\Editor;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\BlogTag;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit']);
    $this->actingAs($this->actor);
});

/**
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function blogValidationPost(array $rowsByLanguageId, string $state = 'draft'): BlogPost
{
    $post = BlogPost::factory()->withoutTranslations()->{$state}()->create();

    foreach ($rowsByLanguageId as $languageId => $attributes) {
        BlogPostTranslation::factory()->create(array_merge([
            'blog_post_id' => $post->id,
            'store_language_id' => $languageId,
        ], $attributes));
    }

    return $post;
}

/**
 * @return array<string, array<int, object>>
 */
function blogValidationSnapshot(): array
{
    return [
        'posts' => DB::table('blog_posts')->orderBy('id')->get()->all(),
        'translations' => DB::table('blog_post_translations')->orderBy('id')->get()->all(),
        'tags' => DB::table('blog_post_tag')->orderBy('blog_post_id')->orderBy('blog_tag_id')->get()->all(),
    ];
}

function blogValidationFrenchRowCount(StoreLanguage $french): int
{
    return BlogPostTranslation::query()->where('store_language_id', $french->id)->count();
}

// =====================================================================
// D-10: untouched / engaged / blanked
// =====================================================================

test('an untouched tab, blank and never translated, is skipped with no row and no error', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('save')
        ->assertHasNoErrors();

    expect(blogValidationFrenchRowCount($this->french))->toBe(0);
});

test('an engaged tab needs a title: a typed title writes the row', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, 'Titre')
        ->call('save')
        ->assertHasNoErrors();

    expect(BlogPostTranslation::query()->where('store_language_id', $this->french->id)->value('title'))->toBe('Titre');
});

test('a tab with the body filled and the title blank is engaged and refused on its title, never skipped', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('bodies.'.$this->french->id, '<p>Corps sans titre</p>')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->french->id]);

    expect(blogValidationFrenchRowCount($this->french))->toBe(0);
});

test('a blanked tab, both fields cleared after having been translated, is refused on its title and the row stays intact', function () {
    $post = blogValidationPost([
        $this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>'],
        $this->french->id => ['title' => 'Titre', 'body' => '<p>Corps</p>'],
    ]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, '')
        ->set('bodies.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->french->id]);

    $french = BlogPostTranslation::query()->where('store_language_id', $this->french->id)->sole();

    expect($french->title)->toBe('Titre')
        ->and($french->body)->toBe('<p>Corps</p>');
});

// =====================================================================
// Default language: error-key adapter and requiredness
// =====================================================================

test('a blank default title is refused unconditionally and surfaces on titles.{defaultId}', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->spanish->id, '   ')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->spanish->id])
        ->assertHasNoErrors(['title']);
});

test('a Published post with a blank default body is refused on bodies.{defaultId}, never on the bare body key', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']], 'published');

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('bodies.'.$this->spanish->id, '')
        ->call('save')
        ->assertHasErrors(['bodies.'.$this->spanish->id])
        ->assertHasNoErrors(['body']);
});

test('a default body made only of markup the sanitizer strips counts as blank on a Published post', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']], 'published');

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('bodies.'.$this->spanish->id, '<script>alert(1)</script>')
        ->call('save')
        ->assertHasErrors(['bodies.'.$this->spanish->id]);
});

test('a default-language slug collision renders on the default tab title', function () {
    blogValidationPost([$this->spanish->id => ['title' => 'Botas de invierno', 'body' => '<p>x</p>']]);
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Otro titulo', 'body' => '<p>x</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->spanish->id, 'botas de invierno')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->spanish->id]);
});

// =====================================================================
// D-11: a body is required in the default language only
// =====================================================================

test('a Published post with a written default body and a blank French body is accepted', function () {
    $post = blogValidationPost([
        $this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>'],
        $this->french->id => ['title' => 'Titre', 'body' => '<p>Corps</p>'],
    ], 'published');

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('bodies.'.$this->french->id, '')
        ->call('save')
        ->assertHasNoErrors();

    expect(BlogPostTranslation::query()->where('store_language_id', $this->french->id)->value('body'))->toBeNull();
});

test('promoting a draft to Published is blocked by a bodiless default language only, never by a bodiless French tab', function () {
    $post = blogValidationPost([
        $this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>'],
        $this->french->id => ['title' => 'Titre', 'body' => null],
    ]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('status', 'published')
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->status->value)->toBe('published');
});

// =====================================================================
// Refusal routing (D-5, D-14)
// =====================================================================

test('a refusal keyed to a non-visible tab is reported and moves the active tab to the failing language', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->set('bodies.'.$this->french->id, '<p>Corps sans titre</p>')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id);
});

test('a per-language slug collision renders on that tab title and focuses the tab', function () {
    $other = blogValidationPost([$this->spanish->id => ['title' => 'Otro', 'body' => '<p>x</p>']]);
    BlogPostTranslation::factory()->forLanguage($this->french)->create([
        'blog_post_id' => $other->id,
        'title' => 'Chaussures de pluie',
    ]);
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, 'Chaussures de Pluie')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id);
});

test('a refusal in a non-default language rolls back the default-language write, the tags and every other row', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);
    $existingTag = BlogTag::factory()->create();
    $post->tags()->attach($existingTag->id);
    $before = blogValidationSnapshot();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->spanish->id, 'Titulo cambiado')
        ->set('tagNames', ['etiqueta nueva'])
        ->set('bodies.'.$this->french->id, '<p>Corps sans titre</p>')
        ->call('save')
        ->assertHasErrors(['titles.'.$this->french->id]);

    expect(blogValidationSnapshot())->toEqual($before)
        ->and(BlogTag::query()->count())->toBe(1);
});

test('a script typed into the French body is stored sanitized through the component', function () {
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, 'Titre')
        ->set('bodies.'.$this->french->id, '<p>Corps</p><script>alert(1)</script>')
        ->call('save')
        ->assertHasNoErrors();

    $stored = BlogPostTranslation::query()->where('store_language_id', $this->french->id)->value('body');

    expect($stored)->toContain('Corps')
        ->and($stored)->not->toContain('<script');
});

test('an actor who lost blog.edit after mounting cannot write any tab, French included', function () {
    $this->withoutExceptionHandling();
    $post = blogValidationPost([$this->spanish->id => ['title' => 'Titulo', 'body' => '<p>Cuerpo</p>']]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$this->french->id, 'Titre');

    $this->actor->revokePermissionTo('blog.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);

    expect(blogValidationFrenchRowCount($this->french))->toBe(0);
});
