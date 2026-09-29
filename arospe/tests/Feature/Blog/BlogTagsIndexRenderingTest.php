<?php

// Story 0060 -- the RENDERED half of App\Livewire\BlogTags\Index (resources/views/livewire/
// blog-tags.blade.php). Component logic, persistence, validation and authorization live in
// BlogTagsIndexTest.php; nothing here duplicates it. Every test asserts against the rendered
// HTML, which that file never does.
//
// The signature test of this story is the delete-confirmation one: this screen's delete is
// UNCONDITIONAL (D-2), so a blocked-delete callout or a conditionally-disabled confirm button
// copied in from the product-categories screen would be dead markup that no "delete succeeds"
// test could ever see (R-2). Only a negative assertion against the RENDERED modal catches it.

use App\Livewire\BlogTags\Index;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogTagsRenderingActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Does the tag carrying `data-test="$hook"` also carry `disabled="disabled"`? Matches the exact
 * attribute, never a bare `disabled` substring: Flux's compiled class list carries the literal
 * `disabled:opacity-75` on the ENABLED branch too (R-4).
 */
function blogTagsControlIsDisabled(string $html, string $hook): bool
{
    return preg_match(
        '/<[a-z0-9-]+(?=[^>]*\bdata-test="'.preg_quote($hook, '/').'")(?=[^>]*\sdisabled="disabled")[^>]*>/is',
        $html,
    ) === 1;
}

function blogTagsControlExists(string $html, string $hook): bool
{
    return str_contains($html, 'data-test="'.$hook.'"');
}

test('the list renders each tag\'s name', function () {
    BlogTag::factory()->create(['name' => 'running']);
    BlogTag::factory()->create(['name' => 'invierno']);
    $this->actingAs(blogTagsRenderingActor(['blog.view']));

    Livewire::test(Index::class)->assertSee('running')->assertSee('invierno')
        ->assertDontSee(__('blog-tags.index.empty'));
});

test('the empty state renders when the catalog holds no tags', function () {
    $this->actingAs(blogTagsRenderingActor(['blog.view']));

    Livewire::test(Index::class)->assertSee(__('blog-tags.index.empty'));
});

test('the header carries the create action, hooked for browser tests', function () {
    $this->actingAs(blogTagsRenderingActor());

    expect(blogTagsControlExists(Livewire::test(Index::class)->html(), 'create-blog-tag-button'))->toBeTrue();
});

test('the create modal contains exactly one input and no select', function () {
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect(substr_count($html, '<input'))->toBe(1)
        ->and(blogTagsControlExists($html, 'blog-tag-name-input'))->toBeTrue()
        ->and($html)->not->toContain('<select');
});

test('the edit modal prefills the one field and offers a single Cancel control', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->call('openEditModal', $tag->id)->html();

    expect(substr_count($html, '<input'))->toBe(1)
        ->and(substr_count($html, __('blog-tags.index.cancel')))->toBe(1);
});

test('the modals render nothing until opened, so a closed screen has no stray Cancel controls', function () {
    BlogTag::factory()->create();
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect($html)->not->toContain('<input')
        ->and($html)->not->toContain(__('blog-tags.index.cancel'));
});

test('a refused name renders its message beside the field and the modal stays open', function () {
    $this->actingAs(blogTagsRenderingActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', '')
        ->call('save')
        ->assertSet('showModal', true)
        ->assertSee(trans('validation.required', ['attribute' => 'name']));
});

test('a duplicate name renders its message beside the field', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsRenderingActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'RUNNING')
        ->call('save')
        ->assertSee(trans('validation.unique', ['attribute' => 'name']));
});

// =====================================================================
// The delete-confirmation modal -- the signature negative assertions (D-2, R-2)
// =====================================================================

