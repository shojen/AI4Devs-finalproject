<?php

use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:customers.view`, not Spatie's `permission:` — Livewire 4's
    // PersistentMiddleware allowlist carries Laravel's `Authorize` (`can:`)
    // but not Spatie's `PermissionMiddleware`, so a `permission:`-gated route
    // would protect the initial GET only, leaving every save()/deleteCustomer()
    // /livewire/update round-trip unauthorized. See
    // docs/architecture/authorization.md.
    Route::livewire('customers', CustomersIndex::class)
        ->middleware(['can:customers.view'])
        ->name('customers.index');

    // `can:customers.view` only. Rendering the order-history section
    // additionally requires `orders.view`, but that check is section-level
    // and lives in the component -- a second `can:` here would 403 the
    // whole page, denying access this actor's `customers.view` grants.
    // See D-1 in ai-spec/tasks/in-progress/0047-customer-order-history-view-ui.md.
    //
    // Declared AFTER `customers` above -- `customers/{customer}` must not
    // precede the literal `customers` segment, or a plain GET /customers
    // request risks binding as a route parameter under some route-cache
    // orderings (the same reason SalesRegions/Roles' own area files order
    // their routes this way).
    Route::livewire('customers/{customer}', CustomersShow::class)
        ->middleware(['can:customers.view'])
        ->name('customers.show');
});
