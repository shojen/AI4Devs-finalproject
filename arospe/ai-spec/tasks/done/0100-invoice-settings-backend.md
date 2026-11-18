# [0100] Invoice settings (backend)

> **Status: Done (2026-11-17).** Phase 1 complete 2026-11-15. Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). First story of the epic; no upstream dependency.
> Dependents: [0100a](../0100a-customer-tax-id-backend.md),
> [0101a](0101a-invoice-numbering-sequences-backend.md),
> [0102](../0102-invoice-pdf-rendering-and-templates-backend.md),
> [0103](../0103-invoice-settings-screen-ui.md),
> [0105](../0105-automatic-invoice-on-order-creation-backend.md).

## Description
One store-wide invoice-settings record: the issuer's fiscal data, logo, two colours, the PDF
template, one numbering format for standard invoices and one for corrective invoices, the
yearly-reset flag and the auto-issue flag. This story ships the table, model, enums, validation,
the pure number composer, the single write action, the policy and the two permissions. No
screen (0103), no route, no sequence counter (0101a), no PDF (0102), no auto-issue behaviour
(0105 reads the flag).

Request items served (owner, 2026-11-14, translated):
> 1. From store settings, a store manager with permission configures the invoice template: store
> logo, colours, and which template to use (at least two templates must exist), how invoices are
> numbered, and a checkbox so the numbering restarts every 1 January.
> 3. A return or refund produces a corrective invoice with its own numbering, different from the
> invoice numbering.

Binding owner decisions:
- U-3 (owner decision, 2026-11-14): a store setting "issue and send the invoice automatically
  when an order is created", off by default.
- U-5 (owner decision, 2026-11-14): invoice settings hold the issuer's legal name, tax ID and
  fiscal address.
- U-1 (owner decision, 2026-11-14): PDFs use `spatie/laravel-pdf`; not used by this story.

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- Singleton precedent: `locale_settings` (`App\Models\LocaleSetting`, `SINGLETON_ID = 1`,
  `$incrementing = false`, `$keyType = 'int'`, fixed-literal PK) and `LocaleSettingPolicy`; its
  refusal-logging tests live in `tests/Feature/Localization/LocaleSettingRefusalLoggingTest.php`.
- `RolePermissionSeeder` syncs the system Administrator role with every catalog permission except
  `roles.manage-administrators`; Super Admin passes every gate through `Gate::before`. The seeded
  catalog has 43 permissions today; `tests/Feature/Seeders/RolePermissionSeederTest.php` and
  `tests/Feature/Orders/OrderLineItemAuthorizationTest.php` hard-code the count.
- `Media` (UUID PK) exists; `lang/{en,es}/roles.php` has a `modules` group with one leaf per
  permission area.
- `orders.tax_rate` and `customers.billing_country` conventions: ISO alpha-2 country, upper-cased.

## Table created

`invoice_settings` (one migration, `create_invoice_settings_table`). No row is inserted.

| Column | Type | Null / default | Notes |
| --- | --- | --- | --- |
| `id` | tinyint unsigned PK | not null | literal `1` (`InvoiceSetting::SINGLETON_ID`) |
| `issuer_legal_name` | varchar(255) | null | required to issue (0101b) |
| `issuer_tax_id` | varchar(40) | null | rule caps at 32 |
| `issuer_address_line1`, `issuer_address_line2` | varchar(255) | null | line 1 required to issue |
| `issuer_city` | varchar(100) | null | |
| `issuer_postal_code` | varchar(20) | null | |
| `issuer_province` | varchar(100) | null | |
| `issuer_country` | varchar(2) | null | ISO alpha-2 |
| `logo_media_id` | char(36) FK -> `media.id` | null | `nullOnDelete()` |
| `primary_color`, `accent_color` | varchar(7) | null | `#RRGGBB`; null = template default |
| `template` | varchar(30) | not null, default `classic` | `InvoiceTemplate` |
| `standard_prefix` / `corrective_prefix` | varchar(10) | not null, default `F` / `R` | |
| `standard_year_format` / `corrective_year_format` | varchar(4) | not null, default `YYYY` | `InvoiceNumberYear` |
| `standard_separator` / `corrective_separator` | varchar(3) | not null, default `-` | |
| `standard_padding` / `corrective_padding` | tinyint unsigned | not null, default 5 | |
| `reset_numbering_yearly` | boolean | not null, default true | |
| `auto_issue_on_order` | boolean | not null, default false | U-3 |
| `created_at`, `updated_at` | timestamp | null | |

