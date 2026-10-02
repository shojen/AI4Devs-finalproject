<?php

namespace Tests\Support\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\SalesOverview;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Shared fixtures and DOM probes for the story 0086 sales-overview tests.
 *
 * A static class, never global functions (a global helper declared by two test files is a fatal
 * redeclare -- see tests/Support/Orders/OrdersUi.php). Probes work on a RENDERED html string and
 * address elements by `data-test` hook, delegating the low-level parsing to DashboardUi.
 *
 * The hook names below are the CONTRACT the Blade view must honour (the story fixes the sr-only
 * tables and the canvas hooks; the rest are chosen here, in one place):
 *
 *  sales-overview                      the card root
 *  sales-title                         the card heading
 *  sales-kpi-{sales|income|orders}     the tile VALUE element (a <x-money> span, or the integer)
 *  sales-kpi-{sales|income|orders}-definition   the tile's one-line definition text
 *  sales-kpi-collected                 the "Collected: N% of sales" hint
 *  sales-kpi-income-hint               the "Income is counted once orders are paid" hint
 *  sales-chip-{status}                 a status chip (<button aria-pressed="true|false">)
 *  sales-empty                         the empty-period message
 *  sales-summary                       the aria-live line summarising the totals
 *  sales-money-table                   sr-only <table> of chart A; sales-money-row-{bucket} per row with
 *                                      cells sales-money-label-{bucket}, sales-money-sales-{bucket},
 *                                      sales-money-income-{bucket}
 *  sales-orders-table                  sr-only <table> of chart B; header cells sales-orders-th-{status}
 *                                      (document order = stack order); per row sales-orders-row-{bucket}
 *                                      with sales-orders-label-{bucket}, sales-orders-cell-{bucket}-{status}
 *                                      and sales-orders-total-{bucket}
 *  money-chart-canvas / orders-chart-canvas   the two canvases (aria-hidden="true")
 */
final class SalesOverviewUi
{
    /** The timezone every seeded wall-clock time is expressed in. */
    public const string TZ = 'Europe/Madrid';

    /**
     * @param  array<string, mixed>  $query
     */
    public static function component(array $query = []): Testable
    {
        return Livewire::withoutLazyLoading()->withQueryParams($query)->test(SalesOverview::class);
    }

    /**
     * Freeze "now" at noon, Madrid, on the given day (the filter tests' reference day is 2026-06-15).
     */
    public static function today(string $date = '2026-06-15'): CarbonImmutable
    {
        $now = CarbonImmutable::parse($date.' 12:00:00', self::TZ);

        test()->travelTo($now);

        return $now;
    }

    /**
     * An order with an explicit Madrid wall-time created_at. status / payment_status / totals are not
     * mass-assignable, so they go through the (unguarded) factory state.
     */
    public static function order(
        string $createdAt,
        string $total = '100.00',
        OrderStatus $status = OrderStatus::Delivered,
        PaymentStatus $payment = PaymentStatus::PendingPayment,
        string $refunded = '0.00',
    ): Order {
        return Order::factory()->state([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'total' => $total,
            'status' => $status,
            'payment_status' => $payment,
            'refunded_amount' => $refunded,
        ])->create();
    }

    /**
     * Emulate what the browser does for a #[Lazy] child: GET the page, take the child's placeholder
     * (its `__lazyLoad` payload and snapshot), then POST the lazy-load call to Livewire's update
     * endpoint with the PAGE URL as Referer -- the only place a lazy request can read the query
     * string from. Returns the child's rendered html, or null when the page carries no lazy
     * placeholder for the component.
     *
     * A page request alone proves nothing about mount(): it renders only the placeholder.
     */
    public static function lazyLoadedHtml(string $url): ?string
    {
        $page = test()->get($url)->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', (string) $page, $snapshots);

        foreach ($snapshots[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);
            $decoded = json_decode($snapshot, true);

            if (($decoded['memo']['name'] ?? null) !== 'dashboard.sales-overview') {
                continue;
            }

            // The placeholder's root carries x-intersect (or x-init) with `$wire.__lazyLoad('<payload>')`.
            if (preg_match('/__lazyLoad\(&#039;([^&]+)&#039;\)|__lazyLoad\(\'([^\']+)\'\)/', (string) $page, $payload) !== 1) {
                return null;
            }

            $response = test()
                ->withHeaders(['X-Livewire' => '1', 'Referer' => url($url)])
                ->postJson(Livewire::getUpdateUri(), [
                    'components' => [[
                        'snapshot' => $snapshot,
                        'updates' => (object) [],
                        'calls' => [['path' => '', 'method' => '__lazyLoad', 'params' => [$payload[1] !== '' ? $payload[1] : $payload[2]]]],
                    ]],
                ])
                ->assertOk();

            return (string) $response->json('components.0.effects.html');
        }

        return null;
    }

