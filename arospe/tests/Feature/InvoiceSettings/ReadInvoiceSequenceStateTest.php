<?php

// Story 0103 -- App\Actions\Invoices\ReadInvoiceSequenceState: the pure read of both counters (T-25).

use App\Actions\Invoices\ReadInvoiceSequenceState;
use App\Enums\InvoiceType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\InvoiceSettings\ScreenFixtures;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    ScreenFixtures::boot();
});

test('a fresh install reports both series as not issued with a zero counter', function () {
    ScreenFixtures::viewer();

    $state = app(ReadInvoiceSequenceState::class)();

    expect(array_keys($state))->toBe(['standard', 'corrective'])
        ->and($state['standard'])->toBe(['hasIssued' => false, 'lastNumber' => 0, 'lastYear' => null, 'lastIssuedAt' => null])
        ->and($state['corrective'])->toBe(['hasIssued' => false, 'lastNumber' => 0, 'lastYear' => null, 'lastIssuedAt' => null]);
});

test('hasIssued follows last_issued_at, not the counter, and each series reports its own row', function () {
    ScreenFixtures::viewer();
    // A starting number moves the counter without issuing anything.
    Numbering::arrange(InvoiceType::Standard, 1499);
    Numbering::arrange(InvoiceType::Corrective, 7, 2025, Numbering::at('2025-06-01 09:00:00'));

    $state = app(ReadInvoiceSequenceState::class)();

    expect($state['standard']['hasIssued'])->toBeFalse()
        ->and($state['standard']['lastNumber'])->toBe(1499)
        ->and($state['corrective']['hasIssued'])->toBeTrue()
        ->and($state['corrective']['lastNumber'])->toBe(7)
        ->and($state['corrective']['lastYear'])->toBe(2025)
        ->and($state['corrective']['lastIssuedAt'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($state['corrective']['lastIssuedAt']->year)->toBe(2025);
});

test('it is refused and logged without invoice-settings.view', function () {
    Log::spy();
    $actor = ScreenFixtures::actingWith('invoice-settings.manage');

    expect(fn () => app(ReadInvoiceSequenceState::class)())->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny')
        ->once();
});

test('it runs exactly one query, on invoice_sequences, and writes nothing', function () {
    ScreenFixtures::viewer();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(ReadInvoiceSequenceState::class)();

    $sequenceQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'invoice_sequences'));

    expect($sequenceQueries)->toHaveCount(1)
        ->and(array_filter($queries, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/', $sql) === 1))->toBe([]);
});
