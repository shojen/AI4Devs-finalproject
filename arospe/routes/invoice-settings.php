<?php

use App\Livewire\InvoiceSettings\Index as InvoiceSettingsIndex;   // aliased: `Index` is ambiguous across areas
use Illuminate\Support\Facades\Route;

// The PDF preview route (invoice-settings.preview) lives in routes/invoices.php next to the other
// PDF stream, with the same throttle and no `can:` (its action logs the refusal).
Route::middleware(['auth', 'verified'])->group(function () {
    // `can:`, not Spatie's `permission:`: Livewire's persistent middleware replays only `Authorize`.
    Route::livewire('invoice-settings', InvoiceSettingsIndex::class)
        ->middleware(['can:invoice-settings.view'])
        ->name('invoice-settings.index');
});
