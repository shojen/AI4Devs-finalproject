<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): App\Models\ShippingRate,
// App\Actions\Shipping\CreateShippingRate, the shipping_rates migration and
// App\Concerns\ShippingRateValidationRules do not exist yet -- every test below is expected to
// fail (class/table not found) until database-expert/backend-expert implement them in the next
// step of the TDD cycle. That failure is the correct, intended "red" outcome.
//
// D-11 (Phase 2 review finding B1, made normative): CreateShippingRate self-authorizes `create`
// on ShippingRate::class as its own first statement, via the injected
// LogRefusedPrivilegedAttempt, the identical self-authorizing shape
// App\Actions\Shipping\CreateShippingZone and App\Actions\ProductCategories\CreateProductCategory
// already use. Every positive test below therefore runs actingAs() an actor holding
// `shipping.create`, matching CreateShippingZoneTest.php's own shape, or the call throws
// AuthorizationException before validation ever runs.
//
// Every assertion goes through the ACTION directly (`app(CreateShippingRate::class)(...)`), never
// a Livewire component -- D-10 is explicit this story ships no screen at all.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

afterEach(function () {
    // Phase 4 security-audit finding F-5's regression test below registers a `creating`
    // listener on ShippingRate to simulate a race -- flush it so it cannot leak into a later
    // test, matching tests/Feature/Products/ProductSkuUniquenessTest.php's identical shape.
    ShippingRate::flushEventListeners();
});

function actingShippingRateCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('shipping.create');
    test()->actingAs($actor);

    return $actor;
}

// PRD's own "Create a rate rule for a carrier" scenario -- every field round-trips.
test('a shipping administrator creates a rate for an active carrier and zone, and every field round-trips', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create(['name' => 'SEUR']);
    $zone = ShippingZone::factory()->create(['name' => 'Península']);

    $rate = app(CreateShippingRate::class)([
        'name' => 'Estándar',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);

    expect(ShippingRate::count())->toBe(1);

    $fresh = $rate->fresh();

    expect($fresh->name)->toBe('Estándar')
        ->and($fresh->shipping_carrier_id)->toBe($carrier->id)
        ->and($fresh->shipping_zone_id)->toBe($zone->id)
        ->and((string) $fresh->min_weight_kg)->toBe('0.000')
        ->and((string) $fresh->max_weight_kg)->toBe('2.000')
        ->and($fresh->price)->toBe('4.95')
        ->and($fresh->delivery_estimate)->toBe('24-48h');
});

// D-4: null means "and above" -- an open-ended top tier is a real carrier configuration, and a
// rate created with no max_weight_kg must persist a genuine NULL, never 0 or a sentinel.
test('a rate created with no max_weight_kg persists null, the open-ended "and above" tier', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Express',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '5',
        'price' => '12.00',
        'delivery_estimate' => '24h',
    ]);

    expect($rate->fresh()->max_weight_kg)->toBeNull();
});

// D-7: min_weight_kg's column default is 0, matching the prototype's own wmin: '0'.
test('min_weight_kg defaults to 0 when omitted', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Base',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'max_weight_kg' => '2',
        'price' => '3.00',
        'delivery_estimate' => '24h',
    ]);

    expect((string) $rate->fresh()->min_weight_kg)->toBe('0.000');
});

