<?php

// Component-level tests for the carrier-cards half of App\Livewire\Shipping\Index, per
// ai-spec/tasks/in-progress/0037-shipping-carriers-and-rates-ui.md's "Tests to perform" section.
// Toggle-action semantics (authorization, no-op on repeat, ModelNotFoundException) are already
// pinned in tests/Feature/Shipping/CarrierAuthorizationTest.php and
// tests/Feature/Shipping/ToggleShippingCarrierTest.php -- not re-tested here.

use App\Livewire\Shipping\Index;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function shippingIndexFullActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);

    return $actor;
}

test('every seeded carrier renders with its code, name and description', function () {
    $this->actingAs(shippingIndexFullActor());

    $carrier = ShippingCarrier::factory()->create([
        'code' => 'F-ABCD',
        'name' => 'Acme Shipping',
        'description' => '24h · Península',
    ]);

    $carriers = Livewire::test(Index::class)->get('carriers');

    $row = collect($carriers)->firstWhere('id', $carrier->id);

    expect($row)
        ->not->toBeNull()
        ->and($row['code'])->toBe('F-ABCD')
        ->and($row['name'])->toBe('Acme Shipping')
        ->and($row['description'])->toBe('24h · Península');
});

test('toggling flips the rendered state for that carrier only', function () {
    $this->actingAs(shippingIndexFullActor());

    $target = ShippingCarrier::factory()->create(['is_active' => true]);
    $other = ShippingCarrier::factory()->create(['is_active' => true]);

    $component = Livewire::test(Index::class)->call('toggleCarrier', $target->id, false);

    $carriers = collect($component->get('carriers'));

    expect($carriers->firstWhere('id', $target->id)['isActive'])->toBeFalse()
        ->and($carriers->firstWhere('id', $other->id)['isActive'])->toBeTrue();
});

test('the new state is read back after a fresh mount, not only the same component instance', function () {
    $this->actingAs(shippingIndexFullActor());

    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    Livewire::test(Index::class)->call('toggleCarrier', $carrier->id, false);

    // A brand-new Livewire::test() instance, per the story's own explicit guard against a
    // fix that mutates only the in-memory property and never reloads from the database.
    $carriers = Livewire::test(Index::class)->get('carriers');

    expect(collect($carriers)->firstWhere('id', $carrier->id)['isActive'])->toBeFalse();
});

test('disabling a carrier that holds rate rules succeeds with no warning, and every rate row survives', function () {
    $this->actingAs(shippingIndexFullActor());

    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);
    $rates = ShippingRate::factory()->count(3)->create(['shipping_carrier_id' => $carrier->id]);

    Livewire::test(Index::class)->call('toggleCarrier', $carrier->id, false);

    expect($carrier->fresh()->is_active)->toBeFalse();

    foreach ($rates as $rate) {
        expect(ShippingRate::query()->find($rate->id))->not->toBeNull();
    }

    expect(ShippingRate::query()->where('shipping_carrier_id', $carrier->id)->count())->toBe(3);
});
