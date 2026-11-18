# [0103] Invoice settings screen (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](done/0100-invoice-settings-backend.md) (settings, rules, policy, save action),
> [0101a](0101a-invoice-numbering-sequences-backend.md) (preview, starting counter) and
> [0102](0102-invoice-pdf-rendering-and-templates-backend.md) (templates, renderer). Uses the
> refusal added by [0101b](0101b-issue-standard-invoice-backend.md) once merged. No dependents.

## Description
A store-settings screen where a manager edits every invoice setting of 0100 in one form: the
issuer's fiscal data, the logo (Media Gallery picker), two colours, the template, both
numbering formats, the yearly-reset flag and the auto-issue flag. It shows the next standard and
corrective numbers live for the format being typed, lets the manager set a starting number for a
series that has not issued yet, and opens a sample PDF of each template with the saved settings.
A viewer without `invoice-settings.manage` sees the same screen read-only. This story also owns
two read-side backend classes the screen alone uses (`ReadInvoiceSequenceState`,
`PreviewInvoicePdf`) and the preview route.

Request item served (owner, 2026-11-14, translated):
> 1. From store settings, a store manager with permission configures the invoice template: store
> logo, colours, and which template to use (at least two templates must exist), how invoices are
> numbered, and a checkbox so the numbering restarts every 1 January.

Binding owner decisions:
- U-3 (owner decision, 2026-11-14): a store setting "issue and send the invoice automatically
  when an order is created", off by default.
- U-5 (owner decision, 2026-11-14): invoice settings hold the issuer's legal name, tax ID and
  fiscal address.
- U-1 (owner decision, 2026-11-14): PDFs use `spatie/laravel-pdf` (Chromium via Browsershot).
- Q-0100-2 (accepted default, 2026-11-15): the starting counter can be set only before a series
  has issued anything.

## Type
frontend (related_task_id: 0100, 0101a, 0102) | includes database-expert: no

