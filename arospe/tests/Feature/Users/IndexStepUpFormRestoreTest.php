<?php

// Story 0097 -- an interrupted create/edit (the step-up guard redirected the actor to confirm their
// password) is not lost: save() stashes the four non-secret form fields in the session, and the
// next mount of the Users screen reopens the modal prefilled, exactly once, for the same actor.
// Nothing is written automatically after the confirmation -- the actor presses Save again.

use App\Enums\UserStatus;
use App\Livewire\Users\Index;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingAsAdministratorWithoutRecentConfirmation(TestCase $case): User
{
    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $case->actingAs($actor);

    return $actor;
}

test('an Administrator-tier creation interrupted by the step-up is restored after the confirmation, and saving then creates it', function () {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $this->actingAs($actor);
    $administratorRole = Role::findByName('Administrator');

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Ana')
        ->set('email', 'ana@example.test')
        ->set('roleId', (string) $administratorRole->id)
        ->set('status', UserStatus::Active->value)
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    expect(User::where('email', 'ana@example.test')->exists())->toBeFalse();

    session(['auth.password_confirmed_at' => now()->unix()]);

    Livewire::test(Index::class)
        ->assertSet('showModal', true)
        ->assertSet('editingUserId', null)
        ->assertSet('name', 'Ana')
        ->assertSet('email', 'ana@example.test')
        ->assertSet('roleId', (string) $administratorRole->id)
        ->assertSet('status', UserStatus::Active->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(User::where('email', 'ana@example.test')->exists())->toBeTrue();
});

test('an edit interrupted by the step-up is restored with the typed values, bound to the same target', function () {
    $actor = actingAsAdministratorWithoutRecentConfirmation($this);
    $target = User::factory()->create(['name' => 'Before', 'status' => UserStatus::Active]);
    $editor = Role::create(['name' => 'Editor', 'guard_name' => 'web']);

    Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->set('name', 'After')
        ->set('roleId', (string) $editor->id)
        ->set('status', UserStatus::Suspended->value)
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    session(['auth.password_confirmed_at' => now()->unix()]);

    Livewire::test(Index::class)
        ->assertSet('showModal', true)
        ->assertSet('editingUserId', $target->id)
        ->assertSet('name', 'After')
        ->assertSet('roleId', (string) $editor->id)
        ->assertSet('status', UserStatus::Suspended->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($target->fresh()->hasRole('Editor'))->toBeTrue();
});

test('the stashed form is used exactly once', function () {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Ana')
        ->set('email', 'ana@example.test')
        ->set('roleId', (string) Role::findByName('Administrator')->id)
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    Livewire::test(Index::class)->assertSet('showModal', true);

    Livewire::test(Index::class)
        ->assertSet('showModal', false)
        ->assertSet('name', '')
        ->assertSet('email', '');
});

test('the stash carries only the four non-secret form fields and the owning actor', function () {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Ana')
        ->set('email', 'ana@example.test')
        ->set('roleId', (string) Role::findByName('Administrator')->id)
        ->call('save');

    $stash = session('users.pending_form');

    expect(array_keys($stash))->toEqualCanonicalizing(['actor_id', 'target_id', 'name', 'email', 'roleId', 'status', 'stored_at']);
});

test('a stash left by another actor is discarded and never restored', function () {
    $first = User::factory()->create();
    $first->assignRole('Super Admin');
    $this->actingAs($first);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Ana')
        ->set('email', 'ana@example.test')
        ->set('roleId', (string) Role::findByName('Administrator')->id)
        ->call('save');

    $second = User::factory()->create();
    $second->assignRole('Administrator');
    $this->actingAs($second);

    Livewire::test(Index::class)
        ->assertSet('showModal', false)
        ->assertSet('email', '');

    expect(session()->has('users.pending_form'))->toBeFalse();
});

test('a stash older than the allowed lifetime is discarded', function () {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Ana')
        ->set('email', 'ana@example.test')
        ->set('roleId', (string) Role::findByName('Administrator')->id)
        ->call('save');

    Carbon::setTestNow(now()->addMinutes(16));

    Livewire::test(Index::class)->assertSet('showModal', false);

    Carbon::setTestNow();
});

test('an edit stash whose target no longer exists is discarded without error', function () {
    $actor = actingAsAdministratorWithoutRecentConfirmation($this);
    $target = User::factory()->create(['status' => UserStatus::Active]);
    $target->assignRole(Role::create(['name' => 'Editor', 'guard_name' => 'web']));

    Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->set('status', UserStatus::Suspended->value)
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    $target->forceDelete();

    Livewire::test(Index::class)->assertSet('showModal', false);
});

test('an ordinary-role creation never stashes a form', function () {
    $actor = actingAsAdministratorWithoutRecentConfirmation($this);
    $editor = Role::create(['name' => 'Editor', 'guard_name' => 'web']);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Bea')
        ->set('email', 'bea@example.test')
        ->set('roleId', (string) $editor->id)
        ->set('status', UserStatus::Active->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(session()->has('users.pending_form'))->toBeFalse()
        ->and(User::where('email', 'bea@example.test')->exists())->toBeTrue();
});