PK only, plus the FK-created index on `logo_media_id`. `down()`: `Schema::dropIfExists`.

## Decisions
Each is binding for this story.

- **FD-1 Defaults without a row.** `InvoiceSetting::current()` returns the saved row or an
  unsaved instance carrying the defaults; no seeder and no migration insert. Reason: only reads
  need defaults (issuing refuses without issuer data anyway), and a migration-inserted row
  survives `RefreshDatabase` and hides "no row" bugs. Defaults: standard `F` / `YYYY` / `-` / 5,
  corrective `R` / `YYYY` / `-` / 5, yearly reset on, auto-issue off, template `classic`, no
  logo, null colours, null issuer. Model constants hold them; a test pins them equal to the
  column defaults.
- **FD-2 Structured numbering.** Each series stores prefix (0-10 of `A-Z a-z 0-9 - /`), year
  token (`none`, `YYYY`, `YY`), separator (0-3 of `- / .`), padding (1-10). Number =
  `prefix + year + separator + zero-padded counter`, e.g. `F2026-00001`, `R26/0007`. The
  separator is emitted even when the year token is `none` (`F-00001`); an empty separator gives
  `F00001`. A counter wider than the padding is never truncated (`F2026-100000`). Longest number:
  27 characters. Reason: the parts are validated one by one; a token string would need a parser
  and widen the injection surface.
- **FD-3 One yearly-reset flag for both series** (BR-0100-6).
- **FD-4 Cross-field rules, refused as validation errors on the named field:**
  - yearly reset on requires a year token in both formats (error on that format's
    `*_year_format`); otherwise year N+1 number 1 would equal year N number 1;
  - the two prefixes must differ case-insensitively (error on `corrective_prefix`);
  - auto-issue cannot be on while legal name, tax ID or address line 1 is empty, **judged on the
    state the save would produce**: switching it on with incomplete data is refused, and so is
    blanking one of those fields while it stays on (error on `auto_issue_on_order`). City and
    country are not required.
- **FD-5 G-2 decision: a save replaces the whole record.** The payload must carry every
  settings key (a nullable field may be null or `''`); a missing key is a validation error on
  that key and nothing is written; unknown keys (`id`, `created_at`, `foo`) are ignored. Reason:
  the only caller is the 0103 form, which always posts the whole record; a merge would let a
  half-filled payload silently keep stale fiscal data or, with "missing = default", blank it. A
  complete payload makes every save reviewable and testable as one state.
- **FD-6 Normalisation before validation.** Text trimmed and `''` -> null; colours upper-cased;
  country upper-cased, two letters A-Z, no ISO-membership check (same as
  `customers.billing_country`); tax ID: all whitespace removed, upper-cased, max 32, only letters,
  digits and `- . /` (`" es-b12.34/5 "` -> `ES-B12.34/5`, `ES B123` -> `ESB123`; `ES_B1`, `<b>`,
  `ES;B1` refused). The tax-ID rule lives in a reusable trait that 0100a also uses.
- **FD-7 One writer, locked.** `SaveInvoiceSettings` is the only code that writes the table:
  logged authorization first, normalise, validate, cross-field checks, then an autocommit
  `insertOrIgnore` of the defaults row **outside** the transaction, then a transaction that
  selects the row `FOR UPDATE` and `forceFill(...)->save()`s. Reason: an insert-ignore followed by
  `FOR UPDATE` in one transaction deadlocks (S-to-X upgrade); a whole-row `upsert()` could
  overwrite a concurrent save. The model is `#[Fillable([])]`; only validated keys reach
  `forceFill`. Success writes one `Log::info` with the actor id and the changed **keys** only (no
  fiscal values).
