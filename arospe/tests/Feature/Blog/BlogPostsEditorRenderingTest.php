<?php

// Story 0063 (layer 2) -- the RENDERED half of App\Livewire\BlogPosts\Editor (resources/views/
// livewire/blog-posts/editor.blade.php). The component's behaviour lives in
// BlogPostsEditorTest.php; nothing here re-tests it. What only the rendered markup can get wrong:
// option sets, the disabled placeholder, the always-present-but-hidden date field, the single
// WYSIWYG embed, the disabled-with-a-reason tag control and the chip hooks.
//
// The publication-date field is revealed CLIENT-SIDE (x-show on the Sales Regions precedent, D-7),
// so the server renders it in EVERY status: a Livewire::test() assertion can prove the wrapper, the
// input and the exact x-show expression are present, never that the reveal fires. The real browser
// proves that -- tests/Browser/BlogPosts/EditorJourneyTest.php.
//
// Several assertions read the view SOURCE rather than the rendered HTML: a Livewire child tag's
// attributes (wire:key, wire:model) are consumed by the child and are not preserved verbatim in
// the parent's output, and an absent `<livewire:media.gallery>` tag has no rendered trace at all.

use App\Enums\BlogPostStatus;
use App\Livewire\BlogPosts\Editor;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\BlogTag;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    StoreLanguage::factory()->default()->create();
});

function blogPostsEditorRenderingDefaultId(): string
{
    return StoreLanguage::query()->where('is_default', true)->firstOrFail()->id;
}

/**
 * @param  array<int, string>  $permissions
 */
function blogPostsEditorRenderingActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

function blogPostsEditorViewSource(): string
{
    return (string) file_get_contents(resource_path('views/livewire/blog-posts/editor.blade.php'));
}

/**
 * The options of the one native <select> bound with `wire:model="$property"`.
 *
 * @return list<array{attributes: string, value: string, text: string}>
 */
function blogPostsEditorSelectOptions(string $html, string $property): array
{
    $pattern = '/<select\b[^>]*wire:model="'.preg_quote($property, '/').'"[^>]*>(.*?)<\/select>/is';

    if (preg_match($pattern, $html, $select) !== 1) {
        return [];
    }

    preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/is', $select[1], $options, PREG_SET_ORDER);

    return array_map(function (array $option): array {
        preg_match('/\bvalue="([^"]*)"/', $option[1], $value);

        return [
            'attributes' => $option[1],
            'value' => html_entity_decode($value[1] ?? ''),
            'text' => trim(html_entity_decode(strip_tags($option[2]))),
        ];
    }, $options);
}

/**
 * The opening tag of the first element carrying `data-test="$hook"`, entity-decoded.
 */
function blogPostsEditorOpeningTag(string $html, string $hook): ?string
{
    if (preg_match('/<[a-z0-9-]+\b[^>]*\bdata-test="'.preg_quote($hook, '/').'"[^>]*>/is', $html, $matches) !== 1) {
        return null;
    }

    return html_entity_decode($matches[0]);
}

/**
 * Does the tag carrying `data-test="$hook"` also carry `disabled="disabled"`? The exact attribute,
 * never a bare `disabled`: Flux's compiled class list carries `disabled:opacity-75` on the ENABLED
 * branch too (D-20).
 */
function blogPostsEditorControlDisabled(string $html, string $hook): bool
{
    return preg_match(
        '/<[a-z0-9-]+(?=[^>]*\bdata-test="'.preg_quote($hook, '/').'")(?=[^>]*\sdisabled="disabled")[^>]*>/is',
        $html,
    ) === 1;
}

/**
 * Is the control carrying `data-test="$hook"` directly inside a <flux:tooltip> (compiled to
 * <ui-tooltip>)? Returns the tooltip's own copy when it is, null when it is not.
 */
function blogPostsEditorTooltipContent(string $html, string $hook): ?string
{
    $quoted = preg_quote($hook, '/');

    if (preg_match('/<ui-tooltip[^>]*>((?:(?!<\/ui-tooltip>).)*?data-test="'.$quoted.'"(?:(?!<\/ui-tooltip>).)*?)<\/ui-tooltip>/is', $html, $tooltip) !== 1) {
        return null;
    }

    if (preg_match('/data-flux-tooltip-content[^>]*>\s*([^<]*)/is', $tooltip[1], $content) !== 1) {
        return null;
    }

    return trim(html_entity_decode($content[1]));
}

// =====================================================================
// Category and status controls
// =====================================================================

