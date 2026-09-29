<?php

// Component-level tests for the grouped-rate-table half of App\Livewire\Shipping\Index, per
// ai-spec/tasks/in-progress/0037-shipping-carriers-and-rates-ui.md's "Tests to perform" section.
// ListShippingRatesByCarrier's own eager-loading/N+1 correctness is pinned in
// tests/Feature/ShippingRates/ListShippingRatesByCarrierTest.php -- this file only pins that the
// COMPONENT consumes it correctly (D-4's "the likeliest cross-wiring in the shipping set").

use App\Livewire\Shipping\Index;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function rateListingFullActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);

    return $actor;
}

test('rates render under their own carrier, by exact carrier-id -> rate-id identity', function () {
    $this->actingAs(rateListingFullActor());

    $seur = ShippingCarrier::factory()->create(['name' => 'SEUR']);
    $mrw = ShippingCarrier::factory()->create(['name' => 'MRW']);
    $seurRate = ShippingRate::factory()->create(['shipping_carrier_id' => $seur->id]);
    $mrwRate = ShippingRate::factory()->create(['shipping_carrier_id' => $mrw->id]);

    $groups = collect(Livewire::test(Index::class)->get('ratesByCarrier'));

    $seurGroup = $groups->firstWhere('carrierId', $seur->id);
    $mrwGroup = $groups->firstWhere('carrierId', $mrw->id);

    expect(collect($seurGroup['rates'])->pluck('id')->all())->toBe([$seurRate->id])
        ->and(collect($mrwGroup['rates'])->pluck('id')->all())->toBe([$mrwRate->id]);
});

test('a carrier with zero rates still renders as its own group with an empty sub-state', function () {
    $this->actingAs(rateListingFullActor());

    $emptyCarrier = ShippingCarrier::factory()->create(['name' => 'DHL Express']);

    $groups = collect(Livewire::test(Index::class)->get('ratesByCarrier'));

    $group = $groups->firstWhere('carrierId', $emptyCarrier->id);

    expect($group)->not->toBeNull()
        ->and($group['rates'])->toBe([]);
});

test('a disabled carrier own rates still appear -- fixture built rate-then-disable', function () {
    $this->actingAs(rateListingFullActor());

    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);
    $rate = ShippingRate::factory()->create(['shipping_carrier_id' => $carrier->id]);

    // Order matters: create the rate FIRST, then disable the carrier -- proving the listing
    // is not accidentally filtered by is_active (D-4's likeliest cross-wiring). forceFill(),
    // never update() -- is_active is deliberately omitted from ShippingCarrier's own
    // #[Fillable] (its seeder-idempotency-adjacent mass-assignment guard), so a plain
    // update() silently no-ops here.
    $carrier->forceFill(['is_active' => false])->save();

    $groups = collect(Livewire::test(Index::class)->get('ratesByCarrier'));
    $group = $groups->firstWhere('carrierId', $carrier->id);

    expect($group)->not->toBeNull()
        ->and($group['carrierIsActive'])->toBeFalse()
        ->and(collect($group['rates'])->pluck('id')->all())->toBe([$rate->id]);
});

test('an open-ended rate renders "and above" text and never the raw word null', function () {
    $this->actingAs(rateListingFullActor());

    $carrier = ShippingCarrier::factory()->create();
    ShippingRate::factory()->openEnded()->create(['shipping_carrier_id' => $carrier->id, 'name' => 'Open Ended Rate']);

    Livewire::test(Index::class)
        ->assertSee('and above')
        ->assertDontSee('–null')
        ->assertDontSee('-null');
});

test('a 0.00 rate renders as a real price, not a blank', function () {
    $this->actingAs(rateListingFullActor());

    $carrier = ShippingCarrier::factory()->create();
    ShippingRate::factory()->pricedAt('0.00')->create(['shipping_carrier_id' => $carrier->id]);

    $groups = collect(Livewire::test(Index::class)->get('ratesByCarrier'));
    $rate = collect($groups->firstWhere('carrierId', $carrier->id)['rates'])->first();

    expect($rate['price'])->toBe('0.00');
});

test('price is exposed as a string, never coerced to a float', function () {
    $this->actingAs(rateListingFullActor());

    $carrier = ShippingCarrier::factory()->create();
    ShippingRate::factory()->pricedAt('4.95')->create(['shipping_carrier_id' => $carrier->id]);

    $groups = collect(Livewire::test(Index::class)->get('ratesByCarrier'));
    $rate = collect($groups->firstWhere('carrierId', $carrier->id)['rates'])->first();

    expect($rate['price'])->toBeString()->toBe('4.95');
});

test('loadRates issues the SAME number of queries regardless of carrier/rate volume, not a magic ceiling', function () {
    // Phase 5 code-review finding M2: a bare toBeLessThan(15) passes identically whether
    // ListShippingRatesByCarrier eager-loads the zone relation or not (measured: 6 queries
    // shipped, 11 with .zone removed -- both under 15), so it cannot fail against the exact
    // N+1 regression it exists to catch. Asserting INVARIANCE across two different volumes is
    // what actually proves the query count does not scale with row count.
    $queryCountFor = function (int $carrierCount, int $ratesPerCarrier): int {
        $this->actingAs(rateListingFullActor());

        $carriers = ShippingCarrier::factory()->count($carrierCount)->create();
        foreach ($carriers as $carrier) {
            ShippingRate::factory()->count($ratesPerCarrier)->create(['shipping_carrier_id' => $carrier->id]);
        }

        $queries = 0;
        $listener = function () use (&$queries) {
            $queries++;
        };
        DB::listen($listener);

        Livewire::test(Index::class);

        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $queries;
    };

    $small = $queryCountFor(1, 1);
    $large = $queryCountFor(4, 3);

    expect($large)->toBe($small);
});

test('the rate table empty state renders when no rate exists at all', function () {
    $this->actingAs(rateListingFullActor());

    // Every seeded/factory carrier here has zero rates.
    ShippingCarrier::factory()->count(2)->create();

    Livewire::test(Index::class)->assertSee(__('shipping.rates.index.empty'));
});

test('a zone catalog entry never renders on this screen unless referenced by a rate', function () {
    $this->actingAs(rateListingFullActor());

    ShippingZone::factory()->create(['name' => 'Unused Zone']);

    Livewire::test(Index::class)->assertDontSee('Unused Zone');
});