    /**
     * The values of the default status set, in canonical order.
     *
     * @return list<string>
     */
    public static function defaultStatusValues(): array
    {
        return array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::defaultDashboardSet());
    }

    /**
     * The text of a KPI tile value.
     */
    public static function kpi(string $html, string $tile): string
    {
        return DashboardUi::text($html, "sales-kpi-{$tile}");
    }

    /**
     * Chart A's sr-only table: bucket => [label, sales, income], in document order.
     *
     * @return array<string, array{label: string, sales: string, income: string}>
     */
    public static function moneyRows(string $html): array
    {
        $rows = [];

        foreach (DashboardUi::hooksStartingWith($html, 'sales-money-row-') as $hook) {
            $bucket = substr($hook, strlen('sales-money-row-'));
            $rows[$bucket] = [
                'label' => DashboardUi::text($html, "sales-money-label-{$bucket}"),
                'sales' => DashboardUi::text($html, "sales-money-sales-{$bucket}"),
                'income' => DashboardUi::text($html, "sales-money-income-{$bucket}"),
            ];
        }

        return $rows;
    }

    /**
     * Chart B's sr-only table: bucket => [label, total, byStatus[status => count]], in document order.
     * Statuses are read from the header cells, so a missing/extra/reordered column fails the probe.
     *
     * @return array<string, array{label: string, total: string, byStatus: array<string, string>}>
     */
    public static function ordersRows(string $html): array
    {
        $statuses = self::orderColumns($html);
        $rows = [];

        foreach (DashboardUi::hooksStartingWith($html, 'sales-orders-row-') as $hook) {
            $bucket = substr($hook, strlen('sales-orders-row-'));
            $byStatus = [];

            foreach ($statuses as $status) {
                $byStatus[$status] = DashboardUi::text($html, "sales-orders-cell-{$bucket}-{$status}");
            }

            $rows[$bucket] = [
                'label' => DashboardUi::text($html, "sales-orders-label-{$bucket}"),
                'total' => DashboardUi::text($html, "sales-orders-total-{$bucket}"),
                'byStatus' => $byStatus,
            ];
        }

        return $rows;
    }

    /**
     * The status columns of chart B's table, in stack (document) order.
     *
     * @return list<string>
     */
    public static function orderColumns(string $html): array
    {
        return array_map(
            fn (string $hook): string => substr($hook, strlen('sales-orders-th-')),
            DashboardUi::hooksStartingWith($html, 'sales-orders-th-'),
        );
    }

    /**
     * The bucket keys both tables show, asserted identical (the two charts always share their buckets).
     *
     * @return list<string>
     */
    public static function buckets(string $html): array
    {
        $money = array_keys(self::moneyRows($html));
        $orders = array_keys(self::ordersRows($html));

        expect($orders)->toBe($money, 'both sr-only tables must list the same buckets');

        return $money;
    }

    /**
     * The status chips: status value => pressed.
     *
     * @return array<string, bool>
     */
    public static function chips(string $html): array
    {
        $chips = [];

        foreach (OrderStatus::cases() as $status) {
            $pressed = DashboardUi::attribute($html, "sales-chip-{$status->value}", 'aria-pressed');
            expect($pressed)->not->toBeNull("chip {$status->value} must exist and carry aria-pressed");
            $chips[$status->value] = $pressed === 'true';
        }

        return $chips;
    }

    /**
     * The params of the last `sales-overview-updated` dispatch of the last request, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function lastPayload(Testable $component): ?array
    {
        $found = null;

        foreach ((array) data_get($component->effects, 'dispatches', []) as $dispatch) {
            if (($dispatch['name'] ?? null) === 'sales-overview-updated') {
                $found = $dispatch['params'];
            }
        }

        return $found;
    }

    /**
     * Everything the page shows that a filter must change, for "same state => same page" comparisons.
     *
     * @return array<string, mixed>
     */
    public static function view(Testable $component): array
    {
        $html = $component->html();

        return [
            'money' => self::moneyRows($html),
            'orders' => self::ordersRows($html),
            'chips' => self::chips($html),
            'kpis' => [self::kpi($html, 'sales'), self::kpi($html, 'income'), self::kpi($html, 'orders')],
        ];
    }
}