test('the category select renders every category plus exactly one disabled, selected, empty placeholder and no none option', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    $guides = BlogCategory::factory()->named('Guías')->create();
    $news = BlogCategory::factory()->named('Novedades')->create();

    $options = blogPostsEditorSelectOptions(Livewire::test(Editor::class)->html(), 'blogCategoryId');

    $placeholders = array_values(array_filter($options, fn (array $option): bool => $option['value'] === ''));
    $real = array_values(array_filter($options, fn (array $option): bool => $option['value'] !== ''));

    expect($placeholders)->toHaveCount(1)
        ->and($placeholders[0]['attributes'])->toMatch('/\bdisabled\b(=|\s|$)/')
        ->and($placeholders[0]['attributes'])->toContain('selected')
        ->and($options[0]['value'])->toBe('')
        ->and(array_column($real, 'value'))->toEqualCanonicalizing([$guides->id, $news->id])
        ->and(array_column($real, 'text'))->toEqualCanonicalizing(['Guías', 'Novedades']);
});

test('the status select renders exactly BlogPostStatus::cases(), labelled, with no placeholder', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $options = blogPostsEditorSelectOptions(Livewire::test(Editor::class)->html(), 'status');

    expect(array_column($options, 'value'))->toBe(array_map(fn (BlogPostStatus $case): string => $case->value, BlogPostStatus::cases()))
        ->and(array_column($options, 'text'))->toBe(array_map(fn (BlogPostStatus $case): string => $case->label(), BlogPostStatus::cases()));
});

test('the status select speaks Spanish under the es locale', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    App::setLocale('es');

    try {
        $options = blogPostsEditorSelectOptions(Livewire::test(Editor::class)->html(), 'status');
    } finally {
        App::setLocale('en');
    }

    expect(array_column($options, 'text'))->toBe(['Borrador', 'Publicado', 'Programado']);
});

test('with no category at all the form says so and links to the category screen', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $html = Livewire::test(Editor::class)->html();

    $callout = blogPostsEditorOpeningTag($html, 'blog-post-no-categories');
    expect($callout)->not->toBeNull()
        ->and($html)->toContain('href="'.route('blog-categories.index').'"');
});

test('the no-categories callout is absent once a category exists', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    BlogCategory::factory()->create();

    $html = Livewire::test(Editor::class)->html();

    expect($html)->not->toContain('data-test="blog-post-no-categories"');
});

// =====================================================================
// The publication date: always in the DOM, revealed by x-show, with a UTC hint
// =====================================================================

test('the publication-date wrapper and input are rendered for every status, gated only by the exact x-show expression', function (string $status) {
    $this->actingAs(blogPostsEditorRenderingActor());
    $post = BlogPost::factory()->titled('Entrada de prueba', '<p>x</p>')->create(['status' => $status, 'published_at' => $status === 'draft' ? null : now()->subDay()]);

    $html = Livewire::test(Editor::class, ['blogPost' => $post])->html();

    $wrapper = blogPostsEditorOpeningTag($html, 'blog-post-published-at');
    $input = blogPostsEditorOpeningTag($html, 'blog-post-published-at-input');

    expect($wrapper)->not->toBeNull()
        ->and($wrapper)->toContain('x-show="$wire.status === \''.BlogPostStatus::Scheduled->value.'\'"')
        ->and($input)->not->toBeNull()
        ->and($input)->toContain('type="datetime-local"')
        ->and($input)->toContain('step="1"')
        ->and($input)->toContain('wire:model="publishedAt"');
})->with(['draft']);

test('the publication-date input is prefilled at seconds precision on an edit', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    $post = BlogPost::factory()->create(['status' => 'scheduled', 'published_at' => '2027-03-15 10:20:33']);

    $html = Livewire::test(Editor::class, ['blogPost' => $post])->html();

    expect($html)->toContain('2027-03-15T10:20:33');
});

test('the date field carries a translated UTC hint, in both locales', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $english = Livewire::test(Editor::class)->html();
    App::setLocale('es');

    try {
        $spanish = Livewire::test(Editor::class)->html();
    } finally {
        App::setLocale('en');
    }

    expect(__('blog-posts.editor.published_at_hint', [], 'en'))->toContain('UTC')
        ->and($english)->toContain(e(__('blog-posts.editor.published_at_hint', [], 'en')))
        ->and(__('blog-posts.editor.published_at_hint', [], 'es'))->toContain('UTC')
        ->and($spanish)->toContain(e(__('blog-posts.editor.published_at_hint', [], 'es')));
});

