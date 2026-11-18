<?php

// Story 0101a -- App\Actions\Invoices\AllocateInvoiceNumber: the FD-1 allocation formula, series
// independence, format changes and the year rule (reset on / off / toggled).

use App\Actions\Invoices\AllocateInvoiceNumber;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use Carbon\CarbonImmutable;
use Tests\Support\Invoices\Numbering;

test('three committed allocations give consecutive numbers and the row records the last one (T-1)', function () {
    $moments = [Numbering::at('2026-03-01 09:00:00'), Numbering::at('2026-03-02 09:00:00'), Numbering::at('2026-03-03 09:00:00')];

    $numbers = array_map(fn ($at): string => Numbering::number(InvoiceType::Standard, $at), $moments);

    $row = Numbering::row(InvoiceType::Standard);

    expect($numbers)->toBe(['F2026-00001', 'F2026-00002', 'F2026-00003'])
        ->and($row->last_number)->toBe(3)
        ->and($row->last_year)->toBe(2026)
        ->and($row->last_issued_at->getTimestamp())->toBe($moments[2]->getTimestamp());
});

test('an allocation returns the number, the series, the sequence year and the sequence number', function () {
    Numbering::arrange(InvoiceType::Corrective, 41, 2026);

    $result = Numbering::allocate(InvoiceType::Corrective, Numbering::at('2026-05-05 10:00:00'));

    expect($result)->toBe([
        'number' => 'R2026-00042',
        'series' => 'corrective',
        'sequence_year' => 2026,
        'sequence_number' => 42,
    ]);
});

test('the allocator locks for itself when the caller has not locked first', function () {
    $result = Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-05-05 10:00:00'), lockFirst: false);

    expect($result['number'])->toBe('F2026-00001')
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1);
});

test('the two series count independently whichever is allocated first (T-2)', function (array $order) {
    $at = Numbering::at('2026-04-01 12:00:00');
    $taken = ['standard' => [], 'corrective' => []];

    foreach ($order as $series) {
        $taken[$series->value][] = Numbering::allocate($series, $at)['sequence_number'];
    }

    expect($taken['standard'])->toBe([1, 2, 3])
        ->and($taken['corrective'])->toBe([1, 2, 3]);
})->with([
    'standard first' => [[InvoiceType::Standard, InvoiceType::Corrective, InvoiceType::Standard, InvoiceType::Corrective, InvoiceType::Standard, InvoiceType::Corrective]],
    'corrective first' => [[InvoiceType::Corrective, InvoiceType::Standard, InvoiceType::Corrective, InvoiceType::Standard, InvoiceType::Corrective, InvoiceType::Standard]],
    'bursts' => [[InvoiceType::Standard, InvoiceType::Standard, InvoiceType::Standard, InvoiceType::Corrective, InvoiceType::Corrective, InvoiceType::Corrective]],
]);

test('the first corrective invoice is R2026-00001 and the standard series is unaffected', function () {
    Numbering::arrange(InvoiceType::Standard, 5, 2026);

    expect(Numbering::number(InvoiceType::Corrective, Numbering::at('2026-06-01 10:00:00')))->toBe('R2026-00001')
        ->and(Numbering::number(InvoiceType::Standard, Numbering::at('2026-06-01 11:00:00')))->toBe('F2026-00006');
});

test('a format change continues the count with the new format (T-5)', function (array $format, string $expected) {
    Numbering::arrange(InvoiceType::Standard, 7, 2026);
    InvoiceSetting::factory()->create($format);

    expect(Numbering::number(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00')))->toBe($expected);
})->with([
    'prefix' => [['standard_prefix' => 'INV'], 'INV2026-00008'],
    'separator' => [['standard_separator' => '/'], 'F2026/00008'],
    'padding' => [['standard_padding' => 3], 'F2026-008'],
    'short year' => [['standard_year_format' => 'YY'], 'F26-00008'],
    'no year with reset off' => [['standard_year_format' => 'none', 'reset_numbering_yearly' => false], 'F-00008'],
]);

test('the corrective series uses the corrective format only', function () {
    InvoiceSetting::factory()->create(['standard_prefix' => 'INV', 'corrective_prefix' => 'COR', 'corrective_separator' => '.', 'corrective_padding' => 3]);

    expect(Numbering::number(InvoiceType::Corrective, Numbering::at('2026-06-01 10:00:00')))->toBe('COR2026.001');
});

// R-2: the year follows the application timezone (Europe/Madrid), never UTC.
test('with reset on the counter restarts exactly at the new year in the app timezone (R-2)', function (string $moment, string $timezone, int $expectedCounter) {
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-06-01 10:00:00'));
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);

    $at = CarbonImmutable::parse($moment, $timezone === 'app' ? config('app.timezone') : $timezone);

    expect(Numbering::allocate(InvoiceType::Standard, $at)['sequence_number'])->toBe($expectedCounter);
})->with([
    'mid 2026' => ['2026-07-15 12:00:00', 'app', 8],
    '2026-12-31 23:59:59 app time' => ['2026-12-31 23:59:59', 'app', 8],
    '2027-01-01 00:00:00 app time' => ['2027-01-01 00:00:00', 'app', 1],
    '22:59 UTC is still 2026 in Madrid' => ['2026-12-31 22:59:00', 'UTC', 8],
    '23:00 UTC is already 2027 in Madrid' => ['2026-12-31 23:00:00', 'UTC', 1],
]);

test('the printed year token is composed from the app-timezone moment, never the raw UTC one (R-2)', function () {
    expect(config('app.timezone'))->toBe('Europe/Madrid');
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-06-01 10:00:00'));
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);

    $beforeMidnight = Numbering::allocate(InvoiceType::Standard, Numbering::utc('2026-12-31 22:59:00'));
    $afterMidnight = Numbering::allocate(InvoiceType::Standard, Numbering::utc('2026-12-31 23:00:00'));

    expect($beforeMidnight['number'])->toBe('F2026-00008')
        ->and($beforeMidnight['sequence_year'])->toBe(2026)
        ->and($afterMidnight['number'])->toBe('F2027-00001')
        ->and($afterMidnight['sequence_year'])->toBe(2027);
});