- **FD-8 G-1 decision: who holds the permissions.** Super Admin holds them through its gate
  bypass, and the system Administrator role receives `invoice-settings.view` and
  `invoice-settings.manage` on seed and re-seed, like every catalog permission. Reason: the
  seeder's established rule syncs Administrator with the whole catalog except
  `roles.manage-administrators`; excluding invoice permissions would be a special case nobody
  asked for. Other roles get them in the roles UI. The permissions live in a new
  `INVOICE_PERMISSIONS` seeder constant, not in `MODULES` (which would mint unwanted
  `create`/`edit`/`delete` names); later stories append `invoices.*` to it.
- **FD-9 Read gate.** Reading is the policy ability `viewAny` (`invoice-settings.view`); internal
  readers (issuing, PDF) call `current()` ungated. 0103's route and component enforce `viewAny`.
- **FD-10 Logo.** Validated as an existing `media.id` only; file checks belong to the media
  pipeline and 0102. Deleting the gallery image nulls the setting (`nullOnDelete`).
- **FD-11 `InvoiceType` is created here** (`Standard='standard'`, `Corrective='corrective'`),
  because the composer takes the series; 0101a/0101b use it and must not recreate it.
- **FD-13 Shared normaliser and final keys (screen contract).** The normaliser is a named method
  of `InvoiceSettingValidationRules`, `normalizeInvoiceSettingAttributes(array $attributes):
  array` (trim, blank to null, colours and country upper-cased with ASCII `strtoupper`, tax ID
  through `normalizeTaxId()`), shared by `SaveInvoiceSettings` and the settings screen
  ([0103](../0103-invoice-settings-screen-ui.md) composes the trait); `invoiceSettingRules()` returns an
  array keyed by field so a single key can be read. Final lang keys in
  `lang/{en,es}/invoice-settings.php`: `validation.year_required_when_reset` (key
  `<series>_year_format`), `validation.prefixes_must_differ` (key `corrective_prefix`),
  `validation.auto_issue_requires_issuer` (key `auto_issue_on_order`).
- **FD-12 Out of this story:** the "next number must not already exist" save check
  (`AssertNumberingFormatIsFree`, story 0101b), the starting counter and number preview (0101a),
  the template views (0102). "Saving never changes an issued invoice" reduces here to: a save
  writes only `invoice_settings`.

## Gherkin
```gherkin
Feature: Invoice settings

  Scenario: A store manager saves complete invoice settings
    Given a store manager with permission to manage invoice settings, and no settings saved yet
    When she saves the issuer legal name, tax ID and address, a logo, the Modern template and
      both numbering formats
    Then the invoice settings read back exactly as she saved them
    And there is a single settings record

  Scenario: Unsaved settings read as the documented defaults
    Given a store manager with permission to view invoice settings, in a store that never saved them
    When she reads the invoice settings
    Then she sees prefixes "F" and "R", a four-digit year, separator "-", five digits, yearly
      reset on, automatic invoicing off and the Classic template

  Scenario: Yearly reset needs a year in every number
    Given a store manager with permission to manage invoice settings, with yearly reset on
    When she saves a corrective format with no year token
    Then the save is refused with an error on the corrective year format
    And the previous settings stay unchanged

  Scenario: The two series must start differently
    Given a store manager with permission to manage invoice settings
    When she saves the prefix "F" for standard invoices and "f" for corrective invoices
    Then the save is refused with an error on the corrective prefix

  Scenario: Automatic invoicing needs complete issuer data
    Given a store manager with permission to manage invoice settings, with no issuer legal name
    When she switches automatic invoicing on
    Then the save is refused with an error on automatic invoicing

  Scenario: Issuer data cannot be blanked while automatic invoicing is on
    Given a store manager with permission to manage invoice settings, with automatic invoicing on
      and complete issuer data
    When she saves the settings with an empty issuer tax ID
    Then the save is refused with an error on automatic invoicing

  Scenario: A partial payload is refused
    Given a store manager with permission to manage invoice settings
    When she saves a payload that leaves out the accent colour key
    Then the save is refused with an error on the accent colour
    And nothing is written

  Scenario: A viewer cannot change the settings
    Given a sales assistant who may view but not manage invoice settings
    When she tries to save a change
    Then the save is refused
    And the settings stay as they were
    And the refused attempt is logged without any fiscal value

  Scenario: The Administrator role gets the new permissions on re-seed
    Given a platform operator whose installation was seeded before this story
    When they run the roles and permissions seeder again
    Then both invoice-settings permissions exist once each
    And the Administrator role holds both
```

