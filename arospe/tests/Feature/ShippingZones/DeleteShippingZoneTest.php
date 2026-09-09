<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Actions\Shipping\CreateShippingZone;
use App\Actions\Shipping\DeleteShippingZone;
use App\Models\GeographyEntry;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Policies\ShippingZonePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Story 0033, Phase 3 (TDD "red" step) -- see CreateShippingZoneTest.php's file banner; the same
// applies here. App\Actions\Shipping\DeleteShippingZone::__invoke(ShippingZone $shippingZone):
// bool does not exist yet.
//
// Corrected at Phase 4 security audit (finding F-1) -- see CreateShippingZoneTest.php's own
// corrected banner. DeleteShippingZone now authorizes `delete` on $shippingZone as its own first
// statement, so every test below runs actingAs() an actor holding both `shipping.create` (used
// here only to set up fixtures) and `shipping.delete`, matching
// tests/Feature/ProductCategories/DeleteProductCategoryTest.php's identical fix.
//
// Story 0036, Phase 3 (TDD "red" step) MODIFICATION: App\Models\ShippingRate and the
// shipping_rates table do not exist yet either -- every test added below (from the un-skipped
// stub onward) is ALSO expected to fail (class/table not found) until database-expert/
// backend-expert implement them AND extend App\Actions\Shipping\DeleteShippingZone with D-5's
// in-use count guard (see this story's task file, D-5's 2026-09-09 correction: the QueryException
// catch is narrowed to errorInfo[1] === 1451, NEVER the whole 23000 SQLSTATE class).

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['shipping.create', 'shipping.delete']);
    $this->actingAs($this->actor);
});

// D-1: the in-use-by-a-rate-rule guard is CONFIRMED and its tests now live in the "story 0036 --
// the in-use-by-a-rate-rule guard (D-5), un-skipped" section further down this file (the stub
// that used to sit at the bottom, per R-8's naming rule, is gone -- un-skipping IS the point).

test('deleting a zone with no memberships removes the row outright, not a soft delete', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');

    $result = app(DeleteShippingZone::class)($zone);

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('shipping_zones', ['id' => $zone->id]);
});

// D-7: proves nothing lingers to hold the unique index -- exactly what a soft delete would break.
// Rule::unique() does not apply the soft-delete scope, so a trashed "Zona Norte" would squat its
// name forever if this model soft-deleted.
test('the freed name is immediately reusable', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');

    app(DeleteShippingZone::class)($zone);

    $recreated = app(CreateShippingZone::class)('Zona Norte');

    expect($recreated->fresh()->name)->toBe('Zona Norte')
        ->and(ShippingZone::where('name', 'Zona Norte')->count())->toBe(1);
});

function deleteShippingZoneFixtureWithMemberships(): array
{
    $country = GeographyEntry::factory()->create();
    $community = GeographyEntry::factory()->community($country)->create();
    $gijon = GeographyEntry::factory()->municipality($community)->create();
    $aviles = GeographyEntry::factory()->municipality($community)->create();

    $zone = app(CreateShippingZone::class)('Zona Norte');
    $zone->geographyEntries()->attach([$gijon->id, $aviles->id]);

    return [$zone, $gijon, $aviles];
}

// Assert the PIVOT TABLE directly, not $zone->geographyEntries() -- the relation would re-query
// through the (now-deleted) zone's own key and could read as empty for the wrong reason.
test('deleting a zone with memberships removes every pivot row for that zone', function () {
    [$zone] = deleteShippingZoneFixtureWithMemberships();
    $zoneId = $zone->id;

    app(DeleteShippingZone::class)($zone);

    $this->assertDatabaseCount('shipping_zone_geography_entry', 0);
    expect(DB::table('shipping_zone_geography_entry')->where('shipping_zone_id', $zoneId)->exists())->toBeFalse();
});

// Highest-severity test in the story (task's own words): the distance between
// $zone->geographyEntries()->detach() and ->delete() is one word, and the second hard-deletes
// seeded catalog rows another story owns. Assert the EXACT ids still present, not a count() --
// a count passes if rows were deleted and recreated; ids do not.
test('deleting a zone leaves geography_entries completely untouched', function () {
    [$zone, $gijon, $aviles] = deleteShippingZoneFixtureWithMemberships();

    $idsBefore = GeographyEntry::query()->pluck('id')->sort()->values()->all();

    app(DeleteShippingZone::class)($zone);

    $idsAfter = GeographyEntry::query()->pluck('id')->sort()->values()->all();

    expect($idsAfter)->toBe($idsBefore)
        ->and(GeographyEntry::query()->whereKey($gijon->id)->exists())->toBeTrue()
        ->and(GeographyEntry::query()->whereKey($aviles->id)->exists())->toBeTrue();
});

