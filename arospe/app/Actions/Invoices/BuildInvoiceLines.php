<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceLineKind;
use App\Models\OrderItem;
use LogicException;
use RuntimeException;

/**
 * Builds the line rows of an invoice (story 0101b, FD-6, FD-7). Pure: no database, bcmath strings.
 *
 * An itemised invoice copies the order's lines and allocates the order's STORED tax over them by
 * largest remainder (ties settled in line order), so the per-line taxes add up exactly to the
 * stored tax. A concept invoice has one adjustment line carrying the given parts.
 */
class BuildInvoiceLines
{
    /**
     * @param  iterable<OrderItem>  $items
     * @return list<array{order_item_id: string|null, position: int, kind: InvoiceLineKind, description: string, sku: string|null, quantity: int, unit_price: string, tax_rate: string|null, line_subtotal: string, tax_amount: string, line_total: string}>
     */
    public function itemised(iterable $items, string $storedTax, ?string $taxRate): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = $item;
        }

        $subtotals = array_map(fn (OrderItem $item): string => $this->numeric((string) $item->line_total), $rows);
        $taxes = $this->allocateTax($subtotals, $this->numeric($storedTax), $taxRate);
        $lines = [];

        foreach ($rows as $index => $item) {
            $subtotal = $subtotals[$index];

            $lines[] = [
                'order_item_id' => $item->getKey(),
                'position' => $index + 1,
                'kind' => InvoiceLineKind::Item,
                'description' => $item->product_name,
                'sku' => $item->product_sku,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => $taxRate,
                'line_subtotal' => $subtotal,
                'tax_amount' => $taxes[$index],
                'line_total' => bcadd($subtotal, $taxes[$index], 2),
            ];
        }

        return $lines;
    }

    /**
     * The single concept line of a partial invoice.
     *
     * @return array{order_item_id: string|null, position: int, kind: InvoiceLineKind, description: string, sku: string|null, quantity: int, unit_price: string, tax_rate: string|null, line_subtotal: string, tax_amount: string, line_total: string}
     */
    public function adjustment(string $description, string $base, string $tax, ?string $taxRate): array
    {
        $base = $this->numeric($base);
        $tax = $this->numeric($tax);

        return [
            'order_item_id' => null,
            'position' => 1,
            'kind' => InvoiceLineKind::Adjustment,
            'description' => $description,
            'sku' => null,
            'quantity' => 1,
            'unit_price' => $base,
            'tax_rate' => $taxRate,
            'line_subtotal' => $base,
            'tax_amount' => $tax,
            'line_total' => bcadd($base, $tax, 2),
        ];
    }

    /**
     * Allocate the stored tax over the lines in whole cents: the exact share of each line (by the
     * rate when there is one, otherwise by subtotal) is floored, then the cents still owed go to
     * the largest fractional remainders (or come back from the smallest), earlier lines first.
     *
     * @param  list<numeric-string>  $subtotals
     * @param  numeric-string  $storedTax
     * @return list<numeric-string>
     */
    private function allocateTax(array $subtotals, string $storedTax, ?string $taxRate): array
    {
        $count = count($subtotals);

        if ($count === 0) {
            return [];
        }

        $storedCents = (int) bcmul($storedTax, '100', 0);

        if ($storedCents < 0) {
            throw new LogicException('The stored order tax cannot be negative.');
        }

        if ($storedCents === 0) {
            return array_fill(0, $count, '0.00');
        }

        $total = '0.00';

        foreach ($subtotals as $subtotal) {
            $total = bcadd($total, $subtotal, 2);
        }

        $floors = [];
        $remainders = [];

        foreach ($subtotals as $index => $subtotal) {
            // subtotal x rate / 100 euros is subtotal x rate cents.
            $cents = $taxRate !== null
                ? bcmul($subtotal, $this->numeric($taxRate), 6)
                : (bccomp($total, '0.00', 2) === 0 ? '0' : bcdiv(bcmul(bcmul($storedTax, '100', 6), $subtotal, 6), $total, 6));

            $floors[$index] = (int) bcadd($cents, '0', 0);
            $remainders[$index] = bcsub($cents, (string) $floors[$index], 6);
        }

        $owed = $storedCents - array_sum($floors);

        $byLargest = array_keys($remainders);
        usort($byLargest, fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 6) ?: $a <=> $b);

        $bySmallest = array_keys($remainders);
        usort($bySmallest, fn (int $a, int $b): int => bccomp($remainders[$a], $remainders[$b], 6) ?: $a <=> $b);

        while ($owed > 0) {
            foreach ($byLargest as $index) {
                if ($owed === 0) {
                    break;
                }

                $floors[$index]++;
                $owed--;
            }
        }

        while ($owed < 0) {
            $before = $owed;

            foreach ($bySmallest as $index) {
                if ($owed === 0) {
                    break;
                }

                if ($floors[$index] > 0) {
                    $floors[$index]--;
                    $owed++;
                }
            }

            if ($owed === $before) {
                throw new LogicException('The tax correction made no progress: the lines cannot carry the stored tax.');
            }
        }

        return array_values(array_map(fn (int $cents): string => bcdiv((string) $cents, '100', 2), $floors));
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
