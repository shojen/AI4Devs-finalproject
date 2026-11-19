<?php

namespace App\Actions\Invoices;

use RuntimeException;

/**
 * Splits the amount of a partial invoice into base, tax and shipping (story 0101b, FD-8). Pure:
 * no database, bcmath strings only, never floats.
 *
 * Tax and shipping are the half-up proportional share `component x amount / billable`; the base is
 * the rest, and when rounding would overdraw it the larger part gives up the difference. The last
 * invoice (amount equal to the uninvoiced amount) takes what is left of each component, so the
 * invoices of an order add up exactly. A part is never allowed to exceed what is left of its
 * component, which keeps the last invoice's remainders non-negative.
 */
class SplitInvoiceAmount
{
    private const KEYS = ['base', 'tax', 'shipping'];

    /**
     * @param  array{base: numeric-string, tax: numeric-string, shipping: numeric-string}  $components  Of the billable amount.
     * @param  array{base: numeric-string, tax: numeric-string, shipping: numeric-string}  $invoicedComponents  Signed sum over the order's invoices.
     * @return array{base: numeric-string, tax: numeric-string, shipping: numeric-string}
     */
    public function __invoke(string $amount, string $billable, string $uninvoiced, array $components, array $invoicedComponents): array
    {
        $amount = $this->numeric($amount);
        $billable = $this->numeric($billable);
        $uninvoiced = $this->numeric($uninvoiced);

        $remaining = [];

        foreach (self::KEYS as $key) {
            $remaining[$key] = bcsub($components[$key], $invoicedComponents[$key], 2);
        }

        if (bccomp($amount, $uninvoiced, 2) === 0) {
            return $this->settle($remaining, $amount);
        }

        if (bccomp($billable, '0.00', 2) <= 0) {
            return $this->fitWithinRemaining(['base' => $amount, 'tax' => '0.00', 'shipping' => '0.00'], $remaining);
        }

        $shipping = $this->share($components['shipping'], $amount, $billable);
        $tax = $this->share($components['tax'], $amount, $billable);
        $base = bcsub(bcsub($amount, $tax, 2), $shipping, 2);

        if (bccomp($base, '0.00', 2) < 0) {
            $deficit = bcmul($base, '-1', 2);
            $reduceTaxFirst = bccomp($tax, $shipping, 2) >= 0;
            $first = $reduceTaxFirst ? 'tax' : 'shipping';
            $second = $reduceTaxFirst ? 'shipping' : 'tax';
            $parts = ['tax' => $tax, 'shipping' => $shipping];

            $takeFirst = bccomp($deficit, $parts[$first], 2) > 0 ? $parts[$first] : $deficit;
            $parts[$first] = bcsub($parts[$first], $takeFirst, 2);
            $parts[$second] = bcsub($parts[$second], bcsub($deficit, $takeFirst, 2), 2);
            $tax = $parts['tax'];
            $shipping = $parts['shipping'];
            $base = '0.00';
        }

        return $this->fitWithinRemaining(['base' => $base, 'tax' => $tax, 'shipping' => $shipping], $remaining);
    }

    /**
     * Half-up `component x amount / billable` at two decimals.
     *
     * @return numeric-string
     */
    private function share(string $component, string $amount, string $billable): string
    {
        $exact = bcdiv(bcmul($this->numeric($component), $this->numeric($amount), 4), $this->numeric($billable), 6);

        return bcadd($exact, '0.005', 2);
    }

    /**
     * Keep every part within what is left of its component, moving the excess to a part that still
     * has room, in the order base, tax, shipping. The parts keep adding up to the amount.
     *
     * @param  array{base: numeric-string, tax: numeric-string, shipping: numeric-string}  $parts
     * @param  array{base: numeric-string, tax: numeric-string, shipping: numeric-string}  $remaining
     * @return array{base: numeric-string, tax: numeric-string, shipping: numeric-string}
     */
    private function fitWithinRemaining(array $parts, array $remaining): array
    {
        $caps = ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00'];

        foreach (self::KEYS as $key) {
            $caps[$key] = bccomp($remaining[$key], '0.00', 2) < 0 ? '0.00' : $remaining[$key];
        }

        foreach (self::KEYS as $key) {
            if (bccomp($parts[$key], $caps[$key], 2) <= 0) {
                continue;
            }

            $excess = bcsub($parts[$key], $caps[$key], 2);
            $parts[$key] = $caps[$key];

            foreach (self::KEYS as $other) {
                if ($other === $key || bccomp($excess, '0.00', 2) <= 0) {
                    continue;
                }

                $spare = bcsub($caps[$other], $parts[$other], 2);

                if (bccomp($spare, '0.00', 2) <= 0) {
                    continue;
                }

                $moved = bccomp($excess, $spare, 2) > 0 ? $spare : $excess;
                $parts[$other] = bcadd($parts[$other], $moved, 2);
                $excess = bcsub($excess, $moved, 2);
            }

            // No component has room left (inconsistent order data): the base keeps the sum exact.
            $parts['base'] = bcadd($parts['base'], $excess, 2);
        }

        return $parts;
    }

    /**
     * Make the parts add up to the amount, the base absorbing any difference.
     *
     * @param  array{base: numeric-string, tax: numeric-string, shipping: numeric-string}  $parts
     * @return array{base: numeric-string, tax: numeric-string, shipping: numeric-string}
     */
    private function settle(array $parts, string $amount): array
    {
        $sum = bcadd(bcadd($parts['base'], $parts['tax'], 2), $parts['shipping'], 2);
        $parts['base'] = bcadd($parts['base'], bcsub($this->numeric($amount), $sum, 2), 2);

        return $parts;
    }

    /**
     * @return numeric-string
     */
    private function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new RuntimeException("Expected a numeric decimal value, got: {$value}");
        }

        return $value;
    }
}
