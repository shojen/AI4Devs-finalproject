<?php

// Story 0060 -- App\Livewire\BlogTags\Index, the blog tag management screen. This file holds the
// route-level HTTP block, the component's behaviour (listing, create, rename, delete) and its
// authorization and refusal-logging layers. The RENDERED markup lives in
// BlogTagsIndexRenderingTest.php; the real-browser round trips in tests/Browser/BlogTags/.
//
// Deliberately NOT re-run here: story 0059's exhaustive normalisation / trim / boundary / race
// matrix, including its two blocking whitespace tests, which are the only proof that
// NormalizeForSearch is in the call path (a case-only or accent-only canary passes on
// utf8mb4_unicode_ci alone -- R-3). This file asserts only that the component ROUTES INTO the
// shared rule, with named canaries. FindOrCreateBlogTag is never called by this screen (R-7).

use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\FindOrCreateBlogTag;
use App\Actions\Blog\RenameBlogTag;
use App\Concerns\BlogTagValidationRules;
use App\Livewire\BlogTags\Index;
use App\Livewire\Roles\Index as RolesIndex;
use App\Models\BlogTag;
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
function blogTagsIndexTestActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
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
function blogTagsIndexRevoke(User $actor, string $permission): void
{
    $actor->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * The component's PHP source with every comment stripped, so a docblock that merely explains why
 * something is NOT done cannot trip an assertion that it is not done.
 */
function blogTagsIndexCodeWithoutComments(): string
{
    $source = file_get_contents((new ReflectionClass(Index::class))->getFileName());

    return collect(token_get_all($source))
        ->reject(fn ($token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

// =====================================================================
// $this->get(route('blog-tags.index')) -- HTTP layer
// =====================================================================

test('guests are redirected to the login page when visiting the blog tag screen', function () {
    $this->get(route('blog-tags.index'))->assertRedirect(route('login'));
});

test('a signed-in user without blog.view is forbidden from the blog tag screen', function () {
    $this->actingAs(blogTagsIndexTestActor([]));

    $this->get(route('blog-tags.index'))->assertForbidden();
});

test('a user holding blog.view can reach the blog tag screen', function () {
    $this->actingAs(blogTagsIndexTestActor(['blog.view']));

    $this->get(route('blog-tags.index'))->assertOk();
});

test('a user holding only the related-but-different blog.edit permission is forbidden from the blog tag screen', function () {
    $this->actingAs(blogTagsIndexTestActor(['blog.edit']));

    $this->get(route('blog-tags.index'))->assertForbidden();
});

test('a Super Admin holding zero permission rows can reach the blog tag screen', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('blog-tags.index'))->assertOk();
});

test('the blog tag route lives at /blog/tags behind the can: gate, never Spatie\'s permission: middleware', function () {
    $route = app('router')->getRoutes()->getByName('blog-tags.index');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('blog/tags')
        ->and($route->gatherMiddleware())->toContain('can:blog.view')
        ->and(collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')))->toBeEmpty();
});

// =====================================================================
// Livewire::test(Index::class) -- the component's own authorization
// =====================================================================

test('mounting the component without blog.view is refused, independently of the route', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(blogTagsIndexTestActor([]));

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
    $this->actingAs(blogTagsIndexTestActor(['blog.view']));

    expect(fn () => Livewire::test(Index::class)->call('openCreateModal'))
        ->toThrow(AuthorizationException::class);
});

test('openEditModal() is refused without blog.edit and populates nothing', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor(['blog.view']));

    $component = Livewire::test(Index::class);

    expect(fn () => $component->call('openEditModal', $tag->id))->toThrow(AuthorizationException::class);

    $component->assertSet('name', '')->assertSet('editingTagId', null)->assertSet('showModal', false);
});

test('confirmDelete() is refused without blog.delete and populates nothing', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor(['blog.view']));

    $component = Livewire::test(Index::class);

    expect(fn () => $component->call('confirmDelete', $tag->id))->toThrow(AuthorizationException::class);

    $component->assertSet('deletingTagName', '')->assertSet('deletingTagId', null)->assertSet('showDeleteModal', false);
});