// D-9: no uniqueness anywhere on this table -- the prototype ships four rows named "Estándar"
// across different carriers. A unique name (globally, or scoped to carrier+zone) would reject the
// PRD's own reference configuration.
test('two rates may share a name across different carriers, and within the same carrier and zone', function () {
    actingShippingRateCreator();

    $seur = ShippingCarrier::factory()->create();
    $correos = ShippingCarrier::factory()->create();
    $peninsula = ShippingZone::factory()->create();
    $baleares = ShippingZone::factory()->create();

    app(CreateShippingRate::class)([
        'name' => 'Estándar',
        'shipping_carrier_id' => $seur->id,
        'shipping_zone_id' => $peninsula->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);

    app(CreateShippingRate::class)([
        'name' => 'Estándar',
        'shipping_carrier_id' => $correos->id,
        'shipping_zone_id' => $baleares->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '5.95',
        'delivery_estimate' => '48-72h',
    ]);

    // Two named services on the same carrier + zone + bracket, at different prices (D-2/D-9).
    app(CreateShippingRate::class)([
        'name' => 'Estándar',
        'shipping_carrier_id' => $seur->id,
        'shipping_zone_id' => $peninsula->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '6.50',
        'delivery_estimate' => '24h',
    ]);

    expect(ShippingRate::where('name', 'Estándar')->count())->toBe(3);
});

// 0024 R-4: `decimal:2` returns a STRING. A value-only assertion passes against either a string
// or a float cast and lets the drift ship. `0.00` must persist as a legal free rate -- `min:0`,
// never `gt:0`.
test('price reads back as a string with two decimals, and 0.00 persists as a legal free rate', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Gratis',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '1',
        'price' => '0',
        'delivery_estimate' => '24h',
    ]);

    $fresh = $rate->fresh();

    expect($fresh->price)->toBeString()
        ->and($fresh->price)->toBe('0.00');
});

// D-6's corollary: rates are configuration, `is_active` governs RESOLUTION only, never authoring.
// Refusing would create a chicken-and-egg for onboarding a carrier (configure its rates, then
// switch it on).
test('a rate may be created for a currently disabled carrier', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->inactive()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Onboarding',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);

    expect($rate->fresh())->not->toBeNull();
    expect(ShippingCarrier::find($carrier->id)->is_active)->toBeFalse();
});

