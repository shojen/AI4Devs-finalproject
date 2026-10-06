<?php

// Story 0086 -- the two Chart.js charts of the dashboard "Sales overview" card, in a real browser.
//
// SELECTOR STRATEGY: data-test hooks only, plus the story's testability contract (canvas hooks,
// `data-chart-ready`, `canvas.chartInstance`, `window.__salesChartCreations`). The canvas cannot be read
// by a Livewire test, so the assertions go through the Chart instance's data; the expected labels and
// figures come from tests/Support/Dashboard/SalesChartBrowser.php, which buckets the seeded orders in
// PHP (seeded relative to now(); "today" is never computed in JavaScript).
//
// WAITS: never networkidle, never a bare wait(n). Every wait is a condition polled inside the page
// (SalesChartBrowser::waitUntil). The multi-step flows are wrapped in retry(3, ..., 250) for the repo's
// documented reason (see tests/Browser/UsersIndexTest.php): a click -> Livewire round trip -> chart update
// chain occasionally exceeds Playwright's fixed 5000ms ceiling on a loaded CI runner. Each attempt starts
// from a fresh page, so a retry never hides a wrong final state; the tooltip test is isolated in its own
// test (flake isolation, R-5).

use App\Enums\OrderStatus;
use Tests\Support\Dashboard\SalesChartBrowser as Chart;

beforeEach(function () {
    Chart::actor();
    Chart::seedOrders();
});

/**
 * Asserts a chart A snapshot equals the PHP-computed series.
 *
 * @param  array{labels: list<string>, sales: list<float>, income: list<float>}  $expected
 */
function salesChartExpectMoney(mixed $page, array $expected): void
{
    $snapshot = Chart::snapshot($page, Chart::MONEY);

    expect($snapshot['labels'])->toBe($expected['labels'])
        ->and($snapshot['datasets'][0]['data'])->toEqual($expected['sales'])
        ->and($snapshot['datasets'][1]['data'])->toEqual($expected['income']);
}

/**
 * Asserts a chart B snapshot equals the PHP-computed series (one dataset per selected status, canonical order).
 *
 * @param  array{labels: list<string>, datasets: array<string, list<int>>}  $expected
 */
function salesChartExpectOrders(mixed $page, array $expected): void
{
    $snapshot = Chart::snapshot($page, Chart::ORDERS);

    expect($snapshot['labels'])->toBe($expected['labels'])
        ->and(array_column($snapshot['datasets'], 'status'))->toBe(array_keys($expected['datasets']))
        ->and(array_column($snapshot['datasets'], 'data'))->toEqual(array_values($expected['datasets']));
}

/**
 * The default state's expected series for a granularity.
 *
 * @return array{money: array{labels: list<string>, sales: list<float>, income: list<float>}, orders: array{labels: list<string>, datasets: array<string, list<int>>}}
 */
function salesChartDefaultSeries(string $granularity, ?array $statuses = null): array
{
    [$from, $to] = Chart::defaultRange($granularity);
    $statuses ??= Chart::defaultStatuses();

    return [
        'money' => Chart::expectedMoney($granularity, $from, $to, $statuses),
        'orders' => Chart::expectedOrders($granularity, $from, $to, $statuses),
    ];
}

/** JS expression: the number of Chart instances ever created on this page load. */
const SALES_CHART_CREATIONS_JS = 'window.__salesChartCreations ?? 0';

/** JS statement remembering both live instances so identity can be compared later. */
const SALES_CHART_REMEMBER_JS = "() => { window.__remembered = [document.querySelector('[data-test=\"money-chart-canvas\"]').chartInstance, document.querySelector('[data-test=\"orders-chart-canvas\"]').chartInstance]; return true; }";

/** JS expression: both canvases still hold the very instances remembered by SALES_CHART_REMEMBER_JS. */
const SALES_CHART_SAME_INSTANCES_JS = "window.__remembered[0] === document.querySelector('[data-test=\"money-chart-canvas\"]').chartInstance && window.__remembered[1] === document.querySelector('[data-test=\"orders-chart-canvas\"]').chartInstance";

