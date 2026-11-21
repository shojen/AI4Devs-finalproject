<?php

// Story 0103 -- App\Livewire\InvoiceSettings\Index: prefill, whole-record save, normalisation,
// refusals re-keyed to properties (FD-8), colour feedback, logo and template cards (T-1..T-10,
// T-20..T-23).

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Invoices\AssertNumberingFormatIsFree;
use App\Actions\Invoices\SaveInvoiceSettings;
use App\Enums\InvoiceTemplate;
use App\Livewire\InvoiceSettings\Index;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Media;
use Livewire\Livewire;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;
use Tests\Feature\InvoiceSettings\ScreenFixtures;

beforeEach(function () {
    ScreenFixtures::boot();
    ScreenFixtures::manager();
});

// T-1
test('with no saved row the form shows every documented default', function () {
    Livewire::test(Index::class)
        ->assertSet('standardPrefix', 'F')
        ->assertSet('standardYearFormat', 'YYYY')
        ->assertSet('standardSeparator', '-')
        ->assertSet('standardPadding', 5)
        ->assertSet('correctivePrefix', 'R')
        ->assertSet('correctiveYearFormat', 'YYYY')
        ->assertSet('correctiveSeparator', '-')
        ->assertSet('correctivePadding', 5)
        ->assertSet('isNumberingResetYearly', true)
        ->assertSet('isAutoIssueEnabled', false)
        ->assertSet('template', 'classic')
        ->assertSet('logoMediaId', null)
        ->assertSet('issuerLegalName', '')
        ->assertSet('primaryColor', '');
});

// T-2
test('a saved row prefills every field and shows null columns as empty strings', function () {
    InvoiceSetting::factory()->withIssuer()->create([
        'issuer_address_line2' => null,
        'issuer_province' => 'Madrid',
        'primary_color' => '#112233',
        'accent_color' => null,
        'template' => 'modern',
        'standard_prefix' => 'INV',
        'standard_year_format' => 'YY',
        'standard_separator' => '/',
        'standard_padding' => 7,
        'reset_numbering_yearly' => false,
        'auto_issue_on_order' => true,
    ]);

    Livewire::test(Index::class)
        ->assertSet('issuerLegalName', 'Arospe Handmade Sociedad Limitada')
        ->assertSet('issuerTaxId', 'ESB12345678')
        ->assertSet('issuerAddressLine1', 'Calle Mayor 1')
        ->assertSet('issuerAddressLine2', '')
        ->assertSet('issuerProvince', 'Madrid')
        ->assertSet('issuerCountry', 'ES')
        ->assertSet('primaryColor', '#112233')
        ->assertSet('accentColor', '')
        ->assertSet('template', 'modern')
        ->assertSet('standardPrefix', 'INV')
        ->assertSet('standardYearFormat', 'YY')
        ->assertSet('standardSeparator', '/')
        ->assertSet('standardPadding', 7)
        ->assertSet('isNumberingResetYearly', false)
        ->assertSet('isAutoIssueEnabled', true);
});

// T-3
test('a valid save sends the action exactly the full key set and replaces the row', function () {
    $watcher = Mockery::mock(SaveInvoiceSettings::class, [
        app(LogRefusedPrivilegedAttempt::class),
        app(AssertNumberingFormatIsFree::class),
    ])->makePartial();
    app()->instance(SaveInvoiceSettings::class, $watcher);
    $watcher->shouldReceive('__invoke')
        ->once()
        ->with(Mockery::on(function (array $attributes): bool {
            $keys = array_keys($attributes);
            sort($keys);
            $expected = InvoiceSettingsPayload::KEYS;
            sort($expected);

            return $keys === $expected
                && is_int($attributes['standard_padding'])
                && is_int($attributes['corrective_padding'])
                && is_bool($attributes['reset_numbering_yearly'])
                && is_bool($attributes['auto_issue_on_order'])
                && array_key_exists('logo_media_id', $attributes);
        }))
        ->passthru();

    Livewire::test(Index::class)
        ->set(ScreenFixtures::form())
        ->call('save')
        ->assertHasNoErrors();

    $row = InvoiceSetting::current();

    expect(InvoiceSetting::query()->count())->toBe(1)
        ->and($row->issuer_legal_name)->toBe('Arospe Handmade Sociedad Limitada')
        ->and($row->template->value)->toBe('modern')
        ->and($row->standard_prefix)->toBe('INV')
        ->and($row->standard_padding)->toBe(6);
});

