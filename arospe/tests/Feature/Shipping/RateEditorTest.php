<?php

// Component-level tests for the create/edit rate modal half of App\Livewire\Shipping\Index, per
// ai-spec/tasks/in-progress/0037-shipping-carriers-and-rates-ui.md's "Tests to perform" section.
// D-2's blank-max-weight finding is guarded here at component level (the row-level regression
// guard) -- the real proof, through a genuine browser round-trip, lives in
// tests/Browser/Shipping/ShippingTest.php.

use App\Livewire\Shipping\Index;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function rateEditorFullActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);

    return $actor;
}

test('creating a valid rate makes it appear under the chosen carrier, and closes the modal', function () {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $component = Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Estándar')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '4.95')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate');

    $component->assertHasNoErrors();
    expect($component->get('showRateModal'))->toBeFalse();

    $rate = ShippingRate::where('name', 'Estándar')->firstOrFail();
    expect($rate->shipping_carrier_id)->toBe($carrier->id)
        ->and($rate->shipping_zone_id)->toBe($zone->id);
});

test('a rate can be created for a currently disabled carrier, and it appears in carrierOptions', function () {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->inactive()->create();
    $zone = ShippingZone::factory()->create();

    $component = Livewire::test(Index::class);

    $options = collect($component->get('carrierOptions'));
    expect($options->pluck('id'))->toContain($carrier->id);

    $component
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

    expect(ShippingRate::where('name', 'Estándar')->where('shipping_carrier_id', $carrier->id)->exists())->toBeTrue();
});

test('zoneOptions lists every zone by name, and a zone created after mount appears on a remount', function () {
    $this->actingAs(rateEditorFullActor());

    ShippingZone::factory()->create(['name' => 'Península']);

    $before = collect(Livewire::test(Index::class)->get('zoneOptions'))->pluck('name');
    expect($before)->toContain('Península')->not->toContain('Zona Nueva');

    ShippingZone::factory()->create(['name' => 'Zona Nueva']);

    $after = collect(Livewire::test(Index::class)->get('zoneOptions'))->pluck('name');
    expect($after)->toContain('Zona Nueva');
});

test('invalid rate attributes are rejected through saveRate, never silently coerced', function (Closure $buildAttributes, string $expectedErrorKey) {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();
    $attributes = $buildAttributes($carrier->id, $zone->id);

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', $attributes['name'])
        ->set('shippingCarrierId', $attributes['shipping_carrier_id'])
        ->set('shippingZoneId', $attributes['shipping_zone_id'])
        ->set('minWeightKg', $attributes['min_weight_kg'])
        ->set('maxWeightKg', $attributes['max_weight_kg'] ?? '')
        ->set('price', $attributes['price'])
        ->set('deliveryEstimate', $attributes['delivery_estimate'])
        ->call('saveRate')
        ->assertHasErrors($expectedErrorKey);

    expect(ShippingRate::where('shipping_carrier_id', $carrier->id)->exists())->toBeFalse();
})->with('invalid_rate_attributes');

test('a validation refusal actually renders a message in the template, not only in the error bag', function () {
    // Phase 5 code-review finding F-5: assertHasErrors() proves the bag was populated on the
    // THROWING request -- it proves nothing about whether the compiled template renders that
    // message. This screen's binding is unusual (saveRate() re-keys a snake_case Validator
    // bag onto camelCase properties before Livewire ever sees it -- see the method's own
    // docblock), so a broken RATE_FIELD_MAP entry or a dropped :label prop would be entirely
    // invisible to assertHasErrors() alone. Matches docs/errors-log.md's 2026-09-07
    // flux:fieldset entry, the exact same class of gap.
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', '')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '4.95')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasErrors('rateName')
        ->assertSee(__('validation.required', ['attribute' => __('shipping.rates.attributes.name')]));
});

test('min weight equal to max weight is accepted', function () {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Estándar')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '2')
        ->set('maxWeightKg', '2')
        ->set('price', '4.95')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasNoErrors();

    expect(ShippingRate::where('name', 'Estándar')->exists())->toBeTrue();
});

