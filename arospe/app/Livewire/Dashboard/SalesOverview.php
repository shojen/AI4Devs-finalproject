<?php

namespace App\Livewire\Dashboard;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Dashboard\GetOrdersSeries;
use App\Actions\Dashboard\GetSalesSeries;
use App\Actions\Orders\ToNumericString;
use App\Concerns\ChecksAbilitiesSafely;
use App\Enums\OrderStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The dashboard's "Sales overview" card (story 0086): a filter bar, a three-tile KPI strip and two
 * Chart.js charts (drawn by resources/js/sales-chart.js), all fed by the story-0082 series actions.
 *
 * Public state is four client-writable scalars, mirrored in the URL (`g`, `from`, `to`, `s`). Every
 * change is validated in `updating()` BEFORE Livewire assigns it: a refused value throws, so the
 * property keeps its last valid value and the card keeps rendering the previous data. The candidate
 * state is run through the series actions there (they own the range and status rules and their
 * translated messages) and the result is memoised for the render of the same request, so a change
 * runs the two aggregate queries once.
 *
 * Authorization: the render path computes nothing for an actor without `orders.view` (no query, no
 * refusal log); every update path authorizes through LogRefusedPrivilegedAttempt first, so a
 * never-authorized or revoked actor is refused (403) and the refusal is logged.
 *
 * @phpstan-type MoneySeries array{granularity: SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, statuses: list<OrderStatus>, totalSales: string, totalIncome: string, points: list<array{bucket: string, label: CarbonImmutable, sales: string, income: string}>}
 * @phpstan-type OrdersSeries array{granularity: SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, statuses: list<OrderStatus>, totalOrders: int, points: list<array{bucket: string, label: CarbonImmutable, total: int, byStatus: array<string, int>}>}
 * @phpstan-type SeriesPair array{money: MoneySeries, orders: OrdersSeries}
 */
#[Lazy]
class SalesOverview extends Component
{
    use ChecksAbilitiesSafely;

    private const int MAX_STATUSES = 5;

    private const int MIN_YEAR = 1000;

    private const int MAX_YEAR = 9998;

    #[Url(as: 'g', except: 'day')]
    public string $granularity = 'day';

    /** First day of a custom range (Y-m-d); '' means the granularity's default range. */
    #[Url(as: 'from', except: '')]
    public string $from = '';

    /** Last inclusive day of a custom range (Y-m-d). */
    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * Selected order-status values. Initialised EMPTY on purpose: Livewire merges a URL array into the
     * property's initial array by index, which would mix a link's statuses with the defaults; mount()
     * turns the empty state into the default set.
     *
     * @var array<array-key, mixed>
     */
    #[Url(as: 's', except: ['pending', 'processing', 'shipped', 'delivered'])]
    public array $statuses = [];

    /** @var array{key: string, value: SeriesPair}|null */
    protected ?array $memo = null;

    /**
     * Values Livewire assigned although `updating()` refused them, by property, with the value to put
     * back. Livewire swallows a ValidationException thrown from a lifecycle hook (the property is still
     * assigned and `updated()` still runs), so a refusal is undone in `updated()` instead.
     *
     * @var array<string, mixed>
     */
    protected array $refusedUpdates = [];

    public function mount(): void
    {
        $this->sanitizeUrlState();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-4 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-busy="true" aria-label="{{ __('dashboard.sales.loading') }}" role="status">
            <span class="sr-only">{{ __('dashboard.sales.loading') }}</span>
            <flux:skeleton.group animate="shimmer" class="flex flex-col gap-4">
                <flux:skeleton class="h-6 w-48" />
                <flux:skeleton class="h-10 w-full" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:skeleton class="h-24" />
                    <flux:skeleton class="h-24" />
                    <flux:skeleton class="h-24" />
                </div>
                <div class="grid gap-4 xl:grid-cols-2">
                    <flux:skeleton class="h-64" />
                    <flux:skeleton class="h-64" />
                </div>
            </flux:skeleton.group>
        </div>
        HTML;
    }

    /**
     * Validates the candidate value before Livewire assigns it. Throwing keeps the previous value.
     */
    public function updating(string $name, mixed $value): void
    {
        $property = explode('.', $name)[0];

        if (! in_array($property, ['granularity', 'from', 'to', 'statuses'], true)) {
            return;
        }

        $this->authorizeUpdate();
        $this->refuseTamperedPath($name, $property);
        $this->resetErrorBag();

        $previous = $this->{$property};

        try {
            $this->seriesFor($this->stateAfterUpdate($name, $value));
        } catch (ValidationException $exception) {
            $this->refusedUpdates[$property] ??= $previous;

            throw $exception;
        }
    }

