<?php

// Story 0063 (layer 2) -- App\Livewire\BlogPosts\Editor, the routed post create/edit screen. This
// file holds the component's BEHAVIOUR: hydration, the save orchestration, the re-keyed refusals,
// the status/date/body rules as the editor reaches them, the tag chip field and its suggestion
// query, and the authorization / refusal-logging layers. The rendered markup lives in
// BlogPostsEditorRenderingTest.php and the real-browser journey in
// tests/Browser/BlogPosts/EditorJourneyTest.php.
//
// Deliberately NOT re-run here: 0061's action-level rules (BlogPostStatusAndPublicationDateTest,
// BlogPostTagAssignmentTest, ...). Each case below proves that the SCREEN reaches the action with
// the right arguments and surfaces the refusal on the right field -- most of them would pass
// against the action alone, which is exactly why several assert the STORED outcome, not only the
// error bag.
//
// Every test acts as an authenticated actor holding the relevant blog.* permissions: the actions
// authorize BEFORE they validate, so an unauthenticated direct call throws AuthorizationException
// rather than ValidationException and would pass for the wrong reason.
//
// Written against the pre-Epic-5 schema (`blog_posts.title/body`, `blog_tags.name`).

use App\Livewire\BlogPosts\Editor;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogPostsEditorActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Fills the form with a complete, valid Draft payload, then applies $overrides on top.
 *
 * @param  array<string, mixed>  $overrides
 */
function blogPostsEditorFill(Testable $component, BlogCategory $category, array $overrides = []): Testable
{
    $payload = array_merge([
        'title' => 'Botas de invierno',
        'blogCategoryId' => $category->id,
        'status' => 'draft',
        'body' => '<p>Contenido</p>',
    ], $overrides);

    foreach ($payload as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

/**
 * The names of the tags attached to a post, read from the real pivot rather than a relation.
 *
 * @return list<string>
 */
function blogPostsEditorAttachedTagNames(BlogPost $post): array
{
    return DB::table('blog_post_tag')
        ->join('blog_tags', 'blog_tags.id', '=', 'blog_post_tag.blog_tag_id')
        ->where('blog_post_tag.blog_post_id', $post->id)
        ->orderBy('blog_tags.name')
        ->pluck('blog_tags.name')
        ->all();
}

/**
 * Drives one refused call and returns the contexts of every 'Privileged action refused' warning
 * it wrote (a spy that captures inside withArgs() would record duplicates -- docs/errors-log.md).
 *
 * @param  Closure(): void  $refuse
 * @return list<array<string, mixed>>
 */
function blogPostsEditorRefusedContexts(Closure $refuse): array
{
    Log::spy();

    $refuse();

    $captured = [];

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            if ($message === 'Privileged action refused') {
                $captured[] = $context;
            }

            return true;
        });

    return $captured;
}

/**
 * @return list<string> the message(s) the component holds against one error-bag key
 */
function blogPostsEditorErrors(Testable $component, string $key): array
{
    return $component->errors()->get($key);
}

// =====================================================================
// Create and edit: the happy paths
// =====================================================================

test('create persists the title, category, status, body and tags, and redirects to the list', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['body' => '<p>Un cuerpo <strong>largo</strong></p>'])
        ->set('tagInput', 'running')
        ->call('addTypedTag')
        ->call('addTag', 'invierno')
        ->call('save');

    $component->assertHasNoErrors()->assertRedirect(route('blog-posts.index'));

    $post = BlogPost::sole();

    expect($post->title)->toBe('Botas de invierno')
        ->and($post->blog_category_id)->toBe($category->id)
        ->and($post->status->value)->toBe('draft')
        ->and($post->body)->toContain('largo')
        ->and($post->published_at)->toBeNull()
        ->and(blogPostsEditorAttachedTagNames($post))->toBe(['invierno', 'running']);
});

