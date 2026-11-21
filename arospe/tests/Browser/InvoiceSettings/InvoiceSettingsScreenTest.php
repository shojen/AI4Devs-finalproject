<?php

// Story 0103 -- Pest 4 browser tests for the invoice settings screen (route invoice-settings.index,
// GET /invoice-settings): journeys B-1..B-9 of the story's "Tests to perform". TDD red: the screen,
// its route and its data-test hooks do not exist yet.
//
// What only a real browser proves, and Livewire::test() cannot: that the typed values really reach
// the Save round trip (wire:model.blur), that the debounced next-number preview follows the format
// being typed, that the Media Gallery modal hands the picked image back, that the colour swatch and
// the template cards are wired to the form, and that Flux's custom elements honour a disabled
// fieldset for a read-only viewer.
//
// Waits: ->waitForEvent('networkidle') is banned here; a bounded ->wait(1) compensates for a Livewire
// round trip with nothing visible to wait on; a database read after a click is polled with
// Execution::instance()->wait(0.1), never usleep() (the in-process HTTP server only advances while the
// test awaits the browser).
//
// Hooks taken from the story's "View and hooks" section: logo-preview, choose-logo, remove-logo,
// primary-color-input, accent-color-input, template-option-{value}, template-thumbnail-{value},
// template-preview-{value}, next-number-standard|corrective, set-start-standard|corrective,
// start-locked-standard|corrective, numbering-refused, invoice-settings-save,
// invoice-settings-readonly-notice, start-number-modal, start-number-input, start-number-confirm.
//
// Hooks ASSUMED beyond the story (adjust here first if the real markup names them differently):
//   primary-color-swatch, accent-color-swatch      the native <input type="color"> next to each colour input
//   primary-color-default                           the "Use template default" ghost button of the primary colour
// Fields are reached by their Livewire property name (as in the sibling tests): issuerLegalName,
// issuerTaxId, issuerAddressLine1, issuerCity, issuerPostalCode, issuerCountry, standardPrefix,
// standardYearFormat, standardPadding, correctivePrefix, correctiveYearFormat, isNumberingResetYearly,
// isAutoIssueEnabled, primaryColor.

use App\Enums\InvoiceType;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSetting;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Execution;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

function invoiceSettingsManager(bool $mayViewMedia = true): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(array_merge(['invoice-settings.view', 'invoice-settings.manage'], $mayViewMedia ? ['media.view'] : []));

    return $actor;
}

function invoiceSettingsViewer(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['invoice-settings.view']);

    return $actor;
}

/**
 * Poll the stored settings row until the given condition holds (bounded), so an assertion after a
 * click with nothing visible to wait on never races the Livewire round trip.
 *
 * @param  callable(InvoiceSetting): bool  $condition
 */
function waitForInvoiceSettings(callable $condition): InvoiceSetting
{
    $setting = InvoiceSetting::current();

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $setting = InvoiceSetting::current();

        if ($condition($setting)) {
            return $setting;
        }

        Execution::instance()->wait(0.1);
    }

    return $setting;
}

function invoiceSettingsLogoGallery(string $dataTest): string
{
    return 'dialog[open] [data-test="'.$dataTest.'"]';
}

/** JS that reads a public property of the invoice settings Livewire component. */
function invoiceSettingsWireValueJs(string $property): string
{
    return "() => { const c = window.Livewire.all().find((component) => component.\$wire.\$get('{$property}') !== undefined); return c ? String(c.\$wire.\$get('{$property}')) : 'component-not-found'; }";
}