test('the delete confirmation is plain: it names the tag and renders no usage count, blocked state, reassign step or disabled confirm button', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->call('confirmDelete', $tag->id)->html();

    // Names the target and tells the editor what deleting means.
    expect($html)->toContain('running')
        ->and($html)->toContain(e(__('blog-tags.index.delete_body', ['name' => 'running'])));

    // The destructive control exists and is never disabled.
    expect(blogTagsControlExists($html, 'confirm-delete-blog-tag'))->toBeTrue()
        ->and(blogTagsControlIsDisabled($html, 'confirm-delete-blog-tag'))->toBeFalse();

    // No usage count, no "used by N posts", no blocked state, no reassign-first instruction.
    expect($html)->not->toMatch('/\b\d+\s+(posts?|articles?|entries)\b/i')
        ->not->toMatch('/used by/i')
        ->not->toMatch('/cannot be deleted/i')
        ->not->toMatch('/still (in use|attached|used|held)/i')
        ->not->toMatch('/reassign/i')
        ->not->toMatch('/blocked/i')
        ->not->toContain('data-flux-error');
});

test('the delete confirmation renders the same plain modal for a tag that has never been used, and for one with a long name', function () {
    $tag = BlogTag::factory()->create(['name' => str_repeat('a', 100)]);
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->call('confirmDelete', $tag->id)->html();

    expect(blogTagsControlExists($html, 'confirm-delete-blog-tag'))->toBeTrue()
        ->and(blogTagsControlIsDisabled($html, 'confirm-delete-blog-tag'))->toBeFalse();
});

test('the delete modal is absent until a confirmation is opened', function () {
    BlogTag::factory()->create();
    $this->actingAs(blogTagsRenderingActor());

    expect(blogTagsControlExists(Livewire::test(Index::class)->html(), 'confirm-delete-blog-tag'))->toBeFalse();
});

// =====================================================================
// Row actions -- hooks on both branches
// =====================================================================

test('row action hooks are present on both the enabled and the disabled branch, and only the disabled one carries disabled="disabled"', function () {
    $tag = BlogTag::factory()->create();
    $edit = 'edit-blog-tag-'.$tag->id;
    $delete = 'delete-blog-tag-'.$tag->id;

    $this->actingAs(blogTagsRenderingActor());
    $enabled = Livewire::test(Index::class)->html();

    $this->actingAs(blogTagsRenderingActor(['blog.view']));
    $disabled = Livewire::test(Index::class)->html();

    expect(blogTagsControlExists($enabled, $edit))->toBeTrue()
        ->and(blogTagsControlExists($enabled, $delete))->toBeTrue()
        ->and(blogTagsControlIsDisabled($enabled, $edit))->toBeFalse()
        ->and(blogTagsControlIsDisabled($enabled, $delete))->toBeFalse()
        ->and(blogTagsControlExists($disabled, $edit))->toBeTrue()
        ->and(blogTagsControlExists($disabled, $delete))->toBeTrue()
        ->and(blogTagsControlIsDisabled($disabled, $edit))->toBeTrue()
        ->and(blogTagsControlIsDisabled($disabled, $delete))->toBeTrue();
});

test('a view-only actor sees the create control unavailable too', function () {
    $this->actingAs(blogTagsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect(blogTagsControlExists($html, 'create-blog-tag-button'))->toBeTrue()
        ->and(blogTagsControlIsDisabled($html, 'create-blog-tag-button'))->toBeTrue();
});

test('every row action carries an accessible name', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('aria-label="'.e(__('blog-tags.index.edit_aria', ['name' => 'running'])).'"')
        ->and($html)->toContain('aria-label="'.e(__('blog-tags.index.delete_aria', ['name' => 'running'])).'"');
});

test('each row\'s wire:click hands its id to the component as a quoted JS literal (@js), never a bare interpolation', function () {
    // R-5: Livewire::test()->call() never goes through a compiled wire:click, so only the
    // rendered HTML can show the argument was encoded.
    $tag = BlogTag::factory()->create();
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->html();
    $quote = '(?:&quot;|&#039;|\'|")';

    expect($html)->toMatch('/wire:click="openEditModal\('.$quote.preg_quote($tag->id, '/').$quote.'\)"/')
        ->and($html)->toMatch('/wire:click="confirmDelete\('.$quote.preg_quote($tag->id, '/').$quote.'\)"/');
});

test('a tag name that looks like markup is escaped, not rendered', function () {
    BlogTag::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs(blogTagsRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('nothing on the screen references blog posts, blog categories or a product taxonomy', function () {
    BlogTag::factory()->create();
    $this->actingAs(blogTagsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect($html)->not->toMatch('/categor/i')
        ->and($html)->not->toContain('blog-categories');
});
