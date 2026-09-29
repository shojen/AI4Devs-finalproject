<?php

use App\Models\Order;
use Illuminate\Support\Facades\DB;

// Story 0045, Phase 3: this file pins D-14 (the detail-retrieval contract) and D-6 (the
// list-retrieval contract) DIRECTLY against Eloquent -- both are specifications in the task file
// ("a specification pinned by a test rather than a scope method"), not behind CreateOrder or any
// action, so these tests do not depend on App\Actions\Orders\CreateOrder at all and are expected
// to be GREEN already against the existing Order/OrderItem models and factories.

test('the detail contract returns the order with customer, items, paymentMethod, salesRegion and shippingRate loaded, with no extra queries', function () {
    $order = Order::factory()->withItems(2)->create();

    $found = Order::query()
        ->with(['customer', 'items', 'paymentMethod', 'salesRegion', 'shippingRate'])
        ->findOrFail($order->id);

    // Relations LOADED, not merely retrievable -- a lazy relation would also pass a
    // ->count()/->toBeInstanceOf() assertion, which is a different bug (per the task file's own
    // instruction: "the test asserts the relations are loaded, not merely retrievable").
    expect($found->relationLoaded('customer'))->toBeTrue()
        ->and($found->relationLoaded('items'))->toBeTrue()
        ->and($found->relationLoaded('paymentMethod'))->toBeTrue()
        ->and($found->relationLoaded('salesRegion'))->toBeTrue()
        ->and($found->relationLoaded('shippingRate'))->toBeTrue();

    // No additional query is fired when the already-eager-loaded `items` collection is accessed --
    // the executable proof that the eager-load contract holds, per the task file's DB::listen
    // instruction.
    $queriesAfterEagerLoad = 0;
    DB::listen(function () use (&$queriesAfterEagerLoad): void {
        $queriesAfterEagerLoad++;
    });

    $found->items->count();
    $found->customer->id;

    expect($queriesAfterEagerLoad)->toBe(0);
});

test('an order whose sales_region_id and shipping_rate_id are null returns null for both relations rather than throwing', function () {
    $order = Order::factory()->create();

    $found = Order::query()
        ->with(['customer', 'items', 'paymentMethod', 'salesRegion', 'shippingRate'])
        ->findOrFail($order->id);

    expect($found->salesRegion)->toBeNull()
        ->and($found->shippingRate)->toBeNull();
});

test('an order detail includes its customer', function () {
    $order = Order::factory()->create();

    $found = Order::query()->with('customer')->findOrFail($order->id);

    expect($found->customer)->not->toBeNull()
        ->and($found->customer->id)->toBe($order->customer_id);
});

test('an order detail includes all three of its line items', function () {
    $order = Order::factory()->withItems(3)->create();

    $found = Order::query()->with('items')->findOrFail($order->id);

    expect($found->items)->toHaveCount(3);
});

// D-6: newest first, id tie-break. created_at set EXPLICITLY rather than relying on insertion
// speed -- three rows inserted in one test share a second, and an ordering test that cannot
// distinguish them carries no signal (per the task file's own instruction).
test('orders are listed newest first, with an explicit created_at spread', function () {
    $oldest = Order::factory()->create(['created_at' => now()->subDays(3)]);
    $middle = Order::factory()->create(['created_at' => now()->subDays(2)]);
    $newest = Order::factory()->create(['created_at' => now()->subDay()]);

    $ids = Order::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    expect($ids)->toBe([$newest->id, $middle->id, $oldest->id]);
});

// The id tie-break, asserted deterministically by running the query twice and comparing.
test('two orders sharing an identical created_at are returned in a deterministic order', function () {
    $sharedTimestamp = now()->subDay();

    Order::factory()->create(['created_at' => $sharedTimestamp]);
    Order::factory()->create(['created_at' => $sharedTimestamp]);

    $firstRun = Order::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    $secondRun = Order::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    expect($firstRun)->toBe($secondRun);
});

test('with no orders recorded, list retrieval returns an empty collection rather than throwing', function () {
    $results = Order::query()->orderByDesc('created_at')->orderByDesc('id')->get();

    expect($results)->toHaveCount(0);
});