    /**
     * Only `<scalar>` and `statuses.<index>` (an integer below the cap) are paths a legitimate client
     * sends. Anything else (`from.x`, `statuses.*`, `statuses.0.x`, ...) is tampering: Livewire cannot
     * assign a sub-path of a string and would fail with a 500 after this hook, so the update is refused
     * with a 422 before anything is assigned (the previous state is kept and nothing is dispatched).
     */
    protected function refuseTamperedPath(string $name, string $property): void
    {
        if ($name === $property) {
            return;
        }

        $segments = explode('.', $name);

        $isIndexedStatus = $property === 'statuses'
            && count($segments) === 2
            && preg_match('/^\d+$/', $segments[1]) === 1
            && (int) $segments[1] < self::MAX_STATUSES;

        abort_unless($isIndexedStatus, 422);
    }

    /**
     * Puts back the refused value of the property being processed (only that entry), or, for an
     * accepted change, normalises what the client wrote and tells the charts. A refused change
     * dispatches nothing, applies no side effect and keeps the previous data.
     */
    public function updated(string $name): void
    {
        $property = explode('.', $name)[0];

        if (array_key_exists($property, $this->refusedUpdates)) {
            $this->{$property} = $this->refusedUpdates[$property];

            unset($this->refusedUpdates[$property]);

            return;
        }

        if ($property === 'granularity') {
            $this->from = '';
            $this->to = '';
        } elseif ($property === 'from' && $this->from === '') {
            $this->to = '';
        } elseif ($property === 'statuses') {
            $this->statuses = $this->statusValues($this->statuses);
        }

        $this->dispatchSeries();
    }

    /**
     * A preset sets the range AND the granularity.
     */
    public function applyPreset(string $preset): void
    {
        $this->authorizeUpdate();

        $today = $this->today();

        $range = match ($preset) {
            'last_7_days' => [SalesGranularity::Day, $today->subDays(6), $today],
            'last_30_days' => [SalesGranularity::Day, $today->subDays(29), $today],
            'this_month' => [SalesGranularity::Day, $today->startOfMonth(), $today],
            'this_year' => [SalesGranularity::Month, $today->startOfYear(), $today],
            default => null,
        };

        if ($range === null) {
            return;
        }

        $this->applyState([
            'granularity' => $range[0]->value,
            'from' => $range[1]->format('Y-m-d'),
            'to' => $range[2]->format('Y-m-d'),
            'statuses' => $this->statuses,
        ]);
    }

    /**
     * Flips one status chip. The whole candidate selection is validated, so deselecting the last
     * status is refused with the backend's message and the previous data stays.
     */
    public function toggleStatus(string $status): void
    {
        $this->authorizeUpdate();

        if (OrderStatus::tryFrom($status) === null) {
            return;
        }

        $selected = $this->statusValues($this->statuses);
        $selected = in_array($status, $selected, true)
            ? array_values(array_diff($selected, [$status]))
            : [...$selected, $status];

        $this->applyState([...$this->currentState(), 'statuses' => $this->statusValues($selected)]);
    }

    /**
     * Back to the default status set (every status except Cancelled).
     */
    public function resetStatuses(): void
    {
        $this->authorizeUpdate();

        $this->applyState([...$this->currentState(), 'statuses' => $this->defaultStatusValues()]);
    }

