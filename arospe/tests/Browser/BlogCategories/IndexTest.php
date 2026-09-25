<?php

// Story 0062 -- Pest 4 browser tests for the blog category management screen (route
// blog-categories.index, GET /blog/categories). Mirrored folder per 0060's V-1, like
// tests/Browser/BlogTags/.
//
// What only a real browser proves, and Livewire::test() cannot: that wire:model actually delivers a
// typed value (->set() writes the property directly), that the compiled wire:click arguments work,
// and -- the reason this story exists -- that a REFUSED delete does not merely look like it
// succeeded. A confirmation modal that closes and drops the row while the server refused is
// invisible to any non-DOM test.
//
// The in-use fixture is seeded with factories directly: Pest's browser plugin runs through the same
// in-process Laravel kernel, so the test's open transaction is visible to the page (V-3).
//
// Rows are asserted through their row-scoped `data-test` hooks, never a page-global assertSee('3')
// that would also match inside '13' or a decoy row's own count (R-6).
//
// ->waitForEvent('networkidle') is banned in this repo (it never settles here); a short bounded
// ->wait(1) after a Livewire round trip is the accepted mitigation, and each one below
// compensates for exactly that: the DOM has not yet re-rendered from the server response.

use App\Models\BlogCategory;
use App\Models\BlogPost;
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
function blogCategoriesBrowserActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('opening the create form shows a blank field, with no stale prefill from a previous edit', function () {
    $this->actingAs(blogCategoriesBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-category-'.$category->id)
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-category-name-input', 'Guías')
        ->click('Cancel')
        ->click('@create-blog-category-button')
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-category-name-input', '');
});

test('creating a category through a real fill and click round trip adds it to the list', function () {
    $this->actingAs(blogCategoriesBrowserActor());

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@create-blog-category-button')
        ->fill('name', 'Guías')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $category = BlogCategory::query()->where('name', 'Guías')->sole();

    visit('/blog/categories')->assertPresent('@edit-blog-category-'.$category->id);
});

test('editing prefills the name, and re-saving it unchanged preserves it', function () {
    $this->actingAs(blogCategoriesBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    visit('/blog/categories')
        ->click('@edit-blog-category-'.$category->id)
        ->assertValue('@blog-category-name-input', 'Guías')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($category->fresh()->name)->toBe('Guías');
});

test('renaming through the real input persists the new name', function () {
    $this->actingAs(blogCategoriesBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    visit('/blog/categories')
        ->click('@edit-blog-category-'.$category->id)
        ->fill('name', 'Novedades')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($category->fresh()->name)->toBe('Novedades');
});

test('cancelling the create form adds nothing', function () {
    $this->actingAs(blogCategoriesBrowserActor());

    visit('/blog/categories')
        ->click('@create-blog-category-button')
        ->fill('name', 'Guías')
        ->click('Cancel')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect(BlogCategory::query()->count())->toBe(0);
});

test('deleting an unused category through the confirmation modal removes it from the list', function () {
    $this->actingAs(blogCategoriesBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@delete-blog-category-'.$category->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@confirm-delete-blog-category')
        ->assertMissing('@blog-category-delete-blocked')
        ->click('@confirm-delete-blog-category')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertMissing('@delete-blog-category-'.$category->id);

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeFalse();
});

test('deleting a category that is in use renders the refusal inline, keeps the category listed and never looks like it succeeded', function () {
    // The highest-value browser test in this story: only a real DOM render proves the
    // confirmation UI does not close and drop the row while the delete was refused server-side.
    $this->actingAs(blogCategoriesBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count(5)->create(['blog_category_id' => $category->id]);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@blog-category-post-count-'.$category->id, '5')
        ->click('@delete-blog-category-'.$category->id)
        ->click('@confirm-delete-blog-category')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@blog-category-delete-blocked')
        ->assertSeeIn('@blog-category-delete-blocked', 'This category is used by 5 posts')
        ->assertPresent('@confirm-delete-blog-category')
        ->assertPresent('@delete-blog-category-'.$category->id);

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('creating a duplicate name through the real form shows the inline error', function () {
    $this->actingAs(blogCategoriesBrowserActor());
    BlogCategory::factory()->create(['name' => 'Guías']);

    visit('/blog/categories')
        ->click('@create-blog-category-button')
        ->fill('name', 'GUÍAS')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSee(trans('validation.unique', ['attribute' => 'name']));

    expect(BlogCategory::query()->count())->toBe(1);
});

test('a smoke pass through create, edit and blocked delete openers and cancels raises no JavaScript error', function () {
    $this->actingAs(blogCategoriesBrowserActor());
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['blog_category_id' => $category->id]);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@create-blog-category-button')
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-category-'.$category->id)
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors()
        ->click('@delete-blog-category-'.$category->id)
        ->assertNoJavaScriptErrors()
        ->click('@confirm-delete-blog-category')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors();

    expect(BlogCategory::query()->count())->toBe(1);
});
