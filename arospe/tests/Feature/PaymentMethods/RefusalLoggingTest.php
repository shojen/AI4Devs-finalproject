<?php

// Story 0038, Phase 5 code review finding N2 — Phase 4 security audit finding F-3 (refusal
// logging via App\Actions\Auth\LogRefusedPrivilegedAttempt on openEditModal()/save(), plus a
// Log::info success line on a permitted save()) shipped with zero assertions. Modelled on
// tests/Feature/SalesRegions/RefusalLoggingTest.php's own shape -- the "third admin screen"
// recipe every other module on this page follows.

use App\Livewire\PaymentMethods\Index;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function paymentMethodsRefusalTestActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['payment-methods.view', 'payment-methods.edit']);

    return $actor;
}

// =====================================================================
// openEditModal()
// =====================================================================

test('openEditModal() authorization refusal is logged with the actor, ability and target', function () {
    Log::spy();

    $method = PaymentMethod::factory()->create();

    $actor = paymentMethodsRefusalTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    $actor->revokePermissionTo('payment-methods.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        $component->call('openEditModal', $method->id);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'payment_method'
            && ($context['target_id'] ?? null) === $method->id)
        ->once();
});

// =====================================================================
// save()
// =====================================================================

test('save() authorization refusal is logged', function () {
    Log::spy();

    $method = PaymentMethod::factory()->create();

    $actor = paymentMethodsRefusalTestActor();
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)->call('openEditModal', $method->id);

    $actor->revokePermissionTo('payment-methods.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        $component->set('iban', 'ES9121000418450200051332')->call('save');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'payment_method'
            && ($context['target_id'] ?? null) === $method->id)
        ->once();
});

// =====================================================================
// Must-not-over-log, and the IBAN value itself is never logged (the action's own docblock claim)
// =====================================================================

test('a permitted save produces no refusal entry, only the success line, and never logs the IBAN itself', function () {
    Log::spy();

    $method = PaymentMethod::factory()->create();

    $actor = paymentMethodsRefusalTestActor();
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'ES9121000418450200051332')
        ->call('save')
        ->assertHasNoErrors();

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($actor, $method): bool {
            expect(json_encode($context))->not->toContain('ES9121000418450200051332');

            return $message === 'Payment method IBAN updated'
                && ($context['actor_id'] ?? null) === $actor->id
                && ($context['payment_method_id'] ?? null) === $method->id
                && ($context['code'] ?? null) === 'bank_transfer';
        })
        ->once();

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});