## Verified facts that shaped the story (2026-11-15)
- Store-settings screens follow `routes/store-languages.php`: an aliased `Index` import,
  `Route::livewire(...)->middleware(['can:<x>.view'])` inside the `auth` + `verified` group,
  required from `routes/web.php`. `can:` (not Spatie's `permission:`) because Livewire's
  persistent middleware replays only `Authorize`.
- `config/modules.php` cluster `store_settings` ("Store settings" / "Configuración de tienda")
  holds sales regions, shipping zones, carriers, payment methods, store languages; each item's
  `permissions` equals its route's `can:` ability. Labels live in `lang/{en,es}/navigation.php`
  `items.*`.
- A component named `Index` resolves to its parent folder's view name:
  `resources/views/livewire/invoice-settings.blade.php`.
- The routeless Media Gallery (`App\Livewire\Media\Gallery`) is embedded with `wire:model` on a
  bool, `:multi`, and a `select-event` name; it dispatches a page-global event with the chosen
  media payload (`Products\Editor::setFeaturedImage()` is the consumer precedent).
- Flux free ships `flux:radio.group variant="cards"`, `flux:callout`, `flux:modal`,
  `Flux::toast()`; `App\Concerns\ChecksAbilitiesSafely` exists.

## Backend contracts used
From 0100:
- `InvoiceSetting::current(): self` (row or unsaved defaults: `F`/`YYYY`/`-`/5, `R`/`YYYY`/`-`/5,
  reset on, auto-issue off, `classic`, no logo, null colours, null issuer),
  `hasCompleteIssuerData(): bool`.
- `SaveInvoiceSettings::__invoke(array $attributes): InvoiceSetting` — logged `update`
  authorization first; **the payload must carry every key** (missing key = error on that key);
  normalises and validates; cross-field rules on `standard_year_format` / `corrective_year_format`
  (reset on needs a year), `corrective_prefix` (prefixes differ case-insensitively),
  `auto_issue_on_order` (needs legal name, tax ID, address line 1 in the resulting state).
- `InvoiceSettingValidationRules` (protected, composed by the component):
  `invoiceSettingRules(): array` keyed by field, `normalizeInvoiceSettingAttributes(array):
  array` (trim, blank -> null, colours and country upper-cased, tax ID via `normalizeTaxId()`).
- `InvoiceSettingPolicy`: `viewAny` = `invoice-settings.view`, `update` = `invoice-settings.manage`.
- Enums `InvoiceTemplate` (`Classic`, `Modern`), `InvoiceNumberYear` (`None='none'`,
  `Full='YYYY'`, `Short='YY'`), `InvoiceType` (`Standard`, `Corrective`).
- Keys: issuer `issuer_legal_name`, `issuer_tax_id`, `issuer_address_line1`,
  `issuer_address_line2`, `issuer_city`, `issuer_postal_code`, `issuer_province`,
  `issuer_country`; look `logo_media_id`, `primary_color`, `accent_color`, `template`; per series
  `<series>_prefix`, `<series>_year_format`, `<series>_separator`, `<series>_padding`; flags
  `reset_numbering_yearly`, `auto_issue_on_order`.

From 0101a:
- `PeekNextInvoiceNumber::__invoke(InvoiceType $series, ?InvoiceSetting $settings = null,
  ?CarbonInterface $at = null): string` — logged `viewAny`; no lock, no write. A candidate built
  with `new InvoiceSetting` + `forceFill()` of **only** the numbering subset is enough: the
  action overlays the series' four numbering fields and `reset_numbering_yearly` on the saved
  settings and validates only those keys; invalid -> `ValidationException` keyed by the field.
- `SetInvoiceSequenceStart::__invoke(InvoiceType $series, int $startAt): void` — logged `update`
  before any read; `1 <= startAt <= 4294967294` (`invoice-settings.numbering.start_invalid`,
  key `start_at`); refused once the series has issued (`numbering.start_already_issued`, key
  `start_at`); sets `last_number = startAt - 1`; can be changed again until the first issue.

From 0101b: `AssertNumberingFormatIsFree` runs inside the save and throws a `ValidationException`
keyed `<series>_prefix` with `invoice-settings.numbering.next_number_exists`.

From 0102: `InvoiceTemplate::view(): string`; `RenderInvoicePdf::__invoke(Invoice $invoice,
bool $isCopy): PdfBuilder`; `BuildInvoicePdfData` and `RenderInvoicePdf` work on an unsaved
`Invoice` whose `lines` relation is set in memory; the named throttle of `invoices.download`
(30 per minute per user).

## Backend classes owned by this story (pure reads)
- `App\Actions\Invoices\ReadInvoiceSequenceState::__invoke(): array{standard: array{hasIssued:
  bool, lastNumber: int, lastYear: ?int, lastIssuedAt: ?CarbonImmutable}, corrective: array{hasIssued:
  bool, lastNumber: int, lastYear: ?int, lastIssuedAt: ?CarbonImmutable}}` — logged
  `authorize('viewAny', InvoiceSetting::class)`, one query on `invoice_sequences`,
  `hasIssued = last_issued_at !== null`. The component never reads the table itself.
- `App\Actions\Invoices\PreviewInvoicePdf::__invoke(InvoiceTemplate $template): PdfBuilder` —
  logged `viewAny`; builds an **unsaved** `Invoice` (issuer, colours, logo and tax ID from the
  saved settings, the given template; number from `PeekNextInvoiceNumber(InvoiceType::Standard)`;
  recipient "Sample customer"; two in-memory lines "Sample item"; no DB write) and returns
  `RenderInvoicePdf($invoice, false)`.
- `App\Http\Controllers\InvoiceSettingsPreviewController::__invoke(InvoiceTemplate $template,
  PreviewInvoicePdf $previewInvoicePdf)` — thin; implicit enum binding (unknown template -> 404);
  inline response named `invoice-preview-<template>.pdf`.
- Route `GET invoice-settings/preview/{template}` named `invoice-settings.preview`, middleware
  `auth`, `verified` and the `invoices.download` named throttle, **no** `can:` (the action's
  logged refusal returns 403, a `can:` refusal would not be logged).

## Component: `App\Livewire\InvoiceSettings\Index`
`#[Title('Invoice settings')]`; composes `InvoiceSettingValidationRules`, `ChecksAbilitiesSafely`.

State (plain types; `''` stands for null):
- strings: `issuerLegalName`, `issuerTaxId`, `issuerAddressLine1`, `issuerAddressLine2`,
  `issuerCity`, `issuerPostalCode`, `issuerProvince`, `issuerCountry`, `primaryColor`,
  `accentColor`, `template`, `standardPrefix`, `standardYearFormat`, `standardSeparator`,
  `correctivePrefix`, `correctiveYearFormat`, `correctiveSeparator`;
- ints: `standardPadding`, `correctivePadding`;
- bools: `isNumberingResetYearly` (`reset_numbering_yearly`), `isAutoIssueEnabled`
  (`auto_issue_on_order`), `showLogoGallery`, `showStartModal`;
- `#[Locked] public ?string $logoMediaId`, `#[Locked] public ?string $startSeries`
  (an `InvoiceType` value), `public string $startAt = ''`.

Methods:
- `mount()`: `Gate::authorize('viewAny', InvoiceSetting::class)`, then
  `fillFromSettings(InvoiceSetting::current())`.
- `save(SaveInvoiceSettings $saveInvoiceSettings, LogRefusedPrivilegedAttempt $log)`: logged
  `update` first; `$saveInvoiceSettings($this->formAttributes())` inside a catch that rethrows the
  `ValidationException` re-keyed to properties (FD-8); then refill from `InvoiceSetting::current()`
  (shows the normalised stored form), `unset` the computeds, `Flux::toast(variant: 'success', ...)`.
- `formAttributes(): array` — **every** key listed above (FD-3), `logo_media_id` from the locked
  property.
- `#[On('invoice-logo-selected')] setLogo(array $media)`: authorize `update` on `InvoiceSetting`,
  then `viewAny` on `Media`; require `$media[0]['id']` to be a string and
  `Media::query()->find()` to find it, else silent no-op; set `$logoMediaId`. `removeLogo()`:
  authorize `update`, set null. Neither persists: Save does (FD-3).
- `updatedPrimaryColor()` / `updatedAccentColor()`: normalise through
  `normalizeInvoiceSettingAttributes()` and validate only that key's rule; the error goes on the
  property, cleared when valid. Enforcement stays in the save.
- `openStartModal(string $series)`: authorize `update`; `InvoiceType::tryFrom($series)` or no-op;
  set `$startSeries`, clear `$startAt`, open. `setStart(SetInvoiceSequenceStart $setStart)`:
  authorize `update`; validate `startAt` as `required|integer` (error on `startAt`); call the
  action with the locked series and `(int) $this->startAt`; re-key `start_at` -> `startAt`; on
  success close, `unset` the computeds, toast.
- Computeds: `canManage` (`allowsSafely('update', InvoiceSetting::class)`), `canChooseLogo`
  (`canManage` and `allowsSafely('viewAny', Media::class)`), `logo` (array|null from
  `Media::find($this->logoMediaId)`: title, `webpUrl`, `url`; never from an event payload),
  `templateOptions` (`InvoiceTemplate::cases()` -> value, translated name and description),
  `safePrimaryColor` / `safeAccentColor` (the value only when it matches `^#[0-9A-Fa-f]{6}$`,
  else null — the only colour ever written into a `style` attribute), `nextNumbers` (FD-5),
  `sequenceState` (`ReadInvoiceSequenceState`).

## View and hooks
One `<form wire:submit="save">`, cards in one column on phones, `sm:grid-cols-2` inside a card:
- **Issuer fiscal data:** legal name, tax ID (hint: letters, digits and `- . /`; spaces
  removed), address lines, city, postal code, province, country (`maxlength="2"`, as on the
  customer form). `wire:model.blur`.
- **Look:** logo preview (`data-test="logo-preview"`, `webpUrl` with `url` fallback, alt = media
  title) or the empty text; "Choose logo" (`data-test="choose-logo"`) and "Remove logo"
  (`data-test="remove-logo"`) shown when `canChooseLogo`;
  `<livewire:media.gallery wire:model="showLogoGallery" wire:key="invoice-logo-gallery" :multi="false" select-event="invoice-logo-selected" />`
  rendered only when `canChooseLogo`. Each colour: a mono text input (`placeholder="#1A2B3C"`,
  `wire:model.blur`, `data-test="primary-color-input"` / `accent-color-input`) plus a native
  `<input type="color">` swatch (constant `x-data`, writes the upper-cased value with
  `$wire.set`), and a "Use template default" ghost button that sets `''`. Template:
  `<flux:radio.group wire:model.live="template" variant="cards">` over `templateOptions`; each
  card (`data-test="template-option-{value}"`) holds
  `<x-invoice-template-thumbnail :template :primary :accent :logo />`
  (`data-test="template-thumbnail-{value}"`) and a "Preview PDF" link
  (`data-test="template-preview-{value}"`, `target="_blank"`, `rel="noopener"`, route
  `invoice-settings.preview`), labelled "with the saved settings" (FD-6).
- **Numbering:** two series blocks (Standard / Corrective), each with prefix input, year
  `flux:select` over `InvoiceNumberYear` cases, separator input, padding select 1..10, the
  next-number line (`data-test="next-number-standard"` / `next-number-corrective`, `<code>`,
  `font-mono`, `{{ }}`), and the starting-number line (FD-4): "Set starting number"
  (`data-test="set-start-standard"` / `set-start-corrective`) or the "already started" text
  (`data-test="start-locked-standard"` / `start-locked-corrective`). Below: the yearly-reset
  checkbox. A danger callout `data-test="numbering-refused"` lists the numbering errors after a
  refused save.
- **Automation:** auto-issue checkbox with its hint (needs legal name, tax ID, address line 1).
- **Save bar:** `sticky bottom-0` on small screens with dark variants; Save button
  `data-test="invoice-settings-save"` (`wire:loading.attr="disabled"`, `wire:target="save"`),
  rendered only when `canManage`.
- **Read-only:** every section inside `<fieldset @disabled(! $this->canManage)>`;
  `flux:callout` `data-test="invoice-settings-readonly-notice"`; no save bar, no logo, colour
  default or start buttons, no gallery.
- **Start modal:** `flux:modal` `data-test="start-number-modal"` with an integer input
  (`wire:model="startAt"`, `data-test="start-number-input"`), the warning (FD-4) and a confirm
  button `data-test="start-number-confirm"`; errors under the input.
- After a refused save, `$this->dispatch('focus-first-error')`; a small Alpine listener scrolls
  to the first `[data-flux-error]`. No error message echoes the typed value.
- New anonymous component `resources/views/components/invoice-template-thumbnail.blade.php`: a
  fixed A4-ratio white mini page drawn with divs (header band, number, lines, totals), Classic
  and Modern laid out like their PDFs; colours only from `safe*Color` (else the template
  defaults), logo only from the server-derived `logo` url; stays white in dark mode inside a
  `border-zinc-200 dark:border-zinc-700` frame.

Sidebar item `invoice_settings` in cluster `store_settings`: icon `document-text`, route
`invoice-settings.index`, `current_when` `invoice-settings.*`, `permissions`
`['invoice-settings.view']`.

## Lang keys
`lang/{en,es}/navigation.php`: `items.invoice_settings` = "Invoice settings" / "Ajustes de
facturas". `lang/{en,es}/invoice-settings.php` (file created by 0100; this story adds the screen
group `screen.*`, key parity), main entries:

