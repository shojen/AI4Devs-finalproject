<?php

// Story 0086 -- what the sales-overview card shows for a given state: the default filter, the KPI strip
// (exact <x-money> strings), the collected-% hint, the two sr-only tables that stand in for the charts,
// the empty state and the canonical status order. The component is tested DIRECTLY (a #[Lazy] child
// renders only a placeholder through a page request).
//
// Red step: App\Livewire\Dashboard\SalesOverview does not exist yet.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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

// --- Default state -------------------------------------------------------------------------

it('starts on the last 30 days per day with every status chip on except Cancelled', function () {
    $component = Sales::component();
    $html = $component->html();

    $component
        ->assertSet('granularity', 'day')
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSet('statuses', Sales::defaultStatusValues());

    $buckets = Sales::buckets($html);

    expect($buckets)->toHaveCount(30)
        ->and($buckets[0])->toBe('2026-05-17')
        ->and($buckets[29])->toBe('2026-06-15')
        ->and(Sales::chips($html))->toBe([
            'pending' => true,
            'processing' => true,
            'shipped' => true,
            'delivered' => true,
            'cancelled' => false,
        ])
        ->and(Sales::orderColumns($html))->toBe(Sales::defaultStatusValues());
});

it('renders the card root, a heading and the two tables as screen-reader-only text', function () {
    $html = Sales::component()->html();

    expect(Ui::present($html, 'sales-overview'))->toBeTrue()
        ->and(Ui::text($html, 'sales-title'))->toBe(__('dashboard.sales.title'))
        ->and(Ui::tagName($html, 'sales-money-table'))->toBe('table')
        ->and($html)->toMatch('/<div class="[^"]*\bsr-only\b[^"]*">(?:(?!<\/div>).)*<table data-test="sales-money-table"/s')
        ->and(Ui::tagName($html, 'sales-orders-table'))->toBe('table')
        ->and($html)->toMatch('/<div class="[^"]*\bsr-only\b[^"]*">(?:(?!<\/div>).)*<table data-test="sales-orders-table"/s')
        ->and(Ui::attribute($html, 'money-chart-canvas', 'aria-hidden'))->toBe('true')
        ->and(Ui::attribute($html, 'orders-chart-canvas', 'aria-hidden'))->toBe('true')
        ->and(Ui::present($html, 'sales-summary'))->toBeTrue();
});

it('never uses an unescaped output or an x-html sink in the card', function () {
    Sales::order('2026-06-10 10:00:00', '10.00');

    $html = Sales::component()->html();

    expect($html)->not->toContain('x-html');
});

// --- KPI strip -----------------------------------------------------------------------------

