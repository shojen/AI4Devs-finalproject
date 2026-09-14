<?php

use App\Enums\OrderStatus;
use Tests\TestCase;

// Story 0045, Phase 3: App\Enums\OrderStatus already exists (scaffolded ahead of the write path),
// so the enum-shape tests below are expected to be GREEN already. The lang-key-parity test below,
// however, targets `lang/en/orders.php` / `lang/es/orders.php`, which do NOT exist yet -- that
// specific test is expected to fail (file not found / undefined array key) until backend-expert
// creates both lang files.
//
// N-4's resolution: NEITHER enum declares label() this story -- story 0055 adds it as their first
// real consumer (docs/conventions/naming.md#translation-keys's "add label() when a second consumer
// appears" rule). So this file asserts the lang-file leaves DIRECTLY against the raw array,
// never through a label() method that does not exist.
//
// CI fix: `lang_path()` resolves through `app()->langPath()`, which needs the app container --
// bound per-file here rather than directory-wide, so this stays a `tests/Unit/` test (no
// RefreshDatabase, no database touched), matching `UserStatusTest.php`'s/`ProductStatusTest.php`'s
// existing precedent (docs/testing/backend/unit-tests.md). Without this, `app()` can return a bare
// `Illuminate\Container\Container` instead of the full `Application` depending on which other
// tests already ran in the same PHPUnit/paratest worker process -- this passed locally by
// accident of test ordering and failed under CI's real parallel distribution instead
// ("Call to undefined method Illuminate\Container\Container::langPath()").
uses(TestCase::class);

test('OrderStatus exposes exactly the cases PRD §3.2 names, with the expected backing values', function () {
    $expected = [
        'Pending' => 'pending',
        'Processing' => 'processing',
        'Shipped' => 'shipped',
        'Delivered' => 'delivered',
        'Cancelled' => 'cancelled',
    ];

    $actual = [];
    foreach (OrderStatus::cases() as $case) {
        $actual[$case->name] = $case->value;
    }

    expect($actual)->toEqualCanonicalizing($expected);
});

test('every OrderStatus case has a statuses leaf in both lang/en/orders.php and lang/es/orders.php, key-for-key identical', function () {
    $en = require lang_path('en/orders.php');
    $es = require lang_path('es/orders.php');

    expect($en)->toHaveKey('statuses')
        ->and($es)->toHaveKey('statuses');

    $expectedKeys = array_map(fn (OrderStatus $case): string => $case->value, OrderStatus::cases());

    expect(array_keys($en['statuses']))->toEqualCanonicalizing($expectedKeys)
        ->and(array_keys($es['statuses']))->toEqualCanonicalizing($expectedKeys)
        ->and(array_keys($en['statuses']))->toEqualCanonicalizing(array_keys($es['statuses']));

    foreach (OrderStatus::cases() as $case) {
        expect($en['statuses'][$case->value])->toBeString()->not->toBeEmpty();
        expect($es['statuses'][$case->value])->toBeString()->not->toBeEmpty();
    }
});

// Phase 5 code review finding F-E: `lang/{en,es}/orders.php`'s third group (`errors`, added at
// Phase 4 re-audit finding N-1 for CreateOrder::assertWithinColumnCeiling()'s message) had no
// parity test of its own -- only `statuses`/`payment_statuses` did. Lives here rather than in its
// own file since this is the same "both lang files, key-for-key identical" property the two enum
// tests already assert, just for a group with no backing enum to iterate.
test('the errors group in lang/en/orders.php and lang/es/orders.php is key-for-key identical', function () {
    $en = require lang_path('en/orders.php');
    $es = require lang_path('es/orders.php');

    expect($en)->toHaveKey('errors')
        ->and($es)->toHaveKey('errors');

    expect(array_keys($en['errors']))->toEqualCanonicalizing(array_keys($es['errors']));

    foreach (array_keys($en['errors']) as $key) {
        expect($en['errors'][$key])->toBeString()->not->toBeEmpty();
        expect($es['errors'][$key])->toBeString()->not->toBeEmpty();
    }
});
