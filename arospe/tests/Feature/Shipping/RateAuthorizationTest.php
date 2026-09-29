<?php

// Authorization tests for the rate-CRUD half of App\Livewire\Shipping\Index, per
// ai-spec/tasks/in-progress/0037-shipping-carriers-and-rates-ui.md's "Tests to perform" section.
// The GET route('shipping.index') HTTP-layer refusal is already owned by
// tests/Feature/Shipping/CarrierAuthorizationTest.php (0035) -- this file asserts only what this
// story adds: saveRate()/deleteRate() component-layer refusals, the disabled-control rendering
// for a view-only actor, and the Super Admin bypass proving ShippingRatePolicy is actually wired
// in, not only that the policy itself passes a direct Gate::forUser() check.

use App\Livewire\Shipping\Index;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function rateAuthViewOnlyActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('shipping.view');

    return $actor;
}

test('a shipping.view-only user gets 200 and sees the rate table with every control rendered disabled', function () {
    $actor = rateAuthViewOnlyActor();
    $this->actingAs($actor);

    $carrier = ShippingCarrier::factory()->create();
    ShippingRate::factory()->create(['shipping_carrier_id' => $carrier->id]);

    $this->get(route('shipping.index'))->assertOk();

    $component = Livewire::test(Index::class);

    expect($component->get('canEditShipping'))->toBeFalse()
        ->and($component->get('canCreateRate'))->toBeFalse()
        ->and($component->get('canDeleteRate'))->toBeFalse();
});

test('openCreateRateModal is refused without shipping.create', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(rateAuthViewOnlyActor());

    expect(fn () => Livewire::test(Index::class)->call('openCreateRateModal'))
        ->toThrow(AuthorizationException::class);
});

test('saveRate create branch is refused without shipping.create, and no rate is created', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(rateAuthViewOnlyActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $component = Livewire::test(Index::class)
        ->set('rateName', 'Estándar')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '4.95')
        ->set('deliveryEstimate', '24-48h');

    // editingRateId is null (a create), so saveRate() must ask 'create' and refuse it -- called
    // directly here, without ever going through openCreateRateModal(), to pin saveRate()'s OWN
    // gate independently of the opener's.
    expect(fn () => $component->call('saveRate'))->toThrow(AuthorizationException::class);
    expect(ShippingRate::where('name', 'Estándar')->exists())->toBeFalse();
});

test('saveRate create branch (no editingRateId set) is refused for a view-only actor without ever loading an edit target', function () {
    // Renamed from a misleading title (Phase 5 code-review finding F-4/F-4-adjacent): a bare
    // Livewire::test(Index::class)->call('saveRate') with no editingRateId set always takes
    // the CREATE branch, never the edit one -- this test pins exactly that (defence in depth,
    // since it's a real refusal either way), and the genuine edit-branch mid-session
    // revocation is pinned by the next test.
    $this->withoutExceptionHandling();
    $actor = rateAuthViewOnlyActor();
    $this->actingAs($actor);

    $rate = ShippingRate::factory()->pricedAt('4.95')->create();

    expect(fn () => Livewire::test(Index::class)->call('saveRate'))
        ->toThrow(AuthorizationException::class);

    // openEditRateModal() itself is also gated -- calling it directly must refuse too, before
    // saveRate() is ever reached with the target's id populated.
    expect(fn () => Livewire::test(Index::class)->call('openEditRateModal', $rate->id))
        ->toThrow(AuthorizationException::class);

    expect($rate->fresh()->price)->toBe('4.95');
});

