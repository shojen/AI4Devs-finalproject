# [0100a] Customer tax ID (backend)

> **Status: In progress (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](../done/0100-invoice-settings-backend.md) (it owns the shared tax-ID rule). Dependents:
> [0100b](0100b-customer-tax-id-field-ui.md) (form field and detail line) and
> [0101b](../0101b-issue-standard-invoice-backend.md) (copies the value onto each invoice).

## Description
Customers gain an optional tax ID, stored on `customers` and accepted by `CreateCustomer` and
`UpdateCustomer` with the same normalisation and character rule as the issuer tax ID of 0100.
It is printed on invoices later (0101b snapshots it at issue). No screen change, no new
permission, no route.

Request context (owner, 2026-11-14): invoices are issued to customers (request items 2 and 5:
"sends the invoice", "create invoices for an order"); a customer invoice needs the recipient's
tax ID when the customer has one.

Binding owner decision:
- U-5 (owner decision, 2026-11-14): "invoice settings hold the issuer's legal name, tax ID and
  fiscal address. Customers gain an optional tax ID printed on the invoice."

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- `CustomerValidationRules` (`app/Concerns/`) has `customerRules()`, per-field rule methods, an
  `OPTIONAL_FIELDS` list whose blank strings become null in `normalizeCustomerAttributes()`
  before validation (Laravel skips non-implicit rules on a blank string), and an ASCII-only
  upper-casing pass for the country.
- `CreateCustomer::__invoke(array $attributes): Customer` and
  `UpdateCustomer::__invoke(Customer $customer, array $attributes): Customer` authorize (logged)
  as their first statement, then validate and write. In `UpdateCustomer` a key omitted from
  `$attributes` is never validated and never appears in `$validated`, so `update($validated)`
  leaves that column untouched (existing contract).
- Gates: `customers.create` / `customers.edit` (`CustomerPolicy`). Customers are soft-deleted;
  `customers.email` is required and unique.
- `Customers\Index` builds its attribute array by hand and will not send `tax_id` until 0100b.

## Column added
Migration `<ts>_add_tax_id_to_customers_table.php`: `customers.tax_id varchar(40) null`,
`->after('billing_country')`. No default, no unique index, no index. No backfill (existing
customers keep null). `down()` drops the column; the docblock states that this loses the data.

## Decisions
- **FD-1 Shared format, one copy.** The rule is the 0100 trait `TaxIdValidationRules`
  (`taxIdRules()`, `normalizeTaxId()`); `CustomerValidationRules` gains
  `customerTaxIdRules()`, which simply returns `$this->taxIdRules()` (that list already starts
  with `'nullable'`; do not add it again). Reason: issuer and customer formats must never drift.
  This is why 0100a depends on 0100.
- **FD-2 Format (same as 0100, Q-0100-1 (a)).** Before validation: all whitespace removed
  (leading, trailing and inside), ASCII-only upper-casing, `''` -> null. Then: string, max 32,
  only `A-Z`, `0-9`, `-`, `.`, `/`. So `" b-12345678 "` -> `B-12345678`, `ES B123` -> `ESB123`;
  `../x` -> `../X` (accepted: `.` and `/` are allowed); `ES_B1`, `<b>`, `B1;`, `ES#1`, `ñ1`,
  emoji refused. Reason: works for non-Spanish customers and
  issuers; removing spaces accepts the way VAT numbers are often typed while keeping one stored
  form. (Settles the earlier contradiction where one draft allowed a space; both stories now pin
  the same dataset.)
- **FD-3 Update without the key leaves the value unchanged; an explicit blank clears it.**
  `UpdateCustomer` called without `tax_id` keeps the stored value; with `''` or whitespace it
  stores null. Reason: this is `UpdateCustomer`'s existing partial-update contract for every
  field, and today's customer screen does not send `tax_id` until 0100b; a "missing = null" rule
  would silently erase tax IDs on every edit. (Unlike 0100's settings save, which replaces the
  whole record because its one caller always posts the whole form.) `CreateCustomer` without the
  key stores null.
