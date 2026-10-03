// Story 0086 -- the Chart.js half of the dashboard's sales overview card
// (App\Livewire\Dashboard\SalesOverview, resources/views/livewire/dashboard/sales-overview.blade.php).
//
// Two Alpine components, `moneyChart` (line: sales vs real income) and `ordersChart` (stacked bars:
// orders by status). Each creates ONE Chart.js instance in init(), updates it in place when the
// component's `sales-overview-updated` event arrives, and destroys it (plus its theme observer) in
// destroy(). Chart.js is loaded with a dynamic import() and only the pieces used are registered, so
// it ships as its own lazily-fetched chunk. The instance lives in a closure, never on `this`: Alpine
// would wrap it in a reactive Proxy, which loops inside Chart.js.
//
// Testability contract (fixed by the story): once a chart exists its canvas carries
// `data-chart-ready="true"` and exposes the instance as `canvas.chartInstance`; every `new Chart()`
// increments `window.__salesChartCreations`; animation is off when <html data-testing> is present
// (the app layouts set it under the testing environment) or the user prefers reduced motion.
//
// Every label arrives as plain text (pre-formatted per locale by the server) and is only ever handed
// to Chart.js as data -- never interpolated into markup.

let chartJsPromise = null;

const loadChartJs = () => {
    chartJsPromise ??= import('chart.js').then(({
        Chart, LineController, LineElement, PointElement, BarController, BarElement,
        CategoryScale, LinearScale, Tooltip, Legend,
    }) => {
        Chart.register(
            LineController, LineElement, PointElement, BarController, BarElement,
            CategoryScale, LinearScale, Tooltip, Legend,
        );

        return Chart;
    });

    return chartJsPromise;
};

const readToken = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

const readTheme = () => ({
    text: readToken('--chart-text'),
    grid: readToken('--chart-grid'),
    sales: readToken('--chart-sales'),
    income: readToken('--chart-income'),
    status: {
        pending: readToken('--chart-status-pending'),
        processing: readToken('--chart-status-processing'),
        shipped: readToken('--chart-status-shipped'),
        delivered: readToken('--chart-status-delivered'),
        cancelled: readToken('--chart-status-cancelled'),
    },
});

const isAnimationDisabled = () => document.documentElement.dataset.testing !== undefined
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * The listener receives the dispatch's named parameters as one object; tolerate the array form too.
 */
const unwrapPayload = (args) => {
    const first = args[0];

    return Array.isArray(first) ? first[0] : first;
};

const makeCurrencyFormat = (locale) => {
    const options = { style: 'currency', currency: 'EUR' };

    return {
        full: new Intl.NumberFormat(locale, options),
        tick: new Intl.NumberFormat(locale, { ...options, maximumFractionDigits: 0 }),
    };
};

/**
 * Shared lifecycle of both charts. `kind` supplies what differs: how to build the Chart.js config
 * from a payload and theme, how to apply a new payload to a live chart, how to re-colour it and
 * whether a payload is all zeros.
 */
const chartComponent = (kind) => {
    let chart = null;
    let observer = null;
    let isDestroyed = false;
    let current = null;
    let strings = {};
    let format = null;
    let unsubscribe = null;

    return {
        isEmpty: false,

        init() {
            // The initial payload and the translated series names are read ONCE, from a child element
            // the server re-renders on every update. They are deliberately not in the `x-data`
            // expression: that attribute would then differ after each Livewire morph and Alpine would
            // tear the component down and build a second chart.
            ({ payload: current, strings } = JSON.parse(this.$refs.config.dataset.config));
            format = makeCurrencyFormat(current.locale);
            this.isEmpty = kind.isEmpty(current);

            // The component-scoped Livewire listener: the event fires on the SalesOverview root and
            // this element is a descendant, so an `x-on` here would never receive it.
            unsubscribe = this.$wire.$on('sales-overview-updated', (...args) => {
                if (isDestroyed) {
                    return;
                }

                current = unwrapPayload(args);
                format = makeCurrencyFormat(current.locale);
                this.isEmpty = kind.isEmpty(current);

                if (chart !== null) {
                    kind.apply(chart, current, readTheme());
                    chart.update(isAnimationDisabled() ? 'none' : undefined);
                    this.$nextTick(() => chart?.resize());
                }
            });

            loadChartJs().then((Chart) => {
                // destroy() may have run while the chunk was loading.
                if (isDestroyed) {
                    return;
                }

                const canvas = this.$refs.canvas;

                Chart.getChart(canvas)?.destroy();

                const theme = readTheme();

                chart = new Chart(canvas, kind.config({
                    payload: current,
                    strings,
                    theme,
                    format: () => format,
                    animation: !isAnimationDisabled(),
                }));

                window.__salesChartCreations = (window.__salesChartCreations ?? 0) + 1;
                canvas.chartInstance = chart;
                canvas.dataset.chartReady = 'true';

                // `@fluxAppearance` toggles the `dark` class on <html>; the tokens flip with it.
                observer = new MutationObserver(() => {
                    kind.theme(chart, readTheme());
                    chart.update('none');
                });
                observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            });
        },

        destroy() {
            isDestroyed = true;
            unsubscribe?.();
            unsubscribe = null;
            observer?.disconnect();
            observer = null;

            if (chart !== null) {
                const canvas = chart.canvas;

                chart.destroy();
                delete canvas.chartInstance;
                delete canvas.dataset.chartReady;
                chart = null;
            }
        },
    };
};

