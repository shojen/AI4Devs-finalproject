<?php

// Story 0101a -- App\Actions\Invoices\SetInvoiceSequenceStart: the one-time starting counter (FD-8, FD-12).

use App\Actions\Invoices\SetInvoiceSequenceStart;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @return array<string, array<int, string>>
 */
function startRefusal(InvoiceType $series, int $startAt): array
{
    try {
        app(SetInvoiceSequenceStart::class)($series, $startAt);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

// T-6
test('a start of 1500 on a fresh series makes the first number end in 01500 and is not an allocation (T-6)', function () {
    Numbering::actingWith('invoice-settings.manage');

    app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 1500);

    $row = Numbering::row(InvoiceType::Standard);

    expect($row->last_number)->toBe(1499)
        ->and($row->last_issued_at)->toBeNull()
        ->and($row->last_year)->toBeNull()
        ->and(Numbering::number(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00')))->toBe('F2026-01500');
});

test('the start touches only its own series row', function () {
    Numbering::actingWith('invoice-settings.manage');

    app(SetInvoiceSequenceStart::class)(InvoiceType::Corrective, 20);

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(0)
        ->and(Numbering::row(InvoiceType::Corrective)->last_number)->toBe(19);
});

// T-20 / T-21
test('the boundary starts 1, 1500 and 4294967294 are accepted (T-20)', function (int $startAt) {
    Numbering::actingWith('invoice-settings.manage');

    app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, $startAt);

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe($startAt - 1)
        ->and(Numbering::allocate(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00'))['sequence_number'])->toBe($startAt);
})->with([1, 1500, 4294967294]);

test('a second start before any issue is accepted and the last one wins (T-21)', function () {
    Numbering::actingWith('invoice-settings.manage');
    $action = app(SetInvoiceSequenceStart::class);

    $action(InvoiceType::Standard, 100);
    $action(InvoiceType::Standard, 250);

    expect(Numbering::number(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00')))->toBe('F2026-00250');
});

// R-9
test('with a start set and reset on, the first issue in a later year still uses the start (R-9)', function () {
    Numbering::actingWith('invoice-settings.manage');
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);

    app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 1500);

    expect(Numbering::number(InvoiceType::Standard, Numbering::at('2027-03-01 10:00:00')))->toBe('F2027-01500');
});

// T-23
test('the start is refused once the series has issued, this year or in a prior year, and the counter is unchanged (T-23)', function (int $year) {
    Numbering::actingWith('invoice-settings.manage');
    Numbering::arrange(InvoiceType::Standard, 7, $year);
    $before = Numbering::snapshot();

    $errors = startRefusal(InvoiceType::Standard, 1500);

    expect(array_keys($errors))->toBe(['start_at'])
        ->and($errors['start_at'][0])->toBe(__('invoice-settings.numbering.start_already_issued'))
        ->and($errors['start_at'][0])->not->toBe('invoice-settings.numbering.start_already_issued')
        ->and(Numbering::snapshot())->toBe($before);
})->with([
    'this year' => [2026],
    'a prior year' => [2024],
]);

test('a start made through the action is refused after the first allocation (T-23)', function () {
    Numbering::actingWith('invoice-settings.manage');
    app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 100);
    Numbering::number(InvoiceType::Standard, Numbering::at('2026-06-01 10:00:00'));

    expect(array_keys(startRefusal(InvoiceType::Standard, 200)))->toBe(['start_at'])
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(100);
});

test('the never-allocated series still accepts a start while the other has issued (T-23)', function () {
    Numbering::actingWith('invoice-settings.manage');
    Numbering::arrange(InvoiceType::Standard, 7, 2026);

    app(SetInvoiceSequenceStart::class)(InvoiceType::Corrective, 300);

    expect(Numbering::row(InvoiceType::Corrective)->last_number)->toBe(299)
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(7);
});

// T-24 / T-34
test('starts of 0, -1, 4294967295 and 9999999999999 are refused on start_at and write nothing (T-24)', function (int $startAt) {
    Numbering::actingWith('invoice-settings.manage');
    $before = Numbering::snapshot();

    $errors = startRefusal(InvoiceType::Standard, $startAt);

    expect(array_keys($errors))->toBe(['start_at'])
        ->and($errors['start_at'][0])->toBe(__('invoice-settings.numbering.start_invalid'))
        ->and($errors['start_at'][0])->not->toBe('invoice-settings.numbering.start_invalid')
        ->and(Numbering::snapshot())->toBe($before);
})->with([0, -1, 4294967295, 9999999999999]);

// T-25
test('without invoice-settings.manage the start is refused before any state is read, whether or not the series has issued (T-25)', function (array $permissions, bool $issued) {
    Numbering::actingWith(...$permissions);

    if ($issued) {
        Numbering::arrange(InvoiceType::Standard, 7, 2026);
    }

    $before = Numbering::snapshot();

    $statements = Numbering::numberingStatements(function (): void {
        expect(fn () => app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 1500))->toThrow(AuthorizationException::class);
    });

    expect($statements)->toBe([])
        ->and(Numbering::snapshot())->toBe($before);
})->with([
    'view only, fresh series' => [['invoice-settings.view'], false],
    'view only, issued series' => [['invoice-settings.view'], true],
    'no permission, fresh series' => [[], false],
    'no permission, issued series' => [[], true],
]);

test('an out-of-range start from an unauthorized actor is refused as unauthorized, not as invalid (T-25)', function () {
    Numbering::actingWith('invoice-settings.view');

    expect(fn () => app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 0))->toThrow(AuthorizationException::class);
});

// T-26
test('a refusal logs one warning with ability update and target invoice_setting (T-26)', function () {
    Log::spy();
    $actor = Numbering::actingWith('invoice-settings.view');

    expect(fn () => app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 1500))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'invoice_setting')
        ->once();
});

test('an unauthenticated start is refused and logged without an actor id (T-26)', function () {
    Log::spy();

    expect(fn () => app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 1500))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null
            && ($context['ability'] ?? null) === 'update')
        ->once();
    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(0);
});

test('a Super Admin may set the start (FD-10)', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 10);

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(9);
});

test('the start locks settings FOR SHARE then the series row FOR UPDATE inside a transaction and inserts nothing (FD-8)', function () {
    Numbering::actingWith('invoice-settings.manage');

    $statements = Numbering::numberingStatements(function (): void {
        app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 10);
    });

    $locks = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'for share') || str_contains($sql, 'for update')));

    expect($locks)->toHaveCount(2)
        ->and($locks[0])->toContain('invoice_settings')->toContain('for share')
        ->and($locks[1])->toContain('invoice_sequences')->toContain('for update')
        ->and(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert')))->toBe([])
        ->and(DB::transactionLevel())->toBeGreaterThan(0);
});

test('a successful start logs who changed which series from which counter to which (F3)', function () {
    $actor = Numbering::actingWith('invoice-settings.manage');
    Log::spy();

    app(SetInvoiceSequenceStart::class)(InvoiceType::Standard, 1500);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Invoice sequence start set'
        && ($context['actor_id'] ?? null) === $actor->id
        && ($context['series'] ?? null) === 'standard'
        && ($context['old_last_number'] ?? null) === 0
        && ($context['new_last_number'] ?? null) === 1499);
});

test('a refused start does not log the success entry (F3)', function () {
    Numbering::actingWith('invoice-settings.manage');
    Log::spy();

    startRefusal(InvoiceType::Standard, 0);

    Log::shouldNotHaveReceived('info');
});