// =====================================================================
// B-1 -- Scenario: A store manager saves the issuer's fiscal data / chooses the Modern template
// =====================================================================
test('a manager fills the fiscal data, picks Modern, ticks both boxes, saves and sees every value after a reload', function () {
    $this->actingAs(invoiceSettingsManager());

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->fill('issuerLegalName', 'Acme SL')
        ->fill('issuerTaxId', ' es b123 ')
        ->fill('issuerAddressLine1', 'Calle Mayor 1')
        ->fill('issuerCity', 'Madrid')
        ->fill('issuerPostalCode', '28001')
        ->fill('issuerCountry', 'es')
        ->click('@template-option-modern')
        ->check('isNumberingResetYearly')
        ->check('isAutoIssueEnabled')
        ->click('@invoice-settings-save')
        ->assertNoJavaScriptErrors();

    $saved = waitForInvoiceSettings(fn (InvoiceSetting $setting): bool => $setting->exists);

    expect($saved->issuer_legal_name)->toBe('Acme SL')
        ->and($saved->issuer_tax_id)->toBe('ESB123')
        ->and($saved->issuer_country)->toBe('ES')
        ->and($saved->template->value)->toBe('modern')
        ->and($saved->auto_issue_on_order)->toBeTrue()
        ->and($saved->reset_numbering_yearly)->toBeTrue();

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertValue('issuerLegalName', 'Acme SL')
        ->assertValue('issuerTaxId', 'ESB123')
        ->assertValue('issuerAddressLine1', 'Calle Mayor 1')
        ->assertValue('issuerCity', 'Madrid')
        ->assertValue('issuerPostalCode', '28001')
        ->assertValue('issuerCountry', 'ES')
        ->assertChecked('isNumberingResetYearly')
        ->assertChecked('isAutoIssueEnabled')
        ->assertScript(invoiceSettingsWireValueJs('template'), 'modern');
});

// =====================================================================
// B-2 -- Scenario: The next numbers follow the format being edited
// =====================================================================
test('typing a numbering format updates the next standard number live and saves nothing', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $this->actingAs(invoiceSettingsManager());

    $page = visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@next-number-standard', 'F2026-00001')
        ->assertSeeIn('@next-number-corrective', 'R2026-00001')
        ->fill('standardPrefix', 'INV')
        ->select('standardYearFormat', 'YY')
        ->select('standardPadding', '3')
        // Compensates for the debounced Livewire round trip behind the preview line.
        ->wait(1)
        ->assertSeeIn('@next-number-standard', 'INV26-001')
        ->assertSeeIn('@next-number-corrective', 'R2026-00001')
        ->assertNoJavaScriptErrors();

    expect(InvoiceSetting::query()->count())->toBe(0);

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('@next-number-standard', 'F2026-00001');
});

// =====================================================================
// B-3 -- Scenario Outline: A refused numbering format is explained on its own field
// =====================================================================
test('a bad prefix and a corrective year of none with yearly reset on are each explained and listed in the callout', function () {
    $this->actingAs(invoiceSettingsManager());

    $page = visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->fill('standardPrefix', 'F;')
        ->select('correctiveYearFormat', 'none')
        ->click('@invoice-settings-save')
        ->wait(1)
        ->assertVisible('@numbering-refused')
        ->assertNoJavaScriptErrors();

    // The first error is scrolled into view: it sits inside the viewport after the refusal.
    $page->assertScript("() => { const e = document.querySelector('[data-flux-error]'); if (! e) { return false; } const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; }", true);

    expect(InvoiceSetting::query()->count())->toBe(0);
});

// =====================================================================
// B-4 -- Scenario: A store manager picks a logo from the media library
// =====================================================================
test('a manager picks a logo through the media gallery, saves it, and a second journey removes it', function () {
    $media = Media::factory()->withRealFiles()->create(['title' => 'Company logo']);
    $this->actingAs(invoiceSettingsManager());

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertMissing('@logo-preview')
        ->click('@choose-logo')
        ->click(invoiceSettingsLogoGallery('media-tile-'.$media->id))
        ->click(invoiceSettingsLogoGallery('media-confirm'))
        ->wait(1)
        ->assertVisible('@logo-preview')
        ->assertNoJavaScriptErrors()
        ->click('@invoice-settings-save')
        ->assertNoJavaScriptErrors();

    $saved = waitForInvoiceSettings(fn (InvoiceSetting $setting): bool => $setting->logo_media_id === $media->id);

    expect($saved->logo_media_id)->toBe($media->id);

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertVisible('@logo-preview')
        ->click('@remove-logo')
        ->assertMissing('@logo-preview')
        ->click('@invoice-settings-save')
        ->assertNoJavaScriptErrors();

    $cleared = waitForInvoiceSettings(fn (InvoiceSetting $setting): bool => $setting->logo_media_id === null);

    expect($cleared->logo_media_id)->toBeNull();

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertMissing('@logo-preview');
});