test('save() in create mode is refused when blog.create is revoked after the modal opened, and persists nothing', function () {
    $this->withoutExceptionHandling();
    $actor = blogTagsIndexTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openCreateModal')->set('name', 'running');

    blogTagsIndexRevoke($actor, 'blog.create');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect(BlogTag::query()->count())->toBe(0);
});

test('save() in edit mode is refused when blog.edit is revoked after the modal opened, and renames nothing', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $actor = blogTagsIndexTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openEditModal', $tag->id)->set('name', 'jogging');

    blogTagsIndexRevoke($actor, 'blog.edit');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect($tag->fresh()->name)->toBe('running');
});

test('deleteTag() is refused when blog.delete is revoked after the confirmation opened, and deletes nothing', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->create();
    $actor = blogTagsIndexTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('confirmDelete', $tag->id);

    blogTagsIndexRevoke($actor, 'blog.delete');

    expect(fn () => $component->call('deleteTag'))->toThrow(AuthorizationException::class);
    expect(BlogTag::query()->count())->toBe(1);
});

test('a Super Admin holding zero permission rows can create, rename and delete', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    $tag = BlogTag::factory()->create(['name' => 'running']);

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', 'invierno')->call('save')
        ->call('openEditModal', $tag->id)->set('name', 'trail running')->call('save')
        ->call('confirmDelete', $tag->id)->call('deleteTag')
        ->assertHasNoErrors();

    expect(BlogTag::query()->pluck('name')->all())->toBe(['invierno']);
});

test('an actor holding only blog.view sees every row action disabled -- one global-state test, not a per-row matrix', function () {
    BlogTag::factory()->count(3)->create();
    $this->actingAs(blogTagsIndexTestActor(['blog.view']));

    $rows = Livewire::test(Index::class)->get('tags');

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('canEdit')->unique()->all())->toBe([false])
        ->and(collect($rows)->pluck('canDelete')->unique()->all())->toBe([false]);
});

test('row hints follow the policy independently: blog.edit alone enables edit but not delete', function () {
    BlogTag::factory()->create();
    $this->actingAs(blogTagsIndexTestActor(['blog.view', 'blog.edit']));

    $row = Livewire::test(Index::class)->get('tags')[0];

    expect($row['canEdit'])->toBeTrue()->and($row['canDelete'])->toBeFalse();
});

// =====================================================================
// Listing
// =====================================================================

test('the list is ordered by name, whatever order the rows were created in', function () {
    foreach (['Zeta', 'alpha', 'Beta'] as $name) {
        BlogTag::factory()->create(['name' => $name]);
    }
    $this->actingAs(blogTagsIndexTestActor(['blog.view']));

    $names = collect(Livewire::test(Index::class)->get('tags'))->pluck('name')->all();

    expect($names)->toBe(['alpha', 'Beta', 'Zeta']);
});

test('each row exposes exactly {id, name, canEdit, canDelete} and no post or usage-count key', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor());

    $row = Livewire::test(Index::class)->get('tags')[0];

    expect(array_keys($row))->toBe(['id', 'name', 'canEdit', 'canDelete']);
});

test('the tag list and every id-carrying property are #[Locked], so a client cannot forge them', function () {
    $tag = BlogTag::factory()->create();
    $this->actingAs(blogTagsIndexTestActor());

    $component = Livewire::test(Index::class);

    foreach (['tags' => [], 'editingTagId' => $tag->id, 'deletingTagId' => $tag->id, 'deletingTagName' => 'x'] as $property => $value) {
        expect(fn () => $component->set($property, $value))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

// =====================================================================
// Create
// =====================================================================

test('creating a tag with a valid name persists exactly one row, lists it and closes the modal', function () {
    $this->actingAs(blogTagsIndexTestActor());

    $component = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'running')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertSet('name', '');

    expect(BlogTag::query()->pluck('name')->all())->toBe(['running'])
        ->and(collect($component->get('tags'))->pluck('name')->all())->toBe(['running']);
});

test('the create name is trimmed by the action, not persisted raw', function () {
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)->call('openCreateModal')->set('name', "  running \u{00A0}")->call('save')->assertHasNoErrors();

    expect(BlogTag::query()->sole()->name)->toBe('running');
});

test('opening the create form after an edit shows a blank field, never the previous tag', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->assertSet('name', 'running')
        ->call('closeModal')
        ->call('openCreateModal')
        ->assertSet('name', '')
        ->assertSet('editingTagId', null);
});