- **FD-4 Non-strings refused.** An int, bool or array `tax_id` is refused on `tax_id` by the
  `string` rule. The customer normalisation pass in `normalizeCustomerAttributes()` guards with
  `is_string($attributes['tax_id'])` before calling `normalizeTaxId()` (as
  `InvoiceSettingValidationRules` does, ~line 48): the files have no `strict_types`, so without
  the guard `123` / `true` would be coerced to `"123"` / `"1"` and saved. Non-strings pass
  through untouched to the validator, so nothing is cast and no `TypeError` occurs.
- **FD-5 Not unique, not indexed.** Several customer records may belong to one company, and a
  customer may share the issuer's own tax ID; nothing filters by it.
- **FD-6 `varchar(40)` with a 32 rule.** Matches the issuer column and the invoice recipient
  snapshot; a later relaxation needs no migration.
- **FD-7 No cascade.** Changing a customer's tax ID writes only the customer row: no order is
  marked out of date and issued invoices keep their snapshot (BR-0100a-4). The invoice half is
  tested in 0101b, because no `invoices` table exists yet.
- **FD-8 Personal data.** For an individual a tax ID is personal data: it never appears in a
  log line, a refusal-log context or the `NotifyCustomerCreated` payload.
- **FD-9 Fillable.** `tax_id` is user data and is added to `Customer`'s `#[Fillable]` with an
  `@property string|null $tax_id`.
- **FD-10 Translated attribute names (owner decision, 2026-11-17: wire them).** A new
  `attributes` block in `lang/{en,es}/customers.php` holds a `tax_id` leaf, and both
  `CreateCustomer` and `UpdateCustomer` pass attribute names as the fourth argument of
  `Validator::make()` (same shape as `SaveInvoiceSettings::attributeNames()`), so a tax-ID error
  reads with the translated field name instead of "tax id". The block as shipped covers all 16 customer fields, not only `tax_id`
  (accepted by code-reviewer at Phase 5, 2026-11-18: every customer field error now uses its
  translated name, an improvement). Reason: validation errors are
  shown on the admin screen in both locales (0100b).

## Gherkin
```gherkin
Feature: Customer tax ID

  Scenario: A store manager creates a customer with a tax ID
    Given a store manager with permission to create customers
    When she creates a customer with the tax ID " b-12345678 "
    Then the customer is saved with the tax ID "B-12345678"

  Scenario: A store manager adds a tax ID to an existing customer
    Given a store manager with permission to edit customers, and a customer without a tax ID
    When she saves the customer with the tax ID "B-87654321"
    Then the customer has the tax ID "B-87654321"
    And every other customer field is unchanged

  Scenario: Spaces are removed from a typed tax ID
    Given a store manager with permission to create customers
    When she creates a customer with the tax ID "es b 123"
    Then the customer is saved with the tax ID "ESB123"

  Scenario: An edit that does not mention the tax ID keeps it
    Given a store manager with permission to edit customers, and a customer with the tax ID "B-12345678"
    When she saves only a new name for the customer
    Then the customer still has the tax ID "B-12345678"

  Scenario: Clearing the tax ID removes it
    Given a store manager with permission to edit customers, and a customer with the tax ID "B-12345678"
    When she saves the customer with an empty tax ID
    Then the customer has no tax ID

  Scenario: A tax ID with forbidden characters is refused
    Given a store manager with permission to edit customers
    When she saves a customer with the tax ID "ES-B1;"
    Then the save is refused with an error on the tax ID
    And the customer is unchanged

  Scenario: Two customers may share a tax ID
    Given a store manager with permission to create customers, and a customer with the tax ID "B-12345678"
    When she creates a second customer with the tax ID "B-12345678"
    Then the second customer is saved

  Scenario: A viewer cannot change a tax ID
    Given a sales assistant who may view but not edit customers
    When she tries to change a customer's tax ID
    Then the change is refused
    And the tax ID stays as it was
    And the refused attempt is logged without the tax ID
```

## Files to create/modify
- `database/migrations/<ts>_add_tax_id_to_customers_table.php` — the column above.
- `app/Models/Customer.php` — `tax_id` in `#[Fillable]`, `@property string|null $tax_id`.
- `app/Concerns/CustomerValidationRules.php` — `use TaxIdValidationRules`; `customerTaxIdRules()`
  (returns `$this->taxIdRules()`, FD-1) added to `customerRules()`; `tax_id` in
  `OPTIONAL_FIELDS`; a normalisation pass in `normalizeCustomerAttributes()` that calls
  `normalizeTaxId()` only when `is_string()` holds (FD-4).
