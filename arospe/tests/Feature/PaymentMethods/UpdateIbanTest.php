<?php

// Story 0038 — App\Livewire\PaymentMethods\Index::save() driving
// App\Actions\PaymentMethods\UpdatePaymentMethodIban, exercised through Livewire::test().

use App\Livewire\PaymentMethods\Index;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function paymentMethodsEditorActor(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('Administrator');

    return $actor;
}

test('an authorised actor sets a valid IBAN, and it is persisted', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsEditorActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'ES9121000418450200051332')
        ->call('save')
        ->assertHasNoErrors();

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

// "First save only" is a plausible incomplete implementation that a first-set-only test cannot
// catch -- this is the edit case, not just the first configuration.
test('an already-configured IBAN is replaced by a different valid one', function () {
    $method = PaymentMethod::factory()->create(['iban' => 'DE89370400440532013000']);
    $this->actingAs(paymentMethodsEditorActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'GB29NWBK60161331926819')
        ->call('save')
        ->assertHasNoErrors();

    expect($method->fresh()->iban)->toBe('GB29NWBK60161331926819');
});

// Assert what was STORED, not merely that the save succeeded -- an implementation that strips
// spaces only for the checksum and persists the raw spaced string passes a success-only
// assertion while corrupting the column.
test('a space-grouped IBAN is accepted and stored normalised, without spaces', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsEditorActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'ES91 2100 0418 4502 0005 1332')
        ->call('save')
        ->assertHasNoErrors();

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

test('a lowercase IBAN is accepted and stored uppercased', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsEditorActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'es9121000418450200051332')
        ->call('save')
        ->assertHasNoErrors();

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

// Both halves are mandatory: asserting only that an exception/error was raised passes against an
// implementation that writes first and validates second. Uses invalid_ibans_after_normalisation
// (not the full invalid_ibans set), which deliberately excludes the two case-only variants --
// save() normalises to uppercase BEFORE validating, so those become the valid ES example and are
// correctly accepted, covered separately by the lowercase-input scenario above.
test('an invalid IBAN is rejected on the iban field, and the previously configured IBAN survives', function (string $invalidIban) {
    $method = PaymentMethod::factory()->create(['iban' => 'DE89370400440532013000']);
    $this->actingAs(paymentMethodsEditorActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', $invalidIban)
        ->call('save')
        ->assertHasErrors(['iban']);

    expect($method->fresh()->iban)->toBe('DE89370400440532013000');
})->with('invalid_ibans_after_normalisation');
