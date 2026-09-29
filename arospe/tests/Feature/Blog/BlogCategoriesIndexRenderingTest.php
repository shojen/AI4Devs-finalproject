<?php

// Story 0062 -- the RENDERED half of App\Livewire\BlogCategories\Index (resources/views/livewire/
// blog-categories.blade.php). Component logic, persistence, validation and authorization live in
// BlogCategoriesIndexTest.php; nothing here duplicates it. Every test asserts against the rendered
// HTML, which that file never does.
//
// The signature tests of this story are the two delete-modal ones: a blocked delete must render
// the refusal the human actually reads, and must offer no control that proceeds past it. An
// implementer under time pressure could add a "delete anyway (Super Admin)" affordance as
// reasonable-seeming UX, and only a NEGATIVE assertion against the rendered modal catches it.

use App\Livewire\BlogCategories\Index;
use App\Models\BlogCategory;
use App\Models\BlogPost;
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
function blogCategoriesRenderingActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
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
 * `disabled:opacity-75` on the ENABLED branch too (R-6).
 */
function blogCategoriesControlIsDisabled(string $html, string $hook): bool
{
    return preg_match(
        '/<[a-z0-9-]+(?=[^>]*\bdata-test="'.preg_quote($hook, '/').'")(?=[^>]*\sdisabled="disabled")[^>]*>/is',
        $html,
    ) === 1;
}

function blogCategoriesControlExists(string $html, string $hook): bool
{
    return str_contains($html, 'data-test="'.$hook.'"');
}

/**
 * The digits inside one row's post-count cell -- never a page-global assertSee('3'), which matches
 * inside `13` and inside a decoy row's own count (R-6).
 */
function blogCategoriesRenderedPostCount(string $html, string $categoryId): ?int
{
    return preg_match('/data-test="blog-category-post-count-'.preg_quote($categoryId, '/').'"[^>]*>\s*(\d+)\s*</', $html, $matches) === 1
        ? (int) $matches[1]
        : null;
}

test('the list renders each category\'s name and its own post count', function () {
    $guides = BlogCategory::factory()->create(['name' => 'Guías']);
    $news = BlogCategory::factory()->create(['name' => 'Novedades']);
    BlogPost::factory()->count(3)->create(['blog_category_id' => $guides->id]);
    $this->actingAs(blogCategoriesRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->assertSee('Guías')->assertSee('Novedades')
        ->assertDontSee(__('blog.categories.index.empty'))->html();

    expect(blogCategoriesRenderedPostCount($html, $guides->id))->toBe(3)
        ->and(blogCategoriesRenderedPostCount($html, $news->id))->toBe(0);
});

test('the empty state renders when the catalog holds no categories', function () {
    $this->actingAs(blogCategoriesRenderingActor(['blog.view']));

    Livewire::test(Index::class)->assertSee(__('blog.categories.index.empty'));
});

test('the header carries the create action, hooked for browser tests', function () {
    $this->actingAs(blogCategoriesRenderingActor());

    expect(blogCategoriesControlExists(Livewire::test(Index::class)->html(), 'create-blog-category-button'))->toBeTrue();
});

test('the create modal contains exactly one input and no select', function () {
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect(substr_count($html, '<input'))->toBe(1)
        ->and(blogCategoriesControlExists($html, 'blog-category-name-input'))->toBeTrue()
        ->and($html)->not->toContain('<select');
});

test('the edit modal prefills the one field and offers a single Cancel control', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->call('openEditModal', $category->id)->html();

    expect(substr_count($html, '<input'))->toBe(1)
        ->and(substr_count($html, __('blog.categories.index.cancel')))->toBe(1);
});

test('the modals render nothing until opened, so a closed screen has no stray Cancel controls', function () {
    BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect($html)->not->toContain('<input')
        ->and($html)->not->toContain(__('blog.categories.index.cancel'));
});

