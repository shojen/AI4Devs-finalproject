<?php

// Real-browser coverage for App\Livewire\Shipping\Index's rate-CRUD half, per
// ai-spec/tasks/in-progress/0037-shipping-carriers-and-rates-ui.md's "Tests to perform" section.
//
// Three tests, each justified individually rather than by "browser is more thorough" --
// see the story's own D-2/R-1. The blank-max-weight and carrier/zone-select tests below are the
// EXECUTABLE PROOF for D-2: neither risk can be observed by Livewire::test()->set(...), which
// writes a component property directly and never touches a DOM element or fires a `change` event
// -- see docs/errors-log.md's null-<select> desync entry and its 2026-09-06 confirmation that
// even a ->select()-driven test cannot prove this, only reading selectedIndex/the persisted row
// can.

use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function shippingBrowserActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);

    return $actor;
}

test('creating an open-ended rate by really clearing the max-weight field persists NULL, and reopens genuinely blank', function () {
    $actor = shippingBrowserActor();
    $this->actingAs($actor);

    $carrier = ShippingCarrier::factory()->create(['name' => 'SEUR Browser']);
    $zone = ShippingZone::factory()->create(['name' => 'Peninsula Browser']);

    $page = visit(route('shipping.index'))->assertNoJavaScriptErrors();

    $page->click('@new-rate-button')
        ->assertNoJavaScriptErrors()
        ->fill('rateName', 'Abierta Browser')
        ->select('shippingCarrierId', 'SEUR Browser')
        ->select('shippingZoneId', 'Peninsula Browser')
        ->fill('minWeightKg', '5')
        // The real risk this test exists for: a genuinely empty field, driven through the DOM,
        // must persist as a database NULL -- never '0', never the empty string '' itself.
        ->fill('maxWeightKg', '')
        ->fill('price', '15')
        ->fill('deliveryEstimate', '24-48h')
        ->click('@save-rate-button')
        ->assertNoJavaScriptErrors()
        ->wait(1);

    $rate = ShippingRate::where('name', 'Abierta Browser')->firstOrFail();
    expect($rate->max_weight_kg)->toBeNull();

    // Reload and reopen the editor -- the field must render genuinely blank, not "0" or "null".
    $page = visit(route('shipping.index'))->assertNoJavaScriptErrors();

    $page->click('@edit-rate-'.$rate->id)
        ->assertNoJavaScriptErrors()
        ->assertValue('maxWeightKg', '');
});

test('picking a carrier and a zone from the real selects persists the CHOSEN option, not the first', function () {
    $actor = shippingBrowserActor();
    $this->actingAs($actor);

    // Two of each, so the "first in the list" trap has something to fall into if it exists --
    // alphabetically first is deliberately NOT the one chosen below.
    ShippingCarrier::factory()->create(['name' => 'Alpha Carrier']);
    $chosenCarrier = ShippingCarrier::factory()->create(['name' => 'Zulu Carrier']);
    ShippingZone::factory()->create(['name' => 'Alpha Zone']);
    $chosenZone = ShippingZone::factory()->create(['name' => 'Zulu Zone']);

    $page = visit(route('shipping.index'))->assertNoJavaScriptErrors();

    $page->click('@new-rate-button')
        ->assertNoJavaScriptErrors()
        ->fill('rateName', 'Selector Test')
        ->select('shippingCarrierId', 'Zulu Carrier')
        ->select('shippingZoneId', 'Zulu Zone')
        ->fill('minWeightKg', '0')
        ->fill('maxWeightKg', '2')
        ->fill('price', '9.99')
        ->fill('deliveryEstimate', '24h')
        ->click('@save-rate-button')
        ->assertNoJavaScriptErrors()
        ->wait(1);

    $rate = ShippingRate::where('name', 'Selector Test')->firstOrFail();
    expect($rate->shipping_carrier_id)->toBe($chosenCarrier->id)
        ->and($rate->shipping_zone_id)->toBe($chosenZone->id);
});

test('the full click-driven journey: create, edit, delete through confirmation, cancel once, toggle a carrier', function () {
    $actor = shippingBrowserActor();
    $this->actingAs($actor);

    $carrier = ShippingCarrier::factory()->create(['name' => 'Journey Carrier', 'is_active' => true]);
    $zone = ShippingZone::factory()->create(['name' => 'Journey Zone']);

    $page = visit(route('shipping.index'))->assertNoJavaScriptErrors();

    // Create.
    $page->click('@new-rate-button')
        ->assertNoJavaScriptErrors()
        ->fill('rateName', 'Journey Rate')
        ->select('shippingCarrierId', 'Journey Carrier')
        ->select('shippingZoneId', 'Journey Zone')
        ->fill('minWeightKg', '0')
        ->fill('maxWeightKg', '3')
        ->fill('price', '5,50')
        ->fill('deliveryEstimate', '24-48h')
        ->click('@save-rate-button')
        ->assertNoJavaScriptErrors()
        ->wait(1);

    $rate = ShippingRate::where('name', 'Journey Rate')->firstOrFail();
    expect($rate->price)->toBe('5.50');

    // Edit.
    $page->click('@edit-rate-'.$rate->id)
        ->assertNoJavaScriptErrors()
        ->fill('price', '6,00')
        ->click('@save-rate-button')
        ->assertNoJavaScriptErrors()
        ->wait(1);

    expect($rate->fresh()->price)->toBe('6.00');

    // Delete -- cancel once first.
    $page->click('@delete-rate-'.$rate->id)
        ->assertNoJavaScriptErrors()
        ->click('Cancel')
        ->assertNoJavaScriptErrors();

    expect(ShippingRate::query()->find($rate->id))->not->toBeNull();

    $page->click('@delete-rate-'.$rate->id)
        ->assertNoJavaScriptErrors()
        ->click('@confirm-delete-rate-button')
        ->assertNoJavaScriptErrors()
        ->wait(1);

    expect(ShippingRate::query()->find($rate->id))->toBeNull();

    // Toggle a carrier.
    $page->click('@toggle-carrier-'.$carrier->id)
        ->assertNoJavaScriptErrors()
        ->wait(1);

    expect($carrier->fresh()->is_active)->toBeFalse();
});