test('a free shipping rate (0.00) is accepted', function () {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Gratis')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '0')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasNoErrors();

    expect(ShippingRate::where('name', 'Gratis')->firstOrFail()->price)->toBe('0.00');
});

test('a blank max weight persists as a real database NULL, at the component level', function () {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Abierta')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '5')
        ->set('maxWeightKg', '') // the real risk: this must become a database NULL, not '0' or ''.
        ->set('price', '12')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('shipping_rates', ['name' => 'Abierta', 'max_weight_kg' => null]);
});

test('a locale comma in the price reaches the server and is normalised, but fails standalone validation', function () {
    $this->actingAs(rateEditorFullActor());

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    Livewire::test(Index::class)
        ->call('openCreateRateModal')
        ->set('rateName', 'Con Coma')
        ->set('shippingCarrierId', $carrier->id)
        ->set('shippingZoneId', $zone->id)
        ->set('minWeightKg', '0')
        ->set('maxWeightKg', '2')
        ->set('price', '4,95')
        ->set('deliveryEstimate', '24-48h')
        ->call('saveRate')
        ->assertHasNoErrors();

    expect(ShippingRate::where('name', 'Con Coma')->firstOrFail()->price)->toBe('4.95');

    // Pinning that the NORMALISATION is the component's, not priceRules() itself: an
    // un-normalised comma fails decimal:0,2 in isolation.
    $failing = Validator::make(['price' => '4,95'], ['price' => ['required', 'numeric', 'decimal:0,2', 'min:0']]);
    expect($failing->fails())->toBeTrue();
});

test('editing only the delivery estimate leaves the rate open-ended', function () {
    $this->actingAs(rateEditorFullActor());

    $rate = ShippingRate::factory()->openEnded()->create();

    Livewire::test(Index::class)
        ->call('openEditRateModal', $rate->id)
        ->set('deliveryEstimate', '48-72h')
        ->call('saveRate')
        ->assertHasNoErrors();

    $rate->refresh();
    expect($rate->max_weight_kg)->toBeNull()
        ->and($rate->delivery_estimate)->toBe('48-72h');
});

test('openEditRateModal and confirmDeleteRate populate from the model, not from ratesByCarrier', function () {
    $this->actingAs(rateEditorFullActor());

    $rate = ShippingRate::factory()->create(['name' => 'Original']);

    $component = Livewire::test(Index::class)->call('openEditRateModal', $rate->id);

    expect($component->get('rateName'))->toBe('Original');

    // Mutate the database directly -- $ratesByCarrier (already loaded before this) must never
    // be what a second call reads back from.
    $rate->update(['name' => 'Renamed']);

    $deleteComponent = Livewire::test(Index::class)->call('confirmDeleteRate', $rate->id);
    expect($deleteComponent->get('deletingRateName'))->toBe('Renamed');
});

test('editingRateId, deletingRateId and deletingRateName are locked properties', function () {
    $this->actingAs(rateEditorFullActor());

    $rate = ShippingRate::factory()->create();
    $component = Livewire::test(Index::class);

    expect(fn () => $component->set('editingRateId', $rate->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('deletingRateId', $rate->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('deletingRateName', 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('deleting through the confirmation flow removes the rate', function () {
    $this->actingAs(rateEditorFullActor());

    $rate = ShippingRate::factory()->create();

    Livewire::test(Index::class)
        ->call('confirmDeleteRate', $rate->id)
        ->call('deleteRate');

    expect(ShippingRate::query()->find($rate->id))->toBeNull();
});

test('cancelling the delete confirmation leaves the rate intact', function () {
    $this->actingAs(rateEditorFullActor());

    $rate = ShippingRate::factory()->create();

    Livewire::test(Index::class)
        ->call('confirmDeleteRate', $rate->id)
        ->call('closeDeleteRateModal');

    expect(ShippingRate::query()->find($rate->id))->not->toBeNull();
});