test('edit loads every field, including the complete tag set, and never a null', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    $post = BlogPost::factory()->create([
        'title' => 'Botas de invierno',
        'blog_category_id' => $category->id,
        'body' => '<p>Contenido</p>',
        'status' => 'scheduled',
        'published_at' => '2027-03-15 10:20:33',
    ]);
    $post->tags()->attach(BlogTag::factory()->count(3)->sequence(['name' => 'zeta'], ['name' => 'alfa'], ['name' => 'medio'])->create()->modelKeys());

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);

    $component->assertSet('blogPostId', $post->id)
        ->assertSet('title', 'Botas de invierno')
        ->assertSet('blogCategoryId', $category->id)
        ->assertSet('status', 'scheduled')
        ->assertSet('publishedAt', '2027-03-15T10:20:33')
        ->assertSet('body', '<p>Contenido</p>')
        ->assertSet('tagNames', ['alfa', 'medio', 'zeta']);
});

test('a bodiless draft with no date loads empty strings, never null', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create(['body' => null]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);

    $component->assertSet('body', '')
        ->assertSet('publishedAt', '')
        ->assertSet('tagNames', [])
        ->assertSet('status', 'draft');
});

test('editing an existing post retitles it and redirects to the list', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('title', 'Botas de invierno 2026')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('blog-posts.index'));

    expect($post->fresh()->title)->toBe('Botas de invierno 2026')
        ->and(BlogPost::count())->toBe(1);
});

test('the form is never saved just by leaving the page', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category)->call('addTag', 'invierno');

    expect(BlogPost::count())->toBe(0)->and(BlogTag::count())->toBe(0);
});

test('the post id is locked against a client write', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create();

    expect(fn () => Livewire::test(Editor::class, ['blogPost' => $post])->set('blogPostId', BlogPost::factory()->create()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// =====================================================================
// Authorization: mount() and save(), with logged refusals
// =====================================================================

test('mounting the create route without blog.create is refused and logged against a blog_post', function () {
    $this->withoutExceptionHandling();
    $actor = blogPostsEditorActor(['blog.view']);
    $this->actingAs($actor);

    $contexts = blogPostsEditorRefusedContexts(function (): void {
        expect(fn () => Livewire::test(Editor::class))->toThrow(AuthorizationException::class);
    });

    expect($contexts)->toHaveCount(1)
        ->and($contexts[0]['actor_id'])->toBe($actor->id)
        ->and($contexts[0]['ability'])->toBe('create')
        ->and($contexts[0]['target_type'])->toBe('blog_post');
});

test('mounting the edit route without blog.edit is refused and logged with the post id', function () {
    $this->withoutExceptionHandling();
    $actor = blogPostsEditorActor(['blog.view', 'blog.create']);
    $this->actingAs($actor);
    $post = BlogPost::factory()->create();

    $contexts = blogPostsEditorRefusedContexts(function () use ($post): void {
        expect(fn () => Livewire::test(Editor::class, ['blogPost' => $post]))->toThrow(AuthorizationException::class);
    });

    expect($contexts)->toHaveCount(1)
        ->and($contexts[0]['ability'])->toBe('update')
        ->and($contexts[0]['target_type'])->toBe('blog_post')
        ->and($contexts[0]['target_id'])->toBe($post->id);
});

test('mounting and saving with the right permissions write no refusal warning at all', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    $post = BlogPost::factory()->create();

    Log::spy();

    blogPostsEditorFill(Livewire::test(Editor::class), $category)->call('save');
    Livewire::test(Editor::class, ['blogPost' => $post])->set('title', 'Otro título')->call('save');

    Log::shouldNotHaveReceived('warning');
});

test('save() re-authorizes at click time: an actor who lost blog.create after mounting is refused and logged', function () {
    $this->withoutExceptionHandling();
    $actor = blogPostsEditorActor();
    $this->actingAs($actor);
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category);
    $actor->revokePermissionTo('blog.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $contexts = blogPostsEditorRefusedContexts(function () use ($component): void {
        expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    });

    expect(BlogPost::count())->toBe(0)
        ->and($contexts)->not->toBeEmpty()
        ->and($contexts[0]['ability'])->toBe('create')
        ->and($contexts[0]['target_type'])->toBe('blog_post');
});

