<?php

// Story 0062 -- App\Livewire\BlogCategories\Index, the blog category management screen. This file
// holds the route-level HTTP block, the component's behaviour (listing, create, rename, delete and
// the hard-blocked delete) and its authorization and refusal-logging layers. The RENDERED markup
// lives in BlogCategoriesIndexRenderingTest.php; the real-browser round trips in
// tests/Browser/BlogCategories/.
//
// Deliberately NOT re-run here: story 0058's exhaustive normalisation / trim / boundary / race
// matrix and story 0061's action-level count semantics. This file asserts only that the component
// ROUTES INTO the shared rules, with named canaries, and that the same `blogCategoryId` key and the
// same digits surface through the UI. The case-only and accent-only canaries pass on
// utf8mb4_unicode_ci alone, so they cannot prove the normaliser ran (R-9) -- 0058's whitespace
// tests do.

use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Actions\Blog\RenameBlogCategory;
use App\Concerns\BlogCategoryValidationRules;
use App\Livewire\BlogCategories\Index;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An actor holding every blog.* CRUD permission -- the default fixture for tests whose subject is
 * not authorization itself.
 *
 * @param  array<int, string>  $permissions
 */
function blogCategoriesIndexTestActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Takes a permission away from an actor whose component is already mounted, the only way to reach
 * a method's own authorization when the opener that would normally gate it is itself refused.
 */
function blogCategoriesIndexRevoke(User $actor, string $permission): void
{
    $actor->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * The component's PHP source with every comment stripped, so a docblock that merely explains why
 * something is NOT done cannot trip an assertion that it is not done.
 */
function blogCategoriesIndexCodeWithoutComments(): string
{
    $source = file_get_contents((new ReflectionClass(Index::class))->getFileName());

    return collect(token_get_all($source))
        ->reject(fn ($token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/**
 * Builds a category holding `$count` posts, with a decoy category holding its own posts so a global
 * `BlogPost::count()` and a correctly scoped one are never indistinguishable.
 */
function blogCategoriesIndexCategoryWithPosts(string $name, int $count, int $decoyPosts = 4): BlogCategory
{
    $category = BlogCategory::factory()->create(['name' => $name]);
    BlogPost::factory()->count($count)->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->count($decoyPosts)->create(['blog_category_id' => BlogCategory::factory()->create(['name' => 'Decoy '.$name])->id]);

    return $category;
}

// =====================================================================
// $this->get(route('blog-categories.index')) -- HTTP layer
// =====================================================================

test('guests are redirected to the login page when visiting the blog category screen', function () {
    $this->get(route('blog-categories.index'))->assertRedirect(route('login'));
});

test('a signed-in user without blog.view is forbidden from the blog category screen', function () {
    $this->actingAs(blogCategoriesIndexTestActor([]));

    $this->get(route('blog-categories.index'))->assertForbidden();
});

test('a user holding blog.view can reach the blog category screen', function () {
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $this->get(route('blog-categories.index'))->assertOk();
});

test('a user holding only the related-but-different blog.edit permission is forbidden from the blog category screen', function () {
    $this->actingAs(blogCategoriesIndexTestActor(['blog.edit']));

    $this->get(route('blog-categories.index'))->assertForbidden();
});

test('a Super Admin holding zero permission rows can reach the blog category screen', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('blog-categories.index'))->assertOk();
});

test('the blog category route lives at /blog/categories behind the can: gate, never Spatie\'s permission: middleware', function () {
    $route = app('router')->getRoutes()->getByName('blog-categories.index');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('blog/categories')
        ->and($route->gatherMiddleware())->toContain('can:blog.view')
        ->and(collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')))->toBeEmpty();
});

// =====================================================================
// Livewire::test(Index::class) -- the component's own authorization
// =====================================================================

test('mounting the component without blog.view is refused, independently of the route', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(blogCategoriesIndexTestActor([]));

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

test('mounting the component as a Super Admin holding zero permission rows succeeds', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    Livewire::test(Index::class)->assertOk();
});

test('openCreateModal() is refused without blog.create', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    expect(fn () => Livewire::test(Index::class)->call('openCreateModal'))
        ->toThrow(AuthorizationException::class);
});

test('openEditModal() is refused without blog.edit', function () {
    $this->withoutExceptionHandling();
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $component = Livewire::test(Index::class);

    expect(fn () => $component->call('openEditModal', $category->id))->toThrow(AuthorizationException::class);
});

test('confirmDelete() is refused without blog.delete', function () {
    $this->withoutExceptionHandling();
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $component = Livewire::test(Index::class);

    expect(fn () => $component->call('confirmDelete', $category->id))->toThrow(AuthorizationException::class);
});

test('save() in create mode is refused when blog.create is revoked after the modal opened, and persists nothing', function () {
    $this->withoutExceptionHandling();
    $actor = blogCategoriesIndexTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openCreateModal')->set('name', 'Guías');

    blogCategoriesIndexRevoke($actor, 'blog.create');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect(BlogCategory::query()->count())->toBe(0);
});

test('save() in edit mode is refused when blog.edit is revoked after the modal opened, and renames nothing', function () {
    $this->withoutExceptionHandling();
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $actor = blogCategoriesIndexTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id)->set('name', 'Novedades');

    blogCategoriesIndexRevoke($actor, 'blog.edit');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect($category->fresh()->name)->toBe('Guías');
});

test('deleteCategory() is refused when blog.delete is revoked after the confirmation opened, and deletes nothing', function () {
    $this->withoutExceptionHandling();
    $category = BlogCategory::factory()->create();
    $actor = blogCategoriesIndexTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('confirmDelete', $category->id);

    blogCategoriesIndexRevoke($actor, 'blog.delete');

    expect(fn () => $component->call('deleteCategory'))->toThrow(AuthorizationException::class);
    expect(BlogCategory::query()->count())->toBe(1);
});

test('a Super Admin holding zero permission rows can create, rename and delete', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', 'Novedades')->call('save')
        ->call('openEditModal', $category->id)->set('name', 'Guías de compra')->call('save')
        ->call('confirmDelete', $category->id)->call('deleteCategory')
        ->assertHasNoErrors();

    expect(BlogCategory::query()->pluck('name')->all())->toBe(['Novedades']);
});

