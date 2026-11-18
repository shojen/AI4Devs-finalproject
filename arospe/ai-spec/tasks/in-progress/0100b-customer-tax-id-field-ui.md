# [0100b] Customer tax ID field in the customer form and detail (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100a](../done/0100a-customer-tax-id-backend.md) (column, rule, normaliser). No dependents; the
> value it lets managers enter is copied onto invoices by
> [0101b](../0101b-issue-standard-invoice-backend.md).

## Description
The customer create/edit modal (`Customers\Index`) gains an optional "Tax ID" field, and the
customer detail (`Customers\Show`) shows the stored tax ID or "Not provided". The story also
repairs a confirmed defect in the same `save()` method: validation errors of every multi-word
field are dropped instead of shown under their field (FD-1). No route, permission, migration,
JS or CSS.

Request context (owner, 2026-11-14): invoices are sent and created for a customer's orders
(request items 2 and 5: "sends the invoice", "create invoices for an order"); the recipient's
tax ID must be recorded somewhere a manager can enter it.

Binding owner decision:
- U-5 (owner decision, 2026-11-14): "invoice settings hold the issuer's legal name, tax ID and
  fiscal address. Customers gain an optional tax ID printed on the invoice."

## Type
frontend (related_task_id: 0100a) | includes database-expert: no

## Verified facts that shaped the story (2026-11-15)
- `App\Livewire\Customers\Index` view is `resources/views/livewire/customers.blade.php` (the
  "component named Index flattens to its parent folder" exception). Form state is fifteen plain
  `public string` camelCase properties listed in `private const FORM_FIELDS`;
  `openCreateModal()` / `closeModal()` `reset()` them; `$editingCustomerId` is `#[Locked]`.
- `openEditModal(string $customerId)` authorizes `update` on the loaded customer before copying
  any value into state. `save(CreateCustomer, UpdateCustomer)` authorizes (`create` or
  `update` on the freshly loaded target), builds `formAttributes()` (camelCase -> snake_case by
  hand), runs `normalizeCustomerAttributes()`, then
  `Validator::make($attributes, $this->customerRules($this->editingCustomerId))->validate()`,
  then the action.
- **Defect:** that validator raises errors on snake_case keys (`billing_country`,
  `shipping_city`, ...). Livewire keeps an error only when its key is a declared public
  property, so every multi-word field's error is lost: the save is refused but no message
  appears, although the view already renders `<flux:error name="shippingCountry" />` and
  `name="billingCountry"`. `name`, `email`, `phone` are unaffected (same key in both shapes).
  No existing test asserts a multi-word field error. Precedent for the fix:
  `App\Livewire\BlogPosts\Editor::save()` catches the `ValidationException` and re-keys it.
- `Customers\Show` is read-only (reflection test pins it); `customer()` is a `#[Computed]`
  re-read on every render; `mount()` authorizes `viewAny` (`customers.view`). The header
  `data-test="customer-detail-header"` lists Email and Phone (`phone ?? '—'`).
- Gates (`CustomerPolicy`): `customers.view`, `customers.create`, `customers.edit`,
  `customers.delete`. No customer screen logs refusals (story 0044 D-7).

## Backend contract used (from 0100a)
- `CreateCustomer::__invoke(array $attributes): Customer` and
  `UpdateCustomer::__invoke(Customer $customer, array $attributes): Customer` accept
  `tax_id`. Update: key absent keeps the stored value; `''` clears it (0100a FD-3).
- `CustomerValidationRules::customerRules()` includes `tax_id` (`customerTaxIdRules()`);
  `tax_id` is in `OPTIONAL_FIELDS`; `normalizeCustomerAttributes()` removes all whitespace,
  ASCII-upper-cases, `''` -> null. Rule: string, max 32, only `A-Z 0-9 - . /` (0100a FD-2).
- `Customer::$tax_id` (`string|null`), fillable; factory state `withTaxId(string $taxId = 'B-12345678')`.
- `lang/{en,es}/customers.php` leaf `attributes.tax_id` (shipped by 0100a).

## Component changes
`Customers\Index`:
- `public string $taxId = '';` declared after `$phone`; `'taxId'` added to `FORM_FIELDS`
  (docblocks "fifteen" -> "sixteen").