    public function render(): View
    {
        if (! $this->allowsSafely('viewAny', Order::class)) {
            return view('livewire.dashboard.sales-overview', ['isAllowed' => false]);
        }

        try {
            $series = $this->seriesFor($this->currentState());
        } catch (ValidationException) {
            $this->resetToDefaults();

            $series = $this->seriesFor($this->currentState());
        }

        $money = $series['money'];
        $orders = $series['orders'];
        $granularity = $money['granularity'];

        $moneyRows = [];
        $orderRows = [];

        foreach ($money['points'] as $index => $point) {
            $label = $this->label($point['label'], $granularity);

            $moneyRows[] = ['bucket' => $point['bucket'], 'label' => $label, 'sales' => $point['sales'], 'income' => $point['income']];
            $orderRows[] = ['bucket' => $point['bucket'], 'label' => $label, 'total' => $orders['points'][$index]['total'], 'byStatus' => $orders['points'][$index]['byStatus']];
        }

        $toNumeric = app(ToNumericString::class);
        $totalSales = $toNumeric($money['totalSales']);
        $totalIncome = $toNumeric($money['totalIncome']);
        $hasIncome = bccomp($totalIncome, '0', 2) > 0;
        $hasSales = bccomp($totalSales, '0', 2) > 0;

        return view('livewire.dashboard.sales-overview', [
            'isAllowed' => true,
            'statusCases' => OrderStatus::cases(),
            'selectedStatuses' => $orders['statuses'],
            'presets' => ['last_7_days', 'last_30_days', 'this_month', 'this_year'],
            'activePreset' => $this->activePreset($money['from'], $money['to'], $granularity),
            'moneyRows' => $moneyRows,
            'orderRows' => $orderRows,
            'totalSales' => $totalSales,
            'totalIncome' => $totalIncome,
            'totalOrders' => $orders['totalOrders'],
            'collectedPercent' => $hasSales && $hasIncome ? $this->collectedPercent($totalSales, $totalIncome) : null,
            'showIncomeHint' => $hasSales && ! $hasIncome,
            'isEmpty' => $orders['totalOrders'] === 0 && ! $hasSales,
            'summaryFrom' => $money['from']->settings(['locale' => app()->getLocale()])->isoFormat('LL'),
            'summaryTo' => $money['to']->settings(['locale' => app()->getLocale()])->isoFormat('LL'),
            'payload' => $this->payload($series),
            'today' => $this->today()->format('Y-m-d'),
        ]);
    }

    /**
     * Refuses an unauthorized or revoked actor on every update path, logging the refusal.
     */
    protected function authorizeUpdate(): void
    {
        app(LogRefusedPrivilegedAttempt::class)->authorize('viewAny', Order::class, targetType: 'order');
    }

    /**
     * @return array{granularity: string, from: string, to: string, statuses: array<array-key, mixed>}
     */
    protected function currentState(): array
    {
        return [
            'granularity' => $this->granularity,
            'from' => $this->from,
            'to' => $this->to,
            'statuses' => $this->statuses,
        ];
    }

    /**
     * The state the component would have if `$name` were set to `$value`, following the same
     * side effects `updated()` applies (a granularity change resets the range; clearing the start
     * clears the end), so the validated candidate and the rendered state share one memo key.
     *
     * @return array{granularity: string, from: string, to: string, statuses: array<array-key, mixed>}
     */
    protected function stateAfterUpdate(string $name, mixed $value): array
    {
        $state = $this->currentState();

        if ($name === 'granularity') {
            $state['granularity'] = $this->asStringOrInvalid($value);
            $state['from'] = '';
            $state['to'] = '';
        } elseif ($name === 'from' || $name === 'to') {
            $state[$name] = $this->asStringOrInvalid($value);

            if ($name === 'from' && $state['from'] === '') {
                $state['to'] = '';
            }
        } elseif ($name === 'statuses') {
            $state['statuses'] = is_array($value) ? $value : ['invalid'];
        } else {
            $statuses = $state['statuses'];
            $statuses[(int) substr($name, strlen('statuses.'))] = $value;
            $state['statuses'] = $statuses;
        }

        if ($name === 'statuses' || str_starts_with($name, 'statuses.')) {
            $state['statuses'] = array_values($state['statuses']);
        }

        return $state;
    }

    /**
     * A non-string value for a string property can only be tampering: a marker no filter accepts.
     */
    protected function asStringOrInvalid(mixed $value): string
    {
        return is_string($value) ? $value : "\0invalid";
    }

    /**
     * Validates a whole candidate state, then assigns it and tells the charts.
     *
     * @param  array{granularity: string, from: string, to: string, statuses: array<array-key, mixed>}  $state
     */
    protected function applyState(array $state): void
    {
        $this->resetErrorBag();
        $this->seriesFor($state);

        $this->granularity = $state['granularity'];
        $this->from = $state['from'];
        $this->to = $state['to'];
        $this->statuses = $state['statuses'];

        $this->dispatchSeries();
    }

    protected function dispatchSeries(): void
    {
        $payload = $this->payload($this->seriesFor($this->currentState()));

        $this->dispatch('sales-overview-updated', ...$payload);
    }