| Key | en | es |
| --- | --- | --- |
| `screen.title` | Invoice settings | Ajustes de facturas |
| `screen.sections.issuer` | Issuer fiscal data | Datos fiscales del emisor |
| `screen.sections.look` | Logo, colours and template | Logo, colores y plantilla |
| `screen.sections.numbering` | Numbering | Numeración |
| `screen.sections.automation` | Automation | Automatización |
| `screen.series.standard` / `.corrective` | Invoices / Corrective invoices | Facturas / Facturas rectificativas |
| `screen.next_number` | Next number | Próximo número |
| `screen.next_number_invalid` | Fix the format to see the next number | Corrige el formato para ver el próximo número |
| `screen.reset_yearly` | Restart numbering every 1 January | Reiniciar la numeración cada 1 de enero |
| `screen.auto_issue` | Issue and send the invoice automatically when an order is created | Emitir y enviar la factura automáticamente al crear un pedido |
| `screen.auto_issue_hint` | Needs the legal name, tax ID and first address line. | Requiere la razón social, el identificador fiscal y la primera línea de dirección. |
| `screen.no_logo` | Invoices are printed without a logo | Las facturas se imprimen sin logo |
| `screen.use_template_default` | Use template default | Usar el color de la plantilla |
| `screen.preview_pdf` | Preview PDF with the saved settings | Ver PDF de ejemplo con los ajustes guardados |
| `screen.start.button` | Set starting number | Fijar número inicial |
| `screen.start.locked` | Numbering has started; the starting number can no longer be changed. | La numeración ya ha empezado; el número inicial ya no se puede cambiar. |
| `screen.start.warning` | Use this to continue numbering from another system. Numbers already used elsewhere cannot be detected. | Úsalo para continuar la numeración de otro sistema. No se pueden detectar números ya usados fuera de la tienda. |
| `screen.readonly_notice` | You can view these settings but not change them. | Puedes ver estos ajustes, pero no cambiarlos. |
| `screen.saved` | Invoice settings saved. | Ajustes de facturas guardados. |
| `screen.start_saved` | Starting number set. | Número inicial fijado. |