test('saveRate edit branch refuses a permission revoked WHILE the modal is open, and the rate keeps its price', function () {
    // Phase 5 code-review finding F-4: the ONLY scenario in which saveRate()'s own
    // 'update' re-authorization (a second layer on top of UpdateShippingRate's own) can
    // ever actually refuse anything is a permission revoked mid-session, after
    // openEditRateModal() already gated the opener successfully -- nothing else can reach
    // this branch with editingRateId populated and the ability missing. Without this test,
    // that whole gate could be deleted and the suite would stay green.
    $this->withoutExceptionHandling();
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.edit']);
    $this->actingAs($actor);

    $rate = ShippingRate::factory()->pricedAt('4.95')->create();

    $component = Livewire::test(Index::class)->call('openEditRateModal', $rate->id);

    $actor->revokePermissionTo('shipping.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $component->set('price', '99.99')->call('saveRate'))
        ->toThrow(AuthorizationException::class);

    expect($rate->fresh()->price)->toBe('4.95');
});

test('a user without shipping.delete cannot delete a rate rule, and it still exists', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(rateAuthViewOnlyActor());

    $rate = ShippingRate::factory()->create();

    expect(fn () => Livewire::test(Index::class)->call('confirmDeleteRate', $rate->id))
        ->toThrow(AuthorizationException::class);

    expect(ShippingRate::query()->find($rate->id))->not->toBeNull();
});

test('a user holding create/edit/delete can save and delete a rate through the component', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);
    $this->actingAs($actor);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Estándar')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '4.95')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasNoErrors();

    $rate = ShippingRate::where('name', 'Estándar')->firstOrFail();

    Livewire::test(Index::class)
        ->call('confirmDeleteRate', $rate->id)
        ->call('deleteRate');

    expect(ShippingRate::query()->find($rate->id))->toBeNull();
});

test('a Super Admin holding no explicit shipping.* grant can create a shipping rate', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Estándar')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '4.95')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasNoErrors();

    expect(ShippingRate::where('name', 'Estándar')->exists())->toBeTrue();
});

test('row actions render both branches -- disabled for a view-only user, enabled for a fully permitted one', function () {
    // Phase 5 code-review finding L5: a page-global assertSeeHtml('disabled') proves only
    // that the WORD "disabled" appears somewhere on the page, never that it is attached to
    // the edit-rate button specifically -- and the "enabled" branch never asserted the
    // word's absence at all. Scope both assertions to each button's own rendered fragment.
    //
    // The needle is the literal HTML boolean attribute `disabled="disabled"`, NEVER the bare
    // substring 'disabled' -- verified by execution: Flux's own static Tailwind classes
    // (`[&[disabled]]:opacity-50`) contain that substring on EVERY switch/button regardless of
    // actual state, which made a bare-substring version of this very test fail against the
    // ENABLED branch on first run.
    $fragmentAround = function (string $html, string $needle): string {
        $position = strpos($html, $needle);
        expect($position)->not->toBeFalse("Expected to find [$needle] in the rendered HTML.");

        return substr($html, max(0, $position - 400), 800);
    };

    $carrier = ShippingCarrier::factory()->create();
    $rate = ShippingRate::factory()->create(['shipping_carrier_id' => $carrier->id]);

    $viewOnly = rateAuthViewOnlyActor();
    $this->actingAs($viewOnly);
    $viewOnlyHtml = Livewire::test(Index::class)->html();

    expect($fragmentAround($viewOnlyHtml, 'data-test="edit-rate-'.$rate->id.'"'))->toContain('disabled="disabled"')
        ->and($fragmentAround($viewOnlyHtml, 'data-test="toggle-carrier-'.$carrier->id.'"'))->toContain('disabled="disabled"');

    $full = User::factory()->create();
    $full->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);
    $this->actingAs($full);
    $fullHtml = Livewire::test(Index::class)->html();

    expect($fragmentAround($fullHtml, 'data-test="edit-rate-'.$rate->id.'"'))->not->toContain('disabled="disabled"')
        ->and($fragmentAround($fullHtml, 'data-test="delete-rate-'.$rate->id.'"'))->not->toContain('disabled="disabled"')
        ->and($fragmentAround($fullHtml, 'data-test="toggle-carrier-'.$carrier->id.'"'))->not->toContain('disabled="disabled"');
});
