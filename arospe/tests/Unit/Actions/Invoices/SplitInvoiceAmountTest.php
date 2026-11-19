<?php

// Story 0101b -- SplitInvoiceAmount is pure (FD-8): no database, bcmath strings only.
//
// Assumed call shape (the story gives no signature for this pure class; adjust `splitAmount()`
// in one place if the implementation differs):
//   (new SplitInvoiceAmount)($amount, $billable, $uninvoiced, $components, $invoicedComponents)
//   -> array{base: string, tax: string, shipping: string}
// with $components and $invoicedComponents keyed base/tax/shipping.

use App\Actions\Invoices\SplitInvoiceAmount;

/**
 * @param  array{base: string, tax: string, shipping: string}  $components
 * @param  array{base: string, tax: string, shipping: string}  $invoiced
 * @return array{base: string, tax: string, shipping: string}
 */
function splitAmount(string $amount, array $components, string $uninvoiced, array $invoiced): array
{
    $billable = bcadd(bcadd($components['base'], $components['tax'], 2), $components['shipping'], 2);

    return (new SplitInvoiceAmount)($amount, $billable, $uninvoiced, $components, $invoiced);
}

/**
 * Invoice an order in the given amounts and return the parts of each invoice.
 *
 * @param  array{base: string, tax: string, shipping: string}  $components
 * @param  list<string>  $amounts
 * @return list<array{base: string, tax: string, shipping: string}>
 */
function splitSequence(array $components, array $amounts): array
{
    $billable = bcadd(bcadd($components['base'], $components['tax'], 2), $components['shipping'], 2);
    $invoiced = ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00'];
    $net = '0.00';
    $parts = [];

    foreach ($amounts as $amount) {
        $split = splitAmount($amount, $components, bcsub($billable, $net, 2), $invoiced);

        foreach (['base', 'tax', 'shipping'] as $key) {
            $invoiced[$key] = bcadd($invoiced[$key], $split[$key], 2);
        }

        $net = bcadd($net, $amount, 2);
        $parts[] = $split;
    }

    return $parts;
}

test('every invoice parts add up to its amount and the invoices add up to the components (T-4)', function (array $components, array $amounts) {
    $parts = splitSequence($components, $amounts);

    foreach ($parts as $index => $split) {
        expect(bcadd(bcadd($split['base'], $split['tax'], 2), $split['shipping'], 2))->toBe($amounts[$index])
            ->and(bccomp($split['base'], '0.00', 2))->toBeGreaterThanOrEqual(0)
            ->and(bccomp($split['tax'], '0.00', 2))->toBeGreaterThanOrEqual(0)
            ->and(bccomp($split['shipping'], '0.00', 2))->toBeGreaterThanOrEqual(0);
    }

    foreach (['base', 'tax', 'shipping'] as $key) {
        expect(array_reduce($parts, fn (string $carry, array $split): string => bcadd($carry, $split[$key], 2), '0.00'))->toBe($components[$key]);
    }
})->with([
    'thirds' => [['base' => '100.00', 'tax' => '21.00', 'shipping' => '0.00'], ['40.33', '40.33', '40.34']],
    'one cent then the rest' => [['base' => '800.00', 'tax' => '168.00', 'shipping' => '32.00'], ['0.01', '999.99']],
    'a cent short then a cent' => [['base' => '800.00', 'tax' => '168.00', 'shipping' => '32.00'], ['999.99', '0.01']],
    'a euro at a time on 7.00' => [['base' => '5.00', 'tax' => '1.00', 'shipping' => '1.00'], ['1.00', '1.00', '1.00', '1.00', '1.00', '1.00', '1.00']],
    'five equal parts' => [['base' => '800.00', 'tax' => '168.00', 'shipping' => '32.00'], ['200.00', '200.00', '200.00', '200.00', '200.00']],
    'only tax and shipping' => [['base' => '0.00', 'tax' => '0.01', 'shipping' => '0.01'], ['0.01', '0.01']],
]);

test('a 200.00 part of a 1000.00 order splits shipping and tax by proportion and leaves the rest as base (FD-8)', function () {
    $components = ['base' => '800.00', 'tax' => '168.00', 'shipping' => '32.00'];

    expect(splitAmount('200.00', $components, '1000.00', ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00']))
        ->toBe(['base' => '160.00', 'tax' => '33.60', 'shipping' => '6.40']);
});

test('the base never goes negative: when rounding would overdraw it the larger part gives up a cent (FD-8)', function () {
    // 0.01 x 0.01 / 0.02 = 0.005 rounds half-up to 0.01 for both tax and shipping: 0.02 > 0.01.
    $components = ['base' => '0.00', 'tax' => '0.01', 'shipping' => '0.01'];

    $split = splitAmount('0.01', $components, '0.02', ['base' => '0.00', 'tax' => '0.00', 'shipping' => '0.00']);

    expect($split['base'])->toBe('0.00')
        ->and(bcadd($split['tax'], $split['shipping'], 2))->toBe('0.01');
});

test('the last invoice absorbs the rounding: components minus what was already invoiced (FD-8)', function () {
    $components = ['base' => '100.00', 'tax' => '21.00', 'shipping' => '0.00'];
    $invoiced = ['base' => '66.66', 'tax' => '13.99', 'shipping' => '0.00'];

    expect(splitAmount('40.35', $components, '40.35', $invoiced))->toBe(['base' => '33.34', 'tax' => '7.01', 'shipping' => '0.00']);
});

test('randomised order shapes and amount sequences always add up exactly (T-4, seeded property test)', function () {
    mt_srand(101_002);

    for ($run = 0; $run < 300; $run++) {
        $components = [
            'base' => number_format(mt_rand(0, 500_000) / 100, 2, '.', ''),
            'tax' => number_format(mt_rand(0, 100_000) / 100, 2, '.', ''),
            'shipping' => number_format(mt_rand(0, 3_000) / 100, 2, '.', ''),
        ];
        $billable = bcadd(bcadd($components['base'], $components['tax'], 2), $components['shipping'], 2);

        if (bccomp($billable, '0.00', 2) === 0) {
            continue;
        }

        $remaining = $billable;
        $amounts = [];

        while (bccomp($remaining, '0.00', 2) > 0) {
            $cents = (int) bcmul($remaining, '100', 0);
            $take = mt_rand(0, 3) === 0 ? $cents : mt_rand(1, $cents);
            $amount = number_format($take / 100, 2, '.', '');
            $amounts[] = $amount;
            $remaining = bcsub($remaining, $amount, 2);
        }

        $parts = splitSequence($components, $amounts);

        foreach ($parts as $index => $split) {
            expect(bcadd(bcadd($split['base'], $split['tax'], 2), $split['shipping'], 2))->toBe($amounts[$index], "run {$run} part {$index}")
                ->and(bccomp($split['base'], '0.00', 2))->toBeGreaterThanOrEqual(0, "run {$run} part {$index} base");
        }

        foreach (['base', 'tax', 'shipping'] as $key) {
            expect(array_reduce($parts, fn (string $carry, array $split): string => bcadd($carry, $split[$key], 2), '0.00'))->toBe($components[$key], "run {$run} {$key}");
        }
    }
});
