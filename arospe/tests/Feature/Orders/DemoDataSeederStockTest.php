<?php

use App\Models\OrderItem;
use Database\Seeders\DemoDataSeeder;

// Story 0088 (FD-5b): DemoDataSeeder creates its order lines without ever deducting stock, so every
// seeded line must be stock-untracked -- otherwise a later cancellation or refund-restock could
// return stock the seeder never took.

test('every order line seeded by DemoDataSeeder has no returnable stock units and no refunded units out of stock', function () {
    (new DemoDataSeeder)();

    $items = OrderItem::query()->get();

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item->returnableStockUnits())->toBe(0)
            ->and($item->refundedUnitsOutOfStock())->toBe(0)
            ->and($item->stock_undeducted_quantity)->toBe($item->quantity - $item->refunded_quantity);
    }
});