## Class contracts
- `App\Enums\InvoiceTemplate`: `Classic='classic'`, `Modern='modern'` (0102 adds `view()`).
- `App\Enums\InvoiceNumberYear`: `None='none'`, `Full='YYYY'`, `Short='YY'`;
  `render(CarbonInterface $at): string` (`2026` / `26` / `''`).
- `App\Enums\InvoiceType`: `Standard='standard'`, `Corrective='corrective'`.
- `App\Models\InvoiceSetting`: `SINGLETON_ID = 1`, `$incrementing = false`, `$keyType = 'int'`,
  `#[Fillable([])]`, `casts()` (template and both year formats to enums, booleans, paddings
  int), default constants, `current(): self`, `hasCompleteIssuerData(): bool`,
  `logo(): BelongsTo<Media>`.
- `Database\Factories\InvoiceSettingFactory`: id fixed to the singleton; states `withIssuer()`,
  `autoIssue()`.
- `App\Concerns\TaxIdValidationRules`: `taxIdRules(bool $required = false): array`,
  `normalizeTaxId(?string): ?string`.
- `App\Concerns\InvoiceSettingValidationRules`: `normalizeInvoiceSettingAttributes(array
  $attributes): array` (FD-13) and `invoiceSettingRules(): array` keyed by field (every key
  `present`; `Rule::enum` for template and year formats; colour `^#[0-9A-F]{6}$` after
  normalisation; `exists:media,id`; prefix `^[A-Za-z0-9\-\/]{0,10}$`; separator `^[\-\/.]{0,3}$`;
  padding integer 1..10; booleans).
- `App\Policies\InvoiceSettingPolicy`: `VIEW_PERMISSION = 'invoice-settings.view'`,
  `MANAGE_PERMISSION = 'invoice-settings.manage'`; `viewAny(User): bool`,
  `update(User, ?InvoiceSetting $target = null): bool`.
- `App\Actions\Invoices\SaveInvoiceSettings::__invoke(array $attributes): InvoiceSetting`
  (new area folder `app/Actions/Invoices/`).
- `App\Actions\Invoices\ComposeInvoiceNumber::__invoke(InvoiceType $series, InvoiceSetting
  $settings, CarbonInterface $at, int $counter): string` — pure; the caller passes the issue
  moment in `config('app.timezone')`.

## Files to create/modify
- `database/migrations/<ts>_create_invoice_settings_table.php` — the table above.
- `app/Enums/InvoiceTemplate.php`, `app/Enums/InvoiceNumberYear.php`, `app/Enums/InvoiceType.php`.
- `app/Models/InvoiceSetting.php`, `database/factories/InvoiceSettingFactory.php`.
- `app/Concerns/TaxIdValidationRules.php`, `app/Concerns/InvoiceSettingValidationRules.php`.
- `app/Policies/InvoiceSettingPolicy.php`.
- `app/Actions/Invoices/SaveInvoiceSettings.php`, `app/Actions/Invoices/ComposeInvoiceNumber.php`.
- `database/seeders/RolePermissionSeeder.php` — `INVOICE_PERMISSIONS = ['invoice-settings.view',
  'invoice-settings.manage']`, spread in `allPermissionNames()`.
- `lang/{en,es}/invoice-settings.php` — attribute names and refusal messages, key for key.
- `lang/{en,es}/roles.php` — `modules.invoice_settings` leaf (otherwise the roles matrix shows a
  raw key).