// A malformed, non-UUID id is a validation failure (the `uuid` rule), distinct from a well-formed
// id that simply does not exist (covered by the invalid_rate_attributes dataset's
// unknown_carrier/unknown_zone cases in ShippingRateValidationTest.php) -- neither may surface as
// a 500.
test('a malformed, non-UUID shipping_carrier_id is refused as a validation failure, not a 500', function () {
    actingShippingRateCreator();

    $zone = ShippingZone::factory()->create();

    $caught = null;

    try {
        app(CreateShippingRate::class)([
            'name' => 'Estándar',
            'shipping_carrier_id' => 'not-a-uuid',
            'shipping_zone_id' => $zone->id,
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('shipping_carrier_id');
    expect(ShippingRate::count())->toBe(0);
});

test('a malformed, non-UUID shipping_zone_id is refused as a validation failure, not a 500', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create();

    $caught = null;

    try {
        app(CreateShippingRate::class)([
            'name' => 'Estándar',
            'shipping_carrier_id' => $carrier->id,
            'shipping_zone_id' => 'not-a-uuid',
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('shipping_zone_id');
    expect(ShippingRate::count())->toBe(0);
});

// D-11: the Gherkin "A user without the shipping create permission cannot create a rate rule".
// There is no route and no component in this story, so the action is the ONLY reachable
// enforcement point -- the whole reason D-11 requires it to self-authorize. Asserts BOTH halves:
// a gate that throws AFTER the insert passes a one-assertion test.
test('a signed-in user without shipping.create is refused, and no row is created', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.edit', 'shipping.delete']);
    $this->actingAs($actor);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $caught = null;

    try {
        app(CreateShippingRate::class)([
            'name' => 'Estándar',
            'shipping_carrier_id' => $carrier->id,
            'shipping_zone_id' => $zone->id,
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    expect(ShippingRate::count())->toBe(0);
});

// An omitted targetType degrades the audit line silently and is invisible to the refusal test
// above -- matching tests/Feature/ShippingZones/RefusalLoggingTest.php's precedent shape.
test('a refusal is logged with target_type shipping_rate and no target_id (class-scoped ability)', function () {
    Log::spy();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    try {
        app(CreateShippingRate::class)([
            'name' => 'Estándar',
            'shipping_carrier_id' => $carrier->id,
            'shipping_zone_id' => $zone->id,
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'create'
            && ($context['target_type'] ?? null) === 'shipping_rate'
            && array_key_exists('target_id', $context) && $context['target_id'] === null)
        ->once();
});

// The allow half of the pair -- without it, the refusal test above passes against an action that
// refuses EVERYONE.
test('a holder of shipping.create succeeds, as the control', function () {
    actingShippingRateCreator();

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

    expect(ShippingRate::count())->toBe(1)
        ->and($rate->fresh())->not->toBeNull();
});

// Phase 4 security-audit finding F-5: QueryException::formatMessage() appends the WHOLE INSERT
// statement to the message, which mentions 'shipping_carrier_id' as a column name in EVERY
// insert regardless of which FK actually failed -- so a str_contains() check keyed on the column
// name mis-attributed a ZONE fk failure to shipping_carrier_id. Reproduced here for real: the
// zone row is deleted from inside ShippingRate's own `creating` event -- which fires AFTER
// Rule::exists() validation has already passed against it, but immediately BEFORE the INSERT
// statement runs -- so the resulting 1452 is a genuine zone-FK failure, never a carrier-FK one,
// and the assertion below would fail against the pre-fix column-name heuristic.
test('a shipping zone deleted between validation and the insert is attributed to shipping_zone_id, not shipping_carrier_id', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    ShippingRate::creating(function () use ($zone): void {
        DB::table('shipping_zones')->where('id', $zone->id)->delete();
    });

    $caught = null;

    try {
        app(CreateShippingRate::class)([
            'name' => 'Estándar',
            'shipping_carrier_id' => $carrier->id,
            'shipping_zone_id' => $zone->id,
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($caught->errors())->toHaveKey('shipping_zone_id')
        ->and($caught->errors())->not->toHaveKey('shipping_carrier_id');
    expect(ShippingRate::count())->toBe(0);
});

// Phase 4 RE-audit finding R-3: the F-5 fix above discriminates on the CONSTRAINT NAME rather
// than the column name -- but the FIRST version of that fix read the constraint name out of
// QueryException::getMessage(), which includes the WHOLE FORMATTED SQL WITH BOUND VALUES
// interpolated in. A rate submitted with `name` set to the literal string
// 'shipping_rates_shipping_carrier_id_foreign' (well within the 150-char limit) made that
// literal string appear in the formatted INSERT statement as DATA, which the str_contains()
// check could not tell apart from the real constraint name appearing there as SCHEMA -- so a
// genuine ZONE-fk failure was misattributed to shipping_carrier_id. Reproduced here with the
// identical creating() race the F-5 test above uses, PLUS a poisoned name, and the fix (reading
// $e->getPrevious()?->getMessage() -- the raw PDOException, which carries no interpolated
// bindings) must still attribute this correctly to shipping_zone_id.
test('a poisoned name equal to the FK constraint name does not defeat the F-5 field-attribution fix', function () {
    actingShippingRateCreator();

    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    ShippingRate::creating(function () use ($zone): void {
        DB::table('shipping_zones')->where('id', $zone->id)->delete();
    });

    $caught = null;

    try {
        app(CreateShippingRate::class)([
            // The poison: this string, if the fix ever regresses to matching against
            // $e->getMessage() (which interpolates bound values), makes the misattribution
            // reproduce even though the FK that actually failed is the ZONE one.
            'name' => 'shipping_rates_shipping_carrier_id_foreign',
            'shipping_carrier_id' => $carrier->id,
            'shipping_zone_id' => $zone->id,
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect($caught->errors())->toHaveKey('shipping_zone_id')
        ->and($caught->errors())->not->toHaveKey('shipping_carrier_id');
    expect(ShippingRate::count())->toBe(0);
});