test('an actor holding only blog.view sees every row action disabled -- one global-state test, not a per-row matrix', function () {
    BlogCategory::factory()->count(3)->create();
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $rows = Livewire::test(Index::class)->get('categories');

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('canEdit')->unique()->all())->toBe([false])
        ->and(collect($rows)->pluck('canDelete')->unique()->all())->toBe([false]);
});

test('row hints follow the policy independently: blog.edit alone enables edit but not delete', function () {
    BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view', 'blog.edit']));

    $row = Livewire::test(Index::class)->get('categories')[0];

    expect($row['canEdit'])->toBeTrue()->and($row['canDelete'])->toBeFalse();
});

// =====================================================================
// Listing
// =====================================================================

test('the list is ordered by name, whatever order the rows were created in', function () {
    foreach (['Zeta', 'alpha', 'Beta'] as $name) {
        BlogCategory::factory()->create(['name' => $name]);
    }
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $names = collect(Livewire::test(Index::class)->get('categories'))->pluck('name')->all();

    expect($names)->toBe(['alpha', 'Beta', 'Zeta']);
});

test('each row exposes exactly {id, name, postCount, canEdit, canDelete}', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    $row = Livewire::test(Index::class)->get('categories')[0];

    expect(array_keys($row))->toBe(['id', 'name', 'postCount', 'canEdit', 'canDelete']);
});

