<?php

// Story 0060 -- Pest 4 browser tests for the blog tag management screen (route blog-tags.index,
// GET /blog/tags). Mirrored folder per the Phase 2 ratification of V-1: the flat browser files
// are debt, not precedent (docs/testing/frontend/playwright-setup).
//
// What only a real browser proves, and Livewire::test() cannot: that wire:model actually delivers
// a typed value (->set() writes the property directly), that the compiled wire:click arguments
// work, and that the one-click delete never meets an intermediate count or blocked step.
//
// Tag rows are asserted through their row-scoped `data-test` hooks, never a page-global
// assertSee('running') that would also match inside 'trail running' (R-4). Names below are chosen
// so none is a substring of another anyway.
//
// ->waitForEvent('networkidle') is banned in this repo (it never settles here); a short bounded
// ->wait(1) after a Livewire round trip is the accepted mitigation, and each one below
// compensates for exactly that: the DOM has not yet re-rendered from the server response.

use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogTagsBrowserActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('opening the create form shows a blank field, with no stale prefill from a previous edit', function () {
    $this->actingAs(blogTagsBrowserActor());
    $tag = BlogTag::factory()->create(['name' => 'Marathon']);

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-tag-name-input', 'Marathon')
        ->click('Cancel')
        ->click('@create-blog-tag-button')
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-tag-name-input', '');
});

test('creating a tag through a real fill and click round trip adds it to the list', function () {
    $this->actingAs(blogTagsBrowserActor());

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@create-blog-tag-button')
        ->fill('name', 'Marathon')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $tag = BlogTag::query()->where('name', 'Marathon')->sole();

    visit('/blog/tags')->assertPresent('@edit-blog-tag-'.$tag->id);
});

test('editing prefills the name, and re-saving it unchanged preserves it', function () {
    $this->actingAs(blogTagsBrowserActor());
    $tag = BlogTag::factory()->create(['name' => 'Marathon']);

    visit('/blog/tags')
        ->click('@edit-blog-tag-'.$tag->id)
        ->assertValue('@blog-tag-name-input', 'Marathon')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($tag->fresh()->name)->toBe('Marathon');
});

test('renaming through the real input persists the new name', function () {
    $this->actingAs(blogTagsBrowserActor());
    $tag = BlogTag::factory()->create(['name' => 'Marathon']);

    visit('/blog/tags')
        ->click('@edit-blog-tag-'.$tag->id)
        ->fill('name', 'Ultratrail')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($tag->fresh()->name)->toBe('Ultratrail');
});

test('cancelling the create form adds nothing', function () {
    $this->actingAs(blogTagsBrowserActor());

    visit('/blog/tags')
        ->click('@create-blog-tag-button')
        ->fill('name', 'Marathon')
        ->click('Cancel')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect(BlogTag::query()->count())->toBe(0);
});

test('deleting a tag through the confirmation modal removes it in one click, with no count or blocked step ever shown', function () {
    // The highest-value browser test in this story, and the exact inverse of the product
    // categories one: only a real DOM click proves the confirm control was never wired to a guard
    // that does not exist server-side.
    $this->actingAs(blogTagsBrowserActor());
    $tag = BlogTag::factory()->create(['name' => 'Marathon']);

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@delete-blog-tag-'.$tag->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@confirm-delete-blog-tag')
        ->assertDontSee('cannot be deleted')
        ->assertDontSee('reassign')
        ->click('@confirm-delete-blog-tag')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertMissing('@delete-blog-tag-'.$tag->id);

    expect(BlogTag::query()->whereKey($tag->id)->exists())->toBeFalse();
});

test('creating a duplicate name through the real form shows the inline error', function () {
    $this->actingAs(blogTagsBrowserActor());
    BlogTag::factory()->create(['name' => 'Marathon']);

    visit('/blog/tags')
        ->click('@create-blog-tag-button')
        ->fill('name', 'MARATHON')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSee(trans('validation.unique', ['attribute' => 'name']));

    expect(BlogTag::query()->count())->toBe(1);
});

test('a smoke pass through create, edit and delete openers and cancels raises no JavaScript error', function () {
    $this->actingAs(blogTagsBrowserActor());
    $tag = BlogTag::factory()->create(['name' => 'Marathon']);

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@create-blog-tag-button')
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors()
        ->click('@delete-blog-tag-'.$tag->id)
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors();

    expect(BlogTag::query()->count())->toBe(1);
});
