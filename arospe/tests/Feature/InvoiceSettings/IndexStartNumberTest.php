<?php

// Story 0103 -- the starting-number control (FD-4, T-24).

use App\Enums\InvoiceType;
use App\Livewire\InvoiceSettings\Index;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Feature\InvoiceSettings\ScreenFixtures;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    ScreenFixtures::boot();
});

test('a fresh series offers the start control and the modal opens for that series', function () {
    ScreenFixtures::manager();

    Livewire::test(Index::class)
        ->assertSeeHtml('data-test="set-start-standard"')
        ->assertDontSeeHtml('data-test="start-locked-standard"')
        ->call('openStartModal', 'standard')
        ->assertSet('startSeries', 'standard')
        ->assertSet('showStartModal', true)
        ->assertSet('startAt', '');
});

test('an unknown series in openStartModal is a no-op', function () {
    ScreenFixtures::manager();

    Livewire::test(Index::class)
        ->call('openStartModal', 'bogus')
        ->assertSet('startSeries', null)
        ->assertSet('showStartModal', false);
});

test('setting 1500 on a fresh series updates the preview to 01500, closes the modal and toasts', function () {
    ScreenFixtures::manager();

    $component = Livewire::test(Index::class)
        ->call('openStartModal', 'standard')
        ->set('startAt', '1500')
        ->call('setStart')
        ->assertHasNoErrors()
        ->assertSet('showStartModal', false)
        ->assertDispatched('toast-show');

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1499)
        ->and($component->html())->toContain('F2026-01500')
        ->and(Numbering::row(InvoiceType::Corrective)->last_number)->toBe(0);
});

test('an invalid start errors on startAt and writes nothing', function (string $value) {
    ScreenFixtures::manager();

    Livewire::test(Index::class)
        ->call('openStartModal', 'standard')
        ->set('startAt', $value)
        ->call('setStart')
        ->assertHasErrors(['startAt']);

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(0);
})->with(['zero' => '0', 'text' => 'abc', 'too large' => '4294967295', 'empty' => '']);

test('an issued series shows the locked text instead of the control and a direct openStartModal is a harmless no-op', function () {
    ScreenFixtures::manager();
    Numbering::arrange(InvoiceType::Standard, 4, 2026);

    Livewire::test(Index::class)
        ->assertDontSeeHtml('data-test="set-start-standard"')
        ->assertSeeHtml('data-test="start-locked-standard"')
        ->assertSeeHtml('data-test="set-start-corrective"')
        ->call('openStartModal', 'standard')
        ->assertSet('startSeries', null)
        ->assertSet('showStartModal', false);
});

test('a direct setStart on a series that has issued is refused on startAt and the sequence is unchanged', function () {
    ScreenFixtures::manager();
    $component = Livewire::test(Index::class)->call('openStartModal', 'standard');

    Numbering::arrange(InvoiceType::Standard, 4, 2026);

    $component->set('startAt', '900')->call('setStart')->assertHasErrors(['startAt']);

    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(4);
});

test('a view-only setStart and openStartModal are forbidden, setStart is logged and nothing changes', function () {
    Log::spy();
    $viewer = ScreenFixtures::viewer();

    Livewire::test(Index::class)->call('openStartModal', 'standard')->assertForbidden();
    Livewire::test(Index::class)->set('startAt', '900')->call('setStart')->assertForbidden();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $viewer->id
            && ($context['ability'] ?? null) === 'update')
        ->atLeast()->once();
    expect(Numbering::row(InvoiceType::Standard)->last_number)->toBe(0);
});