test('a refused name renders its message beside the field and the modal stays open', function () {
    $this->actingAs(blogCategoriesRenderingActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', '')
        ->call('save')
        ->assertSet('showModal', true)
        ->assertSee(trans('validation.required', ['attribute' => 'name']));
});

test('a duplicate name renders its message beside the field', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesRenderingActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'GUÍAS')
        ->call('save')
        ->assertSee(trans('validation.unique', ['attribute' => 'name']));
});

// =====================================================================
// The delete-confirmation modal -- the blocked-delete refusal (0061 D-18, D-2 here)
// =====================================================================

test('the blocked-delete refusal renders in the DOM with the correct digit, singular and plural', function (int $count, string $message) {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count($count)->create(['blog_category_id' => $category->id]);
    $this->actingAs(blogCategoriesRenderingActor());

    // A test asserting only assertHasErrors() never proves the human actually sees the sentence.
    $html = Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->html();

    expect(blogCategoriesControlExists($html, 'blog-category-delete-blocked'))->toBeTrue()
        ->and($html)->toContain(e($message));
})->with([
    'one post' => [1, 'This category is used by 1 post — reassign it before deleting.'],
    'five posts' => [5, 'This category is used by 5 posts — reassign them before deleting.'],
]);

test('the delete confirmation names the category and renders no refusal callout until a delete has been refused', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count(2)->create(['blog_category_id' => $category->id]);
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->call('confirmDelete', $category->id)->html();

    // Never pre-disabled on the post count (D-12): the editor attempts the delete and reads the
    // count in the refusal, which is the only path the PRD's wording allows.
    expect($html)->toContain(e(__('blog.categories.index.delete_body', ['name' => 'Guías'])))
        ->and(blogCategoriesControlExists($html, 'confirm-delete-blog-category'))->toBeTrue()
        ->and(blogCategoriesControlIsDisabled($html, 'confirm-delete-blog-category'))->toBeFalse()
        ->and(blogCategoriesControlExists($html, 'blog-category-delete-blocked'))->toBeFalse();
});

test('the delete modal renders no confirm-and-proceed control of any kind once the delete is blocked', function () {
    // Arguably the single highest-value test in this story: absence is the thing under test.
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count(5)->create(['blog_category_id' => $category->id]);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    $html = Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->html();

    // Every data-test hook in the rendered screen -- so an added control cannot hide behind a
    // hook this test did not think to name.
    preg_match_all('/data-test="([^"]+)"/', $html, $matches);
    $hooks = collect($matches[1])->reject(fn (string $hook): bool => str_starts_with($hook, 'edit-blog-category-')
        || str_starts_with($hook, 'delete-blog-category-')
        || str_starts_with($hook, 'blog-category-post-count-'))->values()->all();

    expect($hooks)->toEqualCanonicalizing(['create-blog-category-button', 'confirm-delete-blog-category', 'blog-category-delete-blocked'])
        ->and($html)->not->toMatch('/\b(force|anyway|override|proceed|bypass)\b/i')
        ->and($html)->not->toMatch('/reassign[^<]*<\/(button|a)>/i')
        ->and($html)->not->toContain('wire:click="forceDelete')
        ->and(substr_count($html, 'wire:click="deleteCategory"'))->toBe(1);
});

test('the delete modal for an unused category renders no refusal and a confirm control that is never disabled', function () {
    $category = BlogCategory::factory()->create(['name' => str_repeat('a', 100)]);
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->call('confirmDelete', $category->id)->html();

    expect(blogCategoriesControlExists($html, 'confirm-delete-blog-category'))->toBeTrue()
        ->and(blogCategoriesControlIsDisabled($html, 'confirm-delete-blog-category'))->toBeFalse()
        ->and($html)->not->toContain('data-flux-error');
});

test('the delete modal is absent until a confirmation is opened', function () {
    BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesRenderingActor());

    expect(blogCategoriesControlExists(Livewire::test(Index::class)->html(), 'confirm-delete-blog-category'))->toBeFalse();
});

