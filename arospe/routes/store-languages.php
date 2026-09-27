<?php

use App\Livewire\StoreLanguages\Index as StoreLanguagesIndex;   // aliased: `Index` is ambiguous across areas,
// exactly like routes/sales-regions.php's own import
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:store-languages.view`, not Spatie's `permission:` -- same reason as
    // sales-regions.index / users.index / roles.index: Livewire 4's
    // PersistentMiddleware allowlist carries Laravel's `Authorize` (`can:`) but
    // not Spatie's `PermissionMiddleware`, so a `permission:`-gated route would
    // protect the initial GET only. See docs/architecture/authorization.md.
    Route::livewire('settings/store-languages', StoreLanguagesIndex::class)
        ->middleware(['can:store-languages.view'])
        ->name('store-languages.index');
});
