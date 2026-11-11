<?php

use Illuminate\Support\Facades\Schema;

// Story 0033, Phase 3 (TDD "red" step): App\Actions\Shipping\CreateShippingZone and the
// shipping_zone_geography_entry migration do not exist yet -- every test below is expected to
// fail (class/table not found) until backend-expert/database-expert implement them in the next
// step of the TDD cycle. That is the correct, intended "red" outcome.
//
// Phase 2 correction item 1: this project's CI and local dev both run MySQL only
// (phpunit.xml:29 pins DB_CONNECTION=mysql, fixed 2026-08-26). The SQLite/MySQL engine-
// portability caveat this test's Phase 1 spec carried ("verify getForeignKeys() returns usefully
// on SQLite before relying on it; drop rather than make it MySQL-only") is stale text kept for
// its historical reasoning, not a live constraint here -- Phase 3 targets MySQL only, so
// Schema::getForeignKeys() is directly and reliably measurable.

test('the pivot reports exactly two foreign keys -- one to shipping_zones, one to geography_entries -- and none to anything else', function () {
    $foreignKeys = collect(Schema::getForeignKeys('shipping_zone_geography_entry'));

    expect($foreignKeys)->toHaveCount(2);

    $foreignTables = $foreignKeys->pluck('foreign_table')->sort()->values()->all();

    expect($foreignTables)->toBe(['geography_entries', 'shipping_zones']);
});
