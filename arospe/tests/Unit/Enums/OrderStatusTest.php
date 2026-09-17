<?php

use App\Enums\OrderStatus;
use Tests\TestCase;

// Story 0045, Phase 3: App\Enums\OrderStatus already exists (scaffolded ahead of the write path),
// so the enum-shape tests below are expected to be GREEN already. The lang-key-parity test below,
// however, targets `lang/en/orders.php` / `lang/es/orders.php`, which do NOT exist yet -- that
// specific test is expected to fail (file not found / undefined array key) until backend-expert
// creates both lang files.
//
// N-4's resolution at story 0045: neither enum declared label() that story, expecting story 0055
// to add it as their first real consumer. Story 0047's own order-history screen is what actually
// renders OrderStatus first (a badge per order row, ahead of 0055), so it is the story that earned
// label() -- per the identical naming.md rule the deferral was made under. PaymentStatus still has
// no label() (OQ-1: payment_status is not rendered in this cut), so its own lang-key-parity test
// below still asserts directly against the raw array.
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

// Story 0047: OrderStatus::label() resolves the identical 'orders.statuses.<value>' key this
// file's own lang-key-parity test above already pins -- matching UserStatus::label()'s shape.
test('every OrderStatus case\'s label() resolves the matching statuses lang key', function () {
    foreach (OrderStatus::cases() as $case) {
        expect($case->label())->toBe(__('orders.statuses.'.$case->value))
            ->toBeString()->not->toBeEmpty();
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

// --- Story 0049, Phase 3 (TDD "red" step): rank()/isBackwardFrom() do not exist yet -- every test
// below is expected to fail with "Call to undefined method App\Enums\OrderStatus::rank()" (or
// "::isBackwardFrom()") until backend-expert adds both methods. Neither case-set test above is
// touched by this story -- no case is added, renamed or removed (the task file's own explicit
// instruction), so both stay green throughout.

test('rank returns the exact ladder position for each of the four linear statuses', function () {
    expect(OrderStatus::Pending->rank())->toBe(0)
        ->and(OrderStatus::Processing->rank())->toBe(1)
        ->and(OrderStatus::Shipped->rank())->toBe(2)
        ->and(OrderStatus::Delivered->rank())->toBe(3);
});

// R-1's mitigation: this is the test that makes "Cancelled has no rank" a property rather than a
// comment -- a later `self::Cancelled => 4` arm added to the match would pass every other test in
// this file while making this one go red.
test('Cancelled has no position on the ladder, so rank() raises UnhandledMatchError rather than inventing one', function () {
    expect(fn () => OrderStatus::Cancelled->rank())->toThrow(UnhandledMatchError::class);
});

// Dataset over all twelve ordered pairs among the four linear statuses plus the four identities --
// tested as a dataset rather than a handful of hand-picked cases, per the task file's own
// instruction. isBackwardFrom() is asked of the TARGET: $target->isBackwardFrom($current).
test('isBackwardFrom is true only when the target ranks lower than the current status', function (OrderStatus $target, OrderStatus $current, bool $expectedBackward) {
    expect($target->isBackwardFrom($current))->toBe($expectedBackward);
})->with([
    // Forward pairs (target ranks higher than current) -- never backward.
    'Pending -> Processing is not backward' => [OrderStatus::Processing, OrderStatus::Pending, false],
    'Pending -> Shipped is not backward' => [OrderStatus::Shipped, OrderStatus::Pending, false],
    'Pending -> Delivered is not backward' => [OrderStatus::Delivered, OrderStatus::Pending, false],
    'Processing -> Shipped is not backward' => [OrderStatus::Shipped, OrderStatus::Processing, false],
    'Processing -> Delivered is not backward' => [OrderStatus::Delivered, OrderStatus::Processing, false],
    'Shipped -> Delivered is not backward' => [OrderStatus::Delivered, OrderStatus::Shipped, false],

    // Backward pairs (target ranks lower than current) -- all backward.
    'Processing -> Pending is backward' => [OrderStatus::Pending, OrderStatus::Processing, true],
    'Shipped -> Pending is backward' => [OrderStatus::Pending, OrderStatus::Shipped, true],
    'Delivered -> Pending is backward' => [OrderStatus::Pending, OrderStatus::Delivered, true],
    'Shipped -> Processing is backward' => [OrderStatus::Processing, OrderStatus::Shipped, true],
    'Delivered -> Processing is backward' => [OrderStatus::Processing, OrderStatus::Delivered, true],
    'Delivered -> Shipped is backward' => [OrderStatus::Shipped, OrderStatus::Delivered, true],

    // Identities -- a status compared with itself is never backward.
    'Pending -> Pending is not backward' => [OrderStatus::Pending, OrderStatus::Pending, false],
    'Processing -> Processing is not backward' => [OrderStatus::Processing, OrderStatus::Processing, false],
    'Shipped -> Shipped is not backward' => [OrderStatus::Shipped, OrderStatus::Shipped, false],
    'Delivered -> Delivered is not backward' => [OrderStatus::Delivered, OrderStatus::Delivered, false],
]);