test('with reset off the count continues across a year turn and last_year still follows (R-3)', function () {
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-06-01 10:00:00'));
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => false]);

    $number = Numbering::number(InvoiceType::Standard, Numbering::at('2027-02-01 10:00:00'));

    expect($number)->toBe('F2027-00008')
        ->and(Numbering::row(InvoiceType::Standard)->last_year)->toBe(2027);
});

test('the first ever allocation with reset on is counter 1 with no restart logic involved (R-3)', function () {
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);

    expect(Numbering::number(InvoiceType::Standard, Numbering::at('2027-03-01 10:00:00')))->toBe('F2027-00001');
});

test('with no settings row the documented defaults apply', function () {
    expect(InvoiceSetting::query()->count())->toBe(0)
        ->and(Numbering::number(InvoiceType::Standard, Numbering::at('2026-03-01 10:00:00')))->toBe('F2026-00001')
        ->and(Numbering::number(InvoiceType::Corrective, Numbering::at('2026-03-01 10:00:00')))->toBe('R2026-00001');
});

// R-4: the highest-risk case of the redesign -- reset ON, OFF, ON within one year never repeats a number.
test('toggling reset on, off, on within one year never repeats a number and continues at 16 (R-4)', function () {
    $settings = InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);
    $numbers = [];

    foreach (range(1, 10) as $day) {
        $numbers[] = Numbering::number(InvoiceType::Standard, Numbering::at('2026-02-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00:00'));
    }

    $settings->forceFill(['reset_numbering_yearly' => false])->save();

    foreach (range(11, 15) as $day) {
        $numbers[] = Numbering::number(InvoiceType::Standard, Numbering::at('2026-02-'.$day.' 10:00:00'));
    }

    $settings->forceFill(['reset_numbering_yearly' => true])->save();
    $next = Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-02-20 10:00:00'));

    expect($next['sequence_number'])->toBe(16)
        ->and(array_unique($numbers))->toHaveCount(15)
        ->and($numbers[14])->toBe('F2026-00015');
});

test('2024 at 500, a 2025 restart reaching 30, then reset off continues at 31 and on again keeps counting until 2027 (R-5)', function () {
    $settings = InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);
    Numbering::arrange(InvoiceType::Standard, 500, 2024, Numbering::at('2024-12-30 10:00:00'));

    $first2025 = Numbering::allocate(InvoiceType::Standard, Numbering::at('2025-01-02 10:00:00'));
    expect($first2025['sequence_number'])->toBe(1);

    Numbering::arrange(InvoiceType::Standard, 30, 2025, Numbering::at('2025-11-02 10:00:00'));
    $settings->forceFill(['reset_numbering_yearly' => false])->save();

    $off2026 = Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-01-05 10:00:00'));
    expect($off2026['sequence_number'])->toBe(31)
        ->and($off2026['number'])->toBe('F2026-00031');

    $settings->forceFill(['reset_numbering_yearly' => true])->save();
    $mid2026 = Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-07-05 10:00:00'));
    $first2027 = Numbering::allocate(InvoiceType::Standard, Numbering::at('2027-01-05 10:00:00'));

    expect($mid2026['sequence_number'])->toBe(32)
        ->and($first2027['sequence_number'])->toBe(1);
});

// FD-9
test('reset off in 2026 and switched on in 2027 before any 2027 issue restarts at 1 on the first 2027 issue (FD-9)', function () {
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);
    Numbering::arrange(InvoiceType::Standard, 40, 2026, Numbering::at('2026-11-01 10:00:00'));

    expect(Numbering::allocate(InvoiceType::Standard, Numbering::at('2027-01-10 10:00:00'))['sequence_number'])->toBe(1);
});

test('reset switched on after 2027 numbers were issued continues in 2027 and restarts on the first 2028 issue (FD-9)', function () {
    $settings = InvoiceSetting::factory()->create(['reset_numbering_yearly' => false]);
    Numbering::arrange(InvoiceType::Standard, 40, 2026, Numbering::at('2026-11-01 10:00:00'));

    expect(Numbering::allocate(InvoiceType::Standard, Numbering::at('2027-01-10 10:00:00'))['sequence_number'])->toBe(41);

    $settings->forceFill(['reset_numbering_yearly' => true])->save();

    expect(Numbering::allocate(InvoiceType::Standard, Numbering::at('2027-06-10 10:00:00'))['sequence_number'])->toBe(42)
        ->and(Numbering::allocate(InvoiceType::Standard, Numbering::at('2028-01-03 10:00:00'))['sequence_number'])->toBe(1);
});

test('nextCounter is read-only: it returns the FD-1 value and writes nothing', function () {
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-06-01 10:00:00'));
    $before = Numbering::snapshot();
    $allocator = app(AllocateInvoiceNumber::class);
    $settings = InvoiceSetting::factory()->make(['reset_numbering_yearly' => true]);

    expect($allocator->nextCounter(InvoiceType::Standard, $settings, Numbering::at('2026-09-01 10:00:00')))->toBe(8)
        ->and($allocator->nextCounter(InvoiceType::Standard, $settings, Numbering::at('2027-01-01 00:00:01')))->toBe(1)
        ->and($allocator->nextCounter(InvoiceType::Corrective, $settings, Numbering::at('2027-01-01 00:00:01')))->toBe(1)
        ->and(Numbering::snapshot())->toBe($before);
});
