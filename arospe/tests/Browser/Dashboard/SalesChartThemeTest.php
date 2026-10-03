<?php

// Story 0086 -- theme and responsive behaviour of the sales overview charts (new ground: no browser
// precedent exists for the dark-mode toggle). The charts read their colours from the --chart-* tokens on
// <html>; a MutationObserver on the `dark` class re-colours the SAME instances. Waits are polled inside the
// page (never networkidle, never a bare wait(n)); retry(3, ..., 250) covers the repo's documented CI timing
// variance on multi-step flows (see tests/Browser/UsersIndexTest.php).

use Tests\Support\Dashboard\SalesChartBrowser as Chart;

beforeEach(function () {
    Chart::actor();
    Chart::seedOrders();
});

/** JS expression: the current value of a --chart-* token on <html>. */
function salesChartTokenJs(string $token): string
{
    return "getComputedStyle(document.documentElement).getPropertyValue('--chart-{$token}').trim()";
}

/** JS function returning the colours the live charts actually use, as JSON. */
const SALES_CHART_COLOURS_JS = "() => { const m = document.querySelector('[data-test=\"money-chart-canvas\"]').chartInstance; const o = document.querySelector('[data-test=\"orders-chart-canvas\"]').chartInstance; return JSON.stringify({ sales: m.data.datasets[0].borderColor, income: m.data.datasets[1].borderColor, delivered: o.data.datasets.find((d) => d.status === 'delivered').backgroundColor, text: m.options.scales.x.ticks.color, grid: m.options.scales.y.grid.color }); }";

test('toggling dark mode recolours the charts without creating a new instance', function () {
    // Reason for retry: a class toggle -> MutationObserver -> chart update chain, with two waits.
    retry(3, function () {
        $page = visit('/dashboard')->assertNoJavaScriptErrors();
        Chart::waitForCharts($page);

        // Start from light so the toggle direction is known, whatever the layout default is.
        $page->script("document.documentElement.classList.remove('dark')");
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.datasets[1].borderColor === '.salesChartTokenJs('income'));

        $page->script("() => { window.__remembered = [document.querySelector('[data-test=\"money-chart-canvas\"]').chartInstance, document.querySelector('[data-test=\"orders-chart-canvas\"]').chartInstance]; return true; }");

        $light = json_decode((string) $page->script(SALES_CHART_COLOURS_JS), true, 512, JSON_THROW_ON_ERROR);

        $page->script("document.documentElement.classList.add('dark')");
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.datasets[1].borderColor === '.salesChartTokenJs('income').' && '.Chart::chartJs(Chart::MONEY).'.data.datasets[1].borderColor !== '.json_encode($light['income']));

        $dark = json_decode((string) $page->script(SALES_CHART_COLOURS_JS), true, 512, JSON_THROW_ON_ERROR);

        // Every themed colour changed, and each one is exactly the token the page now resolves.
        foreach (['sales', 'income', 'delivered', 'text', 'grid'] as $key) {
            expect($dark[$key])->not->toBe($light[$key], "The {$key} colour did not change with the theme.");
        }

        $page->assertScript(Chart::chartJs(Chart::MONEY).'.data.datasets[1].borderColor === '.salesChartTokenJs('income'), true)
            ->assertScript(Chart::chartJs(Chart::ORDERS).".data.datasets.find((d) => d.status === 'delivered').backgroundColor === ".salesChartTokenJs('status-delivered'), true)
            ->assertScript(Chart::chartJs(Chart::MONEY).'.options.scales.x.ticks.color === '.salesChartTokenJs('text'), true)
            // Same instances, nothing re-created.
            ->assertScript('window.__remembered[0] === '.Chart::chartJs(Chart::MONEY).' && window.__remembered[1] === '.Chart::chartJs(Chart::ORDERS), true)
            ->assertScript('window.__salesChartCreations', 2);

        // And back to light: the light colours return.
        $page->script("document.documentElement.classList.remove('dark')");
        Chart::waitUntil($page, Chart::chartJs(Chart::MONEY).'.data.datasets[1].borderColor === '.json_encode($light['income']));

        $page->assertScript('window.__salesChartCreations', 2)->assertNoJavaScriptErrors();
    }, 250);
});

test('the sales overview has no horizontal scroll on a 375 px phone viewport', function () {
    $page = visit('/dashboard')->resize(375, 812)->assertNoJavaScriptErrors();
    Chart::waitForCharts($page);

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('document.body.scrollWidth <= window.innerWidth', true)
        ->assertScript("(() => { const r = document.querySelector('[data-test=\"sales-overview\"]').getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()", true)
        ->assertNoJavaScriptErrors();
});
