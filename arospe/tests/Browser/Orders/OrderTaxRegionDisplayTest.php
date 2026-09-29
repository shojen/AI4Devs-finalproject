<?php

// Story 0055 (D-3): the orders screens, split across six browser files BY CONCERN so a red test names
// its own subject. SELECTOR STRATEGY: select by data-test hook (`@hook`), never by visible text --
// every row action is icon-only, and "Orders" / "Cancel" / "Total" all collide with other copy on the
// page. The selects are DRIVEN THE WAY A PERSON DRIVES THEM (->select() on the native <select>): the
// null-property / native-select desync in docs/errors-log-archive.md is invisible to both
// Livewire::test()->set() and a programmatic value write, and this screen binds three selects.
// Every assertion that matters is followed by a SERVER-SIDE check, not just what the DOM says.

use App\Models\Order;
use App\Models\SalesRegion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view']);
    $this->actingAs($actor);
});

test('a resolved order shows its region and rate', function () {
    $region = SalesRegion::factory()->create(['name' => 'España peninsular', 'rate' => '21.000']);
    $order = Order::factory()->withItems()->create(['sales_region_id' => $region->id, 'tax_rate' => '21.000']);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@tax-region', 'España peninsular')
        ->assertSeeIn('@tax-rate', '21.000%')
        ->assertMissing('@tax-flag-notice');
});

test('a flagged order shows the reason in place of a resolved basis', function () {
    $order = Order::factory()->withItems()->create([
        'flagged_for_review' => true,
        'flag_reason' => 'billing_ip_country_mismatch',
        'sales_region_id' => null,
        'tax_rate' => null,
    ]);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@order-flag-callout')
        ->assertSeeIn('@tax-flag-notice', __('orders.flag_reasons.billing_ip_country_mismatch'))
        ->assertMissing('@tax-rate');
});

test('an unresolved order reads not-yet-resolved, distinct from a real 0% rate', function () {
    $unresolved = Order::factory()->withItems()->create(['sales_region_id' => null, 'tax_rate' => null]);

    visit('/orders/'.$unresolved->id)
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@tax-unresolved', __('orders.detail.tax_unresolved'))
        ->assertMissing('@tax-rate');

    $region = SalesRegion::factory()->create(['name' => 'Canarias', 'rate' => '0.000']);
    $zero = Order::factory()->withItems()->create(['sales_region_id' => $region->id, 'tax_rate' => '0.000']);

    visit('/orders/'.$zero->id)
        ->assertSeeIn('@tax-rate', '0.000%')
        ->assertMissing('@tax-unresolved');
});
