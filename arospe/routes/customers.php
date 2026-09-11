<?php

use App\Livewire\Customers\Index as CustomersIndex;
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
});