test('an unacceptable create name is refused on the name field and adds no row', function (string $invalid) {
    BlogTag::factory()->create(['name' => 'running']);
    BlogTag::factory()->create(['name' => 'Niño']);
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', $invalid)
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSet('showModal', true);

    expect(BlogTag::query()->count())->toBe(2);
})->with([
    'blank' => [''],
    'whitespace only' => ["   \t "],
    'exact duplicate' => ['running'],
    'case-only duplicate' => ['RUNNING'],
    'accent-only duplicate' => ['Nino'],
]);

test('the length boundary is read from the shared constant: max accepted, max + 1 refused', function () {
    $this->actingAs(blogTagsIndexTestActor());
    $max = BlogTag::NAME_MAX_LENGTH;

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', str_repeat('a', $max + 1))->call('save')
        ->assertHasErrors(['name'])
        ->set('name', str_repeat('a', $max))->call('save')
        ->assertHasNoErrors();

    expect(BlogTag::query()->count())->toBe(1);
});

test('save() validates through the injected actions, never the find-or-create action or a component-side rule', function () {
    // D-1: the component neither composes the validation trait nor calls $this->validate();
    // R-7: reaching for FindOrCreateBlogTag would turn every duplicate refusal into a success.
    $injected = collect((new ReflectionMethod(Index::class, 'save'))->getParameters())
        ->map(fn (ReflectionParameter $parameter): string => $parameter->getType()->getName())
        ->all();

    expect($injected)->toContain(CreateBlogTag::class)
        ->toContain(RenameBlogTag::class)
        ->not->toContain(FindOrCreateBlogTag::class)
        ->and(class_uses(Index::class))->not->toContain(BlogTagValidationRules::class)
        ->and(blogTagsIndexCodeWithoutComments())
        ->not->toContain('FindOrCreateBlogTag')
        ->not->toContain('->validate(');
});

test('a duplicate submitted through the screen never silently succeeds -- it is an error, not a find-or-create hit', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)->call('openCreateModal')->set('name', 'running')->call('save')->assertHasErrors(['name']);

    expect(BlogTag::query()->count())->toBe(1);
});

// =====================================================================
// Rename
// =====================================================================

test('renaming a tag to a free name updates the row and closes the modal', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor());

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->assertSet('name', 'running')
        ->assertSet('editingTagId', $tag->id)
        ->assertSet('showModal', true)
        ->set('name', 'trail running')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect($tag->fresh()->name)->toBe('trail running')
        ->and(collect($component->get('tags'))->pluck('name')->all())->toBe(['trail running']);
});

test('saving a tag under its own unchanged name is accepted', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)->call('openEditModal', $tag->id)->call('save')->assertHasNoErrors();

    expect($tag->fresh()->name)->toBe('running');
});

test('renaming a tag onto another tag\'s exact name is refused and the tag keeps its name', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $other = BlogTag::factory()->create(['name' => 'invierno']);
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $other->id)
        ->set('name', 'running')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSet('showModal', true);

    expect($other->fresh()->name)->toBe('invierno');
});