- `tests/Feature/Seeders/RolePermissionSeederTest.php`,
  `tests/Feature/Orders/OrderLineItemAuthorizationTest.php` — catalog count 43 -> 45.
- New tests listed below.

## Tests to perform
Files: `tests/Feature/InvoiceSettings/{SaveInvoiceSettingsTest, InvoiceSettingValidationTest,
InvoiceSettingCrossFieldRulesTest, InvoiceSettingRefusalLoggingTest, InvoiceSettingModelTest}.php`,
`tests/Feature/Policies/InvoiceSettingPolicyTest.php`,
`tests/Unit/Actions/Invoices/ComposeInvoiceNumberTest.php`,
`tests/Unit/Enums/{InvoiceNumberYearTest, InvoiceTemplateTest}.php`, en/es parity (new or the
existing parity test extended). Use the real `RolePermissionSeeder` and `Log::spy()`, as the
`LocaleSetting*` tests do.

Happy path
- [x] T-1 Full valid save with no row: exactly one row, id 1, every field reads back.
- [x] T-2 `current()` with no row: unsaved instance with every default; row count stays 0.
- [x] T-3 `current()` with a row returns the row.
- [x] T-4 Composer dataset: `F2026-00001`, `R26/0007`, `INV/2026.0042`, empty prefix, year `none`
  with separator (`F-00001`), empty separator (`F00001`), padding 10.
- [x] T-5 `invoice-settings.manage` alone can save; `invoice-settings.view` alone passes `viewAny`.
- [x] T-6 Super Admin saves and passes `viewAny`; the catalog holds both names.
- [x] T-7 Model default constants equal the migrated column defaults (one test over all constants).
- [x] T-8 `InvoiceTemplate` has at least two cases with distinct values; default = first case.
- [x] T-9 Empty issuer data with auto-issue off saves; `hasCompleteIssuerData()` false; with legal
  name, tax ID and address line 1 true even without city and country.

Edge
- [x] T-10 Two saves back to back and a first save whose `insertOrIgnore` finds the row: one row,
  last values win; `DB::listen` shows the insert outside the transaction and the `FOR UPDATE`
  inside it.
- [x] T-11 Row held `FOR UPDATE` by a second connection with a short `innodb_lock_wait_timeout`:
  the save fails with 1205 (proves the lock), nothing lost.
- [x] T-12 Boundaries: prefix 0/10 ok, 11 refused; separator 0/3 ok, 4 refused; padding 1/10 ok,
  0/11 refused.
- [x] T-13 Legal name 255 ok / 256 refused (255 round-trips); tax ID 32 ok / 33 refused.
- [x] T-14 Tax ID normalisation dataset of FD-6, `''` and whitespace -> null.
- [x] T-15 Composer: counter 100000 with padding 5 -> `F2026-100000`; year follows the passed
  moment (2026-12-31 23:59 vs 2027-01-01 00:00), never `now()`.
- [x] T-16 `InvoiceNumberYear`: `Short` renders `09` for 2009 and `00` for 2100; `Full` four
  digits; `None` `''`.
- [x] T-17 Reset off accepts year `none` on either series.
- [x] T-18 Prefixes `F`/`f` and both empty refused; `F`/`FA` accepted.
- [x] T-19 Auto-issue on, then a save blanking legal name / tax ID / address line 1 (dataset) is
  refused.
- [x] T-20 Logo set, media row deleted: settings row survives, `logo_media_id` null; saving with
  a logo and with null both work.
- [x] T-21 Colours: `#a1b2c3` stored `#A1B2C3`; `#000000`, null, `''` (-> null) accepted; `#FFF`,
  `A1B2C3`, `#GGGGGG`, `#12345678`, `red`, `#fff;}</style><script>` refused.
- [x] T-21a Extra keys `id`, `created_at`, `foo` ignored: id stays 1, `created_at` untouched.
- [x] T-21b Each omitted key (dataset over all keys) refused on that key; row unchanged (FD-5).
- [x] T-21c Country `es` stored `ES`; `E`, `ESP`, `E1` refused; `XX` accepted (no ISO list).
- [x] T-21d A save writes only `invoice_settings` (`DB::listen`).

