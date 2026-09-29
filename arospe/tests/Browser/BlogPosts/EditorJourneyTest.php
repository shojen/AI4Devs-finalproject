<?php

// Story 0063 (layer 2) -- ONE comprehensive browser journey through App\Livewire\BlogPosts\Editor
// (routes blog-posts.create / blog-posts.edit), deliberately not split into isolated tests, on the
// tests/Browser/Products/EditorJourneyTest.php precedent: several independently hand-rolled
// client-side surfaces (native selects bound by wire:model, an Alpine x-show reveal, the WYSIWYG's
// wire:ignore'd region plus its own gallery, and the bespoke chip field) run together on one real
// page, and isolated tests could each pass while the combined page fails.
//
// What ONLY a real browser proves here, and Livewire::test() cannot:
//   - the status -> publication-date reveal fires from a real <select> change (x-show, no round trip);
//   - the null-<select> desync: `selectedIndex` is read BEFORE any interaction, because ->select()
//     fires `change` unconditionally and can never detect it (docs/errors-log.md, "Playwright's
//     selectOption fires change unconditionally"); the FIRST option is then picked for both selects;
//   - typing into the wire:ignore'd WYSIWYG, saving, reopening and finding the body intact;
//   - inserting an image from the shared gallery into the body (a Media row is SELECTED, never
//     uploaded: an upload cannot complete under visit(), docs/testing/frontend/playwright-setup/
//     waiting-rules.md);
//   - typing a tag with the keyboard, confirming it with Enter, picking a suggestion and removing a
//     chip -- the chip field has no wire:model equivalent a component test can drive.
//
// Timing: a `<flux:select>` + `wire:model` binding has a RECORDED, unresolved race under Playwright
// in this repo, so the whole flow is wrapped in Laravel's retry(3, ..., 250) -- the accepted lever,
// never a longer ->wait() and never ->waitForEvent('networkidle'). Every wait on client state is a
// polling assertion against the component's own `$wire` values. A fresh title is generated on EVERY
// attempt so a retried attempt can never collide with a post a partially-succeeded attempt already
// committed (the title's slug is unique).

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    // Deliberately NOT Storage::fake('public'): the plugin's in-process HTTP server serves an <img
    // src> from THIS process, so a faked disk 403s the inserted image (see
    // tests/Browser/Components/WysiwygEditorTest.php). The Media row uses ->withRealFiles() and its
    // three files are deleted at the end of the test.
});

function blogPostsEditorJourneyActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete', 'media.view', 'media.create', 'media.edit']);

    return $actor;
}

/**
 * JS that is true once the editor component's own client-side `$wire` value for $property strictly
 * equals $expected -- a polling target for assertScript(), so a test waits on the real state rather
 * than a sleep.
 */
function blogPostsEditorJourneyWireEquals(string $property, string $expected): string
{
    $json = json_encode($expected, JSON_THROW_ON_ERROR);

    return <<<JS
        (function() {
            const root = document.querySelector('[data-test="blog-post-editor"]').closest('[wire\\\\:id]');
            return window.Livewire.find(root.getAttribute('wire:id')).{$property} === {$json};
        })()
    JS;
}

function blogPostsEditorJourneyWireContains(string $property, string $needle): string
{
    $json = json_encode($needle, JSON_THROW_ON_ERROR);

    return <<<JS
        (function() {
            const root = document.querySelector('[data-test="blog-post-editor"]').closest('[wire\\\\:id]');
            return String(window.Livewire.find(root.getAttribute('wire:id')).{$property}).includes({$json});
        })()
    JS;
}

function blogPostsEditorJourneyGalleryModal(string $dataTest): string
{
    return 'dialog[open] [data-test="'.$dataTest.'"]';
}