test('save() re-authorizes an edit: an actor who lost blog.edit after mounting is refused and the post is untouched', function () {
    $this->withoutExceptionHandling();
    $actor = blogPostsEditorActor();
    $this->actingAs($actor);
    $post = BlogPost::factory()->create(['title' => 'Original']);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])->set('title', 'Cambiado');
    $actor->revokePermissionTo('blog.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $contexts = blogPostsEditorRefusedContexts(function () use ($component): void {
        expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    });

    expect($post->fresh()->title)->toBe('Original')
        ->and($contexts)->not->toBeEmpty()
        ->and($contexts[0]['ability'])->toBe('update')
        ->and($contexts[0]['target_type'])->toBe('blog_post')
        ->and($contexts[0]['target_id'])->toBe($post->id);
});

test('saving a post that was deleted while the editor was open is a 404, never a resurrection', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create(['title' => 'Original']);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])->set('title', 'Cambiado');
    $post->delete();

    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect(BlogPost::withTrashed()->sole()->title)->toBe('Original')
        ->and(BlogPost::count())->toBe(0);
});

// =====================================================================
// Refusals are RE-KEYED onto the editor's declared properties (one test per key)
// =====================================================================

test('a blank title is refused with the message beside the title field', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['title' => '   '])->call('save');

    $component->assertHasErrors(['title']);
    expect(blogPostsEditorErrors($component, 'title'))->toHaveCount(1)
        ->and(BlogPost::count())->toBe(0);
});

test('a missing category is refused on blogCategoryId, never on the snake_case column key', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['blogCategoryId' => ''])->call('save');

    $component->assertHasErrors(['blogCategoryId'])->assertHasNoErrors(['blog_category_id']);
    expect(blogPostsEditorErrors($component, 'blogCategoryId'))->toHaveCount(1)
        ->and(BlogPost::count())->toBe(0);
});

test('an unknown category id is refused on blogCategoryId and creates nothing', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['blogCategoryId' => '0190a1b2-0000-7000-8000-000000000000'])
        ->call('save');

    $component->assertHasErrors(['blogCategoryId'])->assertHasNoErrors(['blog_category_id']);
    expect(BlogPost::count())->toBe(0);
});

test('a forged status string is refused by validation on the status field, never as an uncaught ValueError', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'archived'])->call('save');

    $component->assertHasErrors(['status']);
    expect(BlogPost::count())->toBe(0);
});

test('a refused body is shown beside the body field', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'published', 'body' => ''])->call('save');

    $component->assertHasErrors(['body']);
    expect(BlogPost::count())->toBe(0);
});

test('a refused publication date is re-keyed onto publishedAt, never published_at', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, [
        'status' => 'scheduled',
        'publishedAt' => now()->subDay()->format('Y-m-d\TH:i:s'),
    ])->call('save');

    $component->assertHasErrors(['publishedAt'])->assertHasNoErrors(['published_at']);
    expect(BlogPost::count())->toBe(0);
});

test('a scheduled post with no date is refused on publishedAt', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'scheduled', 'publishedAt' => ''])->call('save');

    $component->assertHasErrors(['publishedAt']);
    expect(BlogPost::count())->toBe(0);
});

test('an over-long tag set is refused on tagNames, never tag_names', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $names = collect(range(1, BlogPost::MAX_TAGS + 1))->map(fn (int $n): string => 'tag'.$n)->all();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category)->set('tagNames', $names)->call('save');

    $component->assertHasErrors(['tagNames'])->assertHasNoErrors(['tag_names']);
    expect(BlogPost::count())->toBe(0)->and(BlogTag::count())->toBe(0);
});

test('a malformed tag name is re-keyed from the action\'s own name key onto tagNames', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category)
        ->set('tagNames', [str_repeat('a', BlogTag::NAME_MAX_LENGTH + 1)])
        ->call('save');

    $component->assertHasErrors(['tagNames'])->assertHasNoErrors(['name']);
    expect(BlogPost::count())->toBe(0)->and(BlogTag::count())->toBe(0);
});

test('a refusal on one field leaves the whole form as typed: nothing is reset in place', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['title' => '', 'body' => '<p>Escrito con cariño</p>'])
        ->call('addTag', 'invierno')
        ->call('save');

    $component->assertHasErrors(['title'])
        ->assertSet('body', '<p>Escrito con cariño</p>')
        ->assertSet('tagNames', ['invierno'])
        ->assertSet('blogCategoryId', $category->id);
});