Negative
- [x] T-22 Reset on and year `none` (dataset: each series) refused on that series' year field.
- [x] T-23 Equal prefixes refused on `corrective_prefix`.
- [x] T-24 Auto-issue on with each required issuer field missing (dataset) refused on
  `auto_issue_on_order`; all present accepted.
- [x] T-25 No permission (valid and invalid payloads): `AuthorizationException`, no row, no
  `ValidationException` leaks (authorize first).
- [x] T-26 Refusal logs one `Log::warning('Privileged action refused')` with `actor_id`,
  `ability = update`, `target_type = invoice_setting`, `target_id = 1`, no payload values; no
  `Log::info`.
- [x] T-27 Unauthenticated save refused and logged without an actor id.
- [x] T-28 `view` only cannot save; neither permission fails `viewAny`.
- [x] T-29 Permitted save: one `Log::info` with actor and changed keys only, no fiscal values.
- [x] T-30 Out-of-enum template / year format (`classic2`, `yyyy`, `Y`), unknown or non-UUID
  logo id, padding `'5; DROP'`, flag `'maybe'`: each refused on its field.
- [x] T-31 Prefix/separator dataset `F A`, `F.`, `F#`, `<`, `F\n`, `../`, `Fé`, separator `ab`,
  `-/-/` refused.
- [x] T-33 Seeder run twice: both permissions exist once, catalog 45, Administrator holds both.
- [x] T-34 en/es key parity incl. `roles.modules.invoice_settings`.
- [x] T-35 `normalizeInvoiceSettingAttributes()` applied alone (the screen's live check) yields
  exactly the value `SaveInvoiceSettings` stores (colour dataset of T-21); `invoiceSettingRules()`
  read for a single key validates that key alone; the three cross-field refusals use the FD-13 lang
  keys on the named fields.

## Expected outcome
The settings record can be read (defaults until first save) and written only through
`SaveInvoiceSettings` by a holder of `invoice-settings.manage`; invalid or partial payloads are
refused field by field; numbers can be composed from the settings for any series, moment and
counter. Nothing visible changes in the UI yet.

## Acceptance criteria
- [x] `invoice_settings` exists as specified; no row is inserted by migration or seeder.
- [x] `InvoiceSetting::current()` returns the row or the documented defaults; defaults equal the
  column defaults.
- [x] `SaveInvoiceSettings` authorizes (logged) before anything else, replaces the whole record,
  refuses missing keys, ignores unknown keys, normalises per FD-6 and enforces FD-4.
- [x] Concurrent saves never deadlock or lose an update (FD-7).
- [x] `ComposeInvoiceNumber` follows FD-2 exactly.
- [x] `invoice-settings.view` / `invoice-settings.manage` are seeded, held by Administrator and
  Super Admin, gate `viewAny` / `update`; catalog count is 45.
- [x] `InvoiceTemplate` (two cases), `InvoiceNumberYear` and `InvoiceType` exist.
- [x] en/es lang files and the roles-matrix leaf exist with key parity.

## Definition of Done
- [x] Tests written and green
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor)
- [x] Documentation updated (docs-keeper): `docs/database/schema.md` ER diagram
  (`media ||--o| invoice_settings : "logo"`), the authorization catalog (43 -> 45 and the
  Administrator grant), `docs/conventions/directory-structure/actions.md` (new `Invoices/` area),
  `docs/conventions/naming-validation-traits.md` (two traits)
- [x] Acceptance criteria met
- [x] Pint unscoped passed; full suite green (Unit+Feature 6,351 tests in chunks, Browser 324 passed / 3 skipped); Larastan level 7: 0 errors on the changed files. A full unscoped Larastan run was NOT confirmed: it hit the 128M memory limit / exited silently, so project-wide level 7 is unverified.