    /**
     * Both series for a state, authorized and validated by the actions themselves. Memoised by state
     * for the lifetime of the request so the validation in `updating()` and the render that follows
     * share ONE pair of queries.
     *
     * @param  array{granularity: string, from: string, to: string, statuses: array<array-key, mixed>}  $state
     * @return SeriesPair
     *
     * @throws ValidationException
     */
    protected function seriesFor(array $state): array
    {
        $key = (string) json_encode($state);

        if ($this->memo !== null && $this->memo['key'] === $key) {
            return $this->memo['value'];
        }

        $errors = $this->formatErrors($state);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $granularity = SalesGranularity::from($state['granularity']);
        [$from, $to] = $this->rangeFor($granularity, $state['from'], $state['to']);
        $statuses = $this->statusEnums($state['statuses']);

        $value = [
            'money' => app(GetSalesSeries::class)($granularity, $from, $to, $statuses),
            'orders' => app(GetOrdersSeries::class)($granularity, $from, $to, $statuses),
        ];

        $this->memo = ['key' => $key, 'value' => $value];

        return $value;
    }

    /**
     * The shape and bounds of a state, before any action runs: granularity enum, at most five known
     * statuses, strict Y-m-d dates inside the supported years. Messages are keyed the way the inputs
     * show them (`range` for both dates, `statuses`, `granularity`).
     *
     * @param  array{granularity: string, from: string, to: string, statuses: array<array-key, mixed>}  $state
     * @return array<string, string>
     */
    protected function formatErrors(array $state): array
    {
        $errors = [];

        if (SalesGranularity::tryFrom($state['granularity']) === null) {
            $errors['granularity'] = __('dashboard.sales.granularity_invalid');
        }

        if (count($state['statuses']) > self::MAX_STATUSES) {
            $errors['statuses'] = __('dashboard.sales.statuses_max', ['max' => self::MAX_STATUSES]);
        } else {
            foreach ($state['statuses'] as $status) {
                if (! is_string($status) || OrderStatus::tryFrom($status) === null) {
                    $errors['statuses'] = __('dashboard.sales.statuses_invalid');

                    break;
                }
            }
        }

        foreach (['from', 'to'] as $bound) {
            if ($state[$bound] === '') {
                continue;
            }

            $day = $this->parseDay($state[$bound]);

            if ($day === null) {
                $errors['range'] ??= __('dashboard.sales.date_invalid');
            } elseif ($day->year < self::MIN_YEAR || $day->year > self::MAX_YEAR) {
                $errors['range'] ??= __('dashboard.sales.range_year_window', ['min' => self::MIN_YEAR, 'max' => self::MAX_YEAR]);
            }
        }

        return $errors;
    }