- `app/Actions/Customers/CreateCustomer.php`, `UpdateCustomer.php` — small logic change: pass
  attribute names to `Validator::make()` (a private `attributeNames()` mapping `tax_id` to
  `__('customers.attributes.tax_id')`, as in `SaveInvoiceSettings::attributeNames()`), FD-10;
  docblocks updated if they list the fields.
- `database/factories/CustomerFactory.php` — default `tax_id` null; state
  `withTaxId(?string $taxId = null)` producing a valid value.
- `lang/{en,es}/customers.php` — new `attributes` block with a `tax_id` leaf (the form label
  belongs to 0100b).
- New `tests/Datasets/TaxIds.php` — named Pest `dataset()`s shared by 0100 and 0100a (union of
  both stories):
  - normalisation (input -> stored): 0100's six existing inline cases, plus `x.1/2-y` ->
    `X.1/2-Y`, `"B1\n2"` -> `B12`, `" b-12345678 "` -> `B-12345678`, `es b 123` -> `ESB123`,
    `../x` -> `../X`;
  - invalid: `ES_B1`, `<b>`, `ES;B1`, `ES$B1`, `ES'B1`, `B1;`, `B1'--`, `ES#1`, an emoji, `ñ1`,
    `é`.
- `tests/Feature/InvoiceSettings/InvoiceSettingValidationTest.php` (0100) — replace the inline
  `->with([...])` tax-ID datasets (~lines 103-115) with the named datasets above; no assertion
  changes.
- `tests/Feature/Customers/CreateCustomerTest.php` — extend `customerFullPayload()` with
  `tax_id`. (`PersistenceTest` does not list columns and needs no change; `UpdateCustomerTest`
  only where it enumerates every column.)
- New `tests/Unit/Concerns/CustomerValidationRulesTest.php` — guards that `tax_id` is in
  `OPTIONAL_FIELDS`.
- New `tests/Feature/Customers/CustomersLangParityTest.php` — en/es key parity for
  `lang/{en,es}/customers.php` (modelled on `tests/Feature/Orders/OrdersLangParityTest.php`).
- New `tests/Feature/Customers/CustomerTaxIdTest.php` and
  `tests/Feature/Customers/CustomerTaxIdAuthorizationTest.php`.

## Tests to perform
Real `CreateCustomer` / `UpdateCustomer`, real `RolePermissionSeeder`, `Log::spy()`. The
normalisation and invalid-value datasets are the named datasets in `tests/Datasets/TaxIds.php`,
used by both this story and 0100's `InvoiceSettingValidationTest.php`, so the two stories cannot
drift.

Happy path
- [ ] T-1 Create with `B-12345678` stores it.
- [ ] T-2 Update sets a tax ID; every other column unchanged.
- [ ] T-3 Create without the key stores null.
- [ ] T-4 Shared normalisation dataset (create and update), including `" b-12345678 "` ->
  `B-12345678`, `x.1/2-y` -> `X.1/2-Y`, `es b 123` -> `ESB123`, `"B1\n2"` -> `B12`; normalising
  twice equals once.
- [ ] T-4b Pinned accepted case: `../x` is valid under the 0100 rule and is stored as `../X`.
- [ ] T-5 Same tax ID on two customers (one soft-deleted, one active) both saved.
- [ ] T-6 Factory default null; `withTaxId()` passes the rule.
- [ ] T-7 Schema: nullable `varchar(40)`, no unique index, after `billing_country`; `down()`
  drops it.

Edge
- [ ] T-8 Update without the `tax_id` key leaves the stored value unchanged (FD-3).
- [ ] T-9 Update with `''` / `'   '` stores null (dataset); same on create.
- [ ] T-10 32 characters accepted, 33 refused, 33 that are 32 after whitespace removal accepted;
  long input never reaches the column.
- [ ] T-11 `ñ1`, `é` refused with no mangled bytes (ASCII-only upper-casing).
- [ ] T-12 A soft-deleted customer keeps its tax ID (read with `withTrashed()`).
- [ ] T-13 Email unique race (23000) with a tax ID sent: the email error is raised, the tax ID
  not partly saved.
