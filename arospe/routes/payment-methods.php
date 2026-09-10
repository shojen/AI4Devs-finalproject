<?php

use App\Livewire\PaymentMethods\Index as PaymentMethodsIndex;   // aliased: `Index` is ambiguous across areas,
// exactly like every other area file's own import
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:payment-methods.view`, not Spatie's `permission:` -- same reason as
    // every other gated route in this app: Livewire 4's PersistentMiddleware
    // allowlist carries Laravel's `Authorize` (`can:`) but not Spatie's
    // `PermissionMiddleware`, so a `permission:`-gated route would protect
    // the initial GET only, leaving every save() /livewire/update round-trip
    // unauthorized. See docs/architecture/authorization.md.
    Route::livewire('payment-methods', PaymentMethodsIndex::class)
        ->middleware(['can:payment-methods.view'])
        ->name('payment-methods.index');
});