// =====================================================================
// The WYSIWYG seam (D-14)
// =====================================================================

test('the rendered page mounts exactly one WYSIWYG per active store language, each with its own stable wire:key', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    $languages = blogPostsEditorLanguages(3);
    StoreLanguage::factory()->inactive()->create();

    $html = Livewire::test(Editor::class)->html();
    $source = blogPostsEditorViewSource();

    expect(substr_count($html, 'data-test="wysiwyg-editor-region"'))->toBe(count($languages))
        ->and(substr_count($source, '<livewire:components.wysiwyg-editor'))->toBe(1)
        ->and($source)->toContain('wire:key="blog-post-body-editor-{{ $language[\'id\'] }}"');
});

test('the editor view embeds no media gallery tag of its own, listens for no select event and wraps nothing in a Media gate', function () {
    // The N galleries mount transitively, one inside each WysiwygEditor (0079 D-4), never from this view.
    $source = blogPostsEditorViewSource();

    expect($source)->not->toContain('livewire:media.gallery')
        ->and($source)->not->toContain('select-event')
        ->and($source)->not->toContain('Media::class');
});

// =====================================================================
// The chip field
// =====================================================================

test('every chip carries its tag-chip-{name} hook, and every tag on the post is a chip', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    $post = BlogPost::factory()->create();
    $names = collect(range(1, 12))->map(fn (int $n): string => 'tag '.$n)->all();
    $post->tags()->attach(collect($names)->map(fn (string $name) => BlogTag::factory()->named($name)->create()->id)->all());

    $html = Livewire::test(Editor::class, ['blogPost' => $post])->html();

    foreach ($names as $name) {
        expect($html)->toContain('data-test="tag-chip-'.$name.'"')
            ->and($html)->toContain('data-test="tag-chip-remove-'.$name.'"');
    }

    expect(preg_match_all('/data-test="tag-chip-(?!remove-)/', $html))->toBe(12);
});

test('a chip name is escaped, never interpolated raw', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $html = Livewire::test(Editor::class)->call('addTag', '"><script>alert(1)</script>')->html();

    expect($html)->not->toContain('<script>alert(1)</script>');
});

test('the add-tag control is disabled inside an explicit tooltip, with a reason, for an actor without blog.create typing a new name', function () {
    $this->actingAs(blogPostsEditorRenderingActor(['blog.view', 'blog.edit']));
    BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Editor::class, ['blogPost' => BlogPost::factory()->create()])->set('tagInput', 'invierno')->html();

    expect(blogPostsEditorControlDisabled($html, 'blog-post-tag-add'))->toBeTrue()
        ->and(blogPostsEditorTooltipContent($html, 'blog-post-tag-add'))->toBe(__('blog-posts.editor.tag_create_not_allowed'));
});

test('the add-tag control is enabled for the same actor typing a name that already exists, even in another case', function () {
    $this->actingAs(blogPostsEditorRenderingActor(['blog.view', 'blog.edit']));
    BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Editor::class, ['blogPost' => BlogPost::factory()->create()])->set('tagInput', 'RUNNING')->html();

    expect(blogPostsEditorOpeningTag($html, 'blog-post-tag-add'))->not->toBeNull()
        ->and(blogPostsEditorControlDisabled($html, 'blog-post-tag-add'))->toBeFalse()
        ->and(blogPostsEditorTooltipContent($html, 'blog-post-tag-add'))->toBeNull();
});

test('the add-tag control is enabled for an actor holding blog.create typing a new name', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $html = Livewire::test(Editor::class)->set('tagInput', 'invierno')->html();

    expect(blogPostsEditorOpeningTag($html, 'blog-post-tag-add'))->not->toBeNull()
        ->and(blogPostsEditorControlDisabled($html, 'blog-post-tag-add'))->toBeFalse()
        ->and(blogPostsEditorTooltipContent($html, 'blog-post-tag-add'))->toBeNull();
});

test('an actor without blog.create still sees a suggestion for an existing tag, enabled', function () {
    $this->actingAs(blogPostsEditorRenderingActor(['blog.view', 'blog.edit']));
    BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Editor::class, ['blogPost' => BlogPost::factory()->create()])->set('tagInput', 'run')->html();

    expect(blogPostsEditorOpeningTag($html, 'blog-post-tag-suggestion-running'))->not->toBeNull()
        ->and(blogPostsEditorControlDisabled($html, 'blog-post-tag-suggestion-running'))->toBeFalse();
});