- [ ] T-14 Customer tax ID equal to the issuer tax ID in `invoice_settings` accepted.
- [ ] T-15 Changing the tax ID leaves every `orders` row of the customer unchanged (FD-7; the
  invoice-snapshot half is tested in 0101b).

Negative
- [ ] T-16 Shared invalid dataset (`ES_B1`, `<b>`, `ES;B1`, `ES$B1`, `ES'B1`, `B1;`, `B1'--`,
  `ES#1`, emoji, `ñ1`, `é`) on create and update: `ValidationException` on `tax_id`, nothing
  written.
- [ ] T-17 Non-strings (`['a']`, `123`, `true`, nested array) refused on `tax_id`, no 500, and
  nothing coerced and saved (the `is_string()` guard, FD-4).
- [ ] T-17b The `tax_id` validation message uses the translated attribute name
  (`customers.attributes.tax_id`) in `en` and in `es`, on create and on update (FD-10).
- [ ] T-17c `CustomerValidationRulesTest` (unit): `tax_id` is in `OPTIONAL_FIELDS`.
- [ ] T-17d `CustomersLangParityTest`: `lang/en/customers.php` and `lang/es/customers.php` have
  identical key sets.
- [ ] T-18 Without `customers.create` / `customers.edit`: `AuthorizationException`, nothing
  written.
- [ ] T-19 That refusal logs one `Log::warning('Privileged action refused')`, `target_type =
  customer`, no tax ID in the context; a permitted save logs no tax ID either.
- [ ] T-20 Unauthenticated update refused and logged before validation (an invalid tax ID does
  not surface as a `ValidationException`).
- [ ] T-21 Mass-assignment probe (`tax_id` with `id`, `deleted_at`): only fillable columns change.

## Expected outcome
A customer can carry an optional, normalised tax ID through the existing create and update
actions; existing screens keep working unchanged and never erase a stored tax ID.

## Acceptance criteria
- [ ] `customers.tax_id` exists as specified.
- [ ] Create and update accept, normalise and validate it with the 0100 rule (FD-1, FD-2), refuse
  non-strings without coercing them (FD-4) and allow duplicates (FD-5).
- [ ] The tax-ID normalisation and invalid datasets live once in `tests/Datasets/TaxIds.php` and
  are used by both 0100's and this story's tests.