test('exactly two chart instances exist and both survive granularity changes, chip toggles, a custom range and an unrelated re-render', function () {
    $from = Chart::today()->subDays(9);
    $to = Chart::today()->subDays(2);

    // Reason for retry: a four-step click -> Livewire round trip -> in-place chart update chain.
    retry(3, function () use ($from, $to) {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);

        $page->assertScript(SALES_CHART_CREATIONS_JS, 2);
        $page->script(SALES_CHART_REMEMBER_JS);

        // Granularity change.
        $page->script(Chart::clickGranularityJs('month'));
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 12');

        // Chip toggle (Cancelled on).
        $page->script(Chart::clickChipJs('cancelled'));
        Chart::waitUntil($page, Chart::chartJs(Chart::ORDERS).'.data.datasets.length === 5');

        // Custom range.
        $page->script(Chart::clickGranularityJs('day'));
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 30');
        $page->fill('@sales-from', $from->format('Y-m-d'))->fill('@sales-to', $to->format('Y-m-d'));
        $page->script("document.querySelector('[data-test=\"sales-to\"]').blur()");
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 8');

        // An unrelated re-render: resetting the statuses re-renders the card without touching the range.
        $page->script("document.querySelector('[data-test=\"sales-reset-statuses\"]').click()");
        Chart::waitUntil($page, Chart::chartJs(Chart::ORDERS).'.data.datasets.length === 4');

        $page->assertScript(SALES_CHART_CREATIONS_JS, 2)
            ->assertScript(SALES_CHART_SAME_INSTANCES_JS, true)
            ->assertScript("document.querySelectorAll('[data-test=\"sales-overview\"] canvas').length", 2)
            ->assertNoJavaScriptErrors();
    }, 250);
});

test('chip toggles add and remove an orders dataset in place, and the legend hides a series locally only', function () {
    retry(3, function () {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);
        $page->script(SALES_CHART_REMEMBER_JS);

        $orders = Chart::chartJs(Chart::ORDERS);

        expect(Chart::snapshot($page, Chart::ORDERS)['datasets'])->toHaveCount(4);

        // Cancelled on: a fifth dataset appears, the cancelled order of the seed is counted.
        $page->script(Chart::clickChipJs('cancelled'));
        Chart::waitUntil($page, "{$orders}.data.datasets.length === 5");
        salesChartExpectOrders($page, salesChartDefaultSeries('day', OrderStatus::cases())['orders']);

        // Cancelled off again: the dataset goes away.
        $page->script(Chart::clickChipJs('cancelled'));
        Chart::waitUntil($page, "{$orders}.data.datasets.length === 4");
        salesChartExpectOrders($page, salesChartDefaultSeries('day')['orders']);

        $page->assertScript(SALES_CHART_SAME_INSTANCES_JS, true);

        // Legend click on the first entry of chart B: only that chart hides the series.
        $kpiBefore = $page->script("[...document.querySelectorAll('[data-test^=\"sales-kpi-\"]')].map((e) => e.textContent.trim()).join('|')");
        $moneyBefore = Chart::snapshot($page, Chart::MONEY);

        $page->script("() => { const c = {$orders}; const box = c.legend.legendHitBoxes[0]; const r = c.canvas.getBoundingClientRect(); c.canvas.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: r.left + box.left + box.width / 2, clientY: r.top + box.top + box.height / 2 })); return true; }");
        Chart::waitUntil($page, "{$orders}.isDatasetVisible(0) === false");

        expect(Chart::snapshot($page, Chart::MONEY))->toEqual($moneyBefore)
            ->and($page->script("[...document.querySelectorAll('[data-test^=\"sales-kpi-\"]')].map((e) => e.textContent.trim()).join('|')"))->toBe($kpiBefore);

        // The server-side filter did not change: the chip is still pressed and the chart keeps its four datasets.
        $page->assertScript("document.querySelector('[data-test=\"sales-chip-pending\"]').getAttribute('aria-pressed') === 'true'", true)
            ->assertScript("{$orders}.data.datasets.length", 4)
            ->assertScript(SALES_CHART_CREATIONS_JS, 2)
            ->assertNoJavaScriptErrors();

    }, 250);
});