- `openEditModal()`: `$this->taxId = $customer->tax_id ?? '';` after the existing authorize.
- `formAttributes()`: `'tax_id' => $this->taxId` on **every** save, create and edit (FD-2).
- `save()`: the `->validate()` call is wrapped; a `ValidationException` is rethrown through a
  new `private function rekeyErrorsToProperties(ValidationException $exception): never` whose
  keys are `Str::camel($key)` (`tax_id` -> `taxId`, `billing_country` -> `billingCountry`), so
  every one of the sixteen fields shows its own error (FD-1). The validator receives the
  whole `__('customers.attributes')` array as attribute names, exactly as `CreateCustomer` /
  `UpdateCustomer` do (0100a FD-10), so its messages read the same as the actions' (FD-6).
- No client-side normalising, upper-casing or `maxlength` (FD-3).

`Customers\Show`: no PHP change (FD-7).

## View changes and hooks
- Modal, first `space-y-4` block, after Phone:
  `<flux:input wire:model="taxId" :label="__('customers.form.tax_id')" :description="__('customers.form.tax_id_hint')" autocomplete="off" data-test="customer-tax-id-input" />`
  — not `required`; Flux renders the bound `taxId` error itself.
- Detail header, after Phone:
  `<flux:text data-test="customer-tax-id"><span class="font-medium">{{ __('customers.detail.tax_id') }}:</span> {{ $this->customer->tax_id ?? __('customers.detail.tax_id_not_provided') }}</flux:text>`
  — `{{ }}` only, never `{!! !!}`.
- Existing hooks reused: `customer-modal`, `save-customer-button`, `edit-customer-{id}`,
  `create-customer`, `customer-detail-header`.
- No list column (FD-8). Single-column stack, full width at phone size; Flux handles dark mode;
  no new Tailwind classes.

## Lang keys (`lang/en/customers.php` / `lang/es/customers.php`, key parity)
| Key | en | es |
| --- | --- | --- |
| `form.tax_id` | Tax ID | Identificador fiscal |
| `form.tax_id_hint` | Optional. Printed on the customer's invoices. Letters, digits and - . / only; spaces are removed. | Opcional. Se imprime en las facturas del cliente. Solo letras, dígitos y - . /; los espacios se eliminan. |
| `detail.tax_id` | Tax ID | Identificador fiscal |
| `detail.tax_id_not_provided` | Not provided | No indicado |

## Decisions
- **FD-1 The error-key defect is fixed in this story for all sixteen fields.** Reason: the new
  field cannot show its error without a re-key, and one generic `Str::camel` re-key in `save()`
  repairs the twelve broken address/country fields at no extra cost; a tax-ID-only mapping
  would be more code and would knowingly leave the bug in the same method. Regression tests pin
  the tax ID and both country fields (the two with an explicit `flux:error` today). **Follow-up
  (not in scope):** the other components that call `Validator::make()` themselves
  (`Shipping\Index`, `Shipping\Zones`, `Products\Editor`, `Settings\Language`,
  `Settings\LanguageSwitcher`) are not verified for the same mismatch — an audit story is
  proposed to the owner, and docs-keeper records the pitfall in `docs/errors-log.md` (Phase 6).
- **FD-2 The key is always sent.** `formAttributes()` sends `tax_id` even when blank, because
  0100a FD-3 clears only on an explicit blank and this modal is the only caller that can clear
  it. Rejected: send only when non-empty (a tax ID could never be removed). The loaded value
  round-trips, so an edit of another field never erases it.
- **FD-3 No client-side formatting.** The 0100a normaliser is the single source of the format;
  a `maxlength` would refuse valid input (32 counted after whitespace removal), and Alpine
  upper-casing could drift from the server. The modal closes on save; the normalised value is
  what the detail and the next edit show.
- **FD-4 Spanish label "Identificador fiscal"** (facilitator decision). Reason: the field is not
  Spanish-only (Q-0100-1 (a): free format for foreign customers), so "NIF" alone would be wrong
  for them; the hint explains the format. Recorded for the owner as Q-0100b-1.