- [ ] An update without the key keeps the value; an explicit blank clears it (FD-3).
- [ ] The existing customer gates cover the field; refusals and successes never log the value.
- [ ] Changing the tax ID touches no order row (FD-7).
- [ ] en/es `customers.attributes.tax_id` leaves exist with key parity (parity test), and
  `CreateCustomer` / `UpdateCustomer` pass them to the validator so a tax-ID error shows the
  translated field name (FD-10).

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/database/schema-other/customers.md`, the ER
  diagram in `docs/database/schema.md` (the `customers` blocks: add `customers.tax_id`),
  `docs/api/customers.md`
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0100](../done/0100-invoice-settings-backend.md) (`TaxIdValidationRules`). Downstream:
  [0100b](0100b-customer-tax-id-field-ui.md) sends the field from the form;
  [0101b](../0101b-issue-standard-invoice-backend.md) copies it into `invoices.recipient_tax_id`
  and owns the "issued invoice unchanged" test.
- Risk: the value later appears on PDFs and in mail attachments; the whitelist removes HTML/CSS
  metacharacters at the source and Blade escapes at render (0102). Flag the personal-data aspect
  to appsec for 0100b, 0101b and 0104.
- Open question recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3): Q-0100-1 tax ID format: (a) free text, whitespace removed, upper-cased, max 32,
  letters, digits and `- . /` (recommended); (b) Spanish NIF/NIE/CIF checksum validation.
- Out of scope: a warning when two customers share a tax ID (a new story if wanted); searching
  or listing by tax ID.

## Technical tasks
- [ ] Move 0100's inline tax-ID datasets into `tests/Datasets/TaxIds.php` (union) and point
  `InvoiceSettingValidationTest.php` at them; 0100's tests stay green.
- [ ] Red tests in `CustomerTaxIdTest.php`, `CustomerTaxIdAuthorizationTest.php`,
  `CustomerValidationRulesTest.php` and `CustomersLangParityTest.php`.
- [ ] Migration; `Customer` fillable and property; factory state.
- [ ] `customerTaxIdRules()`, `OPTIONAL_FIELDS` entry, `is_string()`-guarded normalisation pass.
- [ ] `attributes.tax_id` lang leaves; `attributeNames()` passed to `Validator::make()` in both
  actions.
- [ ] Extend `customerFullPayload()` (and `UpdateCustomerTest` only where it enumerates columns).
- [ ] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
- Phase 2 (INVEST) on 2026-11-17 by code-reviewer: **rejected, corrections required** (story is
  I/N/V/E/S-sound; Testable fails on spec errors): (1) T-16 lists `../x`, which the 0100 rule
  accepts (`.` and `/` allowed) — remove it; (2) FD-1 `['nullable', ...taxIdRules()]` duplicates
  `nullable` (`taxIdRules()` already returns it) — use `taxIdRules()`; (3) 0100's tax-ID datasets
  are inline in `InvoiceSettingValidationTest.php`, so sharing them needs a new
  `tests/Datasets/TaxIds.php` and an edit to that 0100 test — list both files and pin the union
  dataset; (4) `lang/{en,es}/customers.php` has no `attributes` block and neither customer action
  passes attribute names to `Validator::make()`, so either add that wiring to both actions
  (logic change, not "docblocks only") or drop the lang item and its acceptance criterion;
  (5) FD-4: no `strict_types`, so `normalizeTaxId(123)` would coerce to `"123"` — the customer
  pass must guard `is_string()` before calling it (state it in FD-4/Files). Re-run Phase 2 after.
- Corrections applied 2026-11-17 by product-owner (all five items; owner chose to wire the
  attribute names, FD-10; also added `CustomerValidationRulesTest`, the `customerFullPayload()`
  extension and the ER-diagram location); Phase 2 re-run pending.
- Phase 2 (INVEST) re-run on 2026-11-17 by code-reviewer: **approved** — all five rejection
  points verified resolved against the code (T-16/T-4b, FD-1, `tests/Datasets/TaxIds.php` + 0100
  test edit, FD-10 wiring in both actions, FD-4 `is_string()` guard). Phase 3 note (non-blocking):
  `tests/Unit/Concerns/CustomerValidationRulesTest.php` already exists and its "OPTIONAL_FIELDS
  covers exactly the nullable keys of customerRules()" test guards `tax_id` generically — extend
  that file (T-17c), do not create it; prefer `attributeNames()` returning the whole
  `__('customers.attributes')` block, exactly like `SaveInvoiceSettings::attributeNames()`.
- Phase 4 (security audit) on 2026-11-18 by appsec-auditor: **approved, no blocking findings** —
  authorize-before-normalize/validate kept in both actions; `tax_id` is fillable but only
  `$validated` reaches `create()`/`update()`; regex `^…$/D` anchored, no `m` flag, whitespace
  stripped first, `string` rule rejects non-strings (`is_string()` guard holds); tax ID absent
  from logs, refusal context and the `CustomerCreated` payload; no route/permission/seeder added.
  Low (non-blocking): `normalizeTaxId()`'s `/u` `preg_replace` returns null on invalid UTF-8, so
  such input silently becomes `null` (clears the value on update) instead of failing validation.
  Downstream flags for 0100b/0101b/0104: escape on render (`{{ }}`), never build file paths from
  `tax_id` (`../` passes the charset), guard CSV formula injection on export, gate display by
  the customer view permission, and treat any invoice snapshot as a retained PII copy.
- Phase 5 (code review) on 2026-11-18 by code-reviewer: **approved, conditional on the full suite
  being green** — every acceptance criterion, FD-1..FD-10 and T-1..T-21 traced to code and tests
  in 312723e/0d78d21; Pint unscoped clean. Non-blocking: the `attributes` block lists all 16
  customer fields, not only `tax_id` as FD-10 says, so every customer field error message now
  uses the translated name (an improvement; accept and record it, or trim it to `tax_id`); the
  Phase 4 Low (invalid UTF-8) is fixed in `TaxIdValidationRules` and covered by the shared
  invalid dataset. Phase 6 must update `docs/database/schema-other/customers.md`, the ER
  diagram in `docs/database/schema.md`, `docs/api/customers.md` and the lang-file notes.