const baseOptions = (theme, animation) => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: animation ? { duration: 400 } : false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { labels: { color: theme.text, usePointStyle: true } },
    },
});

const allZero = (series) => series.every((value) => value === 0);

const moneyKind = {
    isEmpty: ({ money }) => allZero(money.sales) && allZero(money.income),

    config: ({ payload, strings, theme, format, animation }) => {
        const options = baseOptions(theme, animation);

        return {
            type: 'line',
            data: {
                labels: payload.money.labels,
                datasets: [
                    {
                        // Sales: muted, dashed, round markers.
                        label: strings.sales,
                        data: payload.money.sales,
                        borderColor: theme.sales,
                        backgroundColor: theme.sales,
                        borderDash: [6, 4],
                        pointStyle: 'circle',
                        pointRadius: 3,
                        tension: 0,
                    },
                    {
                        // Real income: accent, solid, square markers.
                        label: strings.income,
                        data: payload.money.income,
                        borderColor: theme.income,
                        backgroundColor: theme.income,
                        pointStyle: 'rect',
                        pointRadius: 3,
                        tension: 0,
                    },
                ],
            },
            options: {
                ...options,
                scales: {
                    x: { ticks: { color: theme.text, maxRotation: 0, autoSkip: true }, grid: { color: theme.grid } },
                    y: {
                        beginAtZero: true,
                        ticks: { color: theme.text, callback: (value) => format().tick.format(value) },
                        grid: { color: theme.grid },
                    },
                },
                plugins: {
                    ...options.plugins,
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${format().full.format(context.parsed.y)}`,
                            afterBody: (items) => {
                                const sales = items.find((item) => item.datasetIndex === 0)?.parsed.y ?? 0;
                                const income = items.find((item) => item.datasetIndex === 1)?.parsed.y ?? 0;

                                return `${strings.difference}: ${format().full.format(sales - income)}`;
                            },
                        },
                    },
                },
            },
        };
    },

    apply: (chart, payload) => {
        chart.data.labels = payload.money.labels;
        chart.data.datasets[0].data = payload.money.sales;
        chart.data.datasets[1].data = payload.money.income;
    },

    theme: (chart, theme) => {
        const [sales, income] = chart.data.datasets;

        sales.borderColor = sales.backgroundColor = theme.sales;
        income.borderColor = income.backgroundColor = theme.income;
        applyAxisTheme(chart, theme);
    },
};

const applyAxisTheme = (chart, theme) => {
    chart.options.plugins.legend.labels.color = theme.text;
    chart.options.scales.x.ticks.color = theme.text;
    chart.options.scales.x.grid.color = theme.grid;
    chart.options.scales.y.ticks.color = theme.text;
    chart.options.scales.y.grid.color = theme.grid;
};

const statusDatasets = (payload, theme) => payload.orders.datasets.map((dataset) => ({
    status: dataset.status,
    label: dataset.label,
    data: dataset.data,
    backgroundColor: theme.status[dataset.status],
    borderColor: theme.status[dataset.status],
    borderWidth: 1,
}));

const ordersKind = {
    isEmpty: ({ orders }) => orders.datasets.every((dataset) => allZero(dataset.data)),

    config: ({ payload, strings, theme, animation }) => {
        const options = baseOptions(theme, animation);

        return {
            type: 'bar',
            data: { labels: payload.orders.labels, datasets: statusDatasets(payload, theme) },
            options: {
                ...options,
                scales: {
                    x: { stacked: true, ticks: { color: theme.text, maxRotation: 0, autoSkip: true }, grid: { color: theme.grid } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { color: theme.text, precision: 0 },
                        grid: { color: theme.grid },
                    },
                },
                plugins: {
                    ...options.plugins,
                    tooltip: {
                        callbacks: {
                            footer: (items) => `${strings.total}: ${items.reduce((sum, item) => sum + item.parsed.y, 0)}`,
                        },
                    },
                },
            },
        };
    },

    apply: (chart, payload, theme) => {
        chart.data.labels = payload.orders.labels;

        const incoming = payload.orders.datasets.map((dataset) => dataset.status);
        const existing = chart.data.datasets.map((dataset) => dataset.status);

        if (incoming.join() === existing.join()) {
            payload.orders.datasets.forEach((dataset, index) => {
                chart.data.datasets[index].data = dataset.data;
            });

            return;
        }

        // The selected statuses changed: replace the datasets, keeping the series the user hid with
        // the legend hidden.
        const hidden = new Set(chart.data.datasets
            .filter((dataset, index) => !chart.isDatasetVisible(index))
            .map((dataset) => dataset.status));

        chart.data.datasets = statusDatasets(payload, theme);

        chart.data.datasets.forEach((dataset, index) => {
            if (hidden.has(dataset.status)) {
                chart.setDatasetVisibility(index, false);
            }
        });
    },

    theme: (chart, theme) => {
        chart.data.datasets.forEach((dataset) => {
            dataset.backgroundColor = dataset.borderColor = theme.status[dataset.status];
        });
        applyAxisTheme(chart, theme);
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('moneyChart', () => chartComponent(moneyKind));

    window.Alpine.data('ordersChart', () => chartComponent(ordersKind));
});
