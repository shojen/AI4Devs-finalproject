<?php

use App\Livewire\Orders\Index as OrdersIndex;
use App\Livewire\Orders\Show as OrdersShow;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:orders.view`, not Spatie's `permission:` — Livewire 4's
    // PersistentMiddleware allowlist carries Laravel's `Authorize` (`can:`)
    // but not Spatie's `PermissionMiddleware`, so a `permission:`-gated route
    // would protect the initial GET only, leaving every addLineItem() /
    // applyStatusChange() / cancelOrder() / recordRefund() /livewire/update
    // round-trip unauthorized. See docs/architecture/authorization.md.
    Route::livewire('orders', OrdersIndex::class)
        ->middleware(['can:orders.view'])
        ->name('orders.index');

    // `can:orders.view` only. Editing line items, transitioning status and
    // cancelling additionally require `orders.edit` (cancelling also
    // `orders.refund`, per OrderPolicy::cancel()); recording a refund requires
    // `orders.refund`. All are per-control checks that live in the component
    // and in the actions behind it — a second `can:` here would 403 the whole
    // page, denying read access this actor's `orders.view` grants (D-5).
    //
    // Declared AFTER `orders` above — `orders/{order}` must not precede the
    // literal `orders` segment, or a plain GET /orders request risks binding
    // as a route parameter under some route-cache orderings.
    Route::livewire('orders/{order}', OrdersShow::class)
        ->middleware(['can:orders.view'])
        ->name('orders.show');
});
