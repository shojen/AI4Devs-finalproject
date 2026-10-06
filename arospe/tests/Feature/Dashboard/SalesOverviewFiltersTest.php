<?php

// Story 0086 -- the filter bar: granularity, presets, custom range, status chips, caps, refusals and
// the event the Alpine charts listen to. The first three tests are the story's SPIKES (D-2/D-4 + R-1):
//   1. (SalesOverviewUrlTest) a lazy child reads the page's query string;
//   2. a refused change keeps the property's previous value (the `updating*` hook throws before the
//      assignment) -- fallback if red: a validated public `applied*` copy of the four values that
//      the computeds read;
//   3. the dispatched `sales-overview-updated` event carries the documented scalar payload.
//
// "Today" is 2026-06-15 (Europe/Madrid) unless a test says otherwise. Red step: the component does
// not exist yet.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;
use Tests\Support\Dashboard\SalesOverviewUi as Sales;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(Ui::actor(['orders.view']));
    Sales::today();
});

// =====================================================================
// Spikes
// =====================================================================

// SPIKE 2 outcome: Livewire 4 swallows a ValidationException thrown from an updating* hook and assigns the
// value anyway, so the documented fallback was adopted (updating() validates and records the previous value,
// updated() restores it, nothing is dispatched). The observable behaviour asserted here is unchanged.
it('SPIKE 2: a refused change keeps the property previous value, shows the error and renders the previous data', function () {
    Sales::order('2026-06-10 10:00:00', '75.00');

    $component = Sales::component()
        ->set('from', '2026-06-10')
        ->assertSet('from', '2026-06-10');

    $before = Sales::view($component);

    $component
        ->set('to', '2026-06-01')   // before `from`: the action's range_invalid
        ->assertHasErrors('range')
        ->assertSet('to', '')
        ->assertSet('from', '2026-06-10')
        ->assertNotDispatched('sales-overview-updated');

    expect($component->errors()->first('range'))->toBe(__('dashboard.errors.range_invalid'))
        ->and(Sales::view($component))->toBe($before)
        ->and(Sales::kpi($component->html(), 'sales'))->toBe('€ 75.00');
});

it('SPIKE 3: a valid change dispatches sales-overview-updated with the scalar payload for both charts', function () {
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-06-10 11:00:00', '60.00', OrderStatus::Pending);

    $component = Sales::component()->set('granularity', 'month');

    $component->assertDispatched('sales-overview-updated');

    $payload = Sales::lastPayload($component);

    expect($payload)->toBeArray()
        ->and(array_keys($payload))->toEqualCanonicalizing(['locale', 'granularity', 'money', 'orders'])
        ->and($payload['locale'])->toBe('en')
        ->and($payload['granularity'])->toBe('month')
        ->and(array_keys($payload['money']))->toEqualCanonicalizing(['labels', 'sales', 'income'])
        ->and($payload['money']['labels'])->toHaveCount(12)
        ->and($payload['money']['sales'])->toHaveCount(12)
        ->and($payload['money']['income'])->toHaveCount(12)
        ->and($payload['orders']['labels'])->toBe($payload['money']['labels'])
        // numbers are for plotting only (floats; the JSON effect may return whole ones as ints); the last bucket is June 2026
        ->and($payload['money']['sales'][11])->toEqual(160)
        ->and($payload['money']['income'][11])->toEqual(100)
        ->and($payload['money']['sales'][0])->toEqual(0);

    $datasets = $payload['orders']['datasets'];

    expect(array_column($datasets, 'status'))->toBe(Sales::defaultStatusValues())
        ->and(array_column($datasets, 'label'))->toBe(array_map(
            fn (OrderStatus $status): string => $status->label(),
            OrderStatus::defaultDashboardSet(),
        ))
        ->and($datasets[0]['data'])->toHaveCount(12)
        ->and($datasets[0]['data'][11])->toBe(1)   // pending
        ->and($datasets[3]['data'][11])->toBe(1);  // delivered
});

it('dispatches nothing when the component is first mounted (initial data travels through x-data)', function () {
    Sales::component()->assertNotDispatched('sales-overview-updated');
});

