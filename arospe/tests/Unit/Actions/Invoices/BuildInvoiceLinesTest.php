<?php

// Story 0101b -- BuildInvoiceLines is pure (FD-6, FD-7): no database, bcmath strings only.
//
// Assumed call shape (the story gives no signature for this pure class; adjust the two wrappers
// below in one place if the implementation differs):
//   (new BuildInvoiceLines)->itemised(iterable<OrderItem> $items, string $orderTaxAmount, ?string $taxRate): list<array>
//   (new BuildInvoiceLines)->adjustment(string $description, string $base, string $tax, ?string $taxRate): array
// A line array carries: order_item_id, position, kind, description, sku, quantity, unit_price,
// tax_rate, line_subtotal, tax_amount, line_total.

use App\Actions\Invoices\BuildInvoiceLines;
use App\Enums\InvoiceLineKind;
use App\Models\OrderItem;

/**
 * @param  list<array{0: int, 1: string}>  $lines  quantity, unit price
 * @return list<array<string, mixed>>
 */
function itemisedLines(array $lines, string $storedTax, ?string $taxRate): array
{
    $items = array_map(fn (array $line, int $index): OrderItem => (new OrderItem)->forceFill([
        'id' => "00000000-0000-7000-8000-00000000000{$index}",
        'product_name' => "Item {$index}",
        'product_sku' => "SKU-{$index}",
        'quantity' => $line[0],
        'unit_price' => $line[1],
        'line_total' => bcmul($line[1], (string) $line[0], 2),
    ]), $lines, array_keys($lines));

    return (new BuildInvoiceLines)->itemised($items, $storedTax, $taxRate);
}

function linesHalfUp(string $value): string
{
    return bcadd($value, '0.005', 2);
}

// T-5
test('per-line tax adds up exactly to the stored order tax and stays within a cent of the exact share (T-5)', function (array $lines, string $rate) {
    $subtotal = array_reduce($lines, fn (string $carry, array $line): string => bcadd($carry, bcmul($line[1], (string) $line[0], 2), 2), '0.00');
    $storedTax = linesHalfUp(bcdiv(bcmul($subtotal, $rate, 6), '100', 6));

    $built = itemisedLines($lines, $storedTax, $rate);

    expect(array_reduce($built, fn (string $carry, array $line): string => bcadd($carry, $line['tax_amount'], 2), '0.00'))->toBe($storedTax)
        ->and(array_reduce($built, fn (string $carry, array $line): string => bcadd($carry, $line['line_subtotal'], 2), '0.00'))->toBe($subtotal);

    foreach ($built as $line) {
        $exact = bcdiv(bcmul($line['line_subtotal'], $rate, 6), '100', 6);
        $gap = bcsub($line['tax_amount'], $exact, 6);
        $gap = str_starts_with($gap, '-') ? substr($gap, 1) : $gap;

        expect(bccomp($gap, '0.010000', 6))->toBeLessThanOrEqual(0)
            ->and($line['line_total'])->toBe(bcadd($line['line_subtotal'], $line['tax_amount'], 2));
    }
})->with([
    'ten percent, 0.005 residues' => [[[1, '0.05'], [1, '0.15'], [1, '0.25']], '10.000'],
    'twenty one percent odd cents' => [[[3, '3.33'], [1, '0.99'], [7, '1.01']], '21.000'],
    'seven and a half percent' => [[[2, '12.34'], [5, '0.37'], [1, '99.99']], '7.500'],
    'many tiny lines' => [[[1, '0.01'], [1, '0.01'], [1, '0.01'], [1, '0.01'], [1, '0.01'], [1, '0.01']], '21.000'],
]);

test('equal remainders are settled in line order: the earlier lines receive the extra cents (T-5, stable ties)', function () {
    // Three lines of 0.05 at 10 % each owe 0.005; the order stored 0.02 for the 0.15 subtotal.
    $built = itemisedLines([[1, '0.05'], [1, '0.05'], [1, '0.05']], '0.02', '10.000');

    expect(array_column($built, 'tax_amount'))->toBe(['0.01', '0.01', '0.00']);
});

// T-5b
test('the stored order tax wins over a fresh recomputation (T-5b)', function () {
    // 100.00 at 21 % would be 21.00; the order stored 19.99.
    $built = itemisedLines([[1, '60.00'], [1, '40.00']], '19.99', '21.000');

    expect(array_reduce($built, fn (string $carry, array $line): string => bcadd($carry, $line['tax_amount'], 2), '0.00'))->toBe('19.99');
});

// F-7
test('a negative stored order tax is refused instead of looping (F-7)', function () {
    expect(fn () => itemisedLines([[1, '60.00'], [1, '40.00']], '-5.00', '21.000'))->toThrow(LogicException::class);
});

// T-6
test('a null tax rate gives zero tax on every line and a null rate (T-6)', function () {
    $built = itemisedLines([[2, '30.00'], [1, '5.50']], '0.00', null);

    foreach ($built as $line) {
        expect($line['tax_amount'])->toBe('0.00')
            ->and($line['tax_rate'])->toBeNull()
            ->and($line['line_total'])->toBe($line['line_subtotal']);
    }
});

test('itemised lines copy name, SKU, quantity and unit price in position order as item lines linked to the order item (FD-6)', function () {
    $built = itemisedLines([[2, '10.00'], [1, '45.50']], '11.00', '21.000');

    expect($built)->toHaveCount(2)
        ->and(array_column($built, 'position'))->toBe([1, 2])
        ->and(array_column($built, 'kind'))->toBe([InvoiceLineKind::Item, InvoiceLineKind::Item])
        ->and(array_column($built, 'description'))->toBe(['Item 0', 'Item 1'])
        ->and(array_column($built, 'sku'))->toBe(['SKU-0', 'SKU-1'])
        ->and(array_column($built, 'quantity'))->toBe([2, 1])
        ->and(array_column($built, 'unit_price'))->toBe(['10.00', '45.50'])
        ->and(array_column($built, 'line_subtotal'))->toBe(['20.00', '45.50'])
        ->and($built[0]['order_item_id'])->toBe('00000000-0000-7000-8000-000000000000');
});

test('the concept line is one adjustment line, quantity 1, no order item, carrying the given parts (FD-6)', function () {
    $line = (new BuildInvoiceLines)->adjustment('Payment on account of order ORD-1', '160.00', '33.60', '21.000');

    expect($line['kind'])->toBe(InvoiceLineKind::Adjustment)
        ->and($line['order_item_id'])->toBeNull()
        ->and($line['position'])->toBe(1)
        ->and($line['quantity'])->toBe(1)
        ->and($line['description'])->toBe('Payment on account of order ORD-1')
        ->and($line['unit_price'])->toBe('160.00')
        ->and($line['line_subtotal'])->toBe('160.00')
        ->and($line['tax_amount'])->toBe('33.60')
        ->and($line['line_total'])->toBe('193.60')
        ->and($line['tax_rate'])->toBe('21.000');
});

test('the concept line with a null tax rate has zero tax (T-6)', function () {
    $line = (new BuildInvoiceLines)->adjustment('Payment on account of order ORD-1', '50.00', '0.00', null);

    expect($line['tax_rate'])->toBeNull()
        ->and($line['tax_amount'])->toBe('0.00')
        ->and($line['line_total'])->toBe('50.00');
});