test('a stale refusal does not render in the delete modal of an unused category opened afterwards', function () {
    $blocked = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['blog_category_id' => $blocked->id]);
    $unused = BlogCategory::factory()->create(['name' => 'Novedades']);
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)
        ->call('confirmDelete', $blocked->id)->call('deleteCategory')
        ->call('closeDeleteModal')
        ->call('confirmDelete', $unused->id)
        ->html();

    expect(blogCategoriesControlExists($html, 'blog-category-delete-blocked'))->toBeFalse()
        ->and($html)->not->toContain('This category is used by');
});

// =====================================================================
// Row actions -- hooks on both branches
// =====================================================================

test('row action hooks are present on both the enabled and the disabled branch, and only the disabled one carries disabled="disabled"', function () {
    $category = BlogCategory::factory()->create();
    $edit = 'edit-blog-category-'.$category->id;
    $delete = 'delete-blog-category-'.$category->id;

    $this->actingAs(blogCategoriesRenderingActor());
    $enabled = Livewire::test(Index::class)->html();

    $this->actingAs(blogCategoriesRenderingActor(['blog.view']));
    $disabled = Livewire::test(Index::class)->html();

    expect(blogCategoriesControlExists($enabled, $edit))->toBeTrue()
        ->and(blogCategoriesControlExists($enabled, $delete))->toBeTrue()
        ->and(blogCategoriesControlIsDisabled($enabled, $edit))->toBeFalse()
        ->and(blogCategoriesControlIsDisabled($enabled, $delete))->toBeFalse()
        ->and(blogCategoriesControlExists($disabled, $edit))->toBeTrue()
        ->and(blogCategoriesControlExists($disabled, $delete))->toBeTrue()
        ->and(blogCategoriesControlIsDisabled($disabled, $edit))->toBeTrue()
        ->and(blogCategoriesControlIsDisabled($disabled, $delete))->toBeTrue();
});

test('the post count never disables the delete action: a category in use still has an enabled delete control', function () {
    // D-12: pre-disabling on postCount > 0 would conflate the in-use refusal with the
    // authorization hint.
    $category = BlogCategory::factory()->create();
    BlogPost::factory()->count(3)->create(['blog_category_id' => $category->id]);
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect(blogCategoriesControlIsDisabled($html, 'delete-blog-category-'.$category->id))->toBeFalse();
});

test('a view-only actor sees the create control unavailable too', function () {
    $this->actingAs(blogCategoriesRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect(blogCategoriesControlExists($html, 'create-blog-category-button'))->toBeTrue()
        ->and(blogCategoriesControlIsDisabled($html, 'create-blog-category-button'))->toBeTrue();
});

test('every row action carries an accessible name', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('aria-label="'.e(__('blog.categories.index.edit_aria', ['name' => 'Guías'])).'"')
        ->and($html)->toContain('aria-label="'.e(__('blog.categories.index.delete_aria', ['name' => 'Guías'])).'"');
});

test('each row\'s wire:click hands its id to the component as a quoted JS literal (@js), never a bare interpolation', function () {
    // Livewire::test()->call() never goes through a compiled wire:click, so only the rendered HTML
    // can show the argument was encoded.
    $category = BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->html();
    $quote = '(?:&quot;|&#039;|\'|")';

    expect($html)->toMatch('/wire:click="openEditModal\('.$quote.preg_quote($category->id, '/').$quote.'\)"/')
        ->and($html)->toMatch('/wire:click="confirmDelete\('.$quote.preg_quote($category->id, '/').$quote.'\)"/');
});

test('a category name that looks like markup is escaped, not rendered', function () {
    BlogCategory::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs(blogCategoriesRenderingActor(['blog.view']));

    $html = Livewire::test(Index::class)->html();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('nothing on the screen references a product taxonomy', function () {
    // Scoped to the component's own html, never the full page: the shared sidebar legitimately
    // carries product-taxonomy entries (D-10).
    BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect($html)->not->toMatch('/product/i')
        ->and($html)->not->toContain('product-categories');
});
