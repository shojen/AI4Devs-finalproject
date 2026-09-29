<?php

// Story 0038 — the public surface App\Livewire\PaymentMethods\Index exposes, which the paired
// frontend story (0039) renders against. These are cheap and they are what stop the frontend
// story being blocked by a silently-changed contract.

use App\Enums\PaymentMethodCode;
use App\Livewire\PaymentMethods\Index;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('mount() populates $paymentMethods with one row matching the seeded record, canEdit true for an authorized actor', function () {
    $method = PaymentMethod::factory()->create(['iban' => 'ES9121000418450200051332']);

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    expect($component->get('paymentMethods'))->toHaveCount(1);

    $row = $component->get('paymentMethods')[0];

    expect($row['id'])->toBe($method->id)
        ->and($row['code'])->toBe(PaymentMethodCode::BankTransfer)
        ->and($row['iban'])->toBe('ES9121000418450200051332')
        ->and($row['canEdit'])->toBeTrue();
});

test('canEdit is false for an actor holding view but not edit, and that same actor is refused on save', function () {
    $this->withoutExceptionHandling();

    $method = PaymentMethod::factory()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo('payment-methods.view');
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    expect($component->get('paymentMethods')[0]['canEdit'])->toBeFalse();

    expect(fn () => $component->call('openEditModal', $method->id))
        ->toThrow(AuthorizationException::class);
});

test('openEditModal() sets showModal, editingMethodId and iban from the resolved model', function () {
    $method = PaymentMethod::factory()->create(['iban' => 'ES9121000418450200051332']);

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->assertSet('showModal', true)
        ->assertSet('editingMethodId', $method->id)
        ->assertSet('iban', 'ES9121000418450200051332');
});

// The regression guard for the wire:model desync bug -- a '?? \'\'' dropped in a refactor is
// invisible to every other test in this file.
test('openEditModal() sets iban to an empty string, never null, when the method has no IBAN configured', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->assertSet('iban', '');
});

// $paymentMethods is #[Locked] (Phase 4 security audit finding F-5), so tampering with it is not
// merely ignored -- it is architecturally impossible. This is a STRONGER guarantee than "reads
// from the database" alone, so this test asserts the tamper attempt itself throws, rather than
// (as an unlocked-array design would require) setting a tampered value and then proving
// openEditModal() ignores it.
test('$paymentMethods cannot be tampered with -- Locked raises on any client attempt to set it', function () {
    $method = PaymentMethod::factory()->create(['iban' => 'ES9121000418450200051332']);

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    $tampered = $component->get('paymentMethods');
    $tampered[0]['iban'] = 'DE00000000000000000000';

    expect(fn () => $component->set('paymentMethods', $tampered))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // The real row is untouched, and openEditModal() still reads the genuine stored value.
    $component->call('openEditModal', $method->id)
        ->assertSet('iban', 'ES9121000418450200051332');
});

test('openEditModal() with an unknown id raises ModelNotFoundException', function () {
    $this->withoutExceptionHandling();

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Index::class)->call('openEditModal', (string) Str::uuid7()))
        ->toThrow(ModelNotFoundException::class);
});

test('closeModal() clears editingMethodId, iban and showModal', function () {
    $method = PaymentMethod::factory()->create(['iban' => 'ES9121000418450200051332']);

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->call('closeModal')
        ->assertSet('showModal', false)
        ->assertSet('editingMethodId', null)
        ->assertSet('iban', '');
});

test('a successful save() refreshes $paymentMethods so the new IBAN is visible without a reload', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'ES9121000418450200051332')
        ->call('save');

    $row = $component->get('paymentMethods')[0];

    expect($row['iban'])->toBe('ES9121000418450200051332');
});

test('editingMethodId is Locked -- a client attempt to set it directly raises', function () {
    $method = PaymentMethod::factory()->create();

    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openEditModal', $method->id);

    expect(fn () => $component->set('editingMethodId', (string) Str::uuid7()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
