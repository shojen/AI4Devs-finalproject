<?php

// Story 0086 (D-4, D-5) -- the sales overview speaks the administrator's language: labels, hints,
// month/day names, the collected-% decimal separator and the locale handed to the charts.
//
// The component is mounted directly (the SetUiLocale middleware never runs), so the locale is set the
// way the middleware does -- `app()->setLocale()` -- and restored afterwards. The Spanish lang keys are
// read back through `__()` so the tests pin behaviour, not wording.
//
// Red step: the component and the `sales` lang group do not exist yet.

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

afterEach(function () {
    app()->setLocale(config('app.locale'));
});

it('labels the buckets per granularity in English and Spanish', function (string $locale, string $granularity, string $first, string $last) {
    app()->setLocale($locale);

    $rows = Sales::moneyRows(Sales::component(['g' => $granularity])->html());
    $labels = array_column($rows, 'label');

    expect($labels[0])->toBe($first)
        ->and(end($labels))->toBe($last)
        // the two tables label the same buckets identically
        ->and(array_column(Sales::ordersRows(Sales::component(['g' => $granularity])->html()), 'label'))->toBe($labels);
})->with([
    'en day' => ['en', 'day', '17 May', '15 Jun'],
    'es day' => ['es', 'day', '17 may', '15 jun'],
    'en month' => ['en', 'month', 'Jul 2025', 'Jun 2026'],
    'es month' => ['es', 'month', 'jul 2025', 'jun 2026'],
    'en year' => ['en', 'year', '2022', '2026'],
    'es year' => ['es', 'year', '2022', '2026'],
]);

it('names the first of May in the active locale', function (string $locale, string $label) {
    app()->setLocale($locale);
    Sales::today('2026-05-20');

    $rows = Sales::moneyRows(Sales::component(['from' => '2026-05-01', 'to' => '2026-05-20'])->html());

    expect($rows['2026-05-01']['label'])->toBe($label);
})->with([
    'en' => ['en', '1 May'],
    'es' => ['es', '1 may'],
]);

it('shows every static string of the card in Spanish for a Spanish administrator', function () {
    app()->setLocale('es');
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::PendingPayment);

    $html = Sales::component()->html();

    expect(Ui::text($html, 'sales-title'))->toBe(__('dashboard.sales.title', [], 'es'))
        ->and(Ui::text($html, 'sales-title'))->not->toBe(__('dashboard.sales.title', [], 'en'))
        ->and(Ui::text($html, 'sales-kpi-income-hint'))->toBe(__('dashboard.sales.kpi.income_hint', [], 'es'))
        ->and(Ui::text($html, 'sales-kpi-income-hint'))->not->toBe(__('dashboard.sales.kpi.income_hint', [], 'en'));

    foreach (OrderStatus::defaultDashboardSet() as $status) {
        expect(Ui::present($html, "sales-orders-th-{$status->value}"))->toBeTrue()
            ->and(Ui::text($html, "sales-orders-th-{$status->value}"))->toBe(__("orders.statuses.{$status->value}", [], 'es'));
    }
});

it('shows the empty message in the active locale', function (string $locale) {
    app()->setLocale($locale);

    expect(Ui::text(Sales::component()->html(), 'sales-empty'))->toBe(__('dashboard.sales.empty', [], $locale));
})->with(['en', 'es']);

it('formats the collected percentage with the locale decimal separator', function (string $locale, string $expected) {
    app()->setLocale($locale);
    Sales::order('2026-06-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    Sales::order('2026-06-10 11:00:00', '200.00', OrderStatus::Delivered, PaymentStatus::PendingPayment);

    $hint = Ui::text(Sales::component()->html(), 'sales-kpi-collected');

    expect($hint)->toBe(__('dashboard.sales.kpi.collected', ['percent' => $expected], $locale));
})->with([
    'en' => ['en', '33.33'],
    'es' => ['es', '33,33'],
]);

it('keeps the money in the tiles and tables as the decimal strings, whatever the locale', function (string $locale) {
    app()->setLocale($locale);
    Sales::order('2026-06-10 10:00:00', '1234.50', OrderStatus::Delivered, PaymentStatus::Paid);

    $html = Sales::component()->html();

    expect(Sales::kpi($html, 'sales'))->toBe('€ 1234.50')
        ->and(Sales::moneyRows($html)['2026-06-10']['sales'])->toBe('€ 1234.50');
})->with(['en', 'es']);

it('hands the active locale to the charts in the update payload', function (string $locale) {
    app()->setLocale($locale);

    $component = Sales::component()->set('granularity', 'month');
    $payload = Sales::lastPayload($component);

    expect($payload['locale'])->toBe($locale)
        ->and($payload['money']['labels'][11])->toBe($locale === 'es' ? 'jun 2026' : 'Jun 2026')
        ->and(array_column($payload['orders']['datasets'], 'label'))->toBe(array_map(
            fn (OrderStatus $status): string => __("orders.statuses.{$status->value}", [], $locale),
            OrderStatus::defaultDashboardSet(),
        ));
})->with(['en', 'es']);

it('refuses with the backend message in the active locale', function (string $locale) {
    app()->setLocale($locale);

    $component = Sales::component(['s' => ['delivered']])->call('toggleStatus', 'delivered');

    expect($component->errors()->first('statuses'))->toBe(__('dashboard.errors.statuses_required', [], $locale));

    $component->set('from', '2026-06-10')->set('to', '2026-06-01');

    expect($component->errors()->first('range'))->toBe(__('dashboard.errors.range_invalid', [], $locale));
})->with(['en', 'es']);

it('shows the card own year-window message in the active locale', function (string $locale) {
    app()->setLocale($locale);

    $component = Sales::component()->set('from', '0999-01-01');

    expect($component->errors()->first('range'))->toBe(__('dashboard.sales.range_year_window', ['min' => 1000, 'max' => 9998], $locale));
})->with(['en', 'es']);

it('uses the Spanish translation end to end for a Spanish administrator opening the lazy card over HTTP', function () {
    $actor = Ui::actor(['orders.view'], ['ui_locale' => 'es']);
    $this->actingAs($actor);

    $html = Sales::lazyLoadedHtml('/dashboard');

    expect($html)->not->toBeNull()
        ->and(Ui::text($html, 'sales-title'))->toBe(__('dashboard.sales.title', [], 'es'))
        ->and(array_column(Sales::moneyRows($html), 'label')[0])->toBe('17 may');
});