test('a later successful attempt clears the previous refusal', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    $component = blogPostsEditorFill(Livewire::test(Editor::class), $category, ['title' => ''])->call('save');
    $component->assertHasErrors(['title']);

    $component->set('title', 'Ya con título')->call('save')->assertHasNoErrors();
});

// =====================================================================
// Body: 0061b's "visible content" judgement, as the editor reaches it
// =====================================================================

test('a Draft with an empty body is accepted and stores no body', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category, ['body' => ''])->call('save')->assertHasNoErrors();

    expect(BlogPost::sole()->body)->toBeNull();
});

test('a Published post with an empty body is refused', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'published', 'body' => ''])
        ->call('save')
        ->assertHasErrors(['body']);

    expect(BlogPost::count())->toBe(0);
});

test('a Scheduled post with an empty body is refused', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category, [
        'status' => 'scheduled',
        'body' => '',
        'publishedAt' => now()->addDay()->format('Y-m-d\TH:i:s'),
    ])->call('save')->assertHasErrors(['body']);

    expect(BlogPost::count())->toBe(0);
});

test('a body that renders nothing counts as no body: refused when published, stored as null for a draft', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'published', 'body' => '<p><br></p>'])
        ->call('save')
        ->assertHasErrors(['body']);
    expect(BlogPost::count())->toBe(0);

    blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'draft', 'body' => '<p><br></p>'])
        ->call('save')
        ->assertHasNoErrors();
    expect(BlogPost::sole()->body)->toBeNull();
});

test('promoting a bodiless draft to published is refused and the post is still a draft afterwards', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create(['body' => null]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('status', 'published')
        ->call('save')
        ->assertHasErrors(['body']);

    $fresh = $post->fresh();
    expect($fresh->status->value)->toBe('draft')->and($fresh->published_at)->toBeNull();
});

test('the same promotion succeeds when a body is supplied in the same save', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create(['body' => null]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('status', 'published')
        ->set('body', '<p>Ahora sí hay cuerpo</p>')
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->status->value)->toBe('published');
});

// =====================================================================
// Status and the publication date (the clock is FROZEN, so every boundary is exact)
// =====================================================================

test('a scheduled date is accepted only strictly after now(), asserted from both sides of the boundary', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));

    $attempt = fn (string $date): Testable => blogPostsEditorFill(Livewire::test(Editor::class), $category, [
        'status' => 'scheduled',
        'publishedAt' => $date,
    ])->call('save');

    $attempt('2026-06-01T11:59:59')->assertHasErrors(['publishedAt']);
    $attempt('2026-06-01T12:00:00')->assertHasErrors(['publishedAt']);
    expect(BlogPost::count())->toBe(0);

    $attempt('2026-06-01T12:00:01')->assertHasNoErrors();

    $post = BlogPost::sole();
    expect($post->status->value)->toBe('scheduled')
        ->and($post->published_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 12:00:01');
});

test('retitling an overdue scheduled post succeeds, because its date is hydrated at seconds precision', function () {
    $this->actingAs(blogPostsEditorActor());
    $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));
    // A date with NON-ZERO seconds: a minute-precision hydration would resubmit 10:20:00, which is
    // not the stored instant, so the action's "same date" exemption would not apply.
    $post = BlogPost::factory()->create([
        'title' => 'Programada',
        'status' => 'scheduled',
        'published_at' => '2026-06-01 10:20:33',
    ]);

    $this->travelTo(Carbon::parse('2026-06-02 09:00:00'));

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->assertSet('publishedAt', '2026-06-01T10:20:33')
        ->set('title', 'Programada y retitulada')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $post->fresh();
    expect($fresh->title)->toBe('Programada y retitulada')
        ->and($fresh->status->value)->toBe('scheduled')
        ->and($fresh->published_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 10:20:33');
});

test('the same retitle still succeeds when the editor was opened BEFORE the date passed', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->scheduled()->create(['title' => 'Programada']);

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);
    $this->travel(2)->days();

    $component->set('title', 'Retitulada')->call('save')->assertHasNoErrors();

    expect($post->fresh()->title)->toBe('Retitulada');
});

test('a Published post with no date is stamped with the current instant', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));

    blogPostsEditorFill(Livewire::test(Editor::class), $category, ['status' => 'published', 'publishedAt' => ''])
        ->call('save')
        ->assertHasNoErrors();

    $post = BlogPost::sole();
    expect($post->status->value)->toBe('published')
        ->and($post->published_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 12:00:00');
});

