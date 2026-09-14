<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet -- every
// test below is expected to fail with "Target class [App\Actions\Orders\CreateOrder] does not
// exist" until backend-expert implements it.
//
// backend-qa flagged this group explicitly: a false "resolved" state here would mask stories
// 0053/0054/0037's actual work, and would do so SILENTLY, because a populated column looks like a
// working feature. D-9: sales_region_id / shipping_rate_id stay null; tax_rate stays null, NEVER
// 0.000 (D-8, mirroring sales_regions.rate's own "not configured" vs "a legitimate 0%" distinction);
// flagged_for_review stays false (D-10); tax_amount/shipping_amount are 0.00 and total equals
// subtotal (D-8).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingNothingResolvedCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

function createPlainOrder(): Order
{
    actingNothingResolvedCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['price' => '10.00']);

    return app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ]);
}

test('a newly created order sales_region_id is null', function () {
    expect(createPlainOrder()->fresh()->sales_region_id)->toBeNull();
});

test('a newly created order shipping_rate_id is null', function () {
    expect(createPlainOrder()->fresh()->shipping_rate_id)->toBeNull();
});

// tax_rate must be a genuine NULL, never a stringified '0.000' -- the two do not share a meaning.
test('a newly created order tax_rate is null, not 0.000', function () {
    $fresh = createPlainOrder()->fresh();

    expect($fresh->tax_rate)->toBeNull();
});

test('a newly created order flagged_for_review is false', function () {
    expect(createPlainOrder()->fresh()->flagged_for_review)->toBeFalse();
});

test('tax_amount and shipping_amount are 0.00 and total equals subtotal, as decimal strings', function () {
    $fresh = createPlainOrder()->fresh();

    expect((string) $fresh->tax_amount)->toBe('0.00')
        ->and((string) $fresh->shipping_amount)->toBe('0.00')
        ->and((string) $fresh->total)->toBe((string) $fresh->subtotal)
        ->and((string) $fresh->subtotal)->toBe('20.00');
});