it('SPIKE 3b: the payload has one dataset per selected status, in canonical order', function () {
    $component = Sales::component(['s' => ['shipped']])
        ->call('toggleStatus', 'pending');

    expect(array_column(Sales::lastPayload($component)['orders']['datasets'], 'status'))->toBe(['pending', 'shipped']);
});

// =====================================================================
// Granularity
// =====================================================================

it('switches the granularity to that granularity default range', function (string $granularity, int $points, string $first, string $last) {
    $component = Sales::component()->set('granularity', $granularity);

    $buckets = Sales::buckets($component->html());

    $component->assertSet('granularity', $granularity)->assertSet('from', '')->assertSet('to', '');

    // numeric keys such as '2022' are cast to int by PHP arrays, so compare as strings
    $buckets = array_map('strval', $buckets);

    expect($buckets)->toHaveCount($points)
        ->and($buckets[0])->toBe($first)
        ->and($buckets[$points - 1])->toBe($last);
})->with([
    'day' => ['day', 30, '2026-05-17', '2026-06-15'],
    'month' => ['month', 12, '2025-07', '2026-06'],
    'year' => ['year', 5, '2022', '2026'],
]);

it('resets a custom range when the granularity changes', function () {
    $component = Sales::component()
        ->set('from', '2026-05-01')
        ->set('to', '2026-05-15')
        ->set('granularity', 'month');

    $component->assertSet('from', '')->assertSet('to', '');

    expect(Sales::buckets($component->html()))->toHaveCount(12);
});

it('refuses an unknown granularity and keeps the previous one', function () {
    $component = Sales::component()
        ->set('granularity', 'week')
        ->assertHasErrors()
        ->assertSet('granularity', 'day')
        ->assertNotDispatched('sales-overview-updated');

    expect(Sales::buckets($component->html()))->toHaveCount(30);
});

// =====================================================================
// Presets
// =====================================================================

it('a preset sets the range and the granularity', function (string $preset, string $granularity, string $from, string $to, int $points, string $first, string $last) {
    $component = Sales::component()->call('applyPreset', $preset);

    $buckets = Sales::buckets($component->html());

    $component
        ->assertSet('granularity', $granularity)
        ->assertSet('from', $from)
        ->assertSet('to', $to)
        ->assertDispatched('sales-overview-updated');

    expect($buckets)->toHaveCount($points)
        ->and($buckets[0])->toBe($first)
        ->and($buckets[$points - 1])->toBe($last);
})->with([
    'last 7 days' => ['last_7_days', 'day', '2026-06-09', '2026-06-15', 7, '2026-06-09', '2026-06-15'],
    'last 30 days' => ['last_30_days', 'day', '2026-05-17', '2026-06-15', 30, '2026-05-17', '2026-06-15'],
    'this month' => ['this_month', 'day', '2026-06-01', '2026-06-15', 15, '2026-06-01', '2026-06-15'],
    'this year' => ['this_year', 'month', '2026-01-01', '2026-06-15', 6, '2026-01', '2026-06'],
]);

it('a preset replaces a previous custom range and granularity', function () {
    $component = Sales::component(['g' => 'year'])->call('applyPreset', 'last_7_days');

    $component->assertSet('granularity', 'day');

    expect(Sales::buckets($component->html()))->toHaveCount(7);
});

it('ignores an unknown preset without changing anything', function () {
    $component = Sales::component()->call('applyPreset', 'last_century');

    $component->assertSet('granularity', 'day')->assertSet('from', '')->assertSet('to', '');

    expect(Sales::buckets($component->html()))->toHaveCount(30);
});

// =====================================================================
// Custom range
// =====================================================================

