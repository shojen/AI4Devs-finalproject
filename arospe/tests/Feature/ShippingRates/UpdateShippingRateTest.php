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
use Illuminate\Support\Facades\DB;
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
// Reuses this directory's own `Datasets.php` `invalid_rate_attributes` dataset via
// ->with('invalid_rate_attributes') rather than copy-pasting it (0033 R-7): a validation rule
// threaded through one call site and not the other fails silently in one direction only, and
// update is the direction nobody writes a bespoke test for.
//
// Corrected at Phase 4 RE-audit (finding N-1): this dataset used to be defined inline inside
// ShippingRateValidationTest.php, and a bare, same-file-scoped dataset() call can NEVER be
// resolved from a different file -- Pest scopes it to the exact declaring file, not "the whole
// suite" -- so this test's own ->with('invalid_rate_attributes') below silently failed to
// resolve at all, surfacing only as a top-level PHPUnit ERROR with zero named tests run, never as
// a counted pass or fail. See tests/Feature/ShippingRates/Datasets.php for the real, working
// cross-file mechanism and the full explanation.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

afterEach(function () {
    // Phase 4 security-audit finding F-5's regression test below registers an `updating`
    // listener on ShippingRate to simulate a race -- flush it so it cannot leak into a later
    // test, matching tests/Feature/Products/ProductSkuUniquenessTest.php's identical shape.
    ShippingRate::flushEventListeners();
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

// =====================================================================
// Phase 4 security-audit finding F-1: UpdateShippingRate must NOT default an omitted
// min_weight_kg to '0' the way CreateShippingRate does -- on an update, an omitted key means
// "not being touched", never "reset to 0". Every caller submits the full attribute set (per this
// file's own convention above), so minWeightRules()'s pre-existing `required` rule is what must
// now reject the omission as a field-level error.
// =====================================================================

test('omitting min_weight_kg from an update payload fails validation instead of silently resetting it to 0', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo(['shipping.create', 'shipping.edit']);
    $this->actingAs($editor);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    // A non-zero min_weight_kg (a second-tier bracket) makes a silent reset-to-0 observable --
    // the baseline helper's rate already starts at 0, which would hide this exact bug.
    $rate = app(CreateShippingRate::class)([
        'name' => 'Tier 2',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '2',
        'max_weight_kg' => '5',
        'price' => '6.00',
        'delivery_estimate' => '24-48h',
    ]);

    $caught = null;

    try {
        app(UpdateShippingRate::class)($rate, [
            'name' => $rate->name,
            'shipping_carrier_id' => $rate->shipping_carrier_id,
            'shipping_zone_id' => $rate->shipping_zone_id,
            // min_weight_kg deliberately OMITTED.
            'max_weight_kg' => (string) $rate->max_weight_kg,
            'price' => '9.99',
            'delivery_estimate' => $rate->delivery_estimate,
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('min_weight_kg');

    // The row is byte-identical to before the refused attempt -- min_weight_kg was never
    // silently widened to 0, and max_weight_kg (the sibling field the widening would have
    // exposed via D-1's cheapest-wins tiebreak) is untouched too.
    $fresh = $rate->fresh();
    expect((string) $fresh->min_weight_kg)->toBe('2.000')
        ->and((string) $fresh->max_weight_kg)->toBe('5.000')
        ->and($fresh->price)->toBe('6.00');
});

// =====================================================================
// Phase 4 security-audit finding F-5: QueryException::formatMessage() appends the WHOLE UPDATE
// statement to the message, which mentions 'shipping_carrier_id' as a column name in EVERY
// update regardless of which FK actually failed -- so a str_contains() check keyed on the column
// name mis-attributed a ZONE fk failure to shipping_carrier_id. Reproduced here for real: the
// REPLACEMENT zone row is deleted from inside ShippingRate's own `updating` event -- which fires
// AFTER Rule::exists() validation has already passed against it, but immediately BEFORE the
// UPDATE statement runs -- so the resulting 1452 is a genuine zone-FK failure, never a
// carrier-FK one.
// =====================================================================

test('a shipping zone deleted between validation and the update is attributed to shipping_zone_id, not shipping_carrier_id', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();
    $originalZoneId = $rate->shipping_zone_id;
    $newZone = ShippingZone::factory()->create();

    $editor = User::factory()->create();
    $editor->givePermissionTo('shipping.edit');
    $this->actingAs($editor);

    ShippingRate::updating(function () use ($newZone): void {
        DB::table('shipping_zones')->where('id', $newZone->id)->delete();
    });

    $caught = null;

    try {
        app(UpdateShippingRate::class)($rate, [
            'name' => $rate->name,
            'shipping_carrier_id' => $rate->shipping_carrier_id,
            'shipping_zone_id' => $newZone->id,
            'min_weight_kg' => (string) $rate->min_weight_kg,
            'max_weight_kg' => (string) $rate->max_weight_kg,
            'price' => $rate->price,
            'delivery_estimate' => $rate->delivery_estimate,
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($caught->errors())->toHaveKey('shipping_zone_id')
        ->and($caught->errors())->not->toHaveKey('shipping_carrier_id');

    // $rate->shipping_zone_id itself is NOT the right comparison here: Eloquent's update()
    // calls fill() (mutating the in-memory instance to the new, now-deleted zone id) BEFORE the
    // failed UPDATE statement even runs, so the in-memory instance no longer reflects what
    // persisted. $originalZoneId, captured before the attempt, is the row's true un-persisted
    // value.
    expect($rate->fresh()->shipping_zone_id)->toBe($originalZoneId);
});

// =====================================================================
// Phase 4 RE-audit finding R-3: the F-5 fix above discriminates on the CONSTRAINT NAME rather
// than the column name -- but the FIRST version of that fix read the constraint name out of
// QueryException::getMessage(), which includes the WHOLE FORMATTED SQL WITH BOUND VALUES
// interpolated in. A rate whose `name` is set to the literal string
// 'shipping_rates_shipping_carrier_id_foreign' (well within the 150-char limit) made that literal
// string appear in the formatted UPDATE statement as DATA, indistinguishable to a str_contains()
// check from the real constraint name appearing there as SCHEMA -- so a genuine ZONE-fk failure
// was misattributed to shipping_carrier_id. Reproduced here with the identical updating() race
// the F-5 test above uses, PLUS a poisoned name.
// =====================================================================

test('a poisoned name equal to the FK constraint name does not defeat the F-5 field-attribution fix', function () {
    $rate = createBaselineShippingRateAsPrivilegedCreator();
    $newZone = ShippingZone::factory()->create();

    $editor = User::factory()->create();
    $editor->givePermissionTo('shipping.edit');
    $this->actingAs($editor);

    ShippingRate::updating(function () use ($newZone): void {
        DB::table('shipping_zones')->where('id', $newZone->id)->delete();
    });

    $caught = null;

    try {
        app(UpdateShippingRate::class)($rate, [
            // The poison: this string, if the fix ever regresses to matching against
            // $e->getMessage() (which interpolates bound values), makes the misattribution
            // reproduce even though the FK that actually failed is the ZONE one.
            'name' => 'shipping_rates_shipping_carrier_id_foreign',
            'shipping_carrier_id' => $rate->shipping_carrier_id,
            'shipping_zone_id' => $newZone->id,
            'min_weight_kg' => (string) $rate->min_weight_kg,
            'max_weight_kg' => (string) $rate->max_weight_kg,
            'price' => $rate->price,
            'delivery_estimate' => $rate->delivery_estimate,
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($caught->errors())->toHaveKey('shipping_zone_id')
        ->and($caught->errors())->not->toHaveKey('shipping_carrier_id');
});