test('a blog editor writes a scheduled post with a body, an image and tags, saves it, reopens it and edits its tags', function () {
    $this->actingAs(blogPostsEditorJourneyActor());

    // The FIRST category by name is the one the journey picks: a pick of the first real option is
    // the case the null-<select> desync swallowed.
    $first = BlogCategory::factory()->create(['name' => 'Aaa primera']);
    BlogCategory::factory()->create(['name' => 'Zzz última']);
    BlogTag::factory()->create(['name' => 'running']);
    $media = Media::factory()->withRealFiles()->create(['title' => 'Journey Widget', 'description' => null]);
    $bodyText = 'Texto escrito en el navegador';
    $scheduledFor = now()->addDays(30)->format('Y-m-d\TH:i:s');
    // Non-zero seconds: Chromium drops ":00" from a datetime-local value, which would make the
    // reopened value differ from the typed one for a reason that has nothing to do with the app.
    $scheduledFor = substr($scheduledFor, 0, -2).'07';

    try {
        retry(3, function () use ($first, $media, $bodyText, $scheduledFor): void {
            $title = 'Entrada del viaje '.Str::random(10);

            $page = visit(route('blog-posts.create'))->assertNoJavaScriptErrors();

            // (1) The null-<select> detector: the disabled placeholder is GENUINELY selected before
            //     any interaction (-1 would mean wire:model assigned "null" to a native select).
            $page->assertScript('document.querySelector(\'[name="blogCategoryId"]\').selectedIndex', 0)
                ->assertScript('document.querySelector(\'[name="status"]\').selectedIndex', 0);

            // (2) The date reveal, from a real select change: hidden for Draft, shown for Scheduled,
            //     hidden again for Draft -- the FIRST option, picked after another one.
            $page->assertMissing('@blog-post-published-at')
                ->select('status', 'scheduled')
                ->assertVisible('@blog-post-published-at')
                ->select('status', 'draft')
                ->assertMissing('@blog-post-published-at')
                ->select('status', 'scheduled')
                ->assertVisible('@blog-post-published-at')
                ->assertScript(blogPostsEditorJourneyWireEquals('status', 'scheduled'));

            // (3) Title, the FIRST real category option, and the date.
            $page->fill('title', $title)
                ->select('blogCategoryId', $first->id)
                ->assertScript(blogPostsEditorJourneyWireEquals('blogCategoryId', $first->id))
                ->fill('publishedAt', $scheduledFor)
                ->assertScript(blogPostsEditorJourneyWireEquals('publishedAt', $scheduledFor));

            // (4) The body: type into the wire:ignore'd region, then insert an image from the gallery.
            $page->script(<<<'JS'
                (function() {
                    const region = document.querySelector('[data-test="wysiwyg-editor-region"]');
                    region.focus();
                    document.execCommand('insertText', false, 'Texto escrito en el navegador');
                })()
            JS);
            $page->assertScript(blogPostsEditorJourneyWireContains('body', $bodyText));

            $page->click('@wysiwyg-insert-image')
                ->assertNoJavaScriptErrors()
                ->click(blogPostsEditorJourneyGalleryModal('media-tile-'.$media->id))
                ->assertScript("document.querySelector('".blogPostsEditorJourneyGalleryModal('media-confirm')."').disabled === false")
                ->click(blogPostsEditorJourneyGalleryModal('media-confirm'))
                ->assertNoJavaScriptErrors()
                ->assertScript("document.querySelector('dialog[open]') === null")
                ->assertScript("document.querySelector('[data-test=\"wysiwyg-editor-region\"] img') !== null")
                ->assertScript(blogPostsEditorJourneyWireContains('body', '<img'));

            // (5) Tags, by keyboard: type + Enter mints a chip; a suggestion click adds an existing
            //     one; a chip is removed with its own control.
            $page->typeSlowly('@blog-post-tag-input', 'invierno', 30)
                ->keys('@blog-post-tag-input', 'Enter')
                ->assertVisible('@tag-chip-invierno')
                ->typeSlowly('@blog-post-tag-input', 'temporal', 30)
                ->keys('@blog-post-tag-input', 'Enter')
                ->assertVisible('@tag-chip-temporal')
                ->click('@tag-chip-remove-temporal')
                ->assertMissing('@tag-chip-temporal')
                ->typeSlowly('@blog-post-tag-input', 'runn', 30)
                ->assertVisible('@blog-post-tag-suggestion-running')
                ->click('@blog-post-tag-suggestion-running')
                ->assertVisible('@tag-chip-running')
                ->assertNoJavaScriptErrors();

            // (6) Save, waiting on the redirect save() performs -- never a longer ->wait().
            $page->click('@blog-post-save')
                ->assertNoJavaScriptErrors()
                ->assertUrlIs(route('blog-posts.index'));

            $post = BlogPost::where('title', $title)->firstOrFail();

            expect($post->status->value)->toBe('scheduled')
                ->and($post->blog_category_id)->toBe($first->id)
                ->and($post->published_at->format('Y-m-d\TH:i:s'))->toBe($scheduledFor)
                ->and($post->body)->toContain($bodyText)
                ->and($post->body)->toContain('<img')
                ->and(DB::table('blog_post_tag')->join('blog_tags', 'blog_tags.id', '=', 'blog_post_tag.blog_tag_id')
                    ->where('blog_post_id', $post->id)->orderBy('blog_tags.name')->pluck('blog_tags.name')->all())
                ->toBe(['invierno', 'running']);

            // (7) Reopen: every field survived the round trip, the date field is revealed by the
            //     stored status, and the body is intact in the wire:ignore'd region.
            $page = visit(route('blog-posts.edit', $post))
                ->assertNoJavaScriptErrors()
                ->assertValue('title', $title)
                ->assertScript('document.querySelector(\'[name="status"]\').value', 'scheduled')
                ->assertScript('document.querySelector(\'[name="blogCategoryId"]\').value', $first->id)
                ->assertVisible('@blog-post-published-at')
                ->assertValue('publishedAt', $scheduledFor)
                ->assertVisible('@tag-chip-invierno')
                ->assertVisible('@tag-chip-running')
                ->assertScript("document.querySelector('[data-test=\"wysiwyg-editor-region\"]').innerHTML.includes('{$bodyText}')");

            // (8) Edit: drop one chip, retitle, save -- the tag row survives in the catalog.
            $page->click('@tag-chip-remove-invierno')
                ->assertMissing('@tag-chip-invierno')
                ->fill('title', $title.' bis')
                ->assertScript(blogPostsEditorJourneyWireEquals('title', $title.' bis'))
                ->click('@blog-post-save')
                ->assertNoJavaScriptErrors()
                ->assertUrlIs(route('blog-posts.index'));

            $fresh = $post->fresh();
            expect($fresh->title)->toBe($title.' bis')
                ->and($fresh->status->value)->toBe('scheduled')
                ->and($fresh->body)->toContain($bodyText)
                ->and($fresh->tags()->pluck('name')->all())->toBe(['running'])
                ->and(BlogTag::where('name', 'invierno')->exists())->toBeTrue();
        }, 250);
    } finally {
        Storage::disk('public')->delete([$media->path, $media->webp_path, $media->avif_path]);
    }
});

test('a refusal is rendered beside its own field in a real browser, and the typed form survives it', function () {
    $this->actingAs(blogPostsEditorJourneyActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    retry(3, function () use ($category): void {
        visit(route('blog-posts.create'))
            ->assertNoJavaScriptErrors()
            ->select('blogCategoryId', $category->id)
            ->select('status', 'published')
            ->assertScript(blogPostsEditorJourneyWireEquals('status', 'published'))
            ->fill('title', 'Sin cuerpo '.Str::random(8))
            ->click('@blog-post-save')
            ->assertNoJavaScriptErrors()
            ->assertSee(__('validation.required', ['attribute' => 'body']))
            ->assertScript(blogPostsEditorJourneyWireEquals('blogCategoryId', $category->id));

        expect(BlogPost::count())->toBe(0);
    }, 250);
});