it('a custom range shows only those days, including an order at 23:59:59 on the last day', function () {
    Sales::order('2026-05-15 23:59:59', '10.00');   // in
    Sales::order('2026-05-16 00:00:00', '20.00');   // out
    Sales::order('2026-04-30 23:59:59', '40.00');   // out
    Sales::order('2026-05-01 00:00:00', '5.00');    // in, first second

    $component = Sales::component()
        ->set('from', '2026-05-01')
        ->set('to', '2026-05-15');

    $html = $component->html();
    $buckets = Sales::buckets($html);

    expect($buckets)->toHaveCount(15)
        ->and($buckets[0])->toBe('2026-05-01')
        ->and($buckets[14])->toBe('2026-05-15')
        ->and(Sales::kpi($html, 'sales'))->toBe('€ 15.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('2');
});

it('clearing a date goes back to the default range for the granularity', function () {
    $component = Sales::component()
        ->set('from', '2026-05-01')
        ->set('to', '2026-05-15')
        ->set('from', '');

    $buckets = Sales::buckets($component->html());

    expect($buckets)->toHaveCount(30)
        ->and($buckets[0])->toBe('2026-05-17')
        ->and($buckets[29])->toBe('2026-06-15');
});

it('a refunded order reduces real income in a custom range', function () {
    Sales::order('2026-05-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::PartiallyRefunded, '35.00');

    $html = Sales::component()->set('from', '2026-05-01')->set('to', '2026-05-31')->html();

    expect(Sales::kpi($html, 'sales'))->toBe('€ 100.00')
        ->and(Sales::kpi($html, 'income'))->toBe('€ 65.00');
});

// =====================================================================
// Range refusals (the previous data stays)
// =====================================================================

it('refuses a start after the end with the backend range_invalid message and keeps the previous data', function () {
    Sales::order('2026-06-10 10:00:00', '75.00');

    $component = Sales::component()->set('from', '2026-06-10');
    $before = Sales::view($component);

    $component->set('to', '2026-06-09')->assertHasErrors('range')->assertNotDispatched('sales-overview-updated');

    expect($component->errors()->first('range'))->toBe(__('dashboard.errors.range_invalid'))
        ->and(Sales::view($component))->toBe($before);
});

it('shows the translated cap message with its :max at each cap boundary', function (string $granularity, int $cap) {
    $today = CarbonImmutable::parse('2026-06-15', Sales::TZ);

    // `$cap` buckets ending today are accepted, `$cap + 1` refused.
    $accepted = match ($granularity) {
        'day' => $today->subDays($cap - 1),
        'month' => $today->startOfMonth()->subMonths($cap - 1),
        'year' => $today->startOfYear()->subYears($cap - 1),
    };
    $refused = match ($granularity) {
        'day' => $today->subDays($cap),
        'month' => $today->startOfMonth()->subMonths($cap),
        'year' => $today->startOfYear()->subYears($cap),
    };

    $component = Sales::component()->set('granularity', $granularity)->set('from', $accepted->format('Y-m-d'));

    $component->assertHasNoErrors()->assertSet('from', $accepted->format('Y-m-d'));

    expect(Sales::buckets($component->html()))->toHaveCount($cap);

    $before = Sales::view($component);

    $component->set('from', $refused->format('Y-m-d'))
        ->assertHasErrors('range')
        ->assertSet('from', $accepted->format('Y-m-d'))
        ->assertNotDispatched('sales-overview-updated');

    expect($component->errors()->first('range'))->toBe(__('dashboard.errors.range_too_long', ['max' => $cap]))
        ->and(Sales::view($component))->toBe($before);
})->with([
    'day 366' => ['day', 366],
    'month 120' => ['month', 120],
    'year 50' => ['year', 50],
]);

it('refuses years outside 1000..9998 with the card own year-window message, not range_invalid', function (string $property, string $value) {
    $component = Sales::component()
        ->set($property, $value)
        ->assertHasErrors('range')
        ->assertSet($property, '')
        ->assertNotDispatched('sales-overview-updated');

    $message = $component->errors()->first('range');

    expect($message)->toBe(__('dashboard.sales.range_year_window', ['min' => 1000, 'max' => 9998]))
        ->and($message)->not->toBe(__('dashboard.errors.range_invalid'))
        ->and(Sales::buckets($component->html()))->toHaveCount(30);
})->with([
    'a start before the year 1000' => ['from', '0999-12-31'],
    'an end after the year 9998' => ['to', '9999-01-01'],
]);

it('refuses a malformed or impossible date without a 500 and keeps the previous value', function (string $value) {
    $component = Sales::component()
        ->set('from', $value)
        ->assertHasErrors()
        ->assertSet('from', '')
        ->assertNotDispatched('sales-overview-updated');

    expect(Sales::buckets($component->html()))->toHaveCount(30);
})->with([
    'free text' => ['not-a-date'],
    'month 13' => ['2026-13-45'],
    'feb 30' => ['2026-02-30'],
    'wrong separator' => ['15/05/2026'],
    'with a time' => ['2026-05-01 10:00:00'],
]);

// =====================================================================
// Status chips
// =====================================================================

it('toggling the Cancelled chip adds cancelled orders to every figure', function () {
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered);
    Sales::order('2026-06-10 11:00:00', '40.00', OrderStatus::Cancelled);

    $component = Sales::component();

    expect(Sales::kpi($component->html(), 'sales'))->toBe('€ 100.00')
        ->and(Sales::kpi($component->html(), 'orders'))->toBe('1');

    $component->call('toggleStatus', 'cancelled');

    $html = $component->html();
    $rows = Sales::moneyRows($html);

    expect(Sales::kpi($html, 'sales'))->toBe('€ 140.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('2')
        ->and($rows['2026-06-10']['sales'])->toBe('€ 140.00')
        ->and(Sales::ordersRows($html)['2026-06-10']['total'])->toBe('2')
        ->and(Sales::chips($html)['cancelled'])->toBeTrue()
        ->and(Sales::orderColumns($html))->toBe(['pending', 'processing', 'shipped', 'delivered', 'cancelled']);

    $component->call('toggleStatus', 'cancelled');

    expect(Sales::kpi($component->html(), 'sales'))->toBe('€ 100.00');
});

it('the status chips narrow every figure', function (array $toggleOff, string $sales, string $income, string $orders) {
    Sales::order('2026-06-10 09:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-06-10 10:00:00', '50.00', OrderStatus::Shipped, PaymentStatus::Paid);
    Sales::order('2026-06-10 11:00:00', '20.00', OrderStatus::Pending, PaymentStatus::PendingPayment);

    $component = Sales::component();

    foreach ($toggleOff as $status) {
        $component->call('toggleStatus', $status);
    }

    $html = $component->html();

    expect(Sales::kpi($html, 'sales'))->toBe("€ {$sales}")
        ->and(Sales::kpi($html, 'income'))->toBe("€ {$income}")
        ->and(Sales::kpi($html, 'orders'))->toBe($orders);
})->with([
    'Delivered' => [['pending', 'processing', 'shipped'], '100.00', '100.00', '1'],
    'Delivered, Shipped' => [['pending', 'processing'], '150.00', '150.00', '2'],
    'Pending' => [['processing', 'shipped', 'delivered'], '20.00', '0.00', '1'],
]);

it('refuses to deselect the last status with the backend message and keeps the previous data', function () {
    Sales::order('2026-06-10 09:00:00', '100.00', OrderStatus::Delivered);

    $component = Sales::component(['s' => ['delivered']]);
    $before = Sales::view($component);

    $component
        ->call('toggleStatus', 'delivered')
        ->assertHasErrors('statuses')
        ->assertSet('statuses', ['delivered'])
        ->assertNotDispatched('sales-overview-updated');

    expect($component->errors()->first('statuses'))->toBe(__('dashboard.errors.statuses_required'))
        ->and(Sales::view($component))->toBe($before)
        ->and(Sales::chips($component->html())['delivered'])->toBeTrue();
});

it('stacks the statuses as Pending, Shipped, Delivered whatever order they were toggled on', function () {
    $component = Sales::component(['s' => ['cancelled']])
        ->call('toggleStatus', 'shipped')
        ->call('toggleStatus', 'pending')
        ->call('toggleStatus', 'delivered')
        ->call('toggleStatus', 'cancelled');

    expect(Sales::orderColumns($component->html()))->toBe(['pending', 'shipped', 'delivered'])
        ->and(array_column(Sales::lastPayload($component)['orders']['datasets'], 'status'))->toBe(['pending', 'shipped', 'delivered']);
});

it('ignores a status that does not exist', function () {
    $component = Sales::component()->call('toggleStatus', 'refunded');

    $component->assertSet('statuses', Sales::defaultStatusValues())->assertNotDispatched('sales-overview-updated');

    expect(Sales::orderColumns($component->html()))->toBe(Sales::defaultStatusValues());
});

it('refuses a tampered statuses array and keeps the previous selection', function (array $tampered) {
    $component = Sales::component()
        ->set('statuses', $tampered)
        ->assertHasErrors()
        ->assertSet('statuses', Sales::defaultStatusValues())
        ->assertNotDispatched('sales-overview-updated');

    expect(Sales::orderColumns($component->html()))->toBe(Sales::defaultStatusValues());
})->with([
    'empty' => [[]],
    'garbage entry' => [['delivered', 'garbage']],
    'more than five entries' => [['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'pending']],
]);

it('refuses a tampered property path without a 500 and keeps the previous state', function (string $path, mixed $value) {
    // The request is refused with a 422 before anything is assigned or dispatched (the failed response
    // carries no snapshot, so the client keeps the one it already had).
    Sales::component()
        ->set($path, $value)
        ->assertStatus(422)
        ->assertNotDispatched('sales-overview-updated');
})->with([
    'dotted path on from' => ['from.x', '2026-06-01'],
    'dotted path on to' => ['to.x', '2026-06-01'],
    'dotted path on granularity' => ['granularity.x', 'month'],
    'wildcard on statuses' => ['statuses.*', 'delivered'],
    'non-integer statuses key' => ['statuses.foo', 'delivered'],
    'nested statuses path' => ['statuses.0.x', 'delivered'],
    'statuses index beyond the cap' => ['statuses.7', 'delivered'],
]);

it('still accepts a plain indexed statuses change', function () {
    $component = Sales::component()
        ->set('statuses.0', 'cancelled')
        ->assertDispatched('sales-overview-updated');

    expect($component->get('statuses'))->toContain('cancelled');
});

it('a refused granularity applies no range reset and dispatches nothing, then an accepted one dispatches once', function () {
    $component = Sales::component()
        ->set('from', '2026-06-01')
        ->set('to', '2026-06-10')
        ->set('granularity', 'garbage')
        ->assertHasErrors('granularity')
        ->assertSet('granularity', 'day')
        ->assertSet('from', '2026-06-01')
        ->assertSet('to', '2026-06-10');

    $component->set('granularity', 'month')->assertSet('from', '')->assertSet('to', '');

    $dispatches = collect(data_get($component->effects, 'dispatches', []))
        ->where('name', 'sales-overview-updated');

    expect($dispatches)->toHaveCount(1);
});

// =====================================================================
// Event and consistency
// =====================================================================

it('dispatches the same labels for both charts and never after an invalid change', function () {
    $component = Sales::component()->call('applyPreset', 'last_7_days');

    $payload = Sales::lastPayload($component);

    expect($payload['money']['labels'])->toBe($payload['orders']['labels'])
        ->and($payload['money']['labels'])->toHaveCount(7)
        ->and($payload['money']['labels'][0])->toBe('9 Jun');

    $component->set('to', '2026-06-01')->assertNotDispatched('sales-overview-updated');
});

it('ends rapid consecutive changes in the state a fresh load of that URL would produce', function () {
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-06-11 10:00:00', '40.00', OrderStatus::Cancelled);
    Sales::order('2026-03-11 10:00:00', '60.00', OrderStatus::Shipped, PaymentStatus::Paid);

    $component = Sales::component()
        ->set('granularity', 'month')
        ->set('granularity', 'year')
        ->call('applyPreset', 'last_7_days')
        ->set('granularity', 'day')
        ->call('toggleStatus', 'cancelled')
        ->call('toggleStatus', 'pending')
        ->set('granularity', 'month');

    $fresh = Sales::component([
        'g' => $component->get('granularity'),
        'from' => $component->get('from'),
        'to' => $component->get('to'),
        's' => $component->get('statuses'),
    ]);

    expect($component->get('granularity'))->toBe('month')
        ->and($component->get('statuses'))->toEqualCanonicalizing(['processing', 'shipped', 'delivered', 'cancelled'])
        ->and(Sales::view($component))->toBe(Sales::view($fresh));
});
