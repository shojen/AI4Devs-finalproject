<?php

use App\Models\ShippingCarrier;
use Database\Seeders\ShippingCarrierSeeder;

// No forgetCachedPermissions() beforeEach() -- ShippingCarrierSeeder touches no permission
// cache at all, matching SalesRegionSeederTest.php's own reasoning for the identical omission.

// --- Catalog coverage ---

test('seeding creates exactly the four prototype carriers with their own code and name', function () {
    $this->seed(ShippingCarrierSeeder::class);

    expect(ShippingCarrier::count())->toBe(4);

    $pairs = ShippingCarrier::query()->orderBy('code')->pluck('name', 'code')->all();

    expect($pairs)->toBe([
        'CRRS' => 'Correos',
        'DHL' => 'DHL Express',
        'MRW' => 'MRW',
        'SEUR' => 'SEUR',
    ]);
});

test('every seeded carrier is active', function () {
    $this->seed(ShippingCarrierSeeder::class);

    expect(ShippingCarrier::where('is_active', true)->count())->toBe(4);
});

test('every seeded carrier carries a non-empty description', function () {
    $this->seed(ShippingCarrierSeeder::class);

    foreach (ShippingCarrier::all() as $carrier) {
        expect($carrier->description)->not->toBeEmpty();
    }
});

// --- Idempotency ---

test('running the seeder twice leaves the count at four', function () {
    $this->seed(ShippingCarrierSeeder::class);
    $this->seed(ShippingCarrierSeeder::class);

    expect(ShippingCarrier::count())->toBe(4);
});

// --- The no-clobber guarantee (the load-bearing test in this story) ---

test('re-seeding does not resurrect a carrier an administrator disabled', function () {
    $this->seed(ShippingCarrierSeeder::class);

    $mrw = ShippingCarrier::where('code', 'MRW')->firstOrFail();
    $mrw->forceFill(['is_active' => false])->save();

    $this->seed(ShippingCarrierSeeder::class);

    expect($mrw->fresh()->is_active)->toBeFalse();
});

test('re-seeding does not duplicate a carrier an administrator renamed', function () {
    $this->seed(ShippingCarrierSeeder::class);

    $dhl = ShippingCarrier::where('code', 'DHL')->firstOrFail();
    $dhl->update(['name' => 'DHL']);

    $this->seed(ShippingCarrierSeeder::class);

    expect(ShippingCarrier::where('code', 'DHL')->count())->toBe(1)
        ->and($dhl->fresh()->name)->toBe('DHL');
});

test('re-seeding preserves an administrator-edited description', function () {
    $this->seed(ShippingCarrierSeeder::class);

    $seur = ShippingCarrier::where('code', 'SEUR')->firstOrFail();
    $seur->forceFill(['description' => 'Custom administrator description'])->save();

    $this->seed(ShippingCarrierSeeder::class);

    expect($seur->fresh()->description)->toBe('Custom administrator description');
});