test('the id feeding the uniqueness exclusion is server-authoritative: a forged editingTagId throws instead of retargeting the rename', function () {
    // The single most important test in this file (0059's hand-off obligation, R-1): without
    // #[Locked], set('editingTagId', $b) between opening the modal and saving would turn the
    // uniqueness check into a rename-any-tag primitive.
    $this->withoutExceptionHandling();
    $a = BlogTag::factory()->create(['name' => 'alpha']);
    $b = BlogTag::factory()->create(['name' => 'beta']);
    $this->actingAs(blogTagsIndexTestActor());

    $component = Livewire::test(Index::class)->call('openEditModal', $a->id);

    expect(fn () => $component->set('editingTagId', $b->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $component->set('name', 'renamed')->call('save');

    expect($a->fresh()->name)->toBe('renamed')->and($b->fresh()->name)->toBe('beta');
});

test('saving an edit for a tag deleted in the meantime fails cleanly rather than recreating or silently succeeding', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->create();
    $this->actingAs(blogTagsIndexTestActor());

    $component = Livewire::test(Index::class)->call('openEditModal', $tag->id)->set('name', 'x');
    $tag->delete();

    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect(BlogTag::query()->count())->toBe(0);
});

test('closing the modal clears a stale name error so it cannot leak into the next attempt', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')->set('name', '')->call('save')->assertHasErrors(['name'])
        ->call('closeModal')->assertHasNoErrors()
        ->call('openEditModal', $tag->id)->assertHasNoErrors();
});

// =====================================================================
// Delete -- unconditional, by design (D-2)
// =====================================================================

test('deleting a tag removes the row unconditionally and it disappears from the reloaded list', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    BlogTag::factory()->create(['name' => 'invierno']);
    $this->actingAs(blogTagsIndexTestActor());

    $component = Livewire::test(Index::class)
        ->call('confirmDelete', $tag->id)
        ->assertSet('deletingTagId', $tag->id)
        ->assertSet('deletingTagName', 'running')
        ->assertSet('showDeleteModal', true)
        ->call('deleteTag');

    expect(BlogTag::query()->whereKey($tag->id)->exists())->toBeFalse()
        ->and(collect($component->get('tags'))->pluck('name')->all())->toBe(['invierno']);
});

test('deleteTag() closes the modal in one round trip, with no error and no branch that leaves it open', function () {
    $tag = BlogTag::factory()->create();
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)
        ->call('confirmDelete', $tag->id)
        ->call('deleteTag')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingTagId', null)
        ->assertSet('deletingTagName', '');
});

test('there is no force, confirm-and-proceed or reassign path -- the unconditional delete is the contract, not an oversight', function () {
    // Only what this component itself declares -- Livewire's base Component has public members of
    // its own (a forced re-render, say) that are not this screen's affair.
    $reflection = new ReflectionClass(Index::class);
    $publicMembers = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Index::class)
        ->map(fn (ReflectionMethod $method): string => strtolower($method->getName()))
        ->merge(collect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))
            ->filter(fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === Index::class)
            ->map(fn (ReflectionProperty $property): string => strtolower($property->getName())));

    expect($publicMembers->filter(fn (string $name): bool => preg_match('/force|reassign|replacement|blocked|usage|(?<!m)count/', $name) === 1))->toBeEmpty();

    expect((new ReflectionMethod(Index::class, 'deleteTag'))->getParameters()[0]->getType()->getName())
        ->toBe(DeleteBlogTag::class);
});

test('deleteTag() with no confirmation open does nothing', function () {
    BlogTag::factory()->create();
    $this->actingAs(blogTagsIndexTestActor());

    Livewire::test(Index::class)->call('deleteTag')->assertHasNoErrors();

    expect(BlogTag::query()->count())->toBe(1);
});

// =====================================================================
// Malformed / unknown ids
// =====================================================================

