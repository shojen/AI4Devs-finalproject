<?php

// Story 0101a -- App\Actions\Invoices\PeekNextInvoiceNumber: read-only preview (FD-7, FD-12).

use App\Actions\Invoices\PeekNextInvoiceNumber;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<string, mixed>|null  $candidate
 */
function peek(InvoiceType $series, ?array $candidate = null, string $at = '2026-06-15 10:00:00'): string
{
    return app(PeekNextInvoiceNumber::class)($series, $candidate, Numbering::at($at));
}

/**
 * @param  array<string, mixed>  $candidate
 */
function peekRefusedKeys(InvoiceType $series, array $candidate): array
{
    try {
        peek($series, $candidate);
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

// T-7
test('the preview for saved settings is the exact next number of each series and changes nothing (T-7)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create(['corrective_prefix' => 'COR']);
    Numbering::arrange(InvoiceType::Standard, 2, 2026);
    $before = Numbering::snapshot();

    expect(peek(InvoiceType::Standard))->toBe('F2026-00003')
        ->and(peek(InvoiceType::Corrective))->toBe('COR2026-00001')
        ->and(peek(InvoiceType::Standard))->toBe('F2026-00003')
        ->and(Numbering::snapshot())->toBe($before);
});

test('the preview with no settings row uses the defaults and creates no row (T-7)', function () {
    Numbering::actingWith('invoice-settings.view');

    expect(peek(InvoiceType::Standard))->toBe('F2026-00001')
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

test('the preview defaults its moment to now in the app timezone', function () {
    Numbering::actingWith('invoice-settings.view');
    $this->travelTo(Numbering::at('2031-01-01 00:00:01'));

    expect(app(PeekNextInvoiceNumber::class)(InvoiceType::Standard))->toBe('F2031-00001');
});

// T-8
test('a raw candidate array previews the candidate format with the real next counter and persists nothing (T-8)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create(['standard_prefix' => 'SAVED']);
    Numbering::arrange(InvoiceType::Standard, 6, 2026);
    $before = Numbering::snapshot();
    $savedAttributes = InvoiceSetting::query()->firstOrFail()->getAttributes();

    $preview = peek(InvoiceType::Standard, ['standard_prefix' => 'INV', 'standard_year_format' => 'YY', 'standard_padding' => 3]);

    expect($preview)->toBe('INV26-007')
        ->and(Numbering::snapshot())->toBe($before)
        ->and(InvoiceSetting::query()->count())->toBe(1)
        ->and(InvoiceSetting::query()->firstOrFail()->getAttributes())->toBe($savedAttributes);
});

test('a candidate on a database with no settings row persists no settings row (T-8)', function () {
    Numbering::actingWith('invoice-settings.view');

    peek(InvoiceType::Corrective, ['corrective_prefix' => 'COR']);

    expect(InvoiceSetting::query()->count())->toBe(0);
});

// R-11
test('a candidate-format preview with reset on across a year turn shows 1, equal to the allocation at that instant, and takes no lock and writes nothing (R-11)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => true]);
    Numbering::arrange(InvoiceType::Standard, 7, 2026, Numbering::at('2026-06-01 10:00:00'));
    $before = Numbering::snapshot();
    $at = '2027-01-02 10:00:00';
    $preview = null;

    $statements = Numbering::numberingStatements(function () use (&$preview, $at): void {
        $preview = peek(InvoiceType::Standard, ['standard_prefix' => 'INV'], $at);
    });

    $allowed = array_filter($statements, fn (string $sql): bool => ! str_starts_with($sql, 'select') || str_contains($sql, 'for share') || str_contains($sql, 'for update'));

    expect($preview)->toBe('INV2027-00001')
        ->and($allowed)->toBe([])
        ->and(Numbering::snapshot())->toBe($before);

    InvoiceSetting::query()->firstOrFail()->forceFill(['standard_prefix' => 'INV'])->save();

    expect(Numbering::number(InvoiceType::Standard, Numbering::at($at)))->toBe($preview);
});

// T-33
test('a partial candidate uses the saved separator, padding, year token and reset flag for the missing fields (T-33)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create(['standard_separator' => '/', 'standard_padding' => 3, 'standard_year_format' => 'YY', 'reset_numbering_yearly' => false]);
    Numbering::arrange(InvoiceType::Standard, 7, 2025, Numbering::at('2025-06-01 10:00:00'));

    // Reset off in the saved settings: the counter continues into 2026.
    expect(peek(InvoiceType::Standard, ['standard_prefix' => 'INV']))->toBe('INV26/008');
});

