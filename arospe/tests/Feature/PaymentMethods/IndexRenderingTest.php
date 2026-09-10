<?php

// Story 0039 — component-level rendering tests for the Payment methods settings screen
// (resources/views/livewire/payment-methods.blade.php). 0038's own ComponentContractTest.php
// already pins the public surface (`$paymentMethods`, `$showModal`, `$editingMethodId`, `$iban`,
// openEditModal()/save()/closeModal()) this file renders against -- these tests assert what the
// REAL VIEW does with that surface, which 0038's own tests never exercised (its shipped view was
// a one-line placeholder).

use App\Enums\PaymentMethodCode;
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

function paymentMethodsViewerActor(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('Administrator');

    return $actor;
}

test('bank transfer is the only listed method, and the markup exposes no create or delete affordance', function () {
    $method = PaymentMethod::factory()->create();
    $this->actingAs(paymentMethodsViewerActor());

    $html = Livewire::test(Index::class)
        ->assertSee(PaymentMethodCode::BankTransfer->label())
        ->assertSeeHtml('data-test="payment-method-card-'.$method->id.'"')
        ->assertDontSeeHtml('data-test="create-payment-method"')
        ->assertDontSeeHtml('data-test="delete-payment-method"')
        ->html();

    // Exactly one card renders -- not merely "at least one", per Phase 5 code review
    // finding N3: PaymentMethodSeeder/the schema's own layered "bank transfer is the only
    // method" guarantee is what the seeder enforces; this pins the SCREEN's own promise.
    expect(substr_count($html, 'data-test="payment-method-card-'))->toBe(1);
});

test('the not-configured indicator renders when the IBAN is null', function () {
    PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsViewerActor());

    Livewire::test(Index::class)
        ->assertSeeHtml('data-test="payment-method-not-configured"')
        ->assertDontSeeHtml('data-test="payment-method-configured-iban"');
});

test('the configured indicator and the IBAN render when the IBAN is set', function () {
    PaymentMethod::factory()->withIban('ES9121000418450200051332')->create();
    $this->actingAs(paymentMethodsViewerActor());

    Livewire::test(Index::class)
        ->assertSeeHtml('data-test="payment-method-configured-iban"')
        ->assertSee('ES91 2100 0418 4502 0005 1332')
        ->assertDontSeeHtml('data-test="payment-method-not-configured"');
});

test('openEditModal() leaves the form blank when nothing is configured', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsViewerActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->assertSet('iban', '')
        ->assertSeeHtml('data-test="payment-method-modal"');
});

test('openEditModal() prefills the form with the currently configured IBAN', function () {
    $method = PaymentMethod::factory()->withIban('ES9121000418450200051332')->create();
    $this->actingAs(paymentMethodsViewerActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->assertSet('iban', 'ES9121000418450200051332');
});

// Proves the flux:input is really bound to the `iban` error key -- 0038 ships no real markup,
// so this is genuinely new risk this story introduces. assertHasErrors() alone only proves the
// error was RAISED, not that the template RENDERS it (see docs/errors-log.md's "a passing
// assertHasErrors() proves nothing about whether the template renders it" entry) -- assertSee()
// against the real translated message is what closes that gap.
test('a rejected submission renders the inline message and leaves the modal open', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsViewerActor());

    Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'not-a-real-iban')
        ->call('save')
        ->assertHasErrors('iban')
        ->assertSee(__('payment-methods.iban.invalid'))
        ->assertSet('showModal', true)
        ->assertSeeHtml('data-test="payment-method-modal"');
});

// Phase 4 security audit finding F1: $showModal is a plain wire:model-bound property, so an
// actor holding payment-methods.view can force this branch open (e.g. a crafted request) while
// #[Locked] $editingMethodId is still at its default null, with no matching row in
// $paymentMethods. Before the fix, this threw ViewException ("Trying to access array offset on
// null") from $editingMethod['code']->label(). Regression net for the null-guard in
// resources/views/livewire/payment-methods.blade.php.
test('forcing showModal true with no editingMethodId renders without a fatal error', function () {
    PaymentMethod::factory()->create();
    $this->actingAs(paymentMethodsViewerActor());

    Livewire::test(Index::class)
        ->set('showModal', true)
        ->assertOk()
        ->assertSet('editingMethodId', null);
});

// Phase 4 security audit finding F2: closeModal() reset the `iban` error bag, but openEditModal()
// did not -- so dismissing the modal by writing $showModal directly (bypassing closeModal(), the
// X control or a click outside) and reopening it left a stale error from a previously refused
// save rendering with no context. Per docs/security/livewire-error-bag-persistence.md rule 3, a
// single-target test like this is the only kind that can actually fail against this bug --
// re-editing the SAME method after a rejection would pass even without the fix, since the
// property being re-set masks the stale error either way.
test('reopening the modal after a rejected save clears the stale iban error', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);
    $this->actingAs(paymentMethodsViewerActor());

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $method->id)
        ->set('iban', 'not-a-real-iban')
        ->call('save')
        ->assertHasErrors('iban');

    $component->call('openEditModal', $method->id)
        ->assertHasNoErrors('iban');
});

// The needle is the literal HTML boolean attribute `disabled="disabled"`, NEVER the bare
// substring 'disabled' -- Flux's own static Tailwind classes (`[&[disabled]]:opacity-50`)
// contain that substring on every button regardless of actual state (see
// tests/Feature/Shipping/RateAuthorizationTest.php's identical, already-proven-by-execution
// note). Scope both assertions to the button's own rendered fragment, never the whole page.
test('an actor holding view but not edit sees the action disabled; one holding both sees it enabled', function () {
    PaymentMethod::factory()->create();

    $fragmentAround = function (string $html, string $needle): string {
        $position = strpos($html, $needle);
        expect($position)->not->toBeFalse("Expected to find [$needle] in the rendered HTML.");

        return substr($html, max(0, $position - 400), 800);
    };

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('payment-methods.view');
    $this->actingAs($viewer);
    $viewerHtml = Livewire::test(Index::class)->html();

    expect($fragmentAround($viewerHtml, 'data-test="configure-bank-transfer"'))
        ->toContain('disabled="disabled"')
        ->toContain(__('payment-methods.index.action_not_allowed'));

    $editor = paymentMethodsViewerActor();
    $this->actingAs($editor);
    $editorHtml = Livewire::test(Index::class)->html();

    expect($fragmentAround($editorHtml, 'data-test="configure-bank-transfer"'))->not->toContain('disabled="disabled"');
});