// Guards a detach scoped by entry instead of by zone -- the same mistake D-2's overlap test
// catches from the other side.
test('deleting zone A does not strip a shared entry from zone B', function () {
    $country = GeographyEntry::factory()->create();
    $community = GeographyEntry::factory()->community($country)->create();
    $gijon = GeographyEntry::factory()->municipality($community)->create();

    $zoneA = app(CreateShippingZone::class)('Zona Norte');
    $zoneB = app(CreateShippingZone::class)('Asturias Centro');

    $zoneA->geographyEntries()->attach($gijon->id);
    $zoneB->geographyEntries()->attach($gijon->id);

    app(DeleteShippingZone::class)($zoneA);

    expect($zoneB->geographyEntries()->pluck('geography_entries.id')->all())->toBe([$gijon->id]);
});

test('deleting an unknown zone id fails cleanly with ModelNotFoundException, not a silent no-op', function () {
    app(CreateShippingZone::class)('Zona Norte');

    expect(fn () => ShippingZone::findOrFail((string) Str::uuid7()))
        ->toThrow(ModelNotFoundException::class);
});

// This story has no route/HTTP layer at all, so HasUuids' route-model-binding UUID validation
// (resolveRouteBindingQuery() rejecting a non-UUID parameter with Str::isUuid() before running a
// doomed query) is never invoked here -- findOrFail() below runs a real `WHERE id = ?` query that
// simply finds no matching row for the malformed string, throwing ModelNotFoundException for that
// ordinary reason. A malformed id must still fail the identical way as an unknown-but-valid one,
// never as a different error shape a future controller/component would have to special-case.
test('deleting a malformed, non-UUID zone id fails cleanly with ModelNotFoundException', function () {
    app(CreateShippingZone::class)('Zona Norte');

    expect(fn () => ShippingZone::findOrFail('not-a-uuid'))
        ->toThrow(ModelNotFoundException::class);
});

// =====================================================================
// Story 0036 -- the in-use-by-a-rate-rule guard (D-5), un-skipped.
// =====================================================================

/**
 * Creates $count rate rules on $zone, for a fresh carrier of its own (or $carrier when given).
 * Returns the created ShippingRate models.
 *
 * @return array<int, ShippingRate>
 */
function attachRatesToZone(ShippingZone $zone, int $count, ?ShippingCarrier $carrier = null): array
{
    $carrier ??= ShippingCarrier::factory()->create();

    $rates = [];

    for ($i = 0; $i < $count; $i++) {
        $rates[] = app(CreateShippingRate::class)([
            'name' => "Rate {$i}",
            'shipping_carrier_id' => $carrier->id,
            'shipping_zone_id' => $zone->id,
            'min_weight_kg' => '0',
            'max_weight_kg' => '2',
            'price' => '4.95',
            'delivery_estimate' => '24-48h',
        ]);
    }

    return $rates;
}

/**
 * A ShippingZone whose deleteOrFail() is overridden to throw a QueryException carrying the given
 * MySQL errorInfo[1] code -- a partial double, per this story's own D-5 test breakdown ("part 2:
 * the action translates it" / "part 3: the narrowing is real, not incidental").
 *
 * Gate::policy() is registered explicitly against the double's own (anonymous) class name --
 * Laravel's policy auto-discovery (guessPolicyName()) resolves purely from get_class($target),
 * which for an anonymous subclass never matches App\Models\ShippingZone, so without this explicit
 * registration Gate::authorize('delete', $double, ...) would find no policy at all and refuse
 * every actor, masking the very behaviour these tests exist to exercise.
 *
 * `protected $table` is set explicitly for the identical reason: Eloquent's default
 * Model::getTable() derives the table name from class_basename($this) when $table isn't set, and
 * class_basename() on an anonymous class returns its generated (path-and-hash-bearing) name, not
 * "ShippingZone" -- reproduced by direct execution (tinker) before this line existed: without it,
 * every query this double issues targets a nonsense table name and throws its OWN QueryException,
 * which would have masked the simulated one below and made every test using this helper fail for
 * the wrong reason.
 */
