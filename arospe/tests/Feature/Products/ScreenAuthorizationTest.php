<?php

// Discharges 0026 D-8's zero-call-site hand-off and covers App\Livewire\Products\Index's and
// App\Livewire\Products\Editor's own component-level gates, per
// ai-spec/tasks/in-progress/0027-products-list-and-editor-ui.md's "Tests to perform" section.
// Named ScreenAuthorizationTest.php (not AuthorizationTest.php) per the story's own 2026-09-03
// correction -- tests/Feature/Products/ProductAuthorizationTest.php already covers 0024's
// action-level gates and the SyncProductGallery reachability assertion; this file is the
// COMPONENT layer, defence in depth on top of those actions' own gates (ProductPolicy is not a
// zero-call-site policy since 0024's split -- see the story's own 2026-09-01 correction).
//
// Written at TDD Phase 3 step 1 (red), before App\Livewire\Products\Index or
// App\Livewire\Products\Editor exist.

use App\Livewire\Products\Editor;
use App\Livewire\Products\Index;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function screenAuthActor(array $permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

// =====================================================================
// Index -- allow/deny pairs
// =====================================================================

test('Index::mount() is refused for an actor lacking products.view', function () {
    $this->withoutExceptionHandling();
    $actor = User::factory()->create();
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

test('Index::mount() succeeds for an actor holding products.view', function () {
    $actor = screenAuthActor(['products.view']);
    $this->actingAs($actor);

    Livewire::test(Index::class)->assertOk();
});

test('Index::confirmDelete() is refused for an actor lacking products.delete', function () {
    // F-3 (appsec audit): confirmDelete() is now gated too, matching
    // App\Livewire\ProductCategories\Index::confirmDelete()'s precedent -- opening the modal
    // discloses the target row to an actor who may not be authorized to delete it.
    $this->withoutExceptionHandling();
    $creator = screenAuthActor(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($creator);
    $product = Product::factory()->create();

    $deniedActor = screenAuthActor(['products.view']);
    $this->actingAs($deniedActor);

    expect(fn () => Livewire::test(Index::class)->call('confirmDelete', $product->id))
        ->toThrow(AuthorizationException::class);

    expect(Product::find($product->id))->not->toBeNull();
});

test('Index::deleteProduct() is refused for an actor lacking products.delete', function () {
    // deleteProduct() re-authorizes independently of confirmDelete()'s own check -- defence in
    // depth for a permission revoked mid-session, between confirming and deleting.
    $this->withoutExceptionHandling();
    $actor = screenAuthActor(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
    $product = Product::factory()->create();

    $component = Livewire::test(Index::class)->call('confirmDelete', $product->id);

    $actor->revokePermissionTo('products.delete');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $component->call('deleteProduct'))->toThrow(AuthorizationException::class);

    expect(Product::find($product->id))->not->toBeNull();
});

test('Index::deleteProduct() succeeds for an actor holding products.delete', function () {
    $actor = screenAuthActor(['products.view', 'products.delete']);
    $this->actingAs($actor);

    $product = Product::factory()->create();

    Livewire::test(Index::class)
        ->call('confirmDelete', $product->id)
        ->call('deleteProduct');

    expect(Product::find($product->id))->toBeNull();
});

// =====================================================================
// Editor -- allow/deny pairs
// =====================================================================

test('Editor::mount() for a new product is refused for an actor lacking products.create', function () {
    $this->withoutExceptionHandling();
    $actor = screenAuthActor(['products.view']);
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Editor::class))->toThrow(AuthorizationException::class);
});

test('Editor::save() updating an existing product is refused for an actor lacking products.edit', function () {
    $this->withoutExceptionHandling();
    $creator = screenAuthActor(['products.view', 'products.create', 'products.edit']);
    $this->actingAs($creator);
    $product = Product::factory()->named('Original Name')->create();

    $deniedActor = screenAuthActor(['products.view']);
    $this->actingAs($deniedActor);

    expect(fn () => Livewire::test(Editor::class, ['product' => $product]))
        ->toThrow(AuthorizationException::class);

    expect($product->fresh()->translated('name'))->toBe('Original Name')
        ->and(DB::table('product_translations')->count())->toBe(1);
});

test('Editor::setFeaturedImage() is refused for an actor holding every products permission but lacking media.view', function () {
    // F-6 (code-review re-audit, story 0027): setFeaturedImage() is a page-globally-registered,
    // client-dispatchable #[On] listener with no route-level backstop of its own -- Livewire's
    // PersistentMiddleware replays only the host page's can:products.view, which says nothing
    // about media.*. Gated on Gate::authorize('viewAny', Media::class) via
    // LogRefusedPrivilegedAttempt, matching App\Livewire\Components\WysiwygEditor::insertImage()'s
    // own routeless-component precedent (tests/Feature/Components/WysiwygEditorTest.php).
    $this->withoutExceptionHandling();
    $actor = screenAuthActor(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
    $product = Product::factory()->create();

    $component = Livewire::test(Editor::class, ['product' => $product]);

    expect(fn () => $component->call('setFeaturedImage', [['id' => 'irrelevant']]))
        ->toThrow(AuthorizationException::class);
});

test('Editor::addGalleryImages() is refused for an actor holding every products permission but lacking media.view', function () {
    // Same reasoning as setFeaturedImage() above -- addGalleryImages() is the strip picker's own
    // #[On] listener and carries the identical Gate::authorize('viewAny', Media::class) gate.
    $this->withoutExceptionHandling();
    $actor = screenAuthActor(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
    $product = Product::factory()->create();

    $component = Livewire::test(Editor::class, ['product' => $product]);

    expect(fn () => $component->call('addGalleryImages', [['id' => 'irrelevant']]))
        ->toThrow(AuthorizationException::class);
});

// =====================================================================
// Super Admin bypass
// =====================================================================

test('a Super Admin actor holding zero permission rows passes every gated Products screen method', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Livewire::test(Index::class)->assertOk();

    $product = Product::factory()->create();

    Livewire::test(Editor::class, ['product' => $product])->assertOk();

    Livewire::test(Index::class)
        ->call('confirmDelete', $product->id)
        ->call('deleteProduct');

    expect(Product::find($product->id))->toBeNull();
});

// =====================================================================
// Per-row canEdit / canDelete hints -- must come from the SAME policy methods the click authorizes
// against, so the disabled state cannot drift (architecture.md's UI-hint rule).
// =====================================================================

test('an actor holding only products.view sees every row action disabled', function () {
    $creator = screenAuthActor(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($creator);
    Product::factory()->count(2)->create();

    $viewer = screenAuthActor(['products.view']);
    $this->actingAs($viewer);

    $rows = collect(Livewire::test(Index::class)->get('products')->items());

    expect($rows)->toHaveCount(2);
    $rows->each(function ($row) {
        $row = (array) $row;
        expect($row['canEdit'] ?? null)->toBeFalse();
        expect($row['canDelete'] ?? null)->toBeFalse();
    });
});

test('an actor holding the full products permission set sees every row action enabled', function () {
    $actor = screenAuthActor(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
    Product::factory()->count(2)->create();

    $rows = collect(Livewire::test(Index::class)->get('products')->items());

    expect($rows)->toHaveCount(2);
    $rows->each(function ($row) {
        $row = (array) $row;
        expect($row['canEdit'] ?? null)->toBeTrue();
        expect($row['canDelete'] ?? null)->toBeTrue();
    });
});

// =====================================================================
// Story 0077 -- Editor::prefillSlug() and Editor::setActiveLanguageTab()
// =====================================================================

test('Editor::prefillSlug() pre-fills the slug for an actor holding products.edit on an edit', function () {
    $actor = screenAuthActor(['products.view', 'products.edit']);
    $this->actingAs($actor);
    $default = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();
    ProductTranslation::factory()->forLanguage($default)->create(['product_id' => $product->id, 'name' => 'Runner Pro']);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$default->id, 'Runner Pro Max')
        ->call('prefillSlug', $default->id)
        ->assertSet('slugs.'.$default->id, 'runner-pro-max');
});

test('Editor::prefillSlug() pre-fills the slug for an actor holding products.create on a create', function () {
    $actor = screenAuthActor(['products.view', 'products.create']);
    $this->actingAs($actor);
    $default = StoreLanguage::factory()->default()->create();

    Livewire::test(Editor::class)
        ->set('names.'.$default->id, 'Runner Pro')
        ->call('prefillSlug', $default->id)
        ->assertSet('slugs.'.$default->id, 'runner-pro');
});

test('Editor::prefillSlug() is refused, logged and writes nothing once the permission is revoked after mount', function () {
    $this->withoutExceptionHandling();
    Log::spy();
    $actor = screenAuthActor(['products.view', 'products.edit']);
    $this->actingAs($actor);
    $default = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();
    ProductTranslation::factory()->forLanguage($default)->create(['product_id' => $product->id, 'name' => 'Runner Pro']);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$default->id, 'Runner Pro Max');

    $actor->revokePermissionTo('products.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $component->call('prefillSlug', $default->id))->toThrow(AuthorizationException::class);

    $component->assertSet('slugs.'.$default->id, '');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id)
        ->once();
});

test('Editor::setActiveLanguageTab() switches to an active language for an actor who passed mount', function () {
    $actor = screenAuthActor(['products.view', 'products.edit']);
    $this->actingAs($actor);
    StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();
    $product = Product::factory()->create();

    Livewire::test(Editor::class, ['product' => $product])
        ->call('setActiveLanguageTab', $french->id)
        ->assertSet('activeLanguageId', $french->id);
});

test('Editor::setActiveLanguageTab() has no permission check by design: an inactive id is refused as not found and nothing is written', function () {
    $this->withoutExceptionHandling();
    $actor = screenAuthActor(['products.view', 'products.edit']);
    $this->actingAs($actor);
    $default = StoreLanguage::factory()->default()->create();
    $retired = StoreLanguage::factory()->inactive()->create();
    $product = Product::factory()->create();

    $component = Livewire::test(Editor::class, ['product' => $product]);
    $before = DB::table('product_translations')->count();

    expect(fn () => $component->call('setActiveLanguageTab', $retired->id))->toThrow(ModelNotFoundException::class);

    $component->assertSet('activeLanguageId', $default->id);
    expect(DB::table('product_translations')->count())->toBe($before);
});

test('a refused Editor mount leaves product_translations unchanged', function () {
    $this->withoutExceptionHandling();
    $creator = screenAuthActor(['products.view', 'products.create', 'products.edit']);
    $this->actingAs($creator);
    $product = Product::factory()->named('Original Name')->create();
    $before = DB::table('product_translations')->orderBy('id')->get()->all();

    $this->actingAs(screenAuthActor(['products.view']));

    expect(fn () => Livewire::test(Editor::class, ['product' => $product]))->toThrow(AuthorizationException::class);

    expect(DB::table('product_translations')->orderBy('id')->get()->all())->toEqual($before);
});