test('a stale date typed under Scheduled and then hidden by choosing Draft is never sent', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category, [
        'status' => 'draft',
        'publishedAt' => now()->addWeek()->format('Y-m-d\TH:i:s'),
    ])->call('save')->assertHasNoErrors();

    $post = BlogPost::sole();
    expect($post->status->value)->toBe('draft')->and($post->published_at)->toBeNull();
});

test('a future date typed under Scheduled and then hidden by choosing Published is not sent, so the post is Published now and never silently Scheduled', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));

    blogPostsEditorFill(Livewire::test(Editor::class), $category, [
        'status' => 'published',
        'publishedAt' => '2026-12-25T09:00:00',
    ])->call('save')->assertHasNoErrors();

    $post = BlogPost::sole();
    expect($post->status->value)->toBe('published')
        ->and($post->published_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 12:00:00');
});

test('returning a scheduled post to draft clears its publication date', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->scheduled()->create();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $post->fresh();
    expect($fresh->status->value)->toBe('draft')->and($fresh->published_at)->toBeNull();
});

test('re-saving an already published post keeps its own date and does not re-stamp it', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create(['status' => 'published', 'published_at' => '2026-01-10 08:00:00']);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('title', 'Retitulada')
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->published_at->format('Y-m-d H:i:s'))->toBe('2026-01-10 08:00:00');
});

// =====================================================================
// Tags by name: reuse, create, detach -- through the post actions only
// =====================================================================

test('a name matching an existing tag exactly, by case or by accent attaches that row and creates no duplicate', function (string $existing, string $typed) {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    $tag = BlogTag::factory()->create(['name' => $existing]);

    blogPostsEditorFill(Livewire::test(Editor::class), $category)->call('addTag', $typed)->call('save')->assertHasNoErrors();

    $post = BlogPost::sole();
    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $tag->id]);
    expect(BlogTag::count())->toBe(1);
})->with([
    'exact' => ['running', 'running'],
    'case-differing' => ['running', 'Running'],
    'accent-differing' => ['Niño', 'nino'],
]);

test('an unknown name creates the tag and attaches it', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();

    blogPostsEditorFill(Livewire::test(Editor::class), $category)->call('addTag', 'invierno')->call('save')->assertHasNoErrors();

    $tag = BlogTag::where('name', 'invierno')->sole();
    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => BlogPost::sole()->id, 'blog_tag_id' => $tag->id]);
});

test('three names attach three pivot rows in one save', function () {
    $this->actingAs(blogPostsEditorActor());
    $category = BlogCategory::factory()->create();
    BlogTag::factory()->create(['name' => 'running']);

    blogPostsEditorFill(Livewire::test(Editor::class), $category)
        ->call('addTag', 'running')
        ->call('addTag', 'invierno')
        ->call('addTag', 'trail')
        ->call('save')
        ->assertHasNoErrors();

    $post = BlogPost::sole();
    expect(DB::table('blog_post_tag')->where('blog_post_id', $post->id)->count())->toBe(3)
        ->and(blogPostsEditorAttachedTagNames($post))->toBe(['invierno', 'running', 'trail']);
});

test('a removed name is detached from the post while the tag row survives, shared with a second post', function () {
    $this->actingAs(blogPostsEditorActor());
    $keep = BlogTag::factory()->create(['name' => 'running']);
    $drop = BlogTag::factory()->create(['name' => 'invierno']);
    $post = BlogPost::factory()->create();
    $post->tags()->attach([$keep->id, $drop->id]);
    $other = BlogPost::factory()->create();
    $other->tags()->attach([$drop->id]);

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('removeTag', 'invierno')
        ->assertSet('tagNames', ['running'])
        ->call('save')
        ->assertHasNoErrors();

    expect(blogPostsEditorAttachedTagNames($post))->toBe(['running'])
        ->and(blogPostsEditorAttachedTagNames($other))->toBe(['invierno'])
        ->and(BlogTag::count())->toBe(2);
});