    /**
     * Strict `Y-m-d`: Carbon alone would roll 2026-02-30 over to 2 March.
     */
    protected function parseDay(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (InvalidFormatException) {
            return null;
        }

        return $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * The range a state shows. No start means the granularity's default range (ending on the end date
     * when only that is set, otherwise today); a start without an end runs to today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function rangeFor(SalesGranularity $granularity, string $from, string $to): array
    {
        $end = $to === '' ? null : $this->parseDay($to);
        $start = $from === '' ? null : $this->parseDay($from);
        $end ??= $this->today();

        if ($start === null) {
            $start = match ($granularity) {
                SalesGranularity::Day => $end->subDays(29),
                SalesGranularity::Month => $end->startOfMonth()->subMonths(11),
                SalesGranularity::Year => $end->startOfYear()->subYears(4),
            };
        }

        return [$start, $end];
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /**
     * Selected statuses as OrderStatus, unknown values dropped, in OrderStatus::cases() order.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<OrderStatus>
     */
    protected function statusEnums(array $values): array
    {
        return array_values(array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $status): bool => in_array($status->value, $values, true),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    protected function statusValues(array $values): array
    {
        return array_map(fn (OrderStatus $status): string => $status->value, $this->statusEnums($values));
    }

    /**
     * @return list<string>
     */
    protected function defaultStatusValues(): array
    {
        return array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::defaultDashboardSet());
    }

    /**
     * Brings a state read from the URL back to something valid: each group (granularity, statuses,
     * date range) falls back to its default on its own, so a broken link opens the default overview.
     */
    protected function sanitizeUrlState(): void
    {
        $errors = $this->formatErrors($this->currentState());

        if (isset($errors['granularity'])) {
            $this->granularity = 'day';
        }

        $statuses = $this->statuses;

        if (isset($errors['statuses']) || $statuses === []) {
            $this->statuses = $this->defaultStatusValues();
        } else {
            $this->statuses = array_values(array_unique($statuses));
        }

        if (isset($errors['range']) || $this->hasNonScalarBoundInUrl()) {
            $this->from = '';
            $this->to = '';
        }

        if (! $this->allowsSafely('viewAny', Order::class)) {
            return;
        }

        try {
            $this->seriesFor($this->currentState());
        } catch (ValidationException) {
            $this->from = '';
            $this->to = '';
        }
    }

    /**
     * Livewire silently drops a URL value it cannot assign to a string property (`from[]=...`), which
     * would leave the other bound alone and look like a valid single-bound range. The raw query is
     * read to tell that garbage apart from a bound that was never sent.
     */
    protected function hasNonScalarBoundInUrl(): bool
    {
        $query = request()->query();

        if (app('livewire')->isLivewireRequest()) {
            $query = [];
            parse_str((string) parse_url((string) request()->header('Referer'), PHP_URL_QUERY), $query);
        }

        return is_array($query['from'] ?? null) || is_array($query['to'] ?? null);
    }

    protected function resetToDefaults(): void
    {
        $this->granularity = 'day';
        $this->from = '';
        $this->to = '';
        $this->statuses = $this->defaultStatusValues();
    }

    /**
     * The preset matching the resolved range and granularity, for the highlight.
     */
    protected function activePreset(CarbonImmutable $from, CarbonImmutable $to, SalesGranularity $granularity): ?string
    {
        $today = $this->today();

        if (! $to->equalTo($today)) {
            return null;
        }

        return match (true) {
            $granularity === SalesGranularity::Day && $from->equalTo($today->subDays(6)) => 'last_7_days',
            $granularity === SalesGranularity::Day && $from->equalTo($today->subDays(29)) => 'last_30_days',
            $granularity === SalesGranularity::Day && $from->equalTo($today->startOfMonth()) => 'this_month',
            $granularity === SalesGranularity::Month && $from->equalTo($today->startOfYear()) => 'this_year',
            default => null,
        };
    }

    /**
     * The x label of a bucket, formatted for the active locale (Day `D MMM`, Month `MMM YYYY`,
     * Year `YYYY`), so the charts and the tables show the same plain text.
     */
    protected function label(CarbonImmutable $bucketStart, SalesGranularity $granularity): string
    {
        $label = $bucketStart->settings(['locale' => app()->getLocale()])->isoFormat(match ($granularity) {
            SalesGranularity::Day => 'D MMM',
            SalesGranularity::Month => 'MMM YYYY',
            SalesGranularity::Year => 'YYYY',
        });

        // CLDR abbreviates Spanish months with a full stop ("may.", "jul."); the labels read "1 may".
        return str_replace('.', '', $label);
    }

    /**
     * Income / sales x 100, rounded half up to at most two decimals with trailing zeros trimmed, from
     * decimal strings (no float), with the active locale's decimal separator.
     *
     * @param  numeric-string  $sales
     * @param  numeric-string  $income
     */
    protected function collectedPercent(string $sales, string $income): string
    {
        $ratio = bcdiv(bcmul($income, '100', 20), $sales, 20);
        $percent = bcadd($ratio, '0.005', 2);

        if (str_contains($percent, '.')) {
            $percent = rtrim(rtrim($percent, '0'), '.');
        }

        return app()->getLocale() === 'es' ? str_replace('.', ',', $percent) : $percent;
    }

    /**
     * The flat, scalar-only event/x-data payload for the two charts. The numbers are floats for
     * plotting only; the tiles and tables keep the decimal strings.
     *
     * @param  SeriesPair  $series
     * @return array{locale: string, granularity: string, money: array{labels: list<string>, sales: list<float>, income: list<float>}, orders: array{labels: list<string>, datasets: list<array{status: string, label: string, data: list<int>}>}}
     */
    protected function payload(array $series): array
    {
        $money = $series['money'];
        $orders = $series['orders'];
        $granularity = $money['granularity'];

        $labels = [];
        $sales = [];
        $income = [];

        foreach ($money['points'] as $point) {
            $labels[] = $this->label($point['label'], $granularity);
            $sales[] = (float) $point['sales'];
            $income[] = (float) $point['income'];
        }

        $datasets = [];

        foreach ($orders['statuses'] as $status) {
            $datasets[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'data' => array_map(fn (array $point): int => $point['byStatus'][$status->value], $orders['points']),
            ];
        }

        return [
            'locale' => app()->getLocale(),
            'granularity' => $granularity->value,
            'money' => ['labels' => $labels, 'sales' => $sales, 'income' => $income],
            'orders' => ['labels' => $labels, 'datasets' => $datasets],
        ];
    }
}