test('openEditModal() and confirmDelete() with an unknown or malformed id fail cleanly, not as a silent no-op', function (string $method, string $id) {
    $this->withoutExceptionHandling();
    $this->actingAs(blogTagsIndexTestActor());

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
// Refusal logging -- BlogTagPolicy's first component call site
// =====================================================================

/**
 * Drives one refused call and returns the context of the single 'Privileged action refused'
 * warning it wrote.
 *
 * @param  Closure(): void  $refuse  performs the call that is expected to be refused
 * @return array<string, mixed>
 */
function blogTagsRefusedContext(Closure $refuse): array
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

test('every refusal this component raises writes exactly one warning with target_type blog_tag, the actor, the ability and the target', function (string $ability, string $revoke, Closure $act) {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $actor = blogTagsIndexTestActor();
    $this->actingAs($actor);

    $context = blogTagsRefusedContext(function () use ($actor, $revoke, $act, $tag): void {
        $component = Livewire::test(Index::class);
        $act($component, $tag, 'arrange');
        blogTagsIndexRevoke($actor, $revoke);

        try {
            $act($component, $tag, 'refuse');
        } catch (AuthorizationException) {
            // expected -- the log line is what is under test
        }
    });

    expect($context['actor_id'])->toBe($actor->id)
        ->and($context['ability'])->toBe($ability)
        ->and($context['target_type'])->toBe('blog_tag')
        ->and(array_keys($context))->toEqualCanonicalizing(['actor_id', 'ability', 'target_type', 'target_id']);
})->with([
    'openCreateModal' => ['create', 'blog.create', fn ($c, $t, $phase) => $phase === 'refuse' ? $c->call('openCreateModal') : null],
    'openEditModal' => ['update', 'blog.edit', fn ($c, $t, $phase) => $phase === 'refuse' ? $c->call('openEditModal', $t->id) : null],
    'confirmDelete' => ['delete', 'blog.delete', fn ($c, $t, $phase) => $phase === 'refuse' ? $c->call('confirmDelete', $t->id) : null],
    'save (create)' => ['create', 'blog.create', fn ($c, $t, $phase) => $phase === 'arrange' ? $c->call('openCreateModal')->set('name', 'x') : $c->call('save')],
    'save (edit)' => ['update', 'blog.edit', fn ($c, $t, $phase) => $phase === 'arrange' ? $c->call('openEditModal', $t->id) : $c->call('save')],
    'deleteTag' => ['delete', 'blog.delete', fn ($c, $t, $phase) => $phase === 'arrange' ? $c->call('confirmDelete', $t->id) : $c->call('deleteTag')],
]);

test('the edit and delete refusals name the target tag id', function () {
    $tag = BlogTag::factory()->create();
    $actor = blogTagsIndexTestActor(['blog.view']);
    $this->actingAs($actor);

    $context = blogTagsRefusedContext(function () use ($tag): void {
        try {
            Livewire::test(Index::class)->call('openEditModal', $tag->id);
        } catch (AuthorizationException) {
            //
        }
    });

    expect($context['target_id'])->toBe($tag->id);
});

test('the refusal line has the same key set as the one Roles\\Index writes -- one shape across admin screens', function () {
    $blogActor = blogTagsIndexTestActor(['blog.view']);
    $this->actingAs($blogActor);
    $blogComponent = Livewire::test(Index::class);

    $rolesActor = User::factory()->create();
    $rolesActor->givePermissionTo('roles.manage');
    $this->actingAs($rolesActor);
    $rolesComponent = Livewire::test(RolesIndex::class);
    $rolesActor->revokePermissionTo('roles.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Both refusals in ONE spy session: Log::spy() is not reset between calls.
    Log::spy();
    $keySets = [];
    $capture = function (string $message, array $context) use (&$keySets): bool {
        // Keyed by actor: Mockery may evaluate this matcher more than once per recorded call.
        if ($message === 'Privileged action refused') {
            $keySets[$context['actor_id']] = array_keys($context);
        }

        return true;
    };

    $this->actingAs($blogActor);
    try {
        $blogComponent->call('openCreateModal');
    } catch (AuthorizationException) {
        //
    }

    $this->actingAs($rolesActor);
    try {
        $rolesComponent->call('openCreateModal');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')->withArgs($capture)->twice();

    expect($keySets)->toHaveCount(2)
        ->and($keySets[$blogActor->id])->toEqualCanonicalizing($keySets[$rolesActor->id]);
});