Field labels and hints reuse 0100's `attributes.*` leaves; template names and descriptions are
`screen.templates.<value>.name|description` (Classic / Clásica, Modern / Moderna). The sample
PDF's "Sample customer" / "Sample item" strings are `invoices.pdf.sample.*` in the invoice
locale (0102's file), en and es.

## Decisions
- **FD-1 Sidebar label "Invoice settings" / "Ajustes de facturas"** (QA gap G-2; deviates from
  the word "Invoices" in the functional rule). Reason: the Billing entry of
  [0110](0110-billing-section-ui.md) ("Billing" / "Facturación") is the invoice list; a settings
  item called "Invoices" would read as a second list. The page title matches.
- **FD-2 One form, whole-record save, one button.** 0100 FD-5 replaces the whole record; per-
  section saves would need a merge the action does not have.
- **FD-3 Every key always sent; logo saved only by Save.** The picker and "Remove logo" change
  the form state only, so one Save writes one reviewable state.
- **FD-4 The starting-number control is in scope** (gap Q-1 / G-3, facilitator decision).
  Reason: request item 1 asks how invoices are numbered, 0101a ships `SetInvoiceSequenceStart`
  for this screen and Q-0100-2 (a) was accepted; without the control a store moving from another
  system cannot continue its numbering. Shape: one button per series, shown to managers only
  while `sequenceState[<series>].hasIssued` is false, opening a modal; it calls the action
  directly (independent of the form, needs no Save) and refreshes the next-number line; once the
  series has issued, the line states the start is locked. The modal states that numbers used
  outside the store cannot be detected (0101a risk).
- **FD-5 Live next-number preview over the numbering subset plus the reset flag** (gaps G-1 and
  G-4). The candidate is `new InvoiceSetting` + `forceFill()` of the eight numbering fields of
  both series and `reset_numbering_yearly`, passed to `PeekNextInvoiceNumber` for each series;
  backend confirmed the subset suffices (other fields come from the saved settings). The eight
  inputs use `wire:model.live.debounce.400ms`, and the reset checkbox `wire:model.live`, because
  the flag changes the next number at a year change and makes year `none` invalid; leaving it out
  would show a stale number. A `ValidationException` from the peek is caught in the computed: the
  line shows `screen.next_number_invalid`, nothing goes into the error bag (field errors belong
  to Save). Every other field stays deferred.
- **FD-6 Template preview: CSS thumbnails for choosing, plus a sample PDF of the saved settings**
  (Q-0103-1, option (a) built, since `PreviewInvoicePdf` now exists). The thumbnail is instant,
  needs no Chromium and follows unsaved colours; the PDF is the true rendering, but of the
  **saved** settings only (unsaved preview, option (c), would put form values into the renderer
  and spend a Chromium start per change). The link says "with the saved settings" so a manager
  with unsaved edits is not misled.
- **FD-7 Server-derived values only in `style` and `img`.** Colours via the regex-checked
  `safe*Color`, the logo via `Media::find()` on the locked id; never the client string or the
  event payload.
- **FD-8 Error re-keying with an explicit rule.** Save errors are rethrown with each key mapped to
  its property: `Str::camel()` (`issuer_tax_id` -> `issuerTaxId`, `corrective_prefix` ->
  `correctivePrefix`, `logo_media_id` -> `logoMediaId`) except `reset_numbering_yearly` ->
  `isNumberingResetYearly` and `auto_issue_on_order` -> `isAutoIssueEnabled`; `start_at` ->
  `startAt` in the modal. A private method in this component (the predicate-named booleans make a
  shared `Str::camel` concern insufficient; [0100b](in-progress/0100b-customer-tax-id-field-ui.md) keeps its
  own).
- **FD-9 Read-only is a disabled fieldset and no write controls, never hidden values.** A viewer
  may read what is stored, including the preview lines and the sample PDF (both `viewAny`). The
  disabled state is not security: `save()`, `setLogo()`, `removeLogo()`, `openStartModal()` and
  `setStart()` each authorize `update` themselves, the save refusal logged by the action.
- **FD-10 Colour feedback on blur** with the same normaliser and rule as the save, so the user
  sees the format error before Save and the stored value equals what the live check accepted.
- **FD-11 Last write wins.** Two managers saving the same form overwrite each other silently;
  accepted (single store, rare), recorded, not built.

## Gherkin
```gherkin
Feature: Invoice settings screen

  Scenario: A store manager saves the issuer's fiscal data
    Given a store manager with permission to manage invoice settings
    When she saves the issuer legal name "Acme SL", the tax ID " es b123 " and a full fiscal address
    Then the screen shows the tax ID "ESB123"
    And the settings keep the saved fiscal data after she reloads the page

  Scenario: A viewer sees the settings but cannot change them
    Given a sales assistant with permission to view but not manage invoice settings
    When she opens the invoice settings screen
    Then she sees the stored values in read-only form
    And there is no save button

  Scenario: A viewer cannot save even by forging the request
    Given a sales assistant with permission to view but not manage invoice settings
    When she forges a save with a new legal name
    Then the save is refused and the attempt is logged
    And the stored legal name stays as it was

  Scenario: A user without permission cannot open the screen
    Given an administrator without permission to view invoice settings
    When she opens the invoice settings address
    Then access is denied
    And her sidebar has no invoice settings entry

  Scenario: The next numbers follow the format being edited
    Given a store manager editing the numbering on 1 March 2026, with nothing issued yet
    When she changes the standard format to prefix "INV", year "YY" and 3 digits
    Then the next standard number shows "INV26-001"
    And the next corrective number still shows "R2026-00001"
    And nothing is saved

  Scenario: An invalid format being typed hides the next number
    Given a store manager editing the numbering
    When she types the standard prefix "F;"
    Then the next standard number line asks her to fix the format

  Scenario Outline: A refused numbering format is explained on its own field
    Given a store manager editing the numbering with yearly reset on
    When she saves with <change>
    Then the error appears under the <field>
    And the settings are not saved

    Examples:
      | change                         | field                  |
      | the corrective year "none"     | corrective year format |
      | the same prefix on both series | corrective prefix      |
      | the standard prefix "F;"       | standard prefix        |

  Scenario: Automatic issuing needs complete fiscal data
    Given a store manager whose issuer data has no tax ID
    When she switches on automatic issuing and saves
    Then the error appears under the automatic issuing checkbox

  Scenario: A store manager picks a logo from the media library
    Given a store manager who may also view the media library
    When she chooses an image as the invoice logo and saves
    Then the logo preview shows that image after she reloads the page

  Scenario: A store manager chooses the Modern template
    Given a store manager with permission to manage invoice settings
    When she chooses the "Modern" template and saves
    Then the Modern template is selected after she reloads the page

  Scenario: A store manager previews a template as a PDF
    Given a store manager with saved invoice settings
    When she opens the PDF preview of the Classic template
    Then she receives a sample invoice in the Classic layout with her saved issuer data and colours

  Scenario Outline: A malformed colour is refused next to its field
    Given a store manager with permission to manage invoice settings
    When she enters the primary colour "<value>"
    Then the error appears under the primary colour

    Examples:
      | value   |
      | red     |
      | #12345  |
      | #GGGGGG |

  Scenario: A store manager sets the starting number of a fresh series
    Given a store manager whose store has never issued a standard invoice
    When she sets the standard starting number to 1500
    Then the next standard number ends in "01500"

  Scenario: The starting number is locked once a series has issued
    Given a store manager whose store has issued a standard invoice
    When she opens the invoice settings screen
    Then the standard series offers no starting number control
    And it states that numbering has started
```

## Files to create/modify
- `routes/invoice-settings.php` (new; `invoice-settings.index` with `can:invoice-settings.view`,
  `invoice-settings.preview`); `routes/web.php` — `require`.
- `app/Livewire/InvoiceSettings/Index.php`, `resources/views/livewire/invoice-settings.blade.php`,
  `resources/views/components/invoice-template-thumbnail.blade.php` (new).
- `app/Actions/Invoices/ReadInvoiceSequenceState.php`, `app/Actions/Invoices/PreviewInvoicePdf.php`,
  `app/Http/Controllers/InvoiceSettingsPreviewController.php` (new).
- `config/modules.php` — the `invoice_settings` item.
- `lang/{en,es}/navigation.php`, `lang/{en,es}/invoice-settings.php` (`screen.*`),
  `lang/{en,es}/invoices.php` (`pdf.sample.*`, 0102's file).
- Tests: new `tests/Feature/InvoiceSettings/{IndexTest, IndexAuthorizationTest,
  IndexNumberingPreviewTest, IndexStartNumberTest, PreviewInvoicePdfTest,
  ReadInvoiceSequenceStateTest}.php`, `tests/Browser/InvoiceSettings/InvoiceSettingsScreenTest.php`;
  extend the sidebar/module config and route-gate parity tests that enumerate items.

## Tests to perform
Freeze time (`Carbon::setTestNow('2026-03-01 10:00')`); real `RolePermissionSeeder`,
`Log::spy()`, `Pdf::fake()` for every PDF case; real actions.

Component level (`Livewire::test`, HTTP for routes)
- [ ] T-1 No row: every documented default shown (`F`, `YYYY`, `-`, 5, `R`, reset on, auto off,
  `classic`, no logo).
- [ ] T-2 Saved row: every field prefilled, nulls as `''`.
- [ ] T-3 Valid save: the action receives exactly the full key set (`formAttributes()` keys equal
  the 0100 list, incl. `logo_media_id`, both booleans, int paddings); the row is replaced.
- [ ] T-4 `' es b123 '`, `'#a1b2c3'`, `'es'` saved and refilled as `ESB123`, `#A1B2C3`, `ES`;
  cleared colours stored null.
- [ ] T-5 Checkbox dataset (reset x auto) persisted and refilled; auto on with complete data saves.
- [ ] T-6 Dataset over every refusal key (`issuer_tax_id`, `issuer_country`, `primary_color`,
  `accent_color`, `template`, `logo_media_id`, each series' four fields, `auto_issue_on_order`):
  `assertHasErrors` on the mapped property only (FD-8). Red until the re-key exists.
- [ ] T-7 Reset on + year `none` (each series): error on that series' year property; row unchanged.
- [ ] T-8 Prefixes `F` / `f`: error on `correctivePrefix`.
- [ ] T-9 Auto on with an empty tax ID, and auto staying on while blanking the legal name: error on
  `isAutoIssueEnabled`.
- [ ] T-10 Seeded invoice equal to the new format's next number: error on the series prefix, listed
  in `numbering-refused` (skipped with a 0101b reference until 0101b is merged; never deleted).
- [ ] T-11 Defaults render `F2026-00001` / `R2026-00001` in the next-number hooks.
- [ ] T-12 Set prefix `INV`, year `YY`, padding 3: `INV26-001`; corrective unchanged;
  `invoice_settings` and `invoice_sequences` unchanged.
- [ ] T-13 Prefix `<b>` or `F;`: the invalid line, no markup echoed, empty error bag, no 500.
- [ ] T-14 Series at `last_number` 7: `...00008`; with reset on, `last_year` 2025 and reset
  toggled live: the preview follows the flag (FD-5).
- [ ] T-15 View-only actor: fieldset disabled, notice shown, no `invoice-settings-save`, no
  `choose-logo`, `set-start-*`, gallery; stored values and preview lines visible.
- [ ] T-16 View-only `save` with a new name: 403, one logged refusal, row unchanged.
- [ ] T-17 Without `invoice-settings.view`: route 403, `mount` forbidden, sidebar item absent.
- [ ] T-18 `set('logoMediaId', ...)` and `set('startSeries', ...)` refused (`#[Locked]`).
- [ ] T-19 `invoice-logo-selected` with an array id, an unknown id, a view-only actor, an actor
  without `media.view`: no-op or 403, `logoMediaId` unchanged.
- [ ] T-20 Template `bogus`, colour `#fff;}</style><script>`: refused on the field; the rendered
  `style` never contains the value.
- [ ] T-21 `templateOptions` equals `InvoiceTemplate::cases()` (>= 2); one card and one preview
  link per case.
- [ ] T-22 Logo chosen, removed, saved: `logo_media_id` null, `logo-preview` absent.
- [ ] T-23 Colour blur: `red` errors on `primaryColor` before Save; `#a1b2c3` becomes `#A1B2C3`
  with no error, equal to what Save stores.
- [ ] T-24 Start: fresh series, 1500 -> preview `...01500`, toast; `0`, `abc`, `4294967295` errors
  on `startAt`; issued series: no control, locked text, and a direct `openStartModal`/`setStart`
  call is refused with `start_already_issued` on `startAt`; view-only `setStart`: 403, logged.
- [ ] T-25 `ReadInvoiceSequenceState`: both series' shape; `hasIssued` follows `last_issued_at`;
  refused (logged) without `view`; one query.
- [ ] T-26 `invoice-settings.preview/classic` under `Pdf::fake()`: 200, view
  `InvoiceTemplate::Classic->view()`, saved issuer and colours in the data, sample lines, number
  equals the peek; no row written in any table (`DB::listen`).
- [ ] T-27 Preview without `view`: 403, logged, no render; unknown template: 404; guest: login
  redirect; 31st request in a minute: 429.
- [ ] T-28 en/es keys resolve with parity (screen, navigation, sample strings).

Browser level (Pest 4, bounded waits, every case ends with `assertNoJavaScriptErrors`)
- [ ] B-1 Manager fills fiscal data, picks Modern, ticks both boxes, saves: toast; reload shows
  every value.
- [ ] B-2 Typing a prefix updates `next-number-standard` after the debounce (bounded `wait(1)`);
  reload shows the old format (nothing saved).
- [ ] B-3 Bad prefix plus corrective year `none` with reset on: errors under each field, the first
  scrolled into view, `numbering-refused` lists them.
- [ ] B-4 Logo picker: choose, modal closes, preview visible, save, reload; a second journey removes
  it.
- [ ] B-5 Colour swatch writes the upper-cased value; typed `red` errors on blur; "Use template
  default" clears it.
- [ ] B-6 Clicking the other card selects it; the thumbnails follow the current colours; the
  thumbnail stays white in dark mode.
- [ ] B-7 View-only user: inputs, cards and checkboxes cannot be changed, no save button, notice
  visible (proves Flux custom elements honour the disabled fieldset; if not, they get
  `:disabled` explicitly).
- [ ] B-8 Start modal on a fresh series sets 1500 and the preview updates.
- [ ] B-9 Phone viewport: one column; the sticky save bar never covers the last field.

## Expected outcome
A manager configures every invoice setting from one screen in Store settings, sees the next
numbers as she types, sets a starting number before the first invoice, previews each template
as a PDF; a viewer reads the same screen without being able to change it.

## Acceptance criteria
- [ ] The sidebar item "Invoice settings" in Store settings opens `invoice-settings.index`, gated
  by `invoice-settings.view` on route, sidebar and `mount()`.
- [ ] Every 0100 field is editable and saved in one whole-record save; normalised values are shown
  after save.
- [ ] Every backend refusal shows under its own field (FD-8); numbering refusals also listed in
  the numbering callout.
- [ ] The next-number lines follow the numbering fields and the reset flag live, write nothing,
  and never echo invalid input (FD-5).
- [ ] The starting-number control works only before a series has issued (FD-4).
- [ ] Thumbnails follow the unsaved colours; the PDF preview renders the saved settings, writes
  nothing, is logged on refusal and throttled (FD-6).
- [ ] Without `invoice-settings.manage` the screen is read-only and every write method refuses.
- [ ] en/es keys with parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor) — colour/logo injection, the gallery event, the
  preview route
- [ ] Documentation updated (docs-keeper): `docs/api/routes.md` (two routes), the invoices API
  doc (screen, the two read actions), sidebar/modules doc, authorization (preview route without
  `can:`)
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream: [0100](done/0100-invoice-settings-backend.md), [0101a](0101a-invoice-numbering-sequences-backend.md),
  [0102](0102-invoice-pdf-rendering-and-templates-backend.md) (unsaved-invoice render), and
  [0101b](0101b-issue-standard-invoice-backend.md) for T-10 only. Sibling:
  [0110](0110-billing-section-ui.md) owns the "Billing" label FD-1 avoids.
- Risk (INVEST Small): the screen plus two read actions, a route and a modal is the largest
  frontend story of the epic. Split line if Phase 2 asks: 0103 (form, preview lines, thumbnails)
  and a 0103a (starting number + PDF preview), both independent of each other.
- Risk: the gallery event is page-global; only `setLogo()`'s own gates protect it.
- Risk: the sample PDF carries the real issuer data, tax ID and the real next number; printed or
  forwarded, it could pass for a genuine invoice (see Q-0103-3).
- Risk: Flux radio cards and checkboxes are custom elements; B-7 proves they honour the disabled
  fieldset.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0103-1 Template preview: (a) sample PDF with the saved settings (recommended, built, plus
    CSS thumbnails); (b) no preview; (c) preview of unsaved settings.
  - Q-0103-2 Sidebar label: (a) "Invoice settings" / "Ajustes de facturas" (recommended: avoids
    clashing with "Billing" / "Facturación"); (b) "Invoices" / "Facturas" as written in the
    functional rule.
  - Q-0103-3 Mark the sample PDF: (a) a visible "SAMPLE — not a valid invoice" mark, needing a
    small `isSample` flag in 0102's shared copy-mark partial (recommended: the sample uses real
    fiscal data and a real next number); (b) no mark. Proceeding on (a) requires a paragraph in
    0102 before either story's Phase 3.
  - Q-0100-2 (accepted default): starting counter only before the first issue.

## Technical tasks
- [ ] Red tests: component, authorization, preview, start-number, the two read actions, browser.
- [ ] `ReadInvoiceSequenceState`, `PreviewInvoicePdf`, controller, routes, throttle reuse.
- [ ] Component state, mount, `formAttributes()`, save with re-key, logo handlers, colour checks,
  start modal, computeds.
- [ ] View, thumbnail component, sidebar item, lang en/es.
- [ ] Extend sidebar/route parity tests.
- [ ] Pint, Larastan, targeted then full suite; hand the docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa, backend-expert (contract answers). Phase 2 (INVEST) pending.
