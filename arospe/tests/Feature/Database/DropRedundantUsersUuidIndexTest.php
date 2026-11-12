<?php

use Illuminate\Support\Facades\Schema;

// Task 0014: the UUID primary-key conversion (2026_07_22_100001..100005_*.php) left `users`
// carrying both a `PRIMARY` index and a redundant `users_uuid_unique` unique index on the same
// `id` column — confirmed empirically in task 0003 (see docs/errors-log.md's 2026-08-12 entry).
// This suite proves the cleanup migration removes the redundant index and nothing else. MySQL is
// required (not SQLite) because the index-introspection this asserts on is what the redundant index actually is — see docs/database/schema.md.

// A local closure, not a global `function` — this repo's one precedent for a shared test helper
// (tests/Pest.php's "Functions" section) is a suite-wide surface, and this introspection is
// specific to this one file; a top-level `function` declared here would instead be a *global*
// PHP symbol with no file scoping, so a same-named helper added to another test file later would
// fatally redeclare it.
/**
 * @return array<int, array{name: string, columns: array<int, string>, type: string, unique: bool, primary: bool}>
 */
$usersIndexesOnId = fn (): array => collect(Schema::getIndexes('users'))
    ->filter(fn (array $index): bool => in_array('id', $index['columns'], true))
    ->values()
    ->all();

test('the users table carries exactly one index on id after migrating, the PRIMARY', function () use ($usersIndexesOnId) {
    $indexesOnId = $usersIndexesOnId();

    expect($indexesOnId)->toHaveCount(1);
    expect($indexesOnId[0]['name'])->toBe('primary');
    expect($indexesOnId[0]['primary'])->toBeTrue();
    expect(collect(Schema::getIndexes('users'))->pluck('name'))->not->toContain('users_uuid_unique');
});
