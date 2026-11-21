<?php

// Story 0103 -- the live next-number lines of the numbering section (FD-5): follow the typed
// format and the reset flag, write nothing, never echo invalid input (T-11..T-14).

use App\Enums\InvoiceType;
use App\Livewire\InvoiceSettings\Index;
use App\Models\InvoiceSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\InvoiceSettings\ScreenFixtures;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    ScreenFixtures::boot();
    ScreenFixtures::manager();
});

/**
 * The text of the element carrying a data-test hook.
 */
function nextNumberText(string $html, string $hook): string
{
    preg_match('/data-test="'.$hook.'"[^>]*>(.*?)<\/code>/s', $html, $matches);

    return trim(html_entity_decode(strip_tags($matches[1] ?? ''), ENT_QUOTES));
}

// T-11
test('the defaults render F2026-00001 and R2026-00001 in the next-number lines', function () {
    $component = Livewire::test(Index::class);

    expect(nextNumberText($component->html(), 'next-number-standard'))->toBe('F2026-00001')
        ->and(nextNumberText($component->html(), 'next-number-corrective'))->toBe('R2026-00001');
});

// T-12
test('typing prefix INV, short year and padding 3 shows INV26-001, leaves the corrective line alone and writes nothing', function () {
    $settingsBefore = InvoiceSetting::query()->count();
    $sequencesBefore = Numbering::snapshot();

    $component = Livewire::test(Index::class)
        ->set('standardPrefix', 'INV')
        ->set('standardYearFormat', 'YY')
        ->set('standardPadding', 3);

    expect(nextNumberText($component->html(), 'next-number-standard'))->toBe('INV26-001')
        ->and(nextNumberText($component->html(), 'next-number-corrective'))->toBe('R2026-00001')
        ->and(InvoiceSetting::query()->count())->toBe($settingsBefore)
        ->and(Numbering::snapshot())->toBe($sequencesBefore);
});

// T-13
test('a markup or illegal prefix shows the invalid line, echoes nothing and leaves the error bag empty', function (string $prefix) {
    $component = Livewire::test(Index::class)->set('standardPrefix', $prefix);

    $html = $component->html();

    expect(nextNumberText($html, 'next-number-standard'))->toBe(__('invoice-settings.screen.next_number_invalid'))
        ->and($html)->not->toContain('<b>')
        ->and($html)->not->toContain('&lt;b&gt;')
        ->and($component->errors()->toArray())->toBe([]);
})->with(['<b>', 'F;']);

// T-14
test('a series at last_number 7 previews 00008 and the reset flag toggled live changes the preview', function () {
    Numbering::arrange(InvoiceType::Standard, 7);

    $component = Livewire::test(Index::class);

    expect(nextNumberText($component->html(), 'next-number-standard'))->toBe('F2026-00008');

    Numbering::arrange(InvoiceType::Standard, 7, 2025);

    $component = Livewire::test(Index::class);
    expect(nextNumberText($component->html(), 'next-number-standard'))->toBe('F2026-00001');

    $component->set('isNumberingResetYearly', false);
    expect(nextNumberText($component->html(), 'next-number-standard'))->toBe('F2026-00008');

    $component->set('isNumberingResetYearly', true);
    expect(nextNumberText($component->html(), 'next-number-standard'))->toBe('F2026-00001');
});

test('typing in the numbering fields issues no write query', function () {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    Livewire::test(Index::class)
        ->set('standardPrefix', 'INV')
        ->set('correctivePrefix', 'COR')
        ->set('isNumberingResetYearly', false);

    expect($writes)->toBe([]);
});