test('the category list and every id-carrying property are #[Locked], so a client cannot forge them', function () {
    $category = BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class);

    foreach (['categories' => [], 'editingCategoryId' => $category->id, 'blogCategoryId' => $category->id, 'deletingCategoryName' => 'x'] as $property => $value) {
        expect(fn () => $component->set($property, $value))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

// =====================================================================
// Create
// =====================================================================

test('creating a category with a valid name persists exactly one row, lists it and closes the modal', function () {
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Guías')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertSet('name', '');

    expect(BlogCategory::query()->pluck('name')->all())->toBe(['Guías'])
        ->and(collect($component->get('categories'))->pluck('name')->all())->toBe(['Guías']);
});

test('opening the create form after an edit shows a blank field, never the previous category', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->assertSet('name', 'Guías')
        ->call('closeModal')
        ->call('openCreateModal')
        ->assertSet('name', '')
        ->assertSet('editingCategoryId', null);
});

test('an unacceptable create name is refused on the name field and adds no row', function (string $invalid) {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', $invalid)
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSet('showModal', true);

    expect(BlogCategory::query()->count())->toBe(1);
})->with([
    'blank' => [''],
    'whitespace only' => ["   \t "],
    'exact duplicate' => ['Guías'],
    'case-only duplicate' => ['GUÍAS'],
    'accent-only duplicate' => ['Guias'],
]);

test('the length boundary is read from the shared constant: max accepted, max + 1 refused', function () {
    $this->actingAs(blogCategoriesIndexTestActor());
    $max = BlogCategory::NAME_MAX_LENGTH;

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', str_repeat('a', $max + 1))->call('save')
        ->assertHasErrors(['name'])
        ->set('name', str_repeat('a', $max))->call('save')
        ->assertHasNoErrors();

    expect(BlogCategory::query()->count())->toBe(1);
});

test('save() validates through the injected actions, never a component-side rule', function () {
    // D-1: the component neither composes the validation trait nor calls $this->validate().
    $injected = collect((new ReflectionMethod(Index::class, 'save'))->getParameters())
        ->map(fn (ReflectionParameter $parameter): string => $parameter->getType()->getName())
        ->all();

    expect($injected)->toContain(CreateBlogCategory::class)
        ->toContain(RenameBlogCategory::class)
        ->and(class_uses(Index::class))->not->toContain(BlogCategoryValidationRules::class)
        ->and(blogCategoriesIndexCodeWithoutComments())
        ->not->toContain('->validate(')
        ->not->toContain('Str::lower')
        ->not->toContain('Str::ascii');
});

// =====================================================================
// Rename
// =====================================================================

test('renaming a category to a free name updates the row and closes the modal', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->assertSet('name', 'Guías')
        ->assertSet('editingCategoryId', $category->id)
        ->assertSet('showModal', true)
        ->set('name', 'Guías de compra')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect($category->fresh()->name)->toBe('Guías de compra')
        ->and(collect($component->get('categories'))->pluck('name')->all())->toBe(['Guías de compra']);
});

test('saving a category under its own unchanged name is accepted', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)->call('openEditModal', $category->id)->call('save')->assertHasNoErrors();

    expect($category->fresh()->name)->toBe('Guías');
});

test('renaming a category onto another category\'s exact name is refused and the category keeps its name', function () {
    BlogCategory::factory()->create(['name' => 'Guías']);
    $other = BlogCategory::factory()->create(['name' => 'Novedades']);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $other->id)
        ->set('name', 'Guías')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSet('showModal', true);

    expect($other->fresh()->name)->toBe('Novedades');
});

test('the id feeding the uniqueness exclusion is server-authoritative: a forged editingCategoryId throws instead of retargeting the rename', function () {
    // The single most important rename test (0058's hand-off obligation, R-4): without #[Locked],
    // set('editingCategoryId', $b) between opening the modal and saving would turn the uniqueness
    // check into a rename-any-category primitive.
    $this->withoutExceptionHandling();
    $a = BlogCategory::factory()->create(['name' => 'Alfa']);
    $b = BlogCategory::factory()->create(['name' => 'Beta']);
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class)->call('openEditModal', $a->id);

    expect(fn () => $component->set('editingCategoryId', $b->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $component->set('name', 'Renombrada')->call('save');

    expect($a->fresh()->name)->toBe('Renombrada')->and($b->fresh()->name)->toBe('Beta');
});

test('saving an edit for a category deleted in the meantime fails cleanly rather than recreating or silently succeeding', function () {
    $this->withoutExceptionHandling();
    $category = BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id)->set('name', 'x');
    $category->delete();

    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect(BlogCategory::query()->count())->toBe(0);
});

