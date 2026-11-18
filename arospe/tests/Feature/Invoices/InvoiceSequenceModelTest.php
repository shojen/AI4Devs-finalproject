<?php

// Story 0101a -- App\Models\InvoiceSequence (casts, mass-assignment guard, UUID key) and the
// write boundary of T-32: only AllocateInvoiceNumber and SetInvoiceSequenceStart write it.

use App\Actions\Invoices\AllocateInvoiceNumber;
use App\Actions\Invoices\SetInvoiceSequenceStart;
use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Invoices\Numbering;

test('the model uses UUID keys, casts its counters and moment, and has no mass-assignable column', function () {
    $row = InvoiceSequence::query()->where('series', 'standard')->sole();

    expect(class_uses_recursive($row))->toContain(HasUuids::class)
        ->and(Str::isUuid($row->id, 7))->toBeTrue()
        ->and($row->getFillable())->toBe([])
        ->and($row->last_number)->toBeInt()
        ->and($row->last_year)->toBeNull()
        ->and($row->last_issued_at)->toBeNull();
});

test('a persisted counter reads back with its types and an immutable moment', function () {
    Numbering::arrange(InvoiceType::Standard, 12, 2026, Numbering::at('2026-05-05 10:30:00'));

    $row = InvoiceSequence::query()->where('series', 'standard')->sole();

    expect($row->last_number)->toBe(12)
        ->and($row->last_year)->toBe(2026)
        ->and($row->last_issued_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($row->last_issued_at->getTimestamp())->toBe(Numbering::at('2026-05-05 10:30:00')->getTimestamp());
});

test('mass assignment is refused outright and never changes a column', function () {
    $row = InvoiceSequence::query()->where('series', 'standard')->sole();

    expect(fn () => $row->fill(['last_number' => 99, 'last_year' => 2026, 'series' => 'corrective']))
        ->toThrow(MassAssignmentException::class);

    $fresh = InvoiceSequence::query()->where('series', 'standard')->sole();

    expect($fresh->last_number)->toBe(0)
        ->and($fresh->last_year)->toBeNull()
        ->and(DB::table('invoice_sequences')->count())->toBe(2);
});

test('the model has no relations and exposes only its columns', function () {
    $row = InvoiceSequence::query()->where('series', 'standard')->sole();

    expect(array_keys($row->getAttributes()))->toEqualCanonicalizing(['id', 'series', 'last_number', 'last_year', 'last_issued_at', 'created_at', 'updated_at']);
});

// T-32
test('only AllocateInvoiceNumber and SetInvoiceSequenceStart write InvoiceSequence (T-32)', function () {
    $writers = [];
    $referencing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'InvoiceSequence') && ! str_contains($source, 'invoice_sequences')) {
            continue;
        }

        $referencing[] = $file->getFilename();

        if (preg_match('/forceFill|->save\(|->update\(|->insert|->delete\(|::create\(|forceCreate/', $source) === 1) {
            $writers[] = $file->getFilename();
        }
    }

    sort($writers);

    expect($writers)->toBe(['AllocateInvoiceNumber.php', 'SetInvoiceSequenceStart.php'])
        ->and($referencing)->toContain('InvoiceSequence.php');
});

test('no route or Livewire component exposes the sequence model (T-32)', function () {
    $paths = [base_path('routes'), app_path('Livewire')];

    foreach ($paths as $path) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                expect(file_get_contents($file->getPathname()))->not->toContain('InvoiceSequence');
            }
        }
    }
});

test('the writer classes exist where the contract puts them (T-32)', function () {
    expect(class_exists(AllocateInvoiceNumber::class))->toBeTrue()
        ->and(class_exists(SetInvoiceSequenceStart::class))->toBeTrue();
});