// =====================================================================
// B-5 -- Scenario Outline: A malformed colour is refused next to its field
// =====================================================================
test('the colour swatch writes the upper-cased value, a typed malformed colour errors on blur and the default button clears it', function () {
    $this->actingAs(invoiceSettingsManager());

    $page = visit(route('invoice-settings.index'))->assertNoJavaScriptErrors();

    // The native colour input cannot be driven by Playwright's fill(): set its value and fire `input`
    // exactly as the browser's picker does.
    $page->script("() => { const swatch = document.querySelector('[data-test=\"primary-color-swatch\"]'); const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; setter.call(swatch, '#a1b2c3'); swatch.dispatchEvent(new Event('input', { bubbles: true })); swatch.dispatchEvent(new Event('change', { bubbles: true })); return true; }");

    $page->wait(1)
        ->assertValue('@primary-color-input', '#A1B2C3')
        ->assertNoJavaScriptErrors()
        ->fill('@primary-color-input', 'red')
        ->click('@accent-color-input')
        ->wait(2)
        ->assertSee('Primary colour')
        ->assertAttribute('@primary-color-input', 'aria-invalid', 'true')
        ->assertNoJavaScriptErrors()
        ->click('@primary-color-default')
        ->wait(1)
        ->assertValue('@primary-color-input', '')
        ->assertAttributeMissing('@primary-color-input', 'aria-invalid')
        ->assertNoJavaScriptErrors();
});

// =====================================================================
// B-6 -- Scenario: A store manager chooses the Modern template (cards, thumbnails, dark mode)
// =====================================================================
test('clicking the other template card selects it, the thumbnails follow the colours and stay white in dark mode', function () {
    InvoiceSetting::factory()->create(['template' => 'classic', 'primary_color' => '#112233']);
    $this->actingAs(invoiceSettingsManager());

    $page = visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertScript(invoiceSettingsWireValueJs('template'), 'classic')
        ->assertPresent('@template-thumbnail-classic')
        ->assertPresent('@template-thumbnail-modern')
        ->click('@template-option-modern')
        ->wait(1)
        ->assertScript(invoiceSettingsWireValueJs('template'), 'modern')
        ->assertNoJavaScriptErrors();

    // The thumbnail takes the typed colour (rgb(170, 187, 204) = #AABBCC) once the field is committed.
    $page->fill('@primary-color-input', '#aabbcc')
        ->click('@accent-color-input')
        ->wait(2)
        ->assertScript("() => document.querySelector('[data-test=\"template-thumbnail-modern\"]').outerHTML.toLowerCase().includes('#aabbcc') || document.querySelector('[data-test=\"template-thumbnail-modern\"]').outerHTML.toLowerCase().includes('rgb(170, 187, 204)')", true);

    $page->script("document.documentElement.classList.add('dark')");

    $page->assertScript("() => getComputedStyle(document.querySelector('[data-test=\"template-thumbnail-modern\"]')).backgroundColor", 'rgb(255, 255, 255)')
        ->assertNoJavaScriptErrors();
});

// =====================================================================
// Scenario: A store manager previews a template as a PDF (the link, not the PDF bytes: story T-26 owns those)
// =====================================================================
test('each template card links to its own PDF preview in a new tab', function () {
    $this->actingAs(invoiceSettingsManager());

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertAttribute('@template-preview-classic', 'href', route('invoice-settings.preview', ['template' => 'classic']))
        ->assertAttribute('@template-preview-classic', 'target', '_blank')
        ->assertAttribute('@template-preview-modern', 'href', route('invoice-settings.preview', ['template' => 'modern']))
        ->assertAttribute('@template-preview-modern', 'target', '_blank')
        ->assertNoJavaScriptErrors();
});