## Dependencies, risks, open technical questions
- Upstream: none (needs the existing `media` table). Downstream: 0100a reuses
  `TaxIdValidationRules`; 0101a reads the formats and the reset flag; 0101b adds
  `AssertNumberingFormatIsFree` to the save and refuses issuing while
  `hasCompleteIssuerData()` is false; 0102 reads template, colours and logo; 0103 builds the
  screen; 0105 reads `auto_issue_on_order`.
- Security: prefix, separator and colours end up in invoice numbers and PDF CSS, so their
  whitelist regexes are the injection barrier; free-text issuer fields are stored raw and
  escaped at render (0102), never stripped. Authorize before validation so an unprivileged
  caller learns nothing. Mass assignment only through `forceFill` of validated keys.
- 0103 binds these fields as Livewire public properties: it must treat them as client-writable
  and save through this action.
- Deployment: existing installs must re-run `RolePermissionSeeder` for Administrator to gain the
  two permissions (the established catalog-amendment note).
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0100-1 Tax ID format: (a) free text, whitespace removed, upper-cased, max 32, letters,
    digits and `- . /` (recommended: works for non-Spanish issuers and customers); (b) Spanish
    NIF/NIE/CIF checksum.
  - Q-0100-3 Template names: (a) `Classic` and `Modern`, same content, different layout
    (recommended); (b) names supplied by the owner.
  - FD-8 (Administrator holds the permissions) corrects an earlier draft that said "only Super
    Admin"; the owner may ask for the narrower grant, which would need a seeder special case.

## Technical tasks
- [x] Migration, model, factory, three enums; schema and default-parity tests first (red).
- [x] Seeder constant, policy, catalog-count updates, re-seed test.
- [x] `TaxIdValidationRules`, `InvoiceSettingValidationRules`.
- [x] `ComposeInvoiceNumber` with its unit dataset.
- [x] `SaveInvoiceSettings` with refusal-logging, cross-field and lock tests.
- [x] Lang en/es, roles leaf, parity test.
- [x] Pint, Larastan, full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
- Phase 2 (INVEST) **approved** on 2026-11-16 by code-reviewer. Independent (needs only the
  existing `media` table), Negotiable (open questions Q-0100-1/-3 and FD-8 recorded with a
  recommended option), Valuable (unblocks 0100a/0101a/0102/0103/0105), Estimable, Small enough
  (one table, one writer, no UI), Testable (T-1..T-35 map to every AC). Gherkin: every scenario
  opens with a named business-role actor and has a single `When`. Catalog count verified in the
  repo: 10 modules x 4 actions + 2 role + 1 order = 43; Administrator holds 42. FD-1..FD-13,
  class contracts, Files, Tests and AC agree; no blocking contradiction. Non-blocking notes for
  Phase 3: (1) `RolePermissionSeederTest` also hard-codes Administrator `42` (-> 44), the media
  re-seed intermediate `39` (-> 41) and registrar `43` (-> 45), not only `43`;
  `OrderLineItemAuthorizationTest` computes the count from the seeder constants, so the fix there
  is adding `count(RolePermissionSeeder::INVOICE_PERMISSIONS)` to its formula, not editing a
  literal; (2) FD-2's "longest number: 27 characters" holds only while the counter fits the
  padding (FD-2 itself says a wider counter is never truncated) — 0101a must size its column
  accordingly; (3) cosmetic: FD-13 is listed before FD-12 and there is no T-32.
