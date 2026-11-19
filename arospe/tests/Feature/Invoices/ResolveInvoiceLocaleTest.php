<?php

// Story 0101b FD-6 -- the language an invoice is written in (reused by 0102, FD-8).

use App\Actions\Invoices\ResolveInvoiceLocale;
use App\Models\StoreLanguage;
use Database\Seeders\StoreLanguageSeeder;

test('the default store language with an invoices lang file is returned', function () {
    $this->seed(StoreLanguageSeeder::class);

    expect(app(ResolveInvoiceLocale::class)())->toBe('es');
});

test('a default store language without an invoices lang file falls back to the configured fallback locale', function () {
    config(['app.fallback_locale' => 'en']);
    $this->seed(StoreLanguageSeeder::class);
    StoreLanguage::query()->where('is_default', true)->firstOrFail()->forceFill(['code' => 'fr'])->save();

    expect(app(ResolveInvoiceLocale::class)())->toBe('en');
});

test('no default store language falls back to the configured fallback locale', function () {
    config(['app.fallback_locale' => 'en']);

    expect(app(ResolveInvoiceLocale::class)())->toBe('en');
});

test('the ambient locale is never read nor changed', function () {
    $this->seed(StoreLanguageSeeder::class);
    app()->setLocale('en');

    expect(app(ResolveInvoiceLocale::class)())->toBe('es')
        ->and(app()->getLocale())->toBe('en');
});

// Story 0102 T-18 -- the PDF reuses the resolver unchanged: a store language without app translations
// (e.g. `fr`) or no default language at all gives the fallback locale, whatever the UI locale is.
test('a default store language without translations falls back even when the UI locale has them', function () {
    config(['app.fallback_locale' => 'en']);
    $this->seed(StoreLanguageSeeder::class);
    StoreLanguage::query()->where('is_default', true)->firstOrFail()->forceFill(['code' => 'fr'])->save();
    app()->setLocale('es');

    expect(app(ResolveInvoiceLocale::class)())->toBe('en')
        ->and(app()->getLocale())->toBe('es');
});

test('no default store language falls back whatever the UI locale is', function () {
    config(['app.fallback_locale' => 'en']);
    app()->setLocale('es');

    expect(app(ResolveInvoiceLocale::class)())->toBe('en')
        ->and(app()->getLocale())->toBe('es');
});

test('the store language wins over a different UI locale', function () {
    $this->seed(StoreLanguageSeeder::class);
    app()->setLocale('en');

    expect(app(ResolveInvoiceLocale::class)())->toBe('es');
});
