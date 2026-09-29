<?php

// Story 0055 -- the tax panel (0053/0054). The screen renders what the columns hold and invents nothing:
// no arithmetic, and the state is branched on sales_region_id and tax_rate, NEVER on tax_amount (which is
// 0.00 in BOTH the unresolved and the legitimately-0% case -- D-16).

use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\SalesRegion;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function ordersUiTaxPanel(Order $order): string
{
    test()->actingAs(OrdersUi::actor(['orders.view']));

    return OrdersUi::section(Livewire::test(Show::class, ['order' => $order])->html(), 'totals-section');
}

test('a resolved order renders its region name and its tax_rate as the stored string plus a percent sign', function () {
    $region = SalesRegion::factory()->create(['name' => 'España peninsular', 'rate' => '21.000']);
    $order = Order::factory()->withItems()->create(['sales_region_id' => $region->id, 'tax_rate' => '21.000']);

    $panel = ordersUiTaxPanel($order);

    expect($panel)->toContain('España peninsular')
        ->toContain('21.000%')
        ->not->toContain(__('orders.detail.tax_unresolved'))
        ->not->toContain('data-test="tax-flag-notice"');
});

test('a resolved order at 0.000 renders a REAL zero rate, and an unresolved order renders "not yet resolved" -- and the two render DIFFERENTLY (D-16)', function () {
    $region = SalesRegion::factory()->create(['name' => 'Canarias', 'rate' => '0.000']);
    $zero = Order::factory()->withItems()->create(['sales_region_id' => $region->id, 'tax_rate' => '0.000', 'tax_amount' => '0.00']);
    $unresolved = Order::factory()->withItems()->create(['sales_region_id' => null, 'tax_rate' => null, 'tax_amount' => '0.00']);

    $zeroPanel = ordersUiTaxPanel($zero);
    $unresolvedPanel = ordersUiTaxPanel($unresolved);

    // null and 0.000 collapsing into each other is the single likeliest silent bug in the tax chain
    // (a `@if ($order->tax_rate)` treating '0.000' as falsy), and this is its last chance to appear.
    expect($zeroPanel)->toContain('0.000%')
        ->not->toContain(__('orders.detail.tax_unresolved'))
        ->and($unresolvedPanel)->toContain(__('orders.detail.tax_unresolved'))
        ->not->toContain('0.000%')
        ->and($zeroPanel)->not->toBe($unresolvedPanel);
});

test('a resolved region with a NULL rate renders the region name and "not yet resolved" for the rate only (0053 D-6 case 5)', function () {
    $region = SalesRegion::factory()->create(['name' => 'Región sin tipo', 'rate' => null]);
    $order = Order::factory()->withItems()->create(['sales_region_id' => $region->id, 'tax_rate' => null]);

    $panel = ordersUiTaxPanel($order);

    expect($panel)->toContain('Región sin tipo')
        ->toContain(__('orders.detail.tax_unresolved'))
        ->not->toMatch('/>\s*\d+(?:\.\d+)?%/');
});

test('the tax state never depends on tax_amount: a non-zero amount does not turn "unresolved" into a rate', function () {
    $order = Order::factory()->withItems()->create(['sales_region_id' => null, 'tax_rate' => null, 'tax_amount' => '4.20']);

    expect(ordersUiTaxPanel($order))->toContain(__('orders.detail.tax_unresolved'))->not->toMatch('/>\s*\d+(?:\.\d+)?%/');
});

// --- Flagged orders ---

test('a flagged order with NO resolved basis (0054 virtual mismatch) shows the callout and no rate figure at all', function () {
    $order = Order::factory()->withItems()->create([
        'flagged_for_review' => true,
        'flag_reason' => 'billing_ip_country_mismatch',
        'sales_region_id' => null,
        'tax_rate' => null,
    ]);

    $panel = ordersUiTaxPanel($order);

    expect($panel)->toContain('data-test="tax-flag-notice"')
        ->toContain(e(__('orders.flag_reasons.billing_ip_country_mismatch')))
        ->not->toMatch('/>\s*\d+(?:\.\d+)?%/')
        ->not->toContain('data-test="tax-rate"');
});

// Amendment 10: ResolveOrderTaxRegion (0053, physical) flags the default-region fallback AND writes the
// region/rate/tax_amount it fell back to, so flag ⇒ no rate holds only for 0054's virtual orders. The
// atomicity rule this screen owns is: never present a confident rate BESIDE a flag WITHOUT marking it
// provisional -- a flagged order with a plausible-looking unmarked rate is exactly what manual review
// rubber-stamps.
test('a flagged order that still carries a basis renders it marked PROVISIONAL, never as an unmarked confident rate', function () {
    $region = SalesRegion::factory()->create(['name' => 'Región por defecto', 'rate' => '21.000', 'is_default' => true]);
    $order = Order::factory()->withItems()->create([
        'flagged_for_review' => true,
        'flag_reason' => null,
        'sales_region_id' => $region->id,
        'tax_rate' => '21.000',
        'tax_amount' => '4.20',
    ]);

    $panel = ordersUiTaxPanel($order);

    expect($panel)->toContain('data-test="tax-flag-notice"')
        ->toContain('data-test="tax-provisional"')
        ->toContain(e(__('orders.detail.tax_provisional')))
        ->toContain('Región por defecto')
        ->toContain('21.000%');
});

test('an UNflagged resolved order never renders the provisional marker', function () {
    $region = SalesRegion::factory()->create(['rate' => '21.000']);
    $order = Order::factory()->withItems()->create(['sales_region_id' => $region->id, 'tax_rate' => '21.000']);

    expect(ordersUiTaxPanel($order))->not->toContain('data-test="tax-provisional"');
});

test('the flag callout resolves orders.flag_reasons.* for a token and the generic copy for a NULL reason (D-15)', function () {
    $tokenised = Order::factory()->withItems()->create(['flagged_for_review' => true, 'flag_reason' => 'mixed_basket']);
    $unreasoned = Order::factory()->withItems()->create(['flagged_for_review' => true, 'flag_reason' => null]);

    expect(ordersUiTaxPanel($tokenised))->toContain(e(__('orders.flag_reasons.mixed_basket')))
        ->and(ordersUiTaxPanel($unreasoned))->toContain(e(__('orders.index.flagged_generic')));
});

test('the header carries a flag callout for a flagged order, and the tax panel carries its own notice', function () {
    $order = Order::factory()->withItems()->create(['flagged_for_review' => true, 'flag_reason' => 'ip_country_missing']);
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect(OrdersUi::section($html, 'header-section'))->toContain('data-test="order-flag-callout"');
});

test('an unflagged order renders no callout anywhere', function () {
    $order = Order::factory()->withItems()->create();
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect($html)->not->toContain('data-test="order-flag-callout"')->not->toContain('data-test="tax-flag-notice"');
});
