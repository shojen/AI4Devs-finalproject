<?php

use App\Http\Controllers\InvoiceDownloadController;
use Illuminate\Support\Facades\Route;

// No `can:` here on purpose: DownloadInvoice authorizes `view` through LogRefusedPrivilegedAttempt so
// a refusal is logged (a `can:` refusal would not be).
Route::middleware(['auth', 'verified', 'throttle:invoice-downloads'])->group(function () {
    Route::get('invoices/{invoice}/pdf', InvoiceDownloadController::class)->name('invoices.download');
});