test('closing the modal clears a stale name error so it cannot leak into the next attempt', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', '')->call('save')->assertHasErrors(['name'])
        ->call('closeModal')->assertHasNoErrors()
        ->call('openEditModal', $category->id)->assertHasNoErrors();
});

// =====================================================================
// Delete -- unused
// =====================================================================

test('deleting an unused category removes the row and it disappears from the reloaded list', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogCategory::factory()->create(['name' => 'Novedades']);
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->assertSet('blogCategoryId', $category->id)
        ->assertSet('deletingCategoryName', 'Guías')
        ->assertSet('showDeleteModal', true)
        ->call('deleteCategory')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false)
        ->assertSet('blogCategoryId', '')
        ->assertSet('deletingCategoryName', '');

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and(collect($component->get('categories'))->pluck('name')->all())->toBe(['Novedades']);
});

test('deleteCategory() with no confirmation open does nothing', function () {
    BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)->call('deleteCategory')->assertHasNoErrors();

    expect(BlogCategory::query()->count())->toBe(1);
});

// =====================================================================
// Delete -- hard-blocked while any post still uses the category (0061 D-18)
// =====================================================================

test('closeDeleteModal() clears the stale blogCategoryId error so it cannot leak into the next delete attempt', function () {
    // R-5, written before the happy-path delete on purpose: the block message lives in the error
    // bag, not in a property closeDeleteModal()'s reset() would clear.
    $blocked = blogCategoriesIndexCategoryWithPosts('Guías', 2);
    $unused = BlogCategory::factory()->create(['name' => 'Novedades']);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $blocked->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId'])
        ->call('closeDeleteModal')
        ->assertHasNoErrors()
        ->call('confirmDelete', $unused->id)
        ->assertHasNoErrors();
});

test('the two modals reset only their own error key: closeModal() leaves the block message and closeDeleteModal() leaves the name error', function () {
    // R-5 / D-3: a bare resetValidation() in either method would pass the two tests above, which
    // only assert "no errors" after their own close.
    $blocked = blogCategoriesIndexCategoryWithPosts('Guías', 2);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', '')->call('save')->assertHasErrors(['name'])
        ->call('confirmDelete', $blocked->id)->call('deleteCategory')->assertHasErrors(['blogCategoryId'])
        ->call('closeModal')
        ->assertHasNoErrors(['name'])
        ->assertHasErrors(['blogCategoryId'])
        ->call('openCreateModal')->set('name', '')->call('save')->assertHasErrors(['name'])
        ->call('closeDeleteModal')
        ->assertHasNoErrors(['blogCategoryId'])
        ->assertHasErrors(['name']);
});

test('a blocked delete surfaces an error on the blogCategoryId key, keeps the modal open and the category alive', function () {
    $category = blogCategoriesIndexCategoryWithPosts('Guías', 5);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId'])
        ->assertSet('showDeleteModal', true)
        ->assertSet('blogCategoryId', $category->id);

    // A guard that threw AFTER deleting would pass a throw-only test.
    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('the block message states the real count, singular and plural, against a decoy category with posts of its own', function (int $count, string $message) {
    $category = blogCategoriesIndexCategoryWithPosts('Guías', $count);
    $this->actingAs(blogCategoriesIndexTestActor());

    // Literal expected strings, never a second trans_choice() call: re-invoking it with the same
    // arguments would be a tautology.
    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => $message]);
})->with([
    'one post' => [1, 'This category is used by 1 post — reassign it before deleting.'],
    'two posts' => [2, 'This category is used by 2 posts — reassign them before deleting.'],
    'five posts' => [5, 'This category is used by 5 posts — reassign them before deleting.'],
]);

test('unpublished posts count towards the block', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->draft()->count(2)->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->scheduled()->create(['blog_category_id' => $category->id]);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => 'This category is used by 3 posts — reassign them before deleting.']);
});