it('summarizes the period in the KPI strip: sales gross, income net of refunds, order count', function () {
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-06-10 11:00:00', '50.00', OrderStatus::Delivered, PaymentStatus::PartiallyRefunded, '20.00');
    Sales::order('2026-06-11 09:00:00', '30.00', OrderStatus::Pending, PaymentStatus::PendingPayment);

    $html = Sales::component()->html();

    expect(Sales::kpi($html, 'sales'))->toBe('€ 180.00')
        ->and(Sales::kpi($html, 'income'))->toBe('€ 130.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('3');
});

it('shows a one-line definition for each tile', function (string $tile) {
    $html = Sales::component()->html();

    // The exact lang key of a definition is the implementer's to name (D-5 only says "one-line definition
    // strings"); what must hold is that a real translated sentence is rendered, never a raw key.
    $definition = Ui::text($html, "sales-kpi-{$tile}-definition");

    expect($definition)->not->toBe('')
        ->and($definition)->not->toContain('dashboard.sales')
        ->and(mb_strlen($definition))->toBeGreaterThan(15);
})->with(['sales', 'income', 'orders']);

it('reads zero everywhere for a period without orders', function () {
    $html = Sales::component()->html();

    expect(Sales::kpi($html, 'sales'))->toBe('€ 0.00')
        ->and(Sales::kpi($html, 'income'))->toBe('€ 0.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('0');
});

// --- Collected-% hint (owner change 2026-10-02: up to 2 decimals, half up, trailing zeros trimmed) ----

it('shows how much of the sales was collected, with up to two decimals rounded half up', function (string $sales, string $income, string $percent) {
    Sales::order('2026-06-10 10:00:00', $income, OrderStatus::Delivered, PaymentStatus::Paid);

    // The remainder of the sales is one unpaid order, so income / sales is exactly income / (income + rest).
    $rest = bcsub($sales, $income, 2);

    if (bccomp($rest, '0', 2) > 0) {
        Sales::order('2026-06-10 11:00:00', $rest, OrderStatus::Delivered, PaymentStatus::PendingPayment);
    }

    $html = Sales::component()->html();

    expect(Sales::kpi($html, 'sales'))->toBe("€ {$sales}")
        ->and(Ui::text($html, 'sales-kpi-collected'))->toBe(__('dashboard.sales.kpi.collected', ['percent' => $percent]))
        ->and(Ui::present($html, 'sales-kpi-income-hint'))->toBeFalse();
})->with([
    'one half' => ['200.00', '100.00', '50'],
    'one third is not rounded to an integer' => ['300.00', '100.00', '33.33'],
    'two thirds rounds up' => ['300.00', '200.00', '66.67'],
    'rounds half up at the second decimal (4.436)' => ['1000.00', '44.36', '4.44'],
    'a lone half-cent rounds up (0.005)' => ['20000.00', '1.00', '0.01'],
    'a trailing zero is trimmed (12.50)' => ['8.00', '1.00', '12.5'],
    'everything collected' => ['100.00', '100.00', '100'],
]);

it('hides the collected hint when there are no sales', function () {
    $html = Sales::component()->html();

    expect(Ui::present($html, 'sales-kpi-collected'))->toBeFalse()
        ->and(Ui::present($html, 'sales-kpi-income-hint'))->toBeFalse();
});

it('explains that income is counted once orders are paid when sales exist but nothing is paid', function () {
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::PendingPayment);
    Sales::order('2026-06-11 10:00:00', '40.00', OrderStatus::Pending, PaymentStatus::PendingPayment);

    $html = Sales::component()->html();

    expect(Sales::kpi($html, 'income'))->toBe('€ 0.00')
        ->and(Ui::text($html, 'sales-kpi-income-hint'))->toBe(__('dashboard.sales.kpi.income_hint'))
        ->and(Ui::present($html, 'sales-kpi-collected'))->toBeFalse();
});

// --- Chart A: sales vs real income ---------------------------------------------------------

it('lists sales and real income as two series per day in the money table', function () {
    Sales::order('2026-06-10 09:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-06-10 18:00:00', '60.00', OrderStatus::Delivered, PaymentStatus::PendingPayment);

    $rows = Sales::moneyRows(Sales::component()->html());

    expect($rows['2026-06-10']['sales'])->toBe('€ 160.00')
        ->and($rows['2026-06-10']['income'])->toBe('€ 100.00')
        ->and($rows['2026-06-09']['sales'])->toBe('€ 0.00')
        ->and($rows['2026-06-09']['income'])->toBe('€ 0.00');
});

it('counts the sales gross and the real income net of refunds in the money table', function () {
    Sales::order('2026-06-12 10:00:00', '50.00', OrderStatus::Delivered, PaymentStatus::PartiallyRefunded, '20.00');

    $rows = Sales::moneyRows(Sales::component()->html());

    expect($rows['2026-06-12']['sales'])->toBe('€ 50.00')
        ->and($rows['2026-06-12']['income'])->toBe('€ 30.00');
});

it('never counts a cancelled order as income, even with the Cancelled chip on', function () {
    Sales::order('2026-06-12 10:00:00', '40.00', OrderStatus::Cancelled, PaymentStatus::Paid);

    $rows = Sales::moneyRows(Sales::component(['s' => ['cancelled']])->html());

    expect($rows['2026-06-12']['sales'])->toBe('€ 40.00')
        ->and($rows['2026-06-12']['income'])->toBe('€ 0.00');
});

// --- Chart B: orders by status -------------------------------------------------------------

it('breaks each day down by status in the orders table', function () {
    Sales::order('2026-06-01 09:00:00', '10.00', OrderStatus::Pending);
    Sales::order('2026-06-01 10:00:00', '10.00', OrderStatus::Pending);
    Sales::order('2026-06-01 11:00:00', '10.00', OrderStatus::Shipped);

    // 1 June is inside the default window only when "today" is close enough: move to 20 June.
    Sales::today('2026-06-20');

    $rows = Sales::ordersRows(Sales::component()->html());

    expect($rows['2026-06-01']['total'])->toBe('3')
        ->and($rows['2026-06-01']['byStatus'])->toBe([
            'pending' => '2',
            'processing' => '0',
            'shipped' => '1',
            'delivered' => '0',
        ])
        ->and($rows['2026-06-02']['total'])->toBe('0');
});

it('keeps a selected status with no orders as a zero column', function () {
    Sales::order('2026-06-10 10:00:00', '10.00', OrderStatus::Delivered);

    $html = Sales::component(['s' => ['delivered', 'shipped']])->html();
    $rows = Sales::ordersRows($html);

    expect(Sales::orderColumns($html))->toBe(['shipped', 'delivered'])
        ->and($rows['2026-06-10']['byStatus'])->toBe(['shipped' => '0', 'delivered' => '1'])
        ->and($rows['2026-06-10']['total'])->toBe('1');
});

it('stacks the statuses in OrderStatus order whatever order they were selected in', function (array $query) {
    $html = Sales::component($query)->html();

    expect(Sales::orderColumns($html))->toBe(['pending', 'shipped', 'delivered']);
})->with([
    'ascending' => [['s' => ['pending', 'shipped', 'delivered']]],
    'descending' => [['s' => ['delivered', 'shipped', 'pending']]],
    'shuffled' => [['s' => ['shipped', 'pending', 'delivered']]],
]);

// --- Empty and edge states -----------------------------------------------------------------

it('shows the empty message for an all-zero period and none once there is an order', function () {
    $empty = Sales::component()->html();

    expect(Ui::text($empty, 'sales-empty'))->toBe(__('dashboard.sales.empty'));

    Sales::order('2026-06-10 10:00:00', '10.00');

    $filled = Sales::component()->html();

    expect(Ui::present($filled, 'sales-empty'))->toBeFalse();
});

it('does not use the empty message when only unpaid orders exist (income zero, sales not)', function () {
    Sales::order('2026-06-10 10:00:00', '10.00', OrderStatus::Pending, PaymentStatus::PendingPayment);

    expect(Ui::present(Sales::component()->html(), 'sales-empty'))->toBeFalse();
});

it('keeps both canvases in the markup even for an empty period (the chart instance survives)', function () {
    $html = Sales::component()->html();

    expect(Ui::present($html, 'money-chart-canvas'))->toBeTrue()
        ->and(Ui::present($html, 'orders-chart-canvas'))->toBeTrue();
});

it('excludes orders outside the period and statuses outside the selection', function () {
    Sales::order('2026-05-16 23:59:59', '70.00');   // one second before the default window
    Sales::order('2026-06-16 00:00:00', '80.00');   // tomorrow
    Sales::order('2026-06-10 10:00:00', '25.00', OrderStatus::Cancelled);   // Cancelled is off by default

    $html = Sales::component()->html();

    expect(Sales::kpi($html, 'sales'))->toBe('€ 0.00')
        ->and(Sales::kpi($html, 'orders'))->toBe('0');
});
