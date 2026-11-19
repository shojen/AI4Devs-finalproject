<?php

namespace App\Actions\Invoices;

use App\Models\StoreLanguage;

/**
 * The language an invoice is written in (story 0101b FD-6, reused by 0102 FD-8): the code of the
 * default store language when the application ships `lang/{code}/invoices.php` for it, otherwise
 * the configured fallback locale. It never reads the ambient locale or the admin's UI locale.
 */
class ResolveInvoiceLocale
{
    public function __invoke(): string
    {
        $code = StoreLanguage::defaultStoreLanguage()?->code;

        if (is_string($code) && $code !== '' && is_file(lang_path($code.DIRECTORY_SEPARATOR.'invoices.php'))) {
            return $code;
        }

        return (string) config('app.fallback_locale');
    }
}
