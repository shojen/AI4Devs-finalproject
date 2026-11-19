<?php

namespace App\Actions\Invoices;

/**
 * Print a stored decimal string as euros for a locale (story 0102 FD-9): `1.234,56 €` for `es`,
 * `€1,234.56` for any other locale, negatives as `-1.234,56 €` / `-€1,234.56`. Pure string
 * handling: the amount never becomes a float, and grouping is always on (ICU omits it for `es`
 * below five digits).
 */
class FormatInvoiceMoney
{
    /**
     * @param  string  $amount  A decimal string such as `1234.56` or `-45.50`.
     */
    public function __invoke(string $amount, string $locale): string
    {
        $negative = str_starts_with($amount, '-');
        $digits = ltrim($amount, '-+');
        [$integer, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $spanish = $locale === 'es';
        $grouped = strrev(implode($spanish ? '.' : ',', str_split(strrev($integer), 3)));
        $number = $grouped.($spanish ? ',' : '.').$fraction;
        $sign = $negative && trim($number, '0.,') !== '' ? '-' : '';

        return $spanish ? $sign.$number."\u{00A0}€" : $sign.'€'.$number;
    }
}
