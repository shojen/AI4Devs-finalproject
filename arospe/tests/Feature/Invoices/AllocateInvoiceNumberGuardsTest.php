<?php

// Story 0101a -- programming-error guards (FD-3), clock skew (FD-5), overflow (FD-6) and the
// "locked settings decide" contract (FD-4).

use App\Actions\Invoices\AllocateInvoiceNumber;
use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Invoices\Numbering;

// T-29
test('lock outside a transaction throws a LogicException and changes nothing (T-29)', function () {
    $before = Numbering::snapshot();

    Numbering::withoutTransaction(function (): void {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => app(AllocateInvoiceNumber::class)->lock([InvoiceType::Standard], Numbering::at('2026-04-01 10:00:00')))
            ->toThrow(LogicException::class);
    });

    expect(Numbering::snapshot())->toBe($before);
});

test('an allocation outside a transaction throws a LogicException and consumes nothing (T-29)', function () {
    $before = Numbering::snapshot();

    Numbering::withoutTransaction(function (): void {
        expect(fn () => app(AllocateInvoiceNumber::class)(InvoiceType::Standard, Numbering::at('2026-04-01 10:00:00')))
            ->toThrow(LogicException::class);
    });

    expect(Numbering::snapshot())->toBe($before);
});

test('a deleted series row throws a LogicException and nothing is inserted at runtime (T-29)', function () {
    InvoiceSequence::query()->where('series', 'corrective')->delete();

    expect(fn () => Numbering::allocate(InvoiceType::Corrective, Numbering::at('2026-04-01 10:00:00')))
        ->toThrow(LogicException::class)
        ->and(fn () => Numbering::inTransaction(fn () => app(AllocateInvoiceNumber::class)->lock([InvoiceType::Standard, InvoiceType::Corrective], Numbering::at('2026-04-01 10:00:00'))))
        ->toThrow(LogicException::class);

    expect(InvoiceSequence::query()->pluck('series')->all())->toBe(['standard']);
});

test('lock returns the settings read under the lock and the requested sequence rows keyed by series (FD-3, FD-4)', function () {
    InvoiceSetting::factory()->create(['standard_prefix' => 'INV', 'reset_numbering_yearly' => false]);

    $locked = Numbering::inTransaction(fn (): array => app(AllocateInvoiceNumber::class)->lock([InvoiceType::Corrective, InvoiceType::Standard], Numbering::at('2026-04-01 10:00:00')));

    expect($locked['settings']->standard_prefix)->toBe('INV')
        ->and($locked['settings']->reset_numbering_yearly)->toBeFalse()
        ->and(array_keys($locked['sequences']))->toEqualCanonicalizing(['standard', 'corrective'])
        ->and($locked['sequences']['standard'])->toBeInstanceOf(InvoiceSequence::class);
});

test('lock with no settings row returns the documented defaults as an unsaved instance (FD-4)', function () {
    $locked = Numbering::inTransaction(fn (): array => app(AllocateInvoiceNumber::class)->lock([InvoiceType::Standard], Numbering::at('2026-04-01 10:00:00')));

    expect($locked['settings']->exists)->toBeFalse()
        ->and($locked['settings']->standard_prefix)->toBe('F')
        ->and($locked['settings']->reset_numbering_yearly)->toBeTrue()
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

// R-7
test('__invoke takes no settings parameter, only the series and the issue moment (R-7)', function () {
    $parameters = (new ReflectionMethod(AllocateInvoiceNumber::class, '__invoke'))->getParameters();

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe(['series', 'issuedAt']);
});

test('a caller-held settings object with another reset flag cannot decide the year rule (R-7)', function () {
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-06-01 10:00:00'));
    $heldByCaller = InvoiceSetting::factory()->make(['reset_numbering_yearly' => false]);

    $result = Numbering::allocate(InvoiceType::Standard, Numbering::at('2027-01-05 10:00:00'));

    expect($heldByCaller->reset_numbering_yearly)->toBeFalse()
        ->and($result['sequence_number'])->toBe(1);
});

// R-6 / FD-5
test('an issue moment earlier than the last one is refused on invoice and consumes nothing (R-6)', function (string $last, string $attempt) {
    Numbering::arrange(InvoiceType::Standard, 7, 2027, Numbering::at($last));
    $before = Numbering::snapshot();

    try {
        Numbering::allocate(InvoiceType::Standard, Numbering::at($attempt));
        $failure = null;
    } catch (ValidationException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($failure->errors()))->toBe(['invoice'])
        ->and($failure->errors()['invoice'][0])->toBe(__('invoice-settings.numbering.clock_skew'))
        ->and(Numbering::snapshot())->toBe($before);
})->with([
    'a second earlier' => ['2027-06-10 12:00:00', '2027-06-10 11:59:59'],
    'a day earlier' => ['2027-06-10 12:00:00', '2027-06-09 12:00:00'],
    'across a year turn' => ['2027-01-01 00:30:00', '2026-12-31 23:00:00'],
]);

test('an issue moment equal to the last one is accepted (R-6)', function () {
    Numbering::arrange(InvoiceType::Standard, 7, 2027, Numbering::at('2027-06-10 12:00:00'));

    expect(Numbering::number(InvoiceType::Standard, Numbering::at('2027-06-10 12:00:00')))->toBe('F2027-00008');
});

test('clock skew compares real instants, not wall-clock strings across timezones (R-6)', function () {
    Numbering::arrange(InvoiceType::Standard, 7, 2027, Numbering::at('2027-06-10 12:00:00'));

    // 10:30 UTC is 12:30 Madrid in summer: later than 12:00 Madrid, accepted.
    expect(Numbering::number(InvoiceType::Standard, Numbering::utc('2027-06-10 10:30:00')))->toBe('F2027-00008');
});

// T-19 / FD-6
test('a counter wider than the padding is never truncated (T-19)', function () {
    Numbering::arrange(InvoiceType::Standard, 99999, 2026);

    expect(Numbering::number(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00')))->toBe('F2026-100000');
});

test('the largest unsigned counter is accepted and the one after it is refused on number without consuming (T-19)', function () {
    Numbering::arrange(InvoiceType::Standard, 4294967294, 2026);

    expect(Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00'))['sequence_number'])->toBe(4294967295);

    $before = Numbering::snapshot();

    try {
        Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-06-02 10:00:00'));
        $failure = null;
    } catch (ValidationException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($failure->errors()))->toBe(['number'])
        ->and($failure->errors()['number'][0])->toBe(__('invoice-settings.numbering.overflow'))
        ->and(Numbering::snapshot())->toBe($before);
});

test('with reset on a counter at the unsigned limit does not overflow when the year turns (T-19)', function () {
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);
    Numbering::arrange(InvoiceType::Standard, 4294967295, 2026, Numbering::at('2026-06-01 10:00:00'));

    expect(Numbering::allocate(InvoiceType::Standard, Numbering::at('2027-01-02 10:00:00'))['sequence_number'])->toBe(1);
});