function shippingZoneDeleteFailureDouble(ShippingZone $zone, int $simulatedMysqlErrorCode): ShippingZone
{
    $double = new class extends ShippingZone
    {
        protected $table = 'shipping_zones';

        public int $simulatedErrorCode = 0;

        public function deleteOrFail()
        {
            $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: simulated');
            $previous->errorInfo = ['23000', $this->simulatedErrorCode, 'Simulated constraint violation'];

            throw new QueryException(
                'mysql',
                'delete from `shipping_zones` where `id` = ?',
                [$this->getKey()],
                $previous,
            );
        }
    };

    $double->setRawAttributes($zone->getAttributes(), true);
    $double->exists = true;
    $double->simulatedErrorCode = $simulatedMysqlErrorCode;

    Gate::policy($double::class, ShippingZonePolicy::class);

    return $double;
}

// 0033's explicit obligation: a guard hardcoding "1 shipping rate" would pass any single-fixture
// test -- driven over 1, 2 and 7 distinct reference counts.
test('the count is correct for at least two distinct reference counts', function (int $count) {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    attachRatesToZone($zone, $count);

    $caught = null;

    try {
        app(DeleteShippingZone::class)($zone);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('shippingZoneId')
        ->and($caught->errors()['shippingZoneId'][0])->toContain((string) $count);

    $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
})->with([1, 2, 7]);

// Guards a trans_choice string written with one branch (and its Spanish counterpart, R-6) --
// asserted by DIFFERENCE, not by a literal copy of the exact English wording, since the wording
// itself is backend-expert's to write.
test('the singular and plural blocked-deletion message forms genuinely differ', function () {
    $zoneWithOne = app(CreateShippingZone::class)('Zona Uno');
    attachRatesToZone($zoneWithOne, 1);

    $zoneWithTwo = app(CreateShippingZone::class)('Zona Dos');
    attachRatesToZone($zoneWithTwo, 2);

    $messageForOne = null;

    try {
        app(DeleteShippingZone::class)($zoneWithOne);
    } catch (ValidationException $e) {
        $messageForOne = $e->errors()['shippingZoneId'][0];
    }

    $messageForTwo = null;

    try {
        app(DeleteShippingZone::class)($zoneWithTwo);
    } catch (ValidationException $e) {
        $messageForTwo = $e->errors()['shippingZoneId'][0];
    }

    expect($messageForOne)->not->toBeNull()
        ->and($messageForTwo)->not->toBeNull()
        ->and($messageForOne)->not->toBe($messageForTwo);
});

// D-5: the count is UNFILTERED -- it spans every carrier's rates, not one carrier's.
test('the count spans carriers, not just one', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $seur = ShippingCarrier::factory()->create();
    $correos = ShippingCarrier::factory()->create();

    attachRatesToZone($zone, 2, $seur);
    attachRatesToZone($zone, 3, $correos);

    $caught = null;

    try {
        app(DeleteShippingZone::class)($zone);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors()['shippingZoneId'][0])->toContain('5');
});

// R-3/D-5: highest-value single assertion in the guard -- filtering by is_active reads as a
// sensible optimisation and destroys a disabled carrier's configuration. A rate belonging to the
// disabled carrier "MRW" must still count.
test('the count includes a disabled carriers rates too', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $mrw = ShippingCarrier::factory()->inactive()->create(['name' => 'MRW']);

    attachRatesToZone($zone, 1, $mrw);

    $caught = null;

    try {
        app(DeleteShippingZone::class)($zone);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
});

// A blocked deletion destroys nothing -- assert EXACT ids, not count() (0033's rule: a count
// passes if rows were deleted and recreated).
test('a blocked deletion leaves every rate row unchanged', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $rates = attachRatesToZone($zone, 7);
    $idsBefore = collect($rates)->pluck('id')->sort()->values()->all();

    try {
        app(DeleteShippingZone::class)($zone);
    } catch (Throwable) {
        //
    }

    $idsAfter = ShippingRate::query()->pluck('id')->sort()->values()->all();

    expect($idsAfter)->toBe($idsBefore);
});

