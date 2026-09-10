<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Actions\Shipping\DeleteShippingRate;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): see CreateShippingRateTest.php's file banner -- the same
// applies here. App\Actions\Shipping\DeleteShippingRate does not exist yet.
//
// D-11 (Phase 2 review finding B1, made normative): DeleteShippingRate self-authorizes `delete`
// on the target ShippingRate as its own first statement, via the injected
// LogRefusedPrivilegedAttempt.
//
// D-5's own note: this action carries NO in-use guard and NO count of any kind -- deleting a rate
// blocks on nothing. The in-use guard in this story belongs to DeleteShippingZone, not here; see
// tests/Feature/ShippingZones/DeleteShippingZoneTest.php.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function createBaselineShippingRateForDeleteTests(): array
{
    $creator = User::factory()->create();
    $creator->givePermissionTo('shipping.create');
    test()->actingAs($creator);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Estándar',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);

    return [$rate, $carrier, $zone];
}

test('deleting a rate removes the row outright, not a soft delete', function () {
    [$rate] = createBaselineShippingRateForDeleteTests();

    $deleter = User::factory()->create();
    $deleter->givePermissionTo('shipping.delete');
    $this->actingAs($deleter);

    $result = app(DeleteShippingRate::class)($rate);

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('shipping_rates', ['id' => $rate->id]);
});

// D-14: the guard means what it says only because ShippingRate is hard-deleted -- if this ever
// starts using SoftDeletes, `assertDatabaseMissing` here silently starts failing for the wrong
// reason (the row would still exist, merely trashed). The trait-absence guard itself lives in
// tests/Unit/Models/ShippingRateTest.php; this is the behavioural counterpart -- a plain
// unscoped count reads 0, which a soft-deleted row would not.
test('a deleted rate is genuinely gone -- an unscoped count reads zero, not merely trashed', function () {
    [$rate] = createBaselineShippingRateForDeleteTests();

    $deleter = User::factory()->create();
    $deleter->givePermissionTo('shipping.delete');
    $this->actingAs($deleter);

    app(DeleteShippingRate::class)($rate);

    expect(ShippingRate::count())->toBe(0);
});

// Assert EXACT ids, not count() (0033's rule): a count passes if rows were deleted and recreated.
test('deleting a rate leaves its carrier and its zone untouched', function () {
    [$rate, $carrier, $zone] = createBaselineShippingRateForDeleteTests();

    $deleter = User::factory()->create();
    $deleter->givePermissionTo('shipping.delete');
    $this->actingAs($deleter);

    app(DeleteShippingRate::class)($rate);

    $this->assertDatabaseHas('shipping_carriers', ['id' => $carrier->id]);
    $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
});

test('deleting an unknown shipping rate id fails cleanly with ModelNotFoundException, not a silent no-op', function () {
    expect(fn () => ShippingRate::findOrFail((string) Str::uuid7()))
        ->toThrow(ModelNotFoundException::class);
});

test('deleting a malformed, non-UUID shipping rate id fails cleanly with ModelNotFoundException', function () {
    expect(fn () => ShippingRate::findOrFail('not-a-uuid'))
        ->toThrow(ModelNotFoundException::class);
});

// D-11: the Gherkin "A user without the shipping delete permission cannot delete a rate rule".
test('a signed-in user without shipping.delete is refused, and the rate still exists', function () {
    [$rate] = createBaselineShippingRateForDeleteTests();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit']);
    $this->actingAs($actor);

    $caught = null;

    try {
        app(DeleteShippingRate::class)($rate);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    $this->assertDatabaseHas('shipping_rates', ['id' => $rate->id]);
});

test('a delete refusal is logged with target_type shipping_rate and the rows own target_id', function () {
    [$rate] = createBaselineShippingRateForDeleteTests();

    Log::spy();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(DeleteShippingRate::class)($rate);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['ability'] ?? null) === 'delete'
            && ($context['target_type'] ?? null) === 'shipping_rate'
            && ($context['target_id'] ?? null) === $rate->id)
        ->once();
});

// The allow half of the pair.
test('a holder of shipping.delete deletes the rate, as the control', function () {
    [$rate] = createBaselineShippingRateForDeleteTests();

    $deleter = User::factory()->create();
    $deleter->givePermissionTo('shipping.delete');
    $this->actingAs($deleter);

    $result = app(DeleteShippingRate::class)($rate);

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('shipping_rates', ['id' => $rate->id]);
});