test('no suggestion list renders while nothing is typed', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Editor::class)->html();

    expect($html)->not->toContain('data-test="blog-post-tag-suggestion-running"')
        ->and($html)->not->toContain('data-test="blog-post-tag-add"');
});

test('the tag input is capped at the tag name length', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $input = blogPostsEditorOpeningTag(Livewire::test(Editor::class)->html(), 'blog-post-tag-input');

    expect($input)->not->toBeNull()->and($input)->toContain('maxlength="'.BlogTag::NAME_MAX_LENGTH.'"');
});

// =====================================================================
// Errors, actions and the topbar
// =====================================================================

test('every refusal renders its message in the field it belongs to', function (string $field, array $set) {
    $this->actingAs(blogPostsEditorRenderingActor());
    $category = BlogCategory::factory()->create();
    $defaultId = blogPostsEditorRenderingDefaultId();

    // Titles and bodies are keyed by store-language id (0079 D-3); the dataset names them by field.
    $resolve = fn (string $key): string => match ($key) {
        'title' => 'titles.'.$defaultId,
        'body' => 'bodies.'.$defaultId,
        default => $key,
    };

    $component = Livewire::test(Editor::class)
        ->set('titles.'.$defaultId, 'Botas')
        ->set('blogCategoryId', $category->id);

    foreach ($set as $property => $value) {
        $component->set($resolve($property), $value);
    }

    $component->call('save');

    $field = $resolve($field);

    expect($component->errors()->has($field))->toBeTrue();
    expect(substr_count($component->html(), 'data-flux-error'))->toBeGreaterThanOrEqual(1);
    $component->assertSeeHtml($component->errors()->first($field));
})->with([
    'title' => ['title', ['title' => '']],
    'category' => ['blogCategoryId', ['blogCategoryId' => '']],
    'status' => ['status', ['status' => 'archived']],
    'body' => ['body', ['status' => 'published', 'body' => '']],
    'date' => ['publishedAt', ['status' => 'scheduled', 'publishedAt' => '']],
    'tags' => ['tagNames', ['tagNames' => ['']]],
]);

test('save and cancel are rendered, cancel linking back to the list', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $html = Livewire::test(Editor::class)->html();

    $save = blogPostsEditorOpeningTag($html, 'blog-post-save');
    $cancel = blogPostsEditorOpeningTag($html, 'blog-post-cancel');

    expect($save)->not->toBeNull()
        ->and($save)->toContain('wire:click="save"')
        ->and($cancel)->not->toBeNull()
        ->and($cancel)->toContain('href="'.route('blog-posts.index').'"');
});

test('the topbar heading names create or edit', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    $post = BlogPost::factory()->create();

    $this->get(route('blog-posts.create'))->assertOk()->assertSee(__('blog-posts.editor.title_create'));
    $this->get(route('blog-posts.edit', $post))->assertOk()->assertSee(__('blog-posts.editor.title_edit'));
});

test('the editor renders no force-delete or delete control at all', function () {
    $this->actingAs(blogPostsEditorRenderingActor());

    $html = Livewire::test(Editor::class, ['blogPost' => BlogPost::factory()->create()])->html();

    expect(strtolower($html))->not->toContain('forcedelete')->and($html)->not->toContain('delete-blog-post');
});

// =====================================================================
// Language tabs (story 0079): N panels, N editors, one set of non-translatable controls
// =====================================================================

/**
 * Whether `//*[@data-test="$scopeHook"]` contains a descendant carrying `data-test="$hook"`.
 */
function blogPostsEditorPanelContains(string $html, string $scopeHook, string $hook): bool
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    return (new DOMXPath($document))
        ->query('//*[@data-test="'.$scopeHook.'"]//*[@data-test="'.$hook.'"]')
        ->length > 0;
}

/**
 * The default language first, then $count - 1 further active languages.
 *
 * @return array<int, StoreLanguage>
 */
function blogPostsEditorLanguages(int $count): array
{
    $default = StoreLanguage::query()->where('is_default', true)->firstOrFail();

    return [$default, ...StoreLanguage::factory()->count($count - 1)->create()->all()];
}

test('every active language has its own panel in the DOM at once, the inactive ones none', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    [$default, $second, $third] = blogPostsEditorLanguages(3);
    $retired = StoreLanguage::factory()->inactive()->create();

    $html = Livewire::test(Editor::class)->html();

    foreach ([$default, $second, $third] as $language) {
        expect($html)->toContain('data-test="language-panel-'.$language->id.'"');
    }

    expect($html)->not->toContain('data-test="language-panel-'.$retired->id.'"');
});