- Phase 4 (security audit) **approved** on 2026-11-16 by appsec-auditor — no Critical/High/Medium
  findings. Verified: logged authorization runs before normalisation, validation and any DB
  query; `#[Fillable([])]` + `forceFill()` of `validate()` output only (no `id`/timestamps);
  prefix/separator/colour/country/tax-ID regexes are fully anchored with `/D` (no trailing-newline
  bypass), so invoice numbers and PDF CSS values are whitelist-only; `ComposeInvoiceNumber`
  interpolates closed-enum keys only; the save log carries actor id and changed key names, never
  fiscal values; insert-ignore outside + `FOR UPDATE` inside the transaction is sound; the grant
  goes to Administrator (and Super Admin via bypass) only. Non-blocking notes for later stories:
  (L1) FD-4 only refuses identical prefixes — distinct formats can still compose the same number
  (standard `F`+`YYYY`+`-` vs corrective `F2026`+none+`-` both give `F2026-00001`), and a
  mid-year format change can repeat an issued number; 0101a must put a UNIQUE index on the
  invoice number and/or 0103 tighten the rule (neither prefix a case-insensitive prefix of the
  other); (L2) free-text issuer fields are stored raw — 0102 must render them escaped
  (`{{ }}`), never `{!! !!}`; (I1) whole-record replace is last-writer-wins between two admins
  (0103 may add an `updated_at` stale check); (I2) as for every sibling policy, deploying without
  re-running `RolePermissionSeeder` makes `hasPermissionTo()` throw on the missing rows.
- Phase 5 (final code review) **rejected** on 2026-11-16 by code-reviewer — one blocking
  defect, returned to backend-expert (fix) and backend-qa (test). B1: a whitespace-only prefix or
  separator (`' '`, `"\n"`, `"\t\n"`) passes validation and is stored, because Laravel's
  `presentOrRuleIsImplicit()` skips every non-implicit rule (`string`, `regex`) when
  `trim($value) === ''`; `app/Concerns/InvoiceSettingValidationRules.php:79,81` therefore never
  run their whitelist regex on such values, contradicting the trait's own docblock (line 20-21:
  "stray whitespace in them must be refused") and the story's security note (the regexes are the
  injection barrier for invoice numbers). Fix: make the whitelist run on whitespace-only input
  (e.g. an implicit `ValidationRule` with `public bool $implicit = true`, or an explicit check in
  the trait/action) and add `' '`, `"\n"`, `"  "` cases to the T-31 dataset for both prefixes and
  both separators. Everything else verified: AC, FD-1..FD-13, class contracts, T-1..T-35 present,
  Larastan level 7 clean on the changed files, catalog 45 / Administrator 44. Full suite and Pint
  left to the orchestrator's run.
- Phase 5 re-review (orchestrator, 2026-11-17): B1 (whitespace-only prefix/separator bypass) fixed with an implicit rule + extended T-31; full suite green in chunks (Unit+Feature 6,351 tests, Browser pending/recorded at closure).
- Phase 6 (documentation) done on 2026-11-17 by docs-keeper: new part `docs/database/schema-other/invoice-settings.md` (hub `schema-other.md`, `schema.md` ER diagram with `MEDIA ||--o| INVOICE_SETTINGS` and entity block, model count 30, `docs/README.md` row); authorization catalog 43 -> 45, Administrator 42 -> 44, `INVOICE_PERMISSIONS`, re-run-seeder deployment note (`overview-catalog-seeding.md`, `schema-users-auth.md`, `routes-and-permissions.md`, `architecture/overview.md`, `index-details-and-history.md`, `authorization.md` footer); `directory-structure/actions.md` (`Actions/Invoices/`); `naming-validation-traits.md` (two traits, implicit-rule pattern, prefix/separator not trimmed); `InvoiceSetting::defaults()` documented. Epic digest not written (first story of an ad-hoc epic, no sibling yet). `../readme.md` not changed (no user-visible change).
- Phase 7 (closure) done on 2026-11-17: all Acceptance criteria and Definition of Done boxes ticked, with the one honest caveat above (project-wide Larastan not confirmed; only the changed files were analysed). Phase 2, 4, 5 (re-review after B1) and 6 approvals are recorded above. File moved from `ai-spec/tasks/in-progress/` to `ai-spec/tasks/done/`; the claim entry was dropped from `ai-spec/tasks-status.json`. Link check, both directions: same-depth move, so the file's own `](../…)` links resolve unchanged; inbound links in `0100a`, `0101a`, `0101b`, `0102`, `0103` and `0105` were repointed to `done/0100-…`. No epic digest written (first closed story of an ad-hoc epic with no PRD part; nothing for a sibling to inherit yet). Dependents `0100a` and `0101a` become `ready`.
