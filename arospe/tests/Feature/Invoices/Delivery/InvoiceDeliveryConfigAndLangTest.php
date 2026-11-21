<?php

// Story 0104 -- the config('invoicing.delivery') keys and the en/es lang keys the delivery uses.

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

test('the delivery config holds positive integers for tries, backoff and timeout and caps attachments at ten (FD-8, FD-10)', function () {
    $delivery = config('invoicing.delivery');

    expect($delivery)->toBeArray()
        ->and(array_keys($delivery))->toContain('tries', 'backoff_seconds', 'timeout_seconds', 'max_attachments')
        ->and($delivery['max_attachments'])->toBe(10)
        ->and($delivery['tries'])->toBeInt()->toBeGreaterThanOrEqual(1)
        ->and($delivery['backoff_seconds'])->toBeInt()->toBeGreaterThanOrEqual(1)
        ->and($delivery['timeout_seconds'])->toBeInt()->toBeGreaterThanOrEqual(1);
});

test('the error messages the delivery refuses with exist in en and es (FD-3, FD-4, FD-9, FD-16)', function (string $key, string $locale) {
    expect(Lang::has("invoices.errors.{$key}", $locale, false))->toBeTrue()
        ->and(trans("invoices.errors.{$key}", [], $locale))->not->toBe("invoices.errors.{$key}")
        ->and(trans("invoices.errors.{$key}", [], $locale))->not->toBe('');
})->with(['customer_deleted', 'customer_email_unusable', 'issued_but_not_queued'])->with(['en', 'es']);

test('the mail strings exist in en and es and both locales carry the same keys (FD-11)', function () {
    $en = Arr::dot(trans('invoices.mail', [], 'en'));
    $es = Arr::dot(trans('invoices.mail', [], 'es'));

    expect($en)->not->toBeEmpty()
        ->and(array_keys($es))->toEqualCanonicalizing(array_keys($en))
        ->and(collect($en)->every(fn ($value): bool => is_string($value) && $value !== ''))->toBeTrue()
        ->and(collect($es)->every(fn ($value): bool => is_string($value) && $value !== ''))->toBeTrue();
});

test('the error keys of invoices are the same in en and es (en/es parity)', function () {
    $en = array_keys(Arr::dot(trans('invoices.errors', [], 'en')));
    $es = array_keys(Arr::dot(trans('invoices.errors', [], 'es')));

    expect($es)->toEqualCanonicalizing($en)
        ->and($en)->toContain('customer_deleted', 'customer_email_unusable', 'issued_but_not_queued');
});

test('the delivery timeout covers the maximum attachments rendered one after the other plus a margin (F6)', function () {
    expect(config('invoicing.delivery.timeout_seconds'))
        ->toBeGreaterThan(config('invoicing.delivery.max_attachments') * config('invoicing.pdf.timeout_seconds'));
});