test('one WysiwygEditor and one title input render per active language, none shared', function (int $count) {
    $this->actingAs(blogPostsEditorRenderingActor());
    $languages = blogPostsEditorLanguages($count);

    $html = Livewire::test(Editor::class)->html();

    expect(substr_count($html, 'data-test="wysiwyg-editor-region"'))->toBe($count);

    foreach ($languages as $language) {
        expect(blogPostsEditorPanelContains($html, 'language-panel-'.$language->id, 'blog-post-title-input-'.$language->id))->toBeTrue()
            ->and(blogPostsEditorPanelContains($html, 'language-panel-'.$language->id, 'blog-post-body-editor-'.$language->id))->toBeTrue();
    }
})->with([2, 3]);

test('the non-translatable controls render exactly once and outside every language panel', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    $languages = blogPostsEditorLanguages(3);

    $html = Livewire::test(Editor::class)->html();

    foreach (['blog-post-category', 'blog-post-status', 'blog-post-published-at-input', 'blog-post-tag-field'] as $hook) {
        expect(substr_count($html, 'data-test="'.$hook.'"'))->toBe(1);

        foreach ($languages as $language) {
            expect(blogPostsEditorPanelContains($html, 'language-panel-'.$language->id, $hook))->toBeFalse();
        }
    }

    expect(substr_count($html, 'data-test="blog-post-title-input-'))->toBe(count($languages));
});

test('an untranslated tab renders its not-yet-translated hint while a translated one and the default do not', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    [$default, $french, $german] = blogPostsEditorLanguages(3);
    $post = BlogPost::factory()->titled('Titulo', '<p>x</p>')->create();
    BlogPostTranslation::factory()->forLanguage($french)->create(['blog_post_id' => $post->id]);

    $html = Livewire::test(Editor::class, ['blogPost' => $post])->html();

    expect($html)->toContain('data-test="language-untranslated-'.$german->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$french->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$default->id.'"');
});

test('a tab carrying an error shows its marker on the tab header and clean tabs show none', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    [$default, $french, $german] = blogPostsEditorLanguages(3);
    $post = BlogPost::factory()->titled('Titulo', '<p>x</p>')->create();

    $html = Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('bodies.'.$french->id, '<p>Corps sans titre</p>')
        ->call('save')
        ->html();

    expect($html)->toContain('data-test="language-tab-error-'.$french->id.'"')
        ->and($html)->not->toContain('data-test="language-tab-error-'.$default->id.'"')
        ->and($html)->not->toContain('data-test="language-tab-error-'.$german->id.'"');
});

test('no slug input renders on any tab', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    blogPostsEditorLanguages(3);

    $html = Livewire::test(Editor::class)->html();

    expect($html)->not->toContain('data-test="blog-post-slug-input')
        ->and($html)->not->toMatch('/wire:model[^=]*="[^"]*slug/i');
});

test('a translated tab shows its own persisted slug as plain text, an untranslated tab shows none', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    [$default, $french, $german] = blogPostsEditorLanguages(3);
    $post = BlogPost::factory()->titled('Botas de invierno', '<p>x</p>')->create();
    BlogPostTranslation::factory()->forLanguage($french)->create(['blog_post_id' => $post->id, 'title' => 'Chaussures dhiver']);

    $html = Livewire::test(Editor::class, ['blogPost' => $post])->html();
    $slugTag = blogPostsEditorOpeningTag($html, 'blog-post-slug-'.$default->id);

    expect($slugTag)->not->toBeNull()
        ->and($slugTag)->not->toStartWith('<input')
        ->and($html)->toContain('botas-de-invierno')
        ->and($html)->toContain('chaussures-dhiver')
        ->and($html)->not->toContain('data-test="blog-post-slug-'.$german->id.'"');
});

test('the displayed slug follows the stored value, not the typed title, until a save derives the new one', function () {
    $this->actingAs(blogPostsEditorRenderingActor());
    [$default, $french] = blogPostsEditorLanguages(2);
    $post = BlogPost::factory()->titled('Titulo', '<p>x</p>')->create();
    BlogPostTranslation::factory()->forLanguage($french)->create(['blog_post_id' => $post->id, 'title' => 'Ancien titre']);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('titles.'.$french->id, 'Nouveau titre');

    expect($component->html())->toContain('ancien-titre')
        ->and($component->html())->not->toContain('nouveau-titre');

    $component->call('save')->assertHasNoErrors();

    expect(Livewire::test(Editor::class, ['blogPost' => $post])->html())->toContain('nouveau-titre');
});