// T-4
test('a save normalises the tax ID, colours and country and refills the form with the stored form', function () {
    $component = Livewire::test(Index::class)
        ->set(ScreenFixtures::form(['issuerTaxId' => ' es b123 ', 'primaryColor' => '#a1b2c3', 'issuerCountry' => 'es']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('issuerTaxId', 'ESB123')
        ->assertSet('primaryColor', '#A1B2C3')
        ->assertSet('issuerCountry', 'ES');

    $row = InvoiceSetting::current();

    expect($row->issuer_tax_id)->toBe('ESB123')
        ->and($row->primary_color)->toBe('#A1B2C3')
        ->and($row->issuer_country)->toBe('ES');

    $component->set(['primaryColor' => '', 'accentColor' => ''])->call('save')->assertHasNoErrors();

    expect(InvoiceSetting::current()->primary_color)->toBeNull()
        ->and(InvoiceSetting::current()->accent_color)->toBeNull();
});

// T-5
test('both checkboxes persist in every combination and refill', function (bool $reset, bool $auto) {
    Livewire::test(Index::class)
        ->set(ScreenFixtures::form(['isNumberingResetYearly' => $reset, 'isAutoIssueEnabled' => $auto]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('isNumberingResetYearly', $reset)
        ->assertSet('isAutoIssueEnabled', $auto);

    expect(InvoiceSetting::current()->reset_numbering_yearly)->toBe($reset)
        ->and(InvoiceSetting::current()->auto_issue_on_order)->toBe($auto);
})->with([
    'reset on, auto off' => [true, false],
    'reset on, auto on' => [true, true],
    'reset off, auto off' => [false, false],
    'reset off, auto on' => [false, true],
]);

// T-6
test('each backend refusal lands on the mapped property only', function (array $state, string $property) {
    $component = Livewire::test(Index::class)
        ->set(ScreenFixtures::form($state))
        ->call('save')
        ->assertHasErrors([$property]);

    $erroredKeys = array_keys($component->errors()->toArray());

    expect($erroredKeys)->toBe([$property])
        ->and(InvoiceSetting::query()->count())->toBe(0);
})->with([
    'tax id' => [['issuerTaxId' => 'ES B1;2'], 'issuerTaxId'],
    'country' => [['issuerCountry' => 'ESP'], 'issuerCountry'],
    'primary colour' => [['primaryColor' => 'red'], 'primaryColor'],
    'accent colour' => [['accentColor' => '#12'], 'accentColor'],
    'template' => [['template' => 'bogus'], 'template'],
    'standard prefix' => [['standardPrefix' => 'A B'], 'standardPrefix'],
    'standard year' => [['standardYearFormat' => 'XX'], 'standardYearFormat'],
    'standard separator' => [['standardSeparator' => 'ab'], 'standardSeparator'],
    'standard padding' => [['standardPadding' => 99], 'standardPadding'],
    'corrective prefix' => [['correctivePrefix' => 'A B'], 'correctivePrefix'],
    'corrective year' => [['correctiveYearFormat' => 'XX'], 'correctiveYearFormat'],
    'corrective separator' => [['correctiveSeparator' => 'ab'], 'correctiveSeparator'],
    'corrective padding' => [['correctivePadding' => 0], 'correctivePadding'],
    'auto issue without issuer' => [['isAutoIssueEnabled' => true, 'issuerTaxId' => ''], 'isAutoIssueEnabled'],
]);

test('a logo deleted after it was chosen is refused on logoMediaId and the message renders next to the logo', function () {
    $media = Media::factory()->create();
    $component = Livewire::test(Index::class)
        ->set(ScreenFixtures::form())
        ->call('setLogo', [['id' => $media->id]])
        ->assertSet('logoMediaId', $media->id);

    $media->delete();

    $component->call('save')->assertHasErrors(['logoMediaId']);

    $message = $component->errors()->first('logoMediaId');

    expect($message)->not->toBe('')
        ->and(array_keys($component->errors()->toArray()))->toBe(['logoMediaId'])
        ->and($component->html())->toContain(e($message))
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

// T-7
test('reset on with year none is refused on that series year property and the row is unchanged', function (string $property) {
    InvoiceSetting::factory()->create(['standard_prefix' => 'KEEP']);

    Livewire::test(Index::class)
        ->set(ScreenFixtures::form([$property => 'none', 'isNumberingResetYearly' => true]))
        ->call('save')
        ->assertHasErrors([$property]);

    expect(InvoiceSetting::current()->standard_prefix)->toBe('KEEP');
})->with(['standardYearFormat', 'correctiveYearFormat']);

// T-8
test('prefixes differing only in case are refused on the corrective prefix', function () {
    Livewire::test(Index::class)
        ->set(ScreenFixtures::form(['standardPrefix' => 'F', 'correctivePrefix' => 'f']))
        ->call('save')
        ->assertHasErrors(['correctivePrefix']);
});

// T-9
test('auto issue needs the issuer data, also when blanking the legal name of an auto-on row', function () {
    Livewire::test(Index::class)
        ->set(ScreenFixtures::form(['isAutoIssueEnabled' => true, 'issuerTaxId' => '']))
        ->call('save')
        ->assertHasErrors(['isAutoIssueEnabled']);

    InvoiceSetting::factory()->withIssuer()->autoIssue()->create();

    Livewire::test(Index::class)
        ->assertSet('isAutoIssueEnabled', true)
        ->set('issuerLegalName', '')
        ->call('save')
        ->assertHasErrors(['isAutoIssueEnabled']);

    expect(InvoiceSetting::current()->issuer_legal_name)->not->toBeNull();
});

// T-10
test('a format whose next number already exists as an invoice is refused on the series prefix and listed in the numbering callout', function () {
    Invoice::factory()->create(['number' => 'INV26/000001']);

    Livewire::test(Index::class)
        ->set(ScreenFixtures::form(['standardPrefix' => 'INV', 'standardYearFormat' => 'YY', 'standardSeparator' => '/', 'standardPadding' => 6]))
        ->call('save')
        ->assertHasErrors(['standardPrefix'])
        ->assertSeeHtml('data-test="numbering-refused"');

    $settings = InvoiceSetting::current();

    expect($settings->standard_prefix)->toBe('F')
        ->and($settings->standard_year_format->value)->toBe('YYYY');
});

// T-20
test('an unknown template and a script-bearing colour are refused and never reach a style attribute', function () {
    $hostile = '#fff;}</style><script>';

    $component = Livewire::test(Index::class)
        ->set(ScreenFixtures::form(['template' => 'bogus', 'primaryColor' => $hostile]))
        ->call('save')
        ->assertHasErrors(['template', 'primaryColor']);

    expect($component->html())->not->toContain('<script>alert')
        ->and($component->html())->not->toContain('style="'.$hostile)
        ->and(preg_match('/style="[^"]*<\/style>/', $component->html()))->toBe(0)
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

// T-21
test('the template options equal every InvoiceTemplate case with one card and one preview link each', function () {
    $component = Livewire::test(Index::class);

    $values = collect($component->instance()->templateOptions)->pluck('value')->all();
    $expected = array_map(fn (InvoiceTemplate $case): string => $case->value, InvoiceTemplate::cases());

    expect(count($expected))->toBeGreaterThanOrEqual(2)
        ->and($values)->toBe($expected);

    foreach ($expected as $value) {
        $component->assertSeeHtml('data-test="template-option-'.$value.'"')
            ->assertSeeHtml('data-test="template-preview-'.$value.'"')
            ->assertSeeHtml(route('invoice-settings.preview', ['template' => $value]));
    }

    expect(substr_count($component->html(), 'data-test="template-preview-'))->toBe(count($expected));
});

// T-22
test('a chosen logo is previewed, removing it and saving stores null and drops the preview', function () {
    $media = Media::factory()->create(['title' => 'Our logo']);

    $component = Livewire::test(Index::class)
        ->set(ScreenFixtures::form())
        ->call('setLogo', [['id' => $media->id]])
        ->assertSet('logoMediaId', $media->id)
        ->assertSeeHtml('data-test="logo-preview"');

    $component->call('save')->assertHasNoErrors();

    expect(InvoiceSetting::current()->logo_media_id)->toBe($media->id);

    $component->call('removeLogo')->assertSet('logoMediaId', null)->call('save')->assertHasNoErrors();

    expect(InvoiceSetting::current()->logo_media_id)->toBeNull();
    $component->assertDontSeeHtml('data-test="logo-preview"');
});

// T-23
test('colour blur feedback refuses a malformed colour before Save and normalises #a1b2c3 to what Save stores', function (string $malformed) {
    $component = Livewire::test(Index::class)
        ->set('primaryColor', $malformed)
        ->assertHasErrors(['primaryColor'])
        ->set('primaryColor', '#a1b2c3')
        ->assertHasNoErrors(['primaryColor'])
        ->assertSet('primaryColor', '#A1B2C3');

    $component->set(ScreenFixtures::form(['primaryColor' => '#a1b2c3']))->call('save')->assertHasNoErrors();

    expect(InvoiceSetting::current()->primary_color)->toBe('#A1B2C3');
})->with(['a colour name' => 'red', 'too few digits' => '#12345', 'non-hex digits' => '#GGGGGG']);

test('the accent colour blur feedback behaves like the primary one', function () {
    Livewire::test(Index::class)
        ->set('accentColor', 'blue')
        ->assertHasErrors(['accentColor'])
        ->set('accentColor', '#abcdef')
        ->assertHasNoErrors(['accentColor'])
        ->assertSet('accentColor', '#ABCDEF');
});