test('the dashboard raises no javascript error through load, a filter change, a validation error, a theme toggle and wire:navigate away and back', function () {
    $today = Chart::today();

    // Reason for retry: a long multi-step journey (load, filter, invalid range, theme, two navigations).
    retry(3, function () use ($today) {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);

        $page->script(Chart::clickGranularityJs('year'));
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 5');
        $page->assertNoJavaScriptErrors();

        // Validation error: an end date before the start date is refused and the previous data stays.
        $page->script(Chart::clickGranularityJs('day'));
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 30');
        $page->fill('@sales-from', $today->subDays(2)->format('Y-m-d'));
        $page->script("document.querySelector('[data-test=\"sales-from\"]').blur()");
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 3');
        $page->fill('@sales-to', $today->subDays(5)->format('Y-m-d'));
        $page->script("document.querySelector('[data-test=\"sales-to\"]').blur()");
        Chart::waitUntil($page, "document.querySelector('[data-test=\"sales-error-range\"]')?.textContent.trim().length > 0");
        $page->assertScript(Chart::chartJs(Chart::MONEY).'.data.labels.length', 3)
            ->assertNoJavaScriptErrors();

        // Theme toggle, twice.
        $page->script("document.documentElement.classList.toggle('dark')");
        $page->script("document.documentElement.classList.toggle('dark')");
        $page->assertNoJavaScriptErrors();

        // wire:navigate away and back: two instances per page view, the old ones are destroyed.
        $page->script("() => { Livewire.navigate('/settings/profile'); return true; }");
        Chart::waitUntil($page, "location.pathname === '/settings/profile'");
        $page->assertNoJavaScriptErrors();

        $page->script('() => { history.back(); return true; }');
        Chart::waitForCharts($page);
        Chart::waitUntil($page, "location.pathname === '/dashboard'");

        $page->assertScript(SALES_CHART_CREATIONS_JS, 4)
            ->assertScript("document.querySelectorAll('[data-test=\"sales-overview\"] canvas').length", 2)
            ->assertNoJavaScriptErrors();
    }, 250);
});

test('the charts hold the seeded series on first load from a non-default URL range, with no interaction', function () {
    $from = Chart::today()->subDays(5);
    $to = Chart::today()->subDays(1);
    $statuses = [OrderStatus::Pending, OrderStatus::Shipped, OrderStatus::Cancelled];

    $page = visit('/dashboard?g=day&from='.$from->format('Y-m-d').'&to='.$to->format('Y-m-d').'&s[]=pending&s[]=shipped&s[]=cancelled')
        ->assertNoJavaScriptErrors();
    Chart::waitForCharts($page);

    salesChartExpectMoney($page, Chart::expectedMoney('day', $from, $to, $statuses));
    salesChartExpectOrders($page, Chart::expectedOrders('day', $from, $to, $statuses));

    $page->assertScript(SALES_CHART_CREATIONS_JS, 2)->assertNoJavaScriptErrors();
});

test('the charts hold the seeded series on first load from a month URL', function () {
    $from = Chart::today()->startOfMonth()->subMonths(3);
    $to = Chart::today();

    $page = visit('/dashboard?g=month&from='.$from->format('Y-m-d').'&to='.$to->format('Y-m-d'))->assertNoJavaScriptErrors();
    Chart::waitForCharts($page);

    salesChartExpectMoney($page, Chart::expectedMoney('month', $from, $to, Chart::defaultStatuses()));
    salesChartExpectOrders($page, Chart::expectedOrders('month', $from, $to, Chart::defaultStatuses()));
    $page->assertNoJavaScriptErrors();
});

test('the screen-reader tables equal the series before and after a filter change, are rendered, and the canvases are aria-hidden', function () {
    /** JS function reading both sr-only tables as plain figures. */
    $tablesJs = "() => { const num = (t) => parseFloat(t.replace(/[^0-9.\\-]/g, '')); const money = [...document.querySelectorAll('[data-test^=\"sales-money-row-\"]')].map((r) => ({ label: r.querySelector('th').textContent.trim(), sales: num(r.children[1].textContent), income: num(r.children[2].textContent) })); const orders = [...document.querySelectorAll('[data-test^=\"sales-orders-row-\"]')].map((r) => ({ label: r.querySelector('th').textContent.trim(), total: parseInt(r.lastElementChild.textContent, 10) })); return JSON.stringify({ money, orders }); }";

    $assertTablesMatch = function (mixed $page, array $series) use ($tablesJs): void {
        $tables = json_decode((string) $page->script($tablesJs), true, 512, JSON_THROW_ON_ERROR);

        $expectedTotals = [];
        foreach ($series['orders']['labels'] as $index => $label) {
            $expectedTotals[] = ['label' => $label, 'total' => array_sum(array_column($series['orders']['datasets'], $index))];
        }

        $expectedMoney = [];
        foreach ($series['money']['labels'] as $index => $label) {
            $expectedMoney[] = ['label' => $label, 'sales' => $series['money']['sales'][$index], 'income' => $series['money']['income'][$index]];
        }

        expect($tables['money'])->toEqual($expectedMoney)
            ->and($tables['orders'])->toEqual($expectedTotals);
    };

    // Reason for retry: a click -> Livewire round trip -> table + chart update chain.
    retry(3, function () use ($assertTablesMatch) {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);

        $assertTablesMatch($page, salesChartDefaultSeries('day'));

        $page->assertScript("document.querySelector('[data-test=\"sales-money-table\"]').getAttribute('aria-hidden')", null)
            ->assertScript("getComputedStyle(document.querySelector('[data-test=\"sales-money-table\"]')).display !== 'none' && getComputedStyle(document.querySelector('[data-test=\"sales-orders-table\"]')).display !== 'none'", true)
            ->assertScript("getComputedStyle(document.querySelector('[data-test=\"sales-money-table\"]')).visibility !== 'hidden'", true)
            ->assertScript("document.querySelector('[data-test=\"money-chart-canvas\"]').getAttribute('aria-hidden') === 'true' && document.querySelector('[data-test=\"orders-chart-canvas\"]').getAttribute('aria-hidden') === 'true'", true);

        $page->script(Chart::clickGranularityJs('month'));
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 12');

        $assertTablesMatch($page, salesChartDefaultSeries('month'));
        $page->assertNoJavaScriptErrors();
    }, 250);
});

