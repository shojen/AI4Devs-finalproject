<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Actions\Shipping\ListShippingRatesByCarrier;
use App\Models\ShippingCarrier;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): see CreateShippingRateTest.php's file banner -- the same
// applies here. App\Actions\Shipping\ListShippingRatesByCarrier does not exist yet.
//
// D-11/D-10: this action gates NOTHING of its own -- it is a plain query with no route and no
// Livewire component (this story ships neither); 0037 is its gating consumer. No actingAs()/
// permission setup is needed to call it directly.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function createShippingRateBypassingAuthorization(ShippingCarrier $carrier, ShippingZone $zone, string $name = 'Estándar'): mixed
{
    $creator = User::factory()->create();
    $creator->givePermissionTo('shipping.create');
    test()->actingAs($creator);

    return app(CreateShippingRate::class)([
        'name' => $name,
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);
}

test('rates are returned grouped under their own carrier, carriers ordered by name', function () {
    $correos = ShippingCarrier::factory()->create(['name' => 'Correos']);
    $seur = ShippingCarrier::factory()->create(['name' => 'SEUR']);
    $mrw = ShippingCarrier::factory()->create(['name' => 'MRW']);
    $zone = ShippingZone::factory()->create();

    createShippingRateBypassingAuthorization($seur, $zone, 'SEUR rate');
    createShippingRateBypassingAuthorization($mrw, $zone, 'MRW rate');
    createShippingRateBypassingAuthorization($correos, $zone, 'Correos rate');

    $result = app(ListShippingRatesByCarrier::class)();

    expect($result->pluck('name')->all())->toBe(['Correos', 'MRW', 'SEUR']);

    $seurGroup = $result->firstWhere('id', $seur->id);
    expect($seurGroup->shippingRates)->toHaveCount(1)
        ->and($seurGroup->shippingRates->first()->name)->toBe('SEUR rate');
});

// A PHP groupBy() over a flat rate list silently drops a carrier with zero rates -- this
// assertion fails against exactly that implementation shape.
test('a carrier with zero rates still appears, as an empty group', function () {
    $seur = ShippingCarrier::factory()->create(['name' => 'SEUR']);
    $dhl = ShippingCarrier::factory()->create(['name' => 'DHL Express']);
    $zone = ShippingZone::factory()->create();

    createShippingRateBypassingAuthorization($seur, $zone);

    $result = app(ListShippingRatesByCarrier::class)();

    expect($result)->toHaveCount(2);

    $dhlGroup = $result->firstWhere('id', $dhl->id);
    expect($dhlGroup)->not->toBeNull()
        ->and($dhlGroup->shippingRates)->toHaveCount(0);
});

// D-6: the listing is configuration, not resolution -- only the RESOLVER filters on is_active.
// Conflating the two is the likeliest cross-wiring in this story.
test("a disabled carrier's rates still appear in the admin listing", function () {
    $mrw = ShippingCarrier::factory()->inactive()->create(['name' => 'MRW']);
    $zone = ShippingZone::factory()->create();

    createShippingRateBypassingAuthorization($mrw, $zone, 'MRW disabled rate');

    $result = app(ListShippingRatesByCarrier::class)();

    $mrwGroup = $result->firstWhere('id', $mrw->id);
    expect($mrwGroup)->not->toBeNull()
        ->and($mrwGroup->shippingRates)->toHaveCount(1)
        ->and($mrwGroup->shippingRates->first()->name)->toBe('MRW disabled rate');
});

// No N+1 across carriers, rates or zones -- the query count for a bigger fixture must equal the
// query count for a smaller one, following tests/Feature/Products/IndexQueryTest.php's own
// warm-up + before/after comparison shape.
test('the query count is bounded -- no N+1 across carriers, rates or zones', function () {
    // Warm the permission-relation cache before either counted run, matching IndexQueryTest.php's
    // own rationale -- Spatie's PermissionRegistrar lazily loads and caches the whole
    // roles+permissions graph on the first check per test, a one-time cost unrelated to this
    // query's own N+1 shape.
    $creator = User::factory()->create();
    $creator->givePermissionTo('shipping.create');
    test()->actingAs($creator);
    ShippingCarrier::query()->delete();

    $queryCountFor = function (int $carrierCount): int {
        ShippingCarrier::query()->delete();
        ShippingZone::query()->delete();

        for ($i = 0; $i < $carrierCount; $i++) {
            $carrier = ShippingCarrier::factory()->create();
            $zone = ShippingZone::factory()->create();
            app(CreateShippingRate::class)([
                'name' => 'Estándar',
                'shipping_carrier_id' => $carrier->id,
                'shipping_zone_id' => $zone->id,
                'min_weight_kg' => '0',
                'max_weight_kg' => '2',
                'price' => '4.95',
                'delivery_estimate' => '24-48h',
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(ListShippingRatesByCarrier::class)();

        return $queries;
    };

    $countForOne = $queryCountFor(1);
    $countForTen = $queryCountFor(10);

    expect($countForTen)->toBe($countForOne);
});
