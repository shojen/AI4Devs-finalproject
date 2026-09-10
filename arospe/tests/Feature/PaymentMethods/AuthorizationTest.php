<?php

// Story 0038 — authorization layers for the Payment Methods screen: the
// `payment-methods.index` route's `can:` middleware, and
// App\Livewire\PaymentMethods\Index::save()'s own re-check. Both layers are
// exercised: an HTTP test and a Livewire::test() test are not substitutes
// for each other, per docs/testing/README.md.

use App\Livewire\PaymentMethods\Index;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// $this->get(route('payment-methods.index')) — HTTP layer
// =====================================================================

test('guests are redirected to the login page when visiting the payment methods screen', function () {
    $this->get(route('payment-methods.index'))->assertRedirect(route('login'));
});

test('a signed-in user without payment-methods.view is forbidden from the payment methods screen', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $this->get(route('payment-methods.index'))->assertForbidden();
});

test('a user holding payment-methods.view can reach the payment methods screen', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('payment-methods.view');
    $this->actingAs($actor);

    $this->get(route('payment-methods.index'))->assertOk();
});

// =====================================================================
// Livewire::test(Index::class)::save() — authorization layer
// =====================================================================

test('an Administrator can set the IBAN', function () {
    $method = PaymentMethod::factory()->create();

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'ES9121000418450200051332')
        ->call('save')
        ->assertHasNoErrors();

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

// Phase 5 code review finding N1: every other test that puts an unauthorized actor in front of
// save() is stopped earlier, by mount()'s viewAny or openEditModal()'s own update check -- since
// $editingMethodId is #[Locked], no test could otherwise reach save() without a successful
// openEditModal() first. This is the one case that exercises save()'s OWN gate, revoking the
// permission between opening the modal and submitting it -- the same "a revoke takes effect on
// the next request" scenario ModuleRouteAccessTest.php already pins for routes.
test('revoking payment-methods.edit between opening the modal and submitting refuses save() specifically', function () {
    $method = PaymentMethod::factory()->create(['iban' => 'DE89370400440532013000']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['payment-methods.view', 'payment-methods.edit']);
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openEditModal', $method->id);

    $actor->revokePermissionTo('payment-methods.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // A try/catch, not expect(fn () => ...)->toThrow() -- a SECOND ->call() on an
    // already-mounted component goes through Livewire's own request-simulation pipeline, which
    // catches AuthorizationException and renders it as a response rather than re-throwing it to
    // the caller (unlike the FIRST Livewire::test()->call(...) in one chain, where mount()/the
    // call run synchronously in the test's own call stack). Matches
    // tests/Feature/SalesRegions/RefusalLoggingTest.php's identical "second call" pattern.
    try {
        $component->set('iban', 'ES9121000418450200051332')->call('save');
    } catch (AuthorizationException) {
        //
    }

    expect($method->fresh()->iban)->toBe('DE89370400440532013000');
});

test('a user with no role is refused and the IBAN is unchanged', function () {
    $this->withoutExceptionHandling();

    $method = PaymentMethod::factory()->create(['iban' => 'DE89370400440532013000']);

    $actor = User::factory()->create();
    $this->actingAs($actor);

    // No payment-methods.view at all, so this actor is refused at mount() --
    // before openEditModal()/save() can ever be reached.
    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
    expect($method->fresh()->iban)->toBe('DE89370400440532013000');
});

// This is the case that catches a policy checking the wrong permission string -- a very
// plausible copy-paste slip given this component scaffolds from other module screens. Precedent:
// SalesRegions/IndexTest.php's "a blog editor whose role does not grant users.view is denied
// server-side".
test('an actor holding every other module edit permission but not payment-methods.edit is refused', function () {
    $this->withoutExceptionHandling();

    $method = PaymentMethod::factory()->create(['iban' => 'DE89370400440532013000']);

    $actor = User::factory()->create();
    $actor->givePermissionTo([
        'payment-methods.view',
        'users.edit',
        'products.edit',
        'sales-regions.edit',
        'shipping.edit',
        'customers.edit',
        'orders.edit',
        'blog.edit',
        'store-languages.edit',
        'media.edit',
    ]);
    $this->actingAs($actor);

    $caught = null;

    try {
        Livewire::test(Index::class)
            ->call('openEditModal', $method->id)
            ->set('iban', 'ES9121000418450200051332')
            ->call('save');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    expect($method->fresh()->iban)->toBe('DE89370400440532013000');
});

test('a Super Admin holding zero permission rows can set the IBAN, via the Gate::before bypass', function () {
    $method = PaymentMethod::factory()->create();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'ES9121000418450200051332')
        ->call('save')
        ->assertHasNoErrors();

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

// =====================================================================
// PaymentMethodPolicy — create()/delete() are refused categorically
// =====================================================================

test('create and delete are refused for an Administrator despite holding both permissions', function () {
    $method = PaymentMethod::factory()->create();

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');

    expect($actor->hasPermissionTo('payment-methods.create'))->toBeTrue()
        ->and($actor->hasPermissionTo('payment-methods.delete'))->toBeTrue();

    expect(Gate::forUser($actor)->denies('create', PaymentMethod::class))->toBeTrue()
        ->and(Gate::forUser($actor)->denies('delete', $method))->toBeTrue();
});

// Phase 4 security audit finding F-4: Gate::before's Super Admin bypass short-circuits BEFORE
// PaymentMethodPolicy::create()/delete() ever run, for any target other than the Super Admin
// Role row -- so a Super Admin actor genuinely PASSES both, the identical documented behaviour
// RolePolicy::delete()'s Administrator refusal already has. The "only one method" invariant
// still holds for a Super Admin, through the other two layers (no create/delete code path
// exists anywhere; `code` is not mass-assignable) -- never through this policy alone.
test('create and delete are allowed for a Super Admin, via the Gate::before bypass -- documented, not a gap', function () {
    $method = PaymentMethod::factory()->create();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect(Gate::forUser($superAdmin)->allows('create', PaymentMethod::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $method))->toBeTrue();
});