test('a category whose only post is trashed still blocks, with a count of 1 and not 0', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['blog_category_id' => $category->id])->delete();
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => 'This category is used by 1 post — reassign it before deleting.']);

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('the rendered row count equals the count the refusal states: 1 live and 2 trashed posts are both 3', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->count(2)->create(['blog_category_id' => $category->id])->each->delete();
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class);

    expect(collect($component->get('categories'))->firstWhere('id', $category->id)['postCount'])->toBe(3);

    $component->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => 'This category is used by 3 posts — reassign them before deleting.']);
});

test('the row count is scoped to its own category, never a global post count', function () {
    $category = blogCategoriesIndexCategoryWithPosts('Guías', 2, decoyPosts: 7);
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $counts = collect(Livewire::test(Index::class)->get('categories'))->pluck('postCount', 'name');

    expect($counts['Guías'])->toBe(2)->and($counts['Decoy Guías'])->toBe(7)
        ->and($category->posts()->count())->toBe(2);
});

test('a Super Admin is refused identically: the block is a domain invariant, not an authorization rule', function () {
    // The single most important authorization test in this story -- Gate::before's bypass must be
    // provably irrelevant to the in-use guard.
    $category = blogCategoriesIndexCategoryWithPosts('Guías', 5);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => 'This category is used by 5 posts — reassign them before deleting.'])
        ->assertSet('showDeleteModal', true);

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('a post assigned between the count and the delete is refused cleanly by the FK backstop, never as a QueryException', function () {
    // R-7: the only test that reaches DeleteBlogCategory's 1451 fallback through the screen. The
    // collision is driven through the REAL constraint by a `deleting` listener, the technique
    // 0061's own action-level test uses.
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());
    $armed = false;

    BlogCategory::deleting(function (BlogCategory $deleting) use (&$armed): void {
        if ($armed) {
            $armed = false;
            BlogPost::factory()->create(['blog_category_id' => $deleting->id]);
        }
    });

    $component = Livewire::test(Index::class)->call('confirmDelete', $category->id);
    $armed = true;

    $component->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => 'This category is used by 1 post — reassign it before deleting.'])
        ->assertSet('showDeleteModal', true);

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('a category whose posts arrive between opening the modal and confirming fails closed instead of reading as unused', function () {
    // D-13: the loaded postCount is display-only; the action re-counts at click time.
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoriesIndexTestActor());

    $component = Livewire::test(Index::class)->call('confirmDelete', $category->id);
    BlogPost::factory()->count(2)->create(['blog_category_id' => $category->id]);

    $component->call('deleteCategory')
        ->assertHasErrors(['blogCategoryId' => 'This category is used by 2 posts — reassign them before deleting.']);
});