test('rapid Day, Month, Year, Day clicks end on the last click state with the same instances', function () {
    // Reason for retry: four overlapping Livewire requests racing the in-place chart updates.
    retry(3, function () {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);
        $page->script(SALES_CHART_REMEMBER_JS);

        // Synchronous clicks, no waiting in between: the requests overlap.
        $page->script("() => { for (const option of ['month', 'year', 'day']) { document.querySelector('[data-test=\"sales-granularity\"] [value=\"' + option + '\"]').click(); } return true; }");

        $expected = salesChartDefaultSeries('day');

        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.labels.length === 30');
        // No request still in flight (the card's "Updating..." indicator is hidden again), so a straggling
        // out-of-order response cannot land after the assertions.
        Chart::waitUntil($page, "getComputedStyle(document.querySelector('header span[wire\\\\:loading]')).display === 'none'");

        salesChartExpectMoney($page, $expected['money']);
        salesChartExpectOrders($page, $expected['orders']);

        $page->assertScript(SALES_CHART_SAME_INSTANCES_JS, true)
            ->assertScript(SALES_CHART_CREATIONS_JS, 2)
            ->assertNoJavaScriptErrors();
    }, 250);
});

test('the sales-overview-updated event reaches both charts', function () {
    // Reason for retry: click -> Livewire round trip -> event delivery.
    retry(3, function () {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);

        $moneyBefore = Chart::snapshot($page, Chart::MONEY);
        $ordersBefore = Chart::snapshot($page, Chart::ORDERS);

        // Turning Cancelled on changes BOTH series: chart A gains the cancelled order's 40 of sales
        // and chart B gains a dataset, so each chart must have received the event.
        $page->script(Chart::clickChipJs('cancelled'));
        Chart::waitUntil($page, Chart::chartJs(Chart::ORDERS).'.data.datasets.length === 5');

        $expected = salesChartDefaultSeries('day', OrderStatus::cases());

        $moneyAfter = Chart::snapshot($page, Chart::MONEY);

        expect($moneyAfter)->not->toEqual($moneyBefore)
            ->and(Chart::snapshot($page, Chart::ORDERS))->not->toEqual($ordersBefore);

        salesChartExpectMoney($page, $expected['money']);
        salesChartExpectOrders($page, $expected['orders']);

        $page->assertNoJavaScriptErrors();
    }, 250);
});

// Isolated (flake budget R-5): a tooltip depends on synthetic pointer events and on Chart.js's own
// hit-testing, so it lives alone and does not share a page with the data assertions above.
test('hovering a day on chart A shows both values and their difference', function () {
    $offset = 3;
    $label = Chart::label(Chart::today()->subDays($offset), 'day');

    retry(3, function () use ($label) {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);

        $chart = Chart::chartJs(Chart::MONEY);

        $page->script("() => { const c = {$chart}; const index = c.data.labels.indexOf(".json_encode($label)."); const point = c.getDatasetMeta(1).data[index]; const r = c.canvas.getBoundingClientRect(); c.canvas.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: r.left + point.x, clientY: r.top + point.y })); return true; }");
        Chart::waitUntil($page, "{$chart}.tooltip.opacity > 0 && {$chart}.tooltip.body.length > 0");

        /** @var string $text */
        $text = $page->script("() => JSON.stringify({ title: {$chart}.tooltip.title, body: {$chart}.tooltip.body.map((b) => b.lines.join(' ')), after: {$chart}.tooltip.afterBody })");
        $tooltip = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        // Seeded for that day: 160 of sales (100 paid delivered + 60 unpaid pending), 100 collected.
        expect($tooltip['title'])->toBe([$label])
            ->and(implode(' ', $tooltip['body']))->toContain('Sales: €160.00')->toContain('Real income: €100.00')
            ->and(implode(' ', $tooltip['after']))->toContain('Difference: €60.00');

        $page->assertNoJavaScriptErrors();
    }, 250);
});