- **FD-5 Placeholder words, not a dash.** "Not provided" / "No indicado" (BR-0100b-2 says "not
  provided"); a "—" next to Phone's own "—" reads as missing data of either line.
- **FD-6 Validation messages use the shipped attribute names.** The component's own
  `Validator::make()` receives the full `__('customers.attributes')` array (as the actions do), so
  the `tax_id` message names the field with the shipped leaf: `tax ID` (en) / `NIF/CIF` (es).
  The es leaf is not changed here (0100a's lang file stays untouched), so an es error reads
  "NIF/CIF" next to the "Identificador fiscal" label (FD-4). Recorded for the owner as Q-0100b-1
  (label and leaf wording). Other fields keep today's wording (out of scope).
- **FD-7 No `Show` PHP change.** `customer()` already re-reads the model each render.
- **FD-8 No list column, no search by tax ID.** Not requested; adds width on phones.
- **FD-9 Visibility follows the existing contact-data gate** (QA gap G-3, facilitator
  decision). The detail shows the tax ID to every `customers.view` holder, exactly as it shows
  email, phone and addresses; the edit modal discloses it only after `update` is authorized.
  Reason: it is personal data of the same class as data the gate already discloses, and a
  separate permission was not asked for. Recorded for the owner as Q-0100b-2; flagged to
  appsec-auditor (Phase 4).
- **FD-10 The view-only save refusal is tested on the real path** (QA gap G-2). A view-only
  actor cannot reach the edit branch of `save()` directly (`$editingCustomerId` is locked and
  `openEditModal()` refuses them). Test mechanics: an actor holding `customers.view` and
  `customers.edit` opens the edit modal and sets `taxId`; then `customers.edit` is revoked
  (`$actor->revokePermissionTo('customers.edit')` plus
  `app(PermissionRegistrar::class)->forgetCachedPermissions()`) and `save` is called on the same
  `Livewire::test()` instance: 403, tax ID unchanged. The create branch is tested separately: a
  view-only actor calling `save` with no editing id gets 403 and no customer is written.
  Rejected: `set('editingCustomerId', ...)` (refused by `#[Locked]`, so it tests the lock, not
  the gate).
- **FD-11 Personal data never logged.** The component adds no log call; Laravel's messages do
  not echo the input (0100a FD-8).

## Gherkin
```gherkin
Feature: Customer tax ID in the customer form and detail

  Scenario: A store manager records a customer's tax ID
    Given a store manager with permission to edit customers, and a customer without a tax ID
    When she saves the customer form with the tax ID " b-12345678 "
    Then the customer detail shows the tax ID "B-12345678"

  Scenario: A store manager sees the stored tax ID when editing
    Given a store manager with permission to edit customers, and a customer with the tax ID "B-12345678"
    When she opens the customer for editing
    Then the form shows the tax ID "B-12345678"

  Scenario: Editing another field keeps the tax ID
    Given a store manager with permission to edit customers, and a customer with the tax ID "B-12345678"
    When she saves the form with only a new name
    Then the customer still has the tax ID "B-12345678"

  Scenario: A store manager removes a customer's tax ID
    Given a store manager with permission to edit customers, and a customer with the tax ID "B-12345678"
    When she saves the form with the tax ID emptied
    Then the customer detail shows "Not provided" for the tax ID

  Scenario: The tax ID is optional when creating a customer
    Given a store manager with permission to create customers
    When she creates a customer and leaves the tax ID empty
    Then the customer is saved without a tax ID

  Scenario Outline: A malformed tax ID is refused next to the field
    Given a store manager with permission to edit customers, and a customer with the tax ID "B-12345678"
    When she saves the form with the tax ID "<value>"
    Then the form stays open with an error under the tax ID field
    And the customer keeps the tax ID "B-12345678"

    Examples:
      | value |
      | ES_B1 |
      | B1;   |
      | <b>   |

  Scenario: A malformed country code is refused next to its field
    Given a store manager with permission to edit customers, and an existing customer
    When she saves the form with the billing country "ESP"
    Then the form stays open with an error under the billing country field

  Scenario: A previous customer's tax ID does not leak into a new customer
    Given a store manager with permission to create and edit customers, who has just edited a customer with a tax ID
    When she opens the new customer form
    Then the tax ID field is empty

  Scenario: A customer with no tax ID shows a placeholder
    Given a store manager with permission to view customers, and a customer without a tax ID
    When she opens the customer detail
    Then the detail shows "Not provided" for the tax ID

  Scenario: A viewer cannot change a tax ID
    Given a sales assistant who may view but not edit customers
    When she tries to save a new tax ID for a customer
    Then the change is refused
    And the customer's tax ID stays as it was
```

## Files to create/modify
- `app/Livewire/Customers/Index.php` — `$taxId`, `FORM_FIELDS`, `openEditModal()`,
  `formAttributes()`, `save()` re-key and attribute name, `rekeyErrorsToProperties()`,
  docblocks.
- `resources/views/livewire/customers.blade.php` — tax ID input.
- `resources/views/livewire/customers/show.blade.php` — tax ID line.
- `lang/en/customers.php`, `lang/es/customers.php` — the four keys above.
- New `tests/Feature/Customers/IndexTaxIdTest.php` (Livewire component cases).
- `tests/Feature/Customers/IndexRenderingTest.php` — the enumerated field list gains `taxId`.
- `tests/Feature/Customers/ShowRenderingTest.php` — line, placeholder, escaping.
- `tests/Browser/Customers/CustomersScreenTest.php`, `CustomerDetailTest.php` — one journey each;
  the "pre-fills every field" test gains `taxId`.

## Tests to perform
Real `RolePermissionSeeder`, real actions, factories (`withTaxId()`).

Component level (`Livewire::test`)
- [ ] T-1 `openEditModal` on a customer with `B-12345678` sets `taxId`; a null tax ID gives `''`.
- [ ] T-2 Create with `taxId = ' b-12345678 '` stores `B-12345678`.
- [ ] T-3 Edit with `taxId = ''` stores null (pins FD-2).
- [ ] T-4 Edit changing only the name leaves the tax ID unchanged.
- [ ] T-5 Create without touching `taxId` stores null, not `''`.
- [ ] T-6 After editing a customer with a tax ID, `openCreateModal` and `closeModal` leave
  `taxId = ''`.
- [ ] T-7 Creating with a tax ID another customer already has succeeds.
- [ ] T-8 Dataset `ES_B1`, `B1;`, `<b>`, `B1'--`, a 33-character value: `assertHasErrors(['taxId'])`,
  no error on any other field, nothing written. Red until FD-1 lands.
- [ ] T-9 The refusal message is rendered inside the modal and contains `__('customers.attributes.tax_id')` in each locale (en `tax ID`, es `NIF/CIF`), as 0100a's T-17b asserts.
- [ ] T-10 Bad `billingCountry` and bad `shippingCountry` produce errors on `billingCountry` /
  `shippingCountry` (FD-1 regression).
- [ ] T-11 Invalid tax ID plus invalid email: both `email` and `taxId` carry errors.
- [ ] T-12 View-only edit refusal per FD-10: 403, tax ID unchanged.
- [ ] T-13 View-only create refusal: `save` with no editing id gives 403, no customer written.
- [ ] T-14 View-only `openEditModal`: 403 and `taxId` stays `''` (value never disclosed).
- [ ] T-15 `set('taxId', ['a'])` is refused by the `string` typed property: the test asserts the
  thrown type error and that no customer is written (the exact exception class is pinned when the
  red test is written; no integer case).
- [ ] T-16 `set('editingCustomerId', $other)` refused by `#[Locked]`; the other customer unchanged.
- [ ] T-17 Show: `customer-tax-id` holds label and value; null shows "Not provided".
- [ ] T-18 Show: a stored `<b>x</b>` written by a raw `DB::table()` update renders escaped
  (`assertDontSeeHtml('<b>x</b>')`).
- [ ] T-19 Show without `customers.view`: 403, tax ID absent from the response.
- [ ] T-20 en and es: the four keys resolve in modal and detail (no raw key shown); key parity is
  already covered by the existing `CustomersLangParityTest.php` (no new parity test).

Browser level (Pest 4, bounded waits before database assertions, `assertNoJavaScriptErrors`)
- [ ] B-1 Edit a customer, `fill('taxId', ' b-12345678 ')`, save; the modal closes; the detail's
  `customer-tax-id` shows `B-12345678`.
- [ ] B-2 Fill `ES_B1`, save: `customer-modal` stays open with the error visible; correct it,
  save, success; row changed only after the second save.
- [ ] B-3 Edit shows `assertValue('taxId', 'B-12345678')`; clear, save, reopen: `''`.
- [ ] B-4 Detail with and without a tax ID: value or "Not provided".
- [ ] B-5 One combined case: phone viewport, `customer-tax-id-input` visible and full width in
  the scrolling modal, error readable in dark mode.

## Expected outcome
A manager can add, change and remove a customer's tax ID from the existing modal and sees it
on the detail page; every field of the customer form, the tax ID included, shows its own
validation error.

## Acceptance criteria
- [ ] The modal shows an optional tax ID field that prefills on edit, resets on create, and is
  always sent on save (FD-2).
- [ ] A refused tax ID shows its message under the field, naming it with `__('customers.attributes.tax_id')`; nothing is written.
- [ ] Errors of every multi-word field, both country fields at least proven by test, appear
  under their own field (FD-1).
- [ ] The detail shows the tax ID or "Not provided", escaped.
- [ ] View-only actors cannot read the value through the modal nor change it (FD-10); the
  detail shows it under `customers.view` (FD-9).
- [ ] en/es keys exist with parity; no new permission, route or migration.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor) — personal-data visibility (FD-9) reviewed
- [x] Documentation updated (docs-keeper): `docs/api/customers.md` (the modal field, the detail
  line, the error re-key), `docs/errors-log.md` (the snake_case error-key pitfall and the
  proposed audit)
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream: [0100a](../done/0100a-customer-tax-id-backend.md). Without it the validator has no
  `tax_id` rule and the actions drop the key silently, so Phase 3 starts only after 0100a is in
  `done/`. Downstream use of the value: [0101b](../0101b-issue-standard-invoice-backend.md)
  (snapshot) and [0102](../0102-invoice-pdf-rendering-and-templates-backend.md) (printed).
- Risk: tests that enumerate the fifteen form fields (`IndexRenderingTest`, the browser
  "pre-fills every field" test) must be updated, not loosened.
- Risk: the re-key changes which keys the error bag carries; any existing test asserting a
  snake_case key on `Customers\Index` would turn red (none found on 2026-11-15).
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0100b-1 Spanish label: (a) "Identificador fiscal" (recommended: correct for Spanish and
    foreign customers); (b) "NIF/CIF"; (c) "NIF/CIF/VAT".
  - Q-0100b-2 Who sees the tax ID on the detail: (a) every `customers.view` holder, like email
    and phone (recommended: same data class, no new permission); (b) only `customers.edit`
    holders.
  - Q-0100b-3 Follow-up audit of the other `Validator::make()` components for the same
    error-key defect: (a) a separate small story (recommended: outside this story's screen);
    (b) not wanted.
- Out of scope: a tax ID column or search in the customers list; a duplicate-tax-ID warning.

## Technical tasks
- [ ] Red tests: `IndexTaxIdTest.php`, `ShowRenderingTest.php` additions, browser journeys.
- [ ] `Customers\Index` property, `FORM_FIELDS`, prefill, `formAttributes()`, re-key and
  attribute name.
- [ ] Modal input and detail line; lang keys en/es.
- [ ] Update field-enumerating tests.
- [ ] Pint, Larastan, targeted then full suite; hand the docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa. Phase 2 (INVEST) pending.
- Story text corrected 2026-11-18 (FD-6 real attribute names, `withTaxId` signature, T-9, T-15, T-20, country scenario Given); Phase 2 re-run requested.
- Phase 2 (INVEST) **rejected** on 2026-11-18 by code-reviewer — return to product-owner for the
  fixes below, then re-run Phase 2. INVEST otherwise passes (Independent: 0100a is merged, no
  other blocker; Negotiable; Valuable; Estimable; Small: one screen pair; Testable). Gherkin
  follows rules 1 and 3 (named role actor, single `When`). Verified against the code: the
  `Customers\Index` facts (fifteen `public string` fields, `FORM_FIELDS`, `#[Locked]`
  `$editingCustomerId`, authorize-then-copy in `openEditModal()`, `save()` order, unwrapped
  `Validator::make(...)->validate()` with snake_case keys, explicit `flux:error` only on
  `shippingCountry`/`billingCountry`), the `Show` facts (`viewAny` in `mount()`, `#[Computed]`
  `customer()`, header Email/Phone with `?? '—'`), the `BlogPosts\Editor` re-key precedent, and
  0100a's trait/normaliser/`OPTIONAL_FIELDS`/model all match. Required changes:
  1. **FD-6 contradicts the shipped code.** The `customers.attributes.tax_id` leaves are
     `'tax ID'` (en) and `'NIF/CIF'` (es), not "Tax ID" / "Identificador fiscal"; and 0100a FD-10
     already passes the **whole** `__('customers.attributes')` array to `Validator::make()` in
     `CreateCustomer`/`UpdateCustomer` (`attributeNames()`), so "other fields keep today's
     wording" would leave the component's messages inconsistent with the actions'. Rewrite FD-6
     to pass the full attributes array (same shape as the actions), state the real resulting
     wording, and either align the es leaf with FD-4's label (a 0100a lang change this story then
     owns, listed in Files) or accept "NIF/CIF" in messages next to an "Identificador fiscal"
     label and say so. Update T-9 and the acceptance criterion "naming 'Tax ID'" to assert
     `__('customers.attributes.tax_id')` per locale, as 0100a's T-17b does.
  2. **Factory fact is wrong.** The state is `withTaxId(string $taxId = 'B-12345678')`, not
     `withTaxId(?string $taxId = null)` — fix the Backend contract line (null tax ID = plain
     factory).
  3. **T-15 has no assertable outcome.** "Refused by the typed property ... no 500" does not say
     what the test asserts (which exception `Livewire::test()->set()` raises for an array/int on a
     `string` property, or that the state is unchanged). State the expected exception/assertion,
     or drop the "no 500" claim.
  Non-blocking: scenario "A malformed country code ..." has no customer in its `Given` (add
  "and a customer"); `CustomersLangParityTest.php` already exists — name it under T-20 instead of
  implying a new parity test.
- Phase 2 (INVEST) re-run **approved** on 2026-11-18 by code-reviewer. Every required change
  verified against the code: (1) FD-6 now passes the full `__('customers.attributes')` array,
  matching `CreateCustomer::attributeNames()` / `UpdateCustomer::attributeNames()`, and states
  the real leaves (`tax ID` en / `NIF/CIF` es, confirmed in `lang/{en,es}/customers.php`), with
  the es label/leaf mismatch accepted and routed to Q-0100b-1; T-9 and the acceptance criterion
  assert `__('customers.attributes.tax_id')` per locale as 0100a T-17b does. (2) Backend contract
  reads `withTaxId(string $taxId = 'B-12345678')`, matching `CustomerFactory`. (3) T-15 now
  asserts the thrown type error and that no customer is written. Non-blocking items also done:
  the country scenario's `Given` has an existing customer; T-20 names the existing
  `tests/Feature/Customers/CustomersLangParityTest.php` (recursive `Arr::dot` key parity, so it
  covers the four new keys). No new contradiction with the code found; the files listed under
  "Files to create/modify" exist. INVEST passes; ready for Phase 3. Cosmetic only: the
  "Story text corrected" record sits above the rejection it answers.
- Phase 3 (TDD) complete on 2026-11-18 by frontend-expert. Red first: `IndexTaxIdTest`, `ShowRenderingTest` and
  `IndexRenderingTest` additions failed on the missing `taxId` property / view lines / unfixed error
  keys (14 red). Green: `tests/Feature/Customers` 241 passed (604 assertions); `tests/Browser/Customers`
  18 passed (133 assertions; the browser journeys were written with the implementation, not red first);
  Pint unscoped clean (one import fix in the browser test), Larastan level 7 clean on
  `Customers\Index`. T-15 asserts `TypeError`; T-16 asserts
  `Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException`. Full suite not run
  (Phase 5).
- Phase 4 (security audit) **approved** on 2026-11-18 by appsec-auditor — no vulnerabilities. F-1 (Informational, owner decision, = FD-9 / Q-0100b-2): the tax ID of a natural person (NIF = DNI) is shown to every `customers.view` holder; acceptable for the MVP if the owner confirms, otherwise gate the detail line behind `update`. F-2 (Informational): form values stay in the Livewire snapshot after dismissing the modal with Escape (same as email/phone/address today); optional `updatedShowModal(false)` reset. F-3 (Informational): no raw-input cap before normalising; negligible. No code change required.
- Phase 5 (final review) **approved after one fix** on 2026-11-18 by code-reviewer: browser journeys B-2/B-5 asserted the attribute name, which matches the field label before saving (case-insensitive `assertSee`), so they could not fail; now they assert `__('validation.regex', [...])` (fixed, test commit). Full suite green in three chunks (Unit + Feature part 1: 1954 passed; Feature part 2: 4525 passed, 5 skipped; Browser: 330 passed, 3 skipped; skips pre-existing), Pint unscoped and Larastan level 7 (whole project) clean. Non-blocking follow-ups: B-5 does not assert full width; `attributeNames()` is now duplicated in `Customers\Index`, `CreateCustomer` and `UpdateCustomer` (candidate for `CustomerValidationRules`).
- Phase 6 (documentation) complete on 2026-11-18 by docs-keeper: `docs/api/customers.md` (new section: modal field, always-sent key, detail line, error re-key, attribute names) and `docs/errors-log.md` plus its 2026-09-25 to 2026-10-02 part (new entry on the snake_case error-key pitfall in components that call `Validator::make()` themselves, with the proposed audit of `Shipping\Index`, `Shipping\Zones`, `Products\Editor`, `Settings\Language`, `Settings\LanguageSwitcher`; topic index and hub row updated). `docs/README.md` needs no change (no row changed). No stage move or dependency edit, so `tasks-map.md` / `tasks-status.json` are unchanged. The parent `../readme.md` has no per-field customer detail to update.
