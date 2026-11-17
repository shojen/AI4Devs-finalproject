# [0100a] Customer tax ID (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](done/0100-invoice-settings-backend.md) (it owns the shared tax-ID rule). Dependents:
> [0100b](0100b-customer-tax-id-field-ui.md) (form field and detail line) and
> [0101b](0101b-issue-standard-invoice-backend.md) (copies the value onto each invoice).

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
  `customerTaxIdRules()` returning `['nullable', ...$this->taxIdRules()]`. Reason: issuer and
  customer formats must never drift. This is why 0100a depends on 0100.
- **FD-2 Format (same as 0100, Q-0100-1 (a)).** Before validation: all whitespace removed
  (leading, trailing and inside), ASCII-only upper-casing, `''` -> null. Then: string, max 32,
  only `A-Z`, `0-9`, `-`, `.`, `/`. So `" b-12345678 "` -> `B-12345678`, `ES B123` -> `ESB123`;
  `ES_B1`, `<b>`, `B1;`, `ES#1`, `ñ1`, emoji refused. Reason: works for non-Spanish customers and
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
  `string` rule; the normaliser only touches strings, so nothing is cast and no `TypeError`
  occurs.
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
  added to `customerRules()`; `tax_id` in `OPTIONAL_FIELDS`; a `normalizeTaxId()` pass in
  `normalizeCustomerAttributes()`.
- `app/Actions/Customers/CreateCustomer.php`, `UpdateCustomer.php` — docblocks only, if they list
  the fields (no logic change expected).
- `database/factories/CustomerFactory.php` — default `tax_id` null; state
  `withTaxId(?string $taxId = null)` producing a valid value.
- `lang/{en,es}/customers.php` — `attributes.tax_id` leaf for validation messages (the form label
  belongs to 0100b).
- `tests/Feature/Customers/{CreateCustomerTest, UpdateCustomerTest, PersistenceTest}.php` — extend
  only where they enumerate every column.
- New `tests/Feature/Customers/CustomerTaxIdTest.php` and
  `tests/Feature/Customers/CustomerTaxIdAuthorizationTest.php`.

## Tests to perform
Real `CreateCustomer` / `UpdateCustomer`, real `RolePermissionSeeder`, `Log::spy()`. The
normalisation and invalid-value datasets are the **same** as story 0100's tax-ID datasets
(share one dataset file or definition) so the two stories cannot drift.

Happy path
- [ ] T-1 Create with `B-12345678` stores it.
- [ ] T-2 Update sets a tax ID; every other column unchanged.
- [ ] T-3 Create without the key stores null.
- [ ] T-4 Normalisation dataset (create and update): `" b-12345678 "` -> `B-12345678`,
  `x.1/2-y` -> `X.1/2-Y`, `es b 123` -> `ESB123`, `"B1\n2"` -> `B12`; normalising twice equals once.
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
- [ ] T-16 Invalid dataset (`ES_B1`, `<b>`, `B1;`, `B1'--`, `../x`, `ES#1`, emoji) on create and
  update: `ValidationException` on `tax_id`, nothing written.
- [ ] T-17 Non-strings (`['a']`, `123`, `true`, nested array) refused on `tax_id`, no 500.
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
  non-strings (FD-4) and allow duplicates (FD-5).
- [ ] An update without the key keeps the value; an explicit blank clears it (FD-3).
- [ ] The existing customer gates cover the field; refusals and successes never log the value.
- [ ] Changing the tax ID touches no order row (FD-7).
- [ ] en/es `attributes.tax_id` leaves exist with key parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/database/schema-other/customers.md`, the ER
  diagram (`customers.tax_id`), `docs/api/customers.md`
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0100](done/0100-invoice-settings-backend.md) (`TaxIdValidationRules`). Downstream:
  [0100b](0100b-customer-tax-id-field-ui.md) sends the field from the form;
  [0101b](0101b-issue-standard-invoice-backend.md) copies it into `invoices.recipient_tax_id`
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
- [ ] Red tests in `CustomerTaxIdTest.php` and `CustomerTaxIdAuthorizationTest.php`.
- [ ] Migration; `Customer` fillable and property; factory state.
- [ ] `customerTaxIdRules()`, `OPTIONAL_FIELDS` entry, normalisation pass.
- [ ] Extend column-enumerating tests; lang leaves with parity.
- [ ] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