test('deleting twice in succession an in-use category is refused both times, with no confirmed state accumulating', function () {
    $category = blogCategoriesIndexCategoryWithPosts('Guías', 2);
    $this->actingAs(blogCategoriesIndexTestActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')->assertHasErrors(['blogCategoryId'])
        ->call('deleteCategory')->assertHasErrors(['blogCategoryId'])
        ->assertSet('showDeleteModal', true);

    expect(BlogCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('there is no force, confirm-and-proceed or reassign path -- the hard block is the contract, not an oversight', function () {
    $reflection = new ReflectionClass(Index::class);

    // Only what this component itself declares -- Livewire's base Component has public members of
    // its own that are not this screen's affair.
    $declared = fn (ReflectionMethod|ReflectionProperty $member): bool => $member->getDeclaringClass()->getName() === Index::class;

    $publicMembers = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter($declared)
        ->map(fn (ReflectionMethod $method): string => strtolower($method->getName()))
        ->merge(collect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))
            ->filter($declared)
            ->map(fn (ReflectionProperty $property): string => strtolower($property->getName())));

    expect($publicMembers->filter(fn (string $name): bool => preg_match('/force|anyway|override|reassign|replacement|proceed/', $name) === 1))->toBeEmpty();

    $deleteCategory = new ReflectionMethod(Index::class, 'deleteCategory');

    expect($deleteCategory->getParameters()[0]->getType()->getName())->toBe(DeleteBlogCategory::class)
        ->and(collect($deleteCategory->getParameters())->map->getName()->filter(fn (string $name): bool => str_contains(strtolower($name), 'force')))->toBeEmpty()
        ->and(blogCategoriesIndexCodeWithoutComments())->not->toContain('catch');
});

// =====================================================================
// Malformed / unknown ids
// =====================================================================

test('openEditModal() and confirmDelete() with an unknown or malformed id fail cleanly, not as a silent no-op', function (string $method, string $id) {
    $this->withoutExceptionHandling();
    $this->actingAs(blogCategoriesIndexTestActor());

    expect(fn () => Livewire::test(Index::class)->call($method, $id))->toThrow(ModelNotFoundException::class);
})->with([
    'edit: unknown uuid' => ['openEditModal', '0198a3a0-0000-7000-8000-000000000000'],
    'edit: not a uuid' => ['openEditModal', 'not-a-uuid'],
    'edit: empty' => ['openEditModal', ''],
    'delete: unknown uuid' => ['confirmDelete', '0198a3a0-0000-7000-8000-000000000000'],
    'delete: not a uuid' => ['confirmDelete', 'not-a-uuid'],
    'delete: empty' => ['confirmDelete', ''],
]);

// =====================================================================
// Refusal logging -- BlogCategoryPolicy's first component call site
// =====================================================================

/**
 * Drives one refused call and returns the context of the single 'Privileged action refused'
 * warning it wrote.
 *
 * @param  Closure(): void  $refuse  performs the call that is expected to be refused
 * @return array<string, mixed>
 */
function blogCategoriesRefusedContext(Closure $refuse): array
{
    Log::spy();
    $captured = [];

    $refuse();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            if ($message !== 'Privileged action refused') {
                return false;
            }
            $captured = $context;

            return true;
        })
        ->once();

    return $captured;
}

test('every refusal this component raises writes exactly one warning with target_type blog_category, the actor, the ability and the target', function (string $ability, string $revoke, Closure $act) {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $actor = blogCategoriesIndexTestActor();
    $this->actingAs($actor);

    $context = blogCategoriesRefusedContext(function () use ($actor, $revoke, $act, $category): void {
        $component = Livewire::test(Index::class);
        $act($component, $category, 'arrange');
        blogCategoriesIndexRevoke($actor, $revoke);

        try {
            $act($component, $category, 'refuse');
        } catch (AuthorizationException) {
            // expected -- the log line is what is under test
        }
    });

    expect($context['actor_id'])->toBe($actor->id)
        ->and($context['ability'])->toBe($ability)
        ->and($context['target_type'])->toBe('blog_category')
        ->and(array_keys($context))->toEqualCanonicalizing(['actor_id', 'ability', 'target_type', 'target_id']);
})->with([
    'openCreateModal' => ['create', 'blog.create', fn ($c, $t, $phase) => $phase === 'refuse' ? $c->call('openCreateModal') : null],
    'openEditModal' => ['update', 'blog.edit', fn ($c, $t, $phase) => $phase === 'refuse' ? $c->call('openEditModal', $t->id) : null],
    'confirmDelete' => ['delete', 'blog.delete', fn ($c, $t, $phase) => $phase === 'refuse' ? $c->call('confirmDelete', $t->id) : null],
    'save (create)' => ['create', 'blog.create', fn ($c, $t, $phase) => $phase === 'arrange' ? $c->call('openCreateModal')->set('name', 'x') : $c->call('save')],
    'save (edit)' => ['update', 'blog.edit', fn ($c, $t, $phase) => $phase === 'arrange' ? $c->call('openEditModal', $t->id) : $c->call('save')],
    'deleteCategory' => ['delete', 'blog.delete', fn ($c, $t, $phase) => $phase === 'arrange' ? $c->call('confirmDelete', $t->id) : $c->call('deleteCategory')],
]);

test('the edit and delete refusals name the target category id', function () {
    $category = BlogCategory::factory()->create();
    $this->actingAs(blogCategoriesIndexTestActor(['blog.view']));

    $context = blogCategoriesRefusedContext(function () use ($category): void {
        try {
            Livewire::test(Index::class)->call('openEditModal', $category->id);
        } catch (AuthorizationException) {
            //
        }
    });

    expect($context['target_id'])->toBe($category->id);
});