// R-10: a global (unscoped) count query passes a single-zone fixture identically to a correctly
// per-zone-scoped query, and then blocks EVERY zone deletion forever once any rate exists
// anywhere. Only a second, unreferenced zone that must still delete successfully catches this.
test('a second zone with zero referencing rates deletes successfully, in the same test file as a blocked one', function () {
    $blockedZone = app(CreateShippingZone::class)('Zona Bloqueada');
    attachRatesToZone($blockedZone, 3);

    $freeZone = app(CreateShippingZone::class)('Zona Libre');

    expect(fn () => app(DeleteShippingZone::class)($blockedZone))
        ->toThrow(ValidationException::class);

    $result = app(DeleteShippingZone::class)($freeZone);

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('shipping_zones', ['id' => $freeZone->id]);
});

test('deleting the last referencing rate releases the zone, which then deletes normally', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    [$onlyRate] = attachRatesToZone($zone, 1);

    expect(fn () => app(DeleteShippingZone::class)($zone))
        ->toThrow(ValidationException::class);

    $onlyRate->delete();

    $result = app(DeleteShippingZone::class)($zone->fresh());

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('shipping_zones', ['id' => $zone->id]);
});

// A SEPARATE test from the one above -- they are different call sites into the same count, and
// reassignment is the path the error message actually instructs the administrator to take.
test('reassigning the last referencing rate to another zone also releases the original zone', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $otherZone = app(CreateShippingZone::class)('Zona Sur');
    [$onlyRate] = attachRatesToZone($zone, 1);

    expect(fn () => app(DeleteShippingZone::class)($zone))
        ->toThrow(ValidationException::class);

    $onlyRate->update(['shipping_zone_id' => $otherZone->id]);

    $result = app(DeleteShippingZone::class)($zone->fresh());

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('shipping_zones', ['id' => $zone->id]);
    $this->assertDatabaseHas('shipping_rates', ['id' => $onlyRate->id, 'shipping_zone_id' => $otherZone->id]);
});

// A guard that half-executes would be worse than one that fails outright.
test('the zones geography membership rows survive a blocked delete', function () {
    $country = GeographyEntry::factory()->create();
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $zone->geographyEntries()->attach($country->id);
    attachRatesToZone($zone, 2);

    try {
        app(DeleteShippingZone::class)($zone);
    } catch (Throwable) {
        //
    }

    $this->assertDatabaseHas('shipping_zone_geography_entry', [
        'shipping_zone_id' => $zone->id,
        'geography_entry_id' => $country->id,
    ]);
});

// =====================================================================
// The three-part 1451 breakdown (D-5's 2026-09-09 correction): real two-connection concurrency
// is not reproducible, so this is split into exactly what IS.
// =====================================================================

// Part 1: the constraint fires. Bypass the application entirely and assert a QueryException --
// proves restrictOnDelete() exists and is enforced, and doubles as a regression test for anyone
// "simplifying" the FK to cascade.
test('the database constraint itself fires when a zone still referenced by a rate is deleted directly', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    attachRatesToZone($zone, 1);

    expect(fn () => DB::table('shipping_zones')->where('id', $zone->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
});

// Part 2: the action translates it. A partial double whose deleteOrFail() throws a QueryException
// carrying errorInfo[1] === 1451 must surface the SAME ValidationException shape the count guard
// produces -- never a 500. No HTTP layer needed; the action is a plain __invoke(ShippingZone).
test('the action translates a 1451 QueryException into the same ValidationException shape the count guard produces', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $double = shippingZoneDeleteFailureDouble($zone, 1451);

    $caught = null;

    try {
        app(DeleteShippingZone::class)($double);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('shippingZoneId');
});

// Part 3 (added by D-5's 2026-09-09 correction): the narrowing is REAL, not incidental. A 1062
// (duplicate entry -- same 23000 SQLSTATE class, but a completely different failure) must
// propagate UNCHANGED, never be misreported as a shipping-rate count. Without this assertion, a
// catch written as `getCode() === '23000'` passes every other bullet in this file.
test('a 1062 QueryException in the same SQLSTATE class propagates unchanged, proving the narrowing to 1451 is real', function () {
    $zone = app(CreateShippingZone::class)('Zona Norte');
    $double = shippingZoneDeleteFailureDouble($zone, 1062);

    $caught = null;

    try {
        app(DeleteShippingZone::class)($double);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught)->not->toBeInstanceOf(ValidationException::class);
});