test('saving an edit without touching the chips keeps every tag: the whole set is always submitted', function () {
    $this->actingAs(blogPostsEditorActor());
    $post = BlogPost::factory()->create();
    $post->tags()->attach(BlogTag::factory()->count(12)->create()->modelKeys());

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('title', 'Retitulada')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('blog_post_tag')->where('blog_post_id', $post->id)->count())->toBe(12);
});

// =====================================================================
// The blog.edit-without-blog.create actor (0059 D-11's per-branch check, R-3)
// =====================================================================

test('an actor holding blog.edit but not blog.create attaches an existing tag successfully', function () {
    $this->actingAs(blogPostsEditorActor(['blog.view', 'blog.edit']));
    $existing = BlogTag::factory()->create(['name' => 'running']);
    $post = BlogPost::factory()->create();

    Livewire::test(Editor::class, ['blogPost' => $post])
        ->call('addTag', 'Running')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $existing->id]);
    expect(BlogTag::count())->toBe(1);
});

test('the same actor is refused when one name is new, and the whole save rolls back', function () {
    $this->withoutExceptionHandling();
    $actor = blogPostsEditorActor(['blog.view', 'blog.edit']);
    $this->actingAs($actor);
    $kept = BlogTag::factory()->create(['name' => 'kept']);
    $valid = BlogTag::factory()->create(['name' => 'running']);
    $post = BlogPost::factory()->create(['title' => 'Original']);
    $post->tags()->attach([$kept->id]);

    $component = Livewire::test(Editor::class, ['blogPost' => $post])
        ->set('title', 'Cambiado')
        ->set('tagNames', ['kept', 'running', 'brand-new']);

    $contexts = blogPostsEditorRefusedContexts(function () use ($component): void {
        expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    });

    // The refusal is logged by the action against the tag, not the post.
    expect($contexts)->toHaveCount(1)
        ->and($contexts[0]['actor_id'])->toBe($actor->id)
        ->and($contexts[0]['ability'])->toBe('create')
        ->and($contexts[0]['target_type'])->toBe('blog_tag');

    // Rolled back: the post's columns are unchanged, the valid name 'running' was NOT attached, the
    // old set survives, and no tag row was minted.
    expect($post->fresh()->title)->toBe('Original')
        ->and(blogPostsEditorAttachedTagNames($post))->toBe(['kept'])
        ->and(BlogTag::where('name', 'brand-new')->exists())->toBeFalse()
        ->and(BlogTag::count())->toBe(2)
        ->and($valid->posts()->count())->toBe(0);
});

// =====================================================================
// The chip field's own mutations
// =====================================================================

test('adding a name is trimmed, deduplicated case- and accent-insensitively, and ignores blanks', function () {
    $this->actingAs(blogPostsEditorActor());

    $component = Livewire::test(Editor::class)
        ->call('addTag', '  Running ')
        ->call('addTag', 'running')
        ->call('addTag', 'RUNNING')
        ->call('addTag', 'Niño')
        ->call('addTag', 'nino')
        ->call('addTag', '   ')
        ->call('addTag', '');

    $component->assertSet('tagNames', ['Running', 'Niño']);
});

test('the typed name is confirmed from the input, and the input is cleared', function () {
    $this->actingAs(blogPostsEditorActor());

    Livewire::test(Editor::class)
        ->set('tagInput', ' invierno ')
        ->call('addTypedTag')
        ->assertSet('tagNames', ['invierno'])
        ->assertSet('tagInput', '');
});

test('the tag array is bounded at the mutation point to the same maximum the action enforces', function () {
    $this->actingAs(blogPostsEditorActor());

    $component = Livewire::test(Editor::class);

    foreach (range(1, BlogPost::MAX_TAGS + 5) as $n) {
        $component->call('addTag', 'tag'.$n);
    }

    expect($component->get('tagNames'))->toHaveCount(BlogPost::MAX_TAGS);
});

test('a name longer than a tag name may be is not added', function () {
    $this->actingAs(blogPostsEditorActor());

    Livewire::test(Editor::class)
        ->call('addTag', str_repeat('a', BlogTag::NAME_MAX_LENGTH + 1))
        ->assertSet('tagNames', []);
});