test('a candidate flipping the reset flag is judged with the flipped flag (T-33)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create(['reset_numbering_yearly' => false]);
    Numbering::arrange(InvoiceType::Standard, 7, 2025, Numbering::at('2025-06-01 10:00:00'));

    expect(peek(InvoiceType::Standard))->toBe('F2026-00008')
        ->and(peek(InvoiceType::Standard, ['reset_numbering_yearly' => true]))->toBe('F2026-00001');
});

test('an invalid value in a partial candidate is refused on its own field (T-33)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create();

    expect(peekRefusedKeys(InvoiceType::Standard, ['standard_prefix' => 'INV', 'standard_padding' => 11]))->toBe(['standard_padding']);
});

test('keys outside the series subset are ignored and not validated (T-33)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create();

    $preview = peek(InvoiceType::Standard, [
        'standard_prefix' => 'INV',
        'primary_color' => 'not-a-colour',
        'corrective_prefix' => '<b>',
        'corrective_padding' => 99,
        'issuer_tax_id' => '???',
        'template' => 'nonsense',
    ]);

    expect($preview)->toBe('INV2026-00001');
});

test('the corrective preview reads the corrective fields of the candidate only (T-33)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create();

    expect(peek(InvoiceType::Corrective, ['corrective_prefix' => 'COR', 'standard_prefix' => '<b>']))->toBe('COR2026-00001');
});

test('reset on with year none is previewed, not refused: cross-field rules belong to Save (T-33, FD-12)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create();
    Numbering::arrange(InvoiceType::Standard, 7, 2026);

    expect(peek(InvoiceType::Standard, ['reset_numbering_yearly' => true, 'standard_year_format' => 'none']))->toBe('F-00008');
});

// T-28
test('an invalid raw candidate is a ValidationException on that field, never a ValueError (T-28)', function (array $candidate, string $field, InvoiceType $series = InvoiceType::Standard) {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create();

    expect(fn () => peek($series, $candidate))->toThrow(ValidationException::class)
        ->and(peekRefusedKeys($series, $candidate))->toBe([$field]);
})->with([
    'prefix with a dot' => [['standard_prefix' => 'F.'], 'standard_prefix'],
    'prefix with markup' => [['standard_prefix' => '<b>'], 'standard_prefix'],
    'prefix of 11 characters' => [['standard_prefix' => 'ABCDEFGHIJK'], 'standard_prefix'],
    'unknown year token' => [['standard_year_format' => 'XX'], 'standard_year_format'],
    'padding of 11' => [['standard_padding' => 11], 'standard_padding'],
    'padding of 0' => [['standard_padding' => 0], 'standard_padding'],
    'corrective unknown year token' => [['corrective_year_format' => 'XX'], 'corrective_year_format', InvoiceType::Corrective],
]);

test('an invalid candidate returns no preview and reads no number (T-28)', function () {
    Numbering::actingWith('invoice-settings.view');
    InvoiceSetting::factory()->create();
    $result = 'untouched';

    try {
        $result = peek(InvoiceType::Corrective, ['corrective_year_format' => 'XX']);
    } catch (ValidationException) {
        //
    }

    expect($result)->toBe('untouched');
});

// T-27
test('without invoice-settings.view the preview is refused, logged once and nothing is read (T-27)', function (array $permissions) {
    Log::spy();
    $actor = Numbering::actingWith(...$permissions);

    $statements = Numbering::numberingStatements(function (): void {
        expect(fn () => peek(InvoiceType::Standard))->toThrow(AuthorizationException::class);
    });

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === 'invoice_setting')
        ->once();
    expect($statements)->toBe([]);
})->with([
    'manage only' => [['invoice-settings.manage']],
    'no permission at all' => [[]],
]);

test('an unauthenticated preview is refused and logged without an actor (T-27)', function () {
    Log::spy();

    expect(fn () => peek(InvoiceType::Standard))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null)
        ->once();
});