// =====================================================================
// B-7 -- Scenario: A viewer sees the settings but cannot change them
// =====================================================================
test('a view-only user sees the stored values and the notice but cannot change inputs, cards or checkboxes', function () {
    InvoiceSetting::factory()->withIssuer()->create(['issuer_legal_name' => 'Stored Legal Name SL', 'template' => 'classic']);
    $this->actingAs(invoiceSettingsViewer());

    $page = visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertVisible('@invoice-settings-readonly-notice')
        ->assertValue('issuerLegalName', 'Stored Legal Name SL')
        ->assertDisabled('issuerLegalName')
        ->assertDisabled('standardPrefix')
        ->assertMissing('@invoice-settings-save')
        ->assertMissing('@choose-logo')
        ->assertMissing('@remove-logo')
        ->assertMissing('@set-start-standard');

    // A scripted click on the card and the checkboxes must not move the form state: Flux custom
    // elements have to honour the disabled fieldset.
    $page->script("() => { document.querySelector('[data-test=\"template-option-modern\"]').click(); document.querySelectorAll('input[type=\"checkbox\"]').forEach((box) => box.click()); return true; }");

    $page->wait(1)
        ->assertScript(invoiceSettingsWireValueJs('template'), 'classic')
        ->assertScript(invoiceSettingsWireValueJs('isAutoIssueEnabled'), 'false')
        ->assertNoJavaScriptErrors();
});

// =====================================================================
// B-8 -- Scenario: A store manager sets the starting number of a fresh series
// =====================================================================
test('the start modal sets 1500 on a fresh standard series and the next number follows', function () {
    $this->actingAs(invoiceSettingsManager());

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->click('@set-start-standard')
        ->assertVisible('@start-number-modal')
        ->fill('@start-number-input', '1500')
        ->click('@start-number-confirm')
        ->wait(1)
        ->assertMissing('@start-number-modal')
        ->assertSeeIn('@next-number-standard', '01500')
        ->assertNoJavaScriptErrors();

    $sequence = InvoiceSequence::query()->where('series', InvoiceType::Standard->value)->sole();

    expect($sequence->last_number)->toBe(1499);
});

test('a series that has already issued offers no starting number control and says numbering has started', function () {
    Numbering::arrange(InvoiceType::Standard, 7, 2026);
    $this->actingAs(invoiceSettingsManager());

    visit(route('invoice-settings.index'))
        ->assertNoJavaScriptErrors()
        ->assertMissing('@set-start-standard')
        ->assertVisible('@start-locked-standard')
        ->assertVisible('@set-start-corrective')
        ->assertNoJavaScriptErrors();
});

// =====================================================================
// B-9 -- Phone viewport
// =====================================================================
test('on a phone the screen is one column without horizontal scroll and the sticky save bar never covers the last field', function () {
    $this->actingAs(invoiceSettingsManager());

    $page = visit(route('invoice-settings.index'))->resize(375, 812)->assertNoJavaScriptErrors();

    $page->assertScript('() => document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertVisible('@invoice-settings-save');

    $page->script('() => { window.scrollTo(0, document.documentElement.scrollHeight); return true; }');

    $page->wait(1)
        ->assertScript("() => { const last = document.querySelector('input[type=\"checkbox\"]:last-of-type, [name=\"isAutoIssueEnabled\"]'); const save = document.querySelector('[data-test=\"invoice-settings-save\"]'); if (! last || ! save) { return false; } const bar = save.closest('.sticky') || save; return last.getBoundingClientRect().bottom <= bar.getBoundingClientRect().top + 1; }", true)
        ->assertNoJavaScriptErrors();
});
