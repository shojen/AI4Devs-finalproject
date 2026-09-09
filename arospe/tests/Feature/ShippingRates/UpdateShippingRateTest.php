<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Actions\Shipping\UpdateShippingRate;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): see CreateShippingRateTest.php's file banner -- the same
// applies here. App\Actions\Shipping\UpdateShippingRate does not exist yet.
//
// D-11 (Phase 2 review finding B1, made normative): UpdateShippingRate self-authorizes `update`
// on the target ShippingRate as its own first statement, via the injected
// LogRefusedPrivilegedAttempt. Every positive test below runs actingAs() an actor holding
// `shipping.edit`.
//
// Reuses ShippingRateValidationTest.php's `invalid_rate_attributes` dataset via
// ->with('invalid_rate_attributes') rather than copy-pasting it (0033 R-7): a validation rule
// threaded through one call site and not the other fails silently in one direction only, and
// update is the direction nobody writes a bespoke test for.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function createBaselineShippingRateAsPrivilegedCreator(): ShippingRate
{
    $creator = User::factory()->create();
    $creator->givePermissionTo('shipping.create');
    test()->actingAs($creator);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    return app(CreateShippingRate::class)([
        'name' => 'Estándar',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);
}

test('the invalid_rate_attributes dataset is refused on update too, and the row is left unchanged', function (Closure $buildAttributes, string $expectedErrorKey) {
    $rate = createBaselineShippingRateAsPrivilegedCreator();

    $editor = User::factory()->create();
    $editor->givePermissionTo('shipping.edit');
    test()->actingAs($editor);

    $snapshotBefore = $rate->fresh()->getAttributes();

    $caught = null;

    try {
        app(UpdateShippingRate::class)($rate, $buildAttributes($rate->shipping_carrier_id, $rate->shipping_zone_id));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey($expectedErrorKey);

    expect($rate->fresh()->getAttributes())->toBe($snapshotBefore);
})->with('invalid_rate_attributes');

test('editing a price persists it, and editing does not silently clear max_weight_kg', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();

    $editor = User::factory()->create();
    $editor->givePermissionTo('shipping.edit');
    $this->actingAs($editor);

    $updated = app(UpdateShippingRate::class)($rate, [
        'name' => $rate->name,
        'shipping_carrier_id' => $rate->shipping_carrier_id,
        'shipping_zone_id' => $rate->shipping_zone_id,
        'min_weight_kg' => (string) $rate->min_weight_kg,
        'max_weight_kg' => (string) $rate->max_weight_kg,
        'price' => '5.50',
        'delivery_estimate' => $rate->delivery_estimate,
    ]);

    expect($updated->fresh()->price)->toBe('5.50')
        ->and($updated->fresh()->max_weight_kg)->not->toBeNull()
        ->and((string) $updated->fresh()->max_weight_kg)->toBe('2.000');
});

// The operation the delete guard's own message tells the administrator to perform (D-5), so it
// must actually work -- and leave every other field untouched.
test('reassigning a rate to a different zone leaves every other field untouched', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();
    $newZone = ShippingZone::factory()->create(['name' => 'Baleares']);

    $editor = User::factory()->create();
    $editor->givePermissionTo('shipping.edit');
    $this->actingAs($editor);

    $updated = app(UpdateShippingRate::class)($rate, [
        'name' => $rate->name,
        'shipping_carrier_id' => $rate->shipping_carrier_id,
        'shipping_zone_id' => $newZone->id,
        'min_weight_kg' => (string) $rate->min_weight_kg,
        'max_weight_kg' => (string) $rate->max_weight_kg,
        'price' => $rate->price,
        'delivery_estimate' => $rate->delivery_estimate,
    ]);

    $fresh = $updated->fresh();

    expect($fresh->shipping_zone_id)->toBe($newZone->id)
        ->and($fresh->name)->toBe($rate->name)
        ->and($fresh->shipping_carrier_id)->toBe($rate->shipping_carrier_id)
        ->and($fresh->price)->toBe($rate->price)
        ->and($fresh->delivery_estimate)->toBe($rate->delivery_estimate);
});

test('an unknown shipping rate id fails cleanly with ModelNotFoundException, not a silent no-op', function () {
    expect(fn () => ShippingRate::findOrFail((string) Str::uuid7()))
        ->toThrow(ModelNotFoundException::class);
});

test('a malformed, non-UUID shipping rate id fails cleanly with ModelNotFoundException', function () {
    expect(fn () => ShippingRate::findOrFail('not-a-uuid'))
        ->toThrow(ModelNotFoundException::class);
});

// D-11: the Gherkin "A user without the shipping edit permission cannot change a rate rule". A
// gate placed AFTER the write throws and still persists -- this is what makes re-reading the row
// the load-bearing assertion, not merely catching the exception.
test('a signed-in user without shipping.edit is refused, and the rate keeps its price', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.delete']);
    $this->actingAs($actor);

    $caught = null;

    try {
        app(UpdateShippingRate::class)($rate, [
            'name' => $rate->name,
            'shipping_carrier_id' => $rate->shipping_carrier_id,
            'shipping_zone_id' => $rate->shipping_zone_id,
            'min_weight_kg' => (string) $rate->min_weight_kg,
            'max_weight_kg' => (string) $rate->max_weight_kg,
            'price' => '999.99',
            'delivery_estimate' => $rate->delivery_estimate,
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    expect($rate->fresh()->price)->toBe('4.95');
});

test('an update refusal is logged with target_type shipping_rate and the rows own target_id', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();

    Log::spy();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(UpdateShippingRate::class)($rate, [
            'name' => $rate->name,
            'shipping_carrier_id' => $rate->shipping_carrier_id,
            'shipping_zone_id' => $rate->shipping_zone_id,
            'min_weight_kg' => (string) $rate->min_weight_kg,
            'max_weight_kg' => (string) $rate->max_weight_kg,
            'price' => '999.99',
            'delivery_estimate' => $rate->delivery_estimate,
        ]);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'shipping_rate'
            && ($context['target_id'] ?? null) === $rate->id)
        ->once();
});

// The allow half of the pair.
test('a holder of shipping.edit updates the rate, as the control', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();

    $editor = User::factory()->create();
    $editor->givePermissionTo('shipping.edit');
    $this->actingAs($editor);

    $updated = app(UpdateShippingRate::class)($rate, [
        'name' => $rate->name,
        'shipping_carrier_id' => $rate->shipping_carrier_id,
        'shipping_zone_id' => $rate->shipping_zone_id,
        'min_weight_kg' => (string) $rate->min_weight_kg,
        'max_weight_kg' => (string) $rate->max_weight_kg,
        'price' => '7.00',
        'delivery_estimate' => $rate->delivery_estimate,
    ]);

    expect($updated->fresh()->price)->toBe('7.00');
});