test('removing a chip removes exactly that name and keeps the order of the rest', function () {
    $this->actingAs(blogPostsEditorActor());

    Livewire::test(Editor::class)
        ->call('addTag', 'uno')
        ->call('addTag', 'dos')
        ->call('addTag', 'tres')
        ->call('removeTag', 'dos')
        ->assertSet('tagNames', ['uno', 'tres'])
        ->call('removeTag', 'not-there')
        ->assertSet('tagNames', ['uno', 'tres']);
});

// =====================================================================
// The suggestion query
// =====================================================================

test('suggestions match what is typed, case- and accent-insensitively', function () {
    $this->actingAs(blogPostsEditorActor());
    BlogTag::factory()->create(['name' => 'Niño']);
    BlogTag::factory()->create(['name' => 'Trail']);

    $component = Livewire::test(Editor::class)->set('tagInput', 'NIN');

    expect($component->get('tagSuggestions'))->toBe(['Niño']);
});

test('nothing is suggested for an empty or whitespace-only input', function () {
    $this->actingAs(blogPostsEditorActor());
    BlogTag::factory()->create(['name' => 'running']);

    expect(Livewire::test(Editor::class)->set('tagInput', '   ')->get('tagSuggestions'))->toBe([]);
});

test('LIKE wildcards typed by the editor are matched literally', function (string $typed, array $expected) {
    $this->actingAs(blogPostsEditorActor());
    foreach (['a_b', 'axb', '100%', '1000', 'back\\slash', 'backxslash'] as $name) {
        BlogTag::factory()->create(['name' => $name]);
    }

    expect(Livewire::test(Editor::class)->set('tagInput', $typed)->get('tagSuggestions'))->toBe($expected);
})->with([
    'underscore' => ['a_b', ['a_b']],
    'percent' => ['0%', ['100%']],
    'backslash' => ['k\\s', ['back\\slash']],
    'lone percent matches only tags containing one' => ['%', ['100%']],
]);

test('names already on the post are never suggested, compared case-insensitively', function () {
    $this->actingAs(blogPostsEditorActor());
    BlogTag::factory()->create(['name' => 'running']);
    BlogTag::factory()->create(['name' => 'runner']);

    $component = Livewire::test(Editor::class)->call('addTag', 'RUNNING')->set('tagInput', 'run');

    expect($component->get('tagSuggestions'))->toBe(['runner']);
});

test('the suggestion list is small and bounded however many tags match', function () {
    $this->actingAs(blogPostsEditorActor());
    BlogTag::factory()->count(30)->sequence(fn ($sequence) => ['name' => 'tag'.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();

    $suggestions = Livewire::test(Editor::class)->set('tagInput', 'tag')->get('tagSuggestions');

    expect($suggestions)->not->toBeEmpty()->and(count($suggestions))->toBeLessThanOrEqual(10);
});

test('the suggestion query is gated on viewing the tag catalog, and a refusal is logged against a blog_tag', function () {
    $this->withoutExceptionHandling();
    $actor = blogPostsEditorActor(['blog.view', 'blog.edit']);
    $this->actingAs($actor);
    BlogTag::factory()->create(['name' => 'running']);
    $post = BlogPost::factory()->create();

    $component = Livewire::test(Editor::class, ['blogPost' => $post]);
    $actor->revokePermissionTo('blog.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $contexts = blogPostsEditorRefusedContexts(function () use ($component): void {
        expect(fn () => $component->set('tagInput', 'run'))->toThrow(AuthorizationException::class);
    });

    expect($contexts)->not->toBeEmpty()
        ->and($contexts[0]['ability'])->toBe('viewAny')
        ->and($contexts[0]['target_type'])->toBe('blog_tag');
});

// =====================================================================
// Scope fences
// =====================================================================

test('the editor neither composes the post validation rules nor calls validate(), and reaches tags only through the post actions', function () {
    $source = file_get_contents((new ReflectionClass(Editor::class))->getFileName());
    $code = collect(token_get_all($source))
        ->reject(fn ($token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
        ->implode('');

    expect($code)->not->toContain('BlogPostValidationRules')
        ->and($code)->not->toContain('->validate(')
        ->and($code)->not->toContain('SyncBlogPostTags')
        ->and($code)->not->toContain('FindOrCreateBlogTag')
        ->and($code)->not->toContain('forceDelete');
});
