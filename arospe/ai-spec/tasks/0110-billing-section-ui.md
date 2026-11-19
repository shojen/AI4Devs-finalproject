# [0110] Billing section: invoice list, filters, send and create invoice (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0101b](done/0101b-issue-standard-invoice-backend.md) (`IssueInvoice`, `ComputeOrderInvoiceBalance`,
> amount rules, `InvoicePolicy`), [0102](done/0102-invoice-pdf-rendering-and-templates-backend.md)
> (`invoices.download`), [0104](in-progress/0104-send-invoice-by-email-backend.md) (`SendInvoice`),
> [0106](0106-invoice-collection-order-debt-partially-paid-backend.md) (collected state, debt,
> legacy rule), [0109a](0109a-orders-list-invoice-send-ui.md) (`DescribeOrderInvoicing`, send dialog
> copy) and [0109b](0109b-order-detail-invoices-panel-ui.md) (shared invoice badges). Dependent:
> [0110a](0110a-billing-collect-and-undo-ui.md) (collect and undo from the same list).

## Description
A "Billing" screen in the sidebar lists every invoice and corrective invoice, newest first, with
filters that survive a reload, pagination, a download link and a send action per row, and a
"Create invoice" dialog that picks an order still to be invoiced and issues an invoice for the
whole uninvoiced amount or a part of it, never above what is left. Collecting and undoing a
collection from this list are 0110a.

Request item served (owner, 2026-11-14, translated):
> 5. A sidebar entry "Facturación" (Billing) opens a screen listing invoices. The manager can also
> create invoices for an order: a 1000 EUR order may have five 200 EUR invoices. The manager can
> keep issuing invoices until the order's debt is 0.

Binding decisions:
- U-7 (owner decision, 2026-11-15): an invoice for the whole order copies its lines; a partial
  invoice has one line "Payment on account of order N" (the dialog says so).
- U-2 (owner decision, 2026-11-14) as made precise in 0101b: issuing is capped by the **uninvoiced**
  amount; once nothing is left to invoice, remaining debt is settled by collecting (0110a).
- Accepted default (owner, 2026-11-15; Q-0110-1 (a), overturnable): an order paid before invoicing
  existed can be invoiced only for its whole amount, and that invoice is born collected.

## Type
frontend | includes database-expert: no

## Verified facts that shaped the story
- `config/modules.php` items: `orders` is a bare top-level item (`group` null, `cluster` null,
  `permissions` `['orders.view']`, equal to the route's `can:`); declaration order is render order.
- `<x-money>` prints the decimal string as given (no cast, no sign handling).
- List screens reset to page 1 by hand on every filter change (`Livewire\BlogPosts\Index`); the
  dashboard `SalesOverview` is the `#[Url]` pattern; Livewire 4 swallows validation exceptions
  thrown in `updating*`, so filters are sanitised in `updated*`.
- `routes/invoices.php` exists from 0102 (`invoices.download`); components must never reference
  `IssueInvoiceForOrder` (0101b architecture test).

## Backend contracts used
- `IssueInvoice::__invoke(Order $order, ?string $amount = null): Invoice` (0101b); refusals keyed
  `amount` (range, ceiling, legacy whole-amount rule), `issuer` (`invoices.errors.issuer_incomplete`),
  `number` (numbering collision) and `invoice` (cancelled, out of date, nothing left, customer
  problems). Amount: dot-decimal, at most two decimals.
- `ComputeOrderInvoiceBalance` (0101b/0106): billable, net invoiced, uninvoiced, net collected,
  debt, `isLegacy`.
- `SendInvoice::__invoke(Invoice $invoice): InvoiceDelivery` (0104), refusals keyed `invoice`.
- `invoices.download` route (0102); `PeekNextInvoiceNumber` (0101a, needs `invoice-settings.view`).
- `DescribeOrderInvoicing::forInvoices(Collection|array $invoices)` (0109a): per invoice `canSend`,
  `sendReasonKey`, `canCollect`, `collectReasonKey`, `canUndo`, `undoReasonKey`, `lastDelivery`; at
  most 4 queries; accepts models or ids.
- Permissions: `invoices.view` (screen), `invoices.create` (dialog), `invoices.send` (send).

## Backend classes owned by this story (pure reads)
- `App\Actions\Invoices\ListInvoices::__invoke(array $filters = [], int $perPage = 25):
  Illuminate\Contracts\Pagination\LengthAwarePaginator` of rows `array{id: string, number: string,
  type: InvoiceType, issuedAt: CarbonImmutable, orderId: string, orderNumber: string,
  recipientName: string, total: string, remaining: string, state: 'live'|'voided'|'corrective',
  collected: bool, lastDeliveryStatus: ?InvoiceDeliveryStatus, lastDeliveryFailureReason:
  ?string}`; logged `authorize('viewAny', Invoice::class)`. Filters `type`, `state`, `collected`,
  `issued_from`, `issued_to` (inclusive for the user; half-open internally in the app timezone,
  same year bounds as the dashboard), `search` (max 100; matches the number prefix, the order
  number and the recipient name with `%`, `_`, `\` escaped). `state` lists standard invoices only;
  `collected` matches a corrective by the collected state of the invoice it corrects (FD-4). Order
  `issued_at desc, id desc`. Budget: count + page + one eager batch (remaining, last delivery).
- `App\Actions\Invoices\FindInvoiceableOrders::__invoke(string $search = '', int $limit = 20):
  list<array{id: string, orderNumber: string, customerName: string, total: string, refunded: string,
  billable: string, netInvoiced: string, uninvoiced: string, collected: string, debt: string,
  isLegacy: bool}>`; logged `authorize('create', Invoice::class)`; eligible = not cancelled, not out
  of date, uninvoiced > 0; search by order-number prefix or customer name; limit capped at 20.
- `App\Concerns\InvoiceFilterValidationRules::invoiceFilterRules(): array` (shared by
  `ListInvoices` and the component).

## Component: `App\Livewire\Invoices\Index` (`#[Title('Billing')]`)
- Route `Route::livewire('invoices', InvoicesIndex::class)->middleware(['can:invoices.view'])
  ->name('invoices.index')` in `routes/invoices.php`, before the `{invoice}` routes. Sidebar item
  `invoices` (bare, right after `orders`): icon `receipt-percent`, route `invoices.index`,
  `current_when` `invoices.index`, `permissions` `['invoices.view']`; label `navigation.items.invoices`
  = "Billing" / "Facturación".
- `mount()`: `Gate::authorize('viewAny', Invoice::class)`, then `sanitizeFilters()`.
- `#[Url]` state, plain strings with defaults omitted: `type` (`type`), `state` (`state`),
  `collected` (`collected`: '' | yes | no), `issuedFrom` (`from`), `issuedTo` (`to`), `search` (`q`);
  page through `WithPagination`; page size constant 25. `sanitizeFilters()` validates with
  `invoiceFilterRules()` and resets any failing filter to its default (a bad link never throws);
  every `updated*()` sanitises and resets to page 1. A `to` before `from` shows a field error under
  the date and leaves the list unchanged.
- Computed (memoised): `invoices` (`ListInvoices`), `rowStates`
  (`DescribeOrderInvoicing::forInvoices` with the page's ids), `canCreateInvoice`,
  `canSendInvoices`, `canViewOrders`, `canViewSettings` (all `allowsSafely`).
- Send: `confirmSendInvoice(string $invoiceId)` stores `#[Locked] ?string $pendingInvoiceId` after
  `Invoice::findOrFail`; `sendInvoice(SendInvoice $sendInvoice)` calls the action unconditionally;
  refusals become a toast with the first message; success toast names original or copy.
- Create: `openCreateModal()`; `string $orderSearch` (max 100, `.live.debounce.300ms`) feeding
  computed `orderResults` (`FindInvoiceableOrders`, only while the modal is open);
  `selectOrder(string $orderId)` accepts the id only if it is among the rows the server lists now
  (else no-op) and sets `#[Locked] ?string $selectedOrderId`; computed `selectedOrder` re-reads the
  order and its `ComputeOrderInvoiceBalance` on every render; `string $amountMode` ('whole' |
  'partial'), `string $amount`; `issueInvoice(IssueInvoice $issueInvoice)` re-resolves the order
  from the locked id and passes `null` (whole) or the normalised amount (FD-3); dismissers
  `closeCreateModal()`, `cancelSend()`.
- Mutating surface (pinned by `IndexMutationSurfaceTest`): `confirmSendInvoice`, `sendInvoice`,
  `openCreateModal`, `selectOrder`, `issueInvoice` and the dismissers. 0110a extends the allow-list.

## View and `data-test` hooks
| Hook | Element |
| --- | --- |
| `create-invoice` | header "Create invoice" button (disabled with a tooltip without `invoices.create`) |
| `filter-search`, `filter-type`, `filter-state`, `filter-collected`, `filter-from`, `filter-to`, `clear-filters` | filter bar |
| `invoice-row-{id}`, `invoice-number-{id}` | a row (table and mobile card) and its number |
| `download-invoice-{id}`, `send-invoice-{id}` | row actions |
| `invoice-row-actions-slot-{id}` | empty slot 0110a fills with collect and undo |
| `send-invoice-dialog`, `send-invoice-confirm` | send confirmation |
| `invoices-empty`, `invoices-no-results` | empty states |
| `create-invoice-modal` | the dialog |
| `invoice-order-search`, `invoice-order-option-{id}`, `invoice-order-refine`, `invoice-order-none` | order picker (search, a result button, the "first 20" note, no result) |
| `invoice-selected-order`, `invoice-change-order` | the selected order's figures and "Change order" |
| `invoice-mode-whole`, `invoice-mode-partial` | amount mode radios |
| `invoice-amount`, `invoice-amount-max`, `invoice-amount-error` | partial amount input, its maximum, its error |
| `invoice-next-number` | "Next number" hint (only with `invoice-settings.view`) |
| `invoice-create-error` | callout for `invoice`, `issuer` and `number` errors |
| `issue-invoice-submit` | submit |

Columns: number (mono), type (`<x-invoice.type-badge :type />`), issue date (`d/m/Y`), order (link
to `orders.show` with `orders.view`, plain text otherwise), customer (snapshot name), total and
remaining (`<x-money>`; a corrective prints its negative total unchanged with `text-red-600
dark:text-red-400`), state (`<x-invoice.state-badge :state />`, nothing for correctives),
collected (`<x-invoice.collection-badge :collected :derived />`, `derived` true for a corrective,
shown muted), last delivery (`<x-invoice.delivery-badge :status :is-copy :failure-reason />`, the
sanitised failure reason in a tooltip), actions. The four badge components are provided by 0109b
(its FD-9) and take the scalar and enum values of `ListInvoices` rows. `flux:table` from `md`, stacked cards below with the same
hooks; filter bar `grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6`; Flux colours and dark
variants as the orders screen; no custom CSS.

Row actions: Download always (`target="_blank"`, no Livewire request); Send absent without
`invoices.send`, disabled with `__($sendReasonKey)` when blocked; voided and corrective rows offer
Download and, for correctives, Send when `canSend`.

Create dialog: step 1 picker (button list, at most 20 rows, "refine your search" note at 20, "No
order can be invoiced" when empty); step 2 figures as `<x-money>` (order total; billable only when
refunds make it differ; invoiced so far; uninvoiced; collected; debt), "Change order", mode radios
("Whole uninvoiced amount" default, "Partial amount" revealing the input with `inputmode="decimal"`
and "Maximum X"), a hint explaining U-7 (whole order lists its lines, a partial invoice prints one
"payment on account" line), a legacy order's partial radio disabled with the reason and a hint that
the invoice will be recorded as collected, and the next-number hint when allowed. Errors: `amount`
under the input; `invoice`, `issuer` and `number` in the callout; for `issuer` a link to
`invoice-settings.index` when the actor may view the settings.

## Lang keys (`lang/en` and `lang/es`, key for key)
- `navigation.php`: `items.invoices` ("Billing" / "Facturación").
- `invoices.php` group `index`: `title`, `summary` (`trans_choice` ":count invoice|:count
  invoices"), `create`, `create_not_allowed`, `columns.number|type|issued|order|customer|total|
  remaining|state|collected|delivery|actions`, `types.standard|corrective`,
  `states.live|voided|corrective`, `collected.yes|no`, `delivery.queued|sent|failed|copy`,
  `filters.search_placeholder|type|state|collected|from|to|all|clear`, `empty`, `empty_create`,
  `no_results`, `actions.download|send`, `send.dialog_title|dialog_body|confirm|sent_original|
  sent_copy`; group `create`: `title`, `search_placeholder`, `refine`, `none`, `change_order`,
  `figures.total|billable|invoiced|uninvoiced|collected|debt`, `mode.whole|partial`, `amount_label`,
  `amount_max` (`:max`), `amount_too_high` (`:max`), `amount_format`, `legacy_whole_only`,
  `legacy_collected_hint`, `lines_hint`, `next_number` (`:number`), `issuer_settings_link`,
  `submit`, `issued` (`:number`, `:amount`).

## Decisions
- **FD-1 Sidebar placement.** "Billing" is a bare top-level item right after Orders: billing is
  daily work, the owner asked for a sidebar entry, and the settings page stays in the store-settings
  cluster as "Invoice settings", so the labels never collide.
- **FD-2 Filters in the URL, sanitised, never refused.** A stale or pasted link opens the list with
  invalid filters reset; one search box covers number, order and customer.
- **FD-3 Amount input (QA G-1).** Accepted: digits with at most one decimal separator, `.` or `,`,
  and at most two decimals (`200`, `200.5`, `200,50`); the screen trims and turns the comma into a
  dot. Refused on `amount`, never guessed: thousands separators (`1.000,50`, `1,000.50`), exponents
  (`1e3`), signs (`-5`), several separators (`200.50.1`), more than two decimals (`200.123`), text.
  While typing, a `bccomp` comparison only disables submit and shows "Cannot exceed X"; the action
  decides.
- **FD-4 Correctives in the filters (QA G-3).** The state filter lists standard invoices only (a
  corrective has no live/voided state); the collected filter matches a corrective by the collected
  state of the invoice it corrects; type and dates apply to both.
- **FD-5 "Whole" sends null.** The backend then issues the exact uninvoiced amount under its lock,
  so a stale figure never causes a refusal; the success toast names the issued number **and
  amount** (QA G-6), and a stale partial amount is refused with the fresh maximum shown.
- **FD-6 No "Issue and send" here.** Issuing and sending are separate confirmed actions in Billing;
  the orders list owns "Issue and send".
- **FD-7 Snapshot customer name.** The customer column shows the invoice's frozen recipient name,
  never the live customer.
- **FD-8 Rules never in Blade.** Row availability comes from `DescribeOrderInvoicing`; every action
  re-authorizes and re-validates server-side.
- **FD-9 Picker as a button list.** At most 20 rows from the server; a forged `selectOrder` for an
  order the server does not list now is a no-op.
- **FD-10 0110a slot.** Rows render an empty actions slot; with 0110a not merged nothing for
  collect or undo appears.

## Gherkin
```gherkin
Feature: Billing section

  Scenario: A store manager reads the invoice list
    Given a store manager with permission to view invoices, and invoices of two customers
    When she opens Billing
    Then she sees each invoice with number, type, date, order, customer, total, state, collection and last delivery, newest first

  Scenario: A user without permission cannot open Billing
    Given a sales assistant without permission to view invoices
    When she opens the Billing address
    Then access is denied and the sidebar has no Billing entry

  Scenario Outline: A store manager narrows the list
    Given a store manager with permission to view invoices, and invoices of every kind
    When she filters by <filter>
    Then only the matching invoices are listed

    Examples:
      | filter                          |
      | type "Corrective"               |
      | state "Voided"                  |
      | collected "No"                  |
      | issue dates from 1 to 31 March  |
      | the text of an order number     |
      | part of a customer name         |

  Scenario: Correctives follow the invoice they correct in the collected filter
    Given a store manager with permission to view invoices, and a corrective of a collected invoice
    When she filters by collected "Yes"
    Then the corrective is listed

  Scenario: Filters survive a reload
    Given a store manager who filtered Billing by state "Voided" and went to page 2
    When she reloads the page
    Then the same filters and page are still applied

  Scenario: A filter with no match says so
    Given a store manager with permission to view invoices
    When she searches for text no invoice contains
    Then Billing says no invoice matches and offers to clear the filters

  Scenario: An empty Billing invites the first invoice
    Given a store manager with permission to create invoices, and no invoice issued yet
    When she opens Billing
    Then she sees "No invoices yet" with a button to create an invoice

  Scenario: A viewer sees no send action
    Given a sales assistant with permission to view but not send invoices
    When she opens Billing
    Then every row offers download and no row offers send

  Scenario: A store manager sends an invoice from Billing
    Given a store manager with permission to send invoices, and an invoice never sent
    When she confirms sending it from its row
    Then she is told it was sent as the original and the row shows the delivery

  Scenario: A store manager issues an invoice for the whole uninvoiced amount
    Given a store manager with permission to create invoices, and a 1000.00 EUR order with nothing invoiced
    When she issues an invoice for the whole uninvoiced amount
    Then an invoice of 1000.00 EUR is listed and the message names its number and amount

  Scenario Outline: The partial amount is held to what is left to invoice
    Given a store manager with permission to create invoices, and a 1000.00 EUR order with 800.00 EUR already invoiced
    When she issues a partial invoice of <amount>
    Then <outcome>

    Examples:
      | amount   | outcome                                                |
      | 200.00   | the invoice is issued and nothing is left to invoice   |
      | 200,00   | the invoice is issued for 200.00 EUR                   |
      | 200.01   | she is told the maximum is 200.00 EUR under the amount |
      | 0        | she is told the amount must be at least 0.01 EUR       |
      | -5       | she is told the amount is not valid                    |
      | 1.000,50 | she is told the amount is not valid                    |
      | abc      | she is told the amount is not valid                    |

  Scenario: An order with nothing left to invoice cannot be picked
    Given a store manager with permission to create invoices, and an order fully invoiced
    When she searches for that order in the create-invoice dialog
    Then the order is not offered

  Scenario: An order paid before invoicing is invoiced whole and collected
    Given a store manager with permission to create invoices, and an order paid before invoicing existed
    When she issues its invoice
    Then only the whole amount is offered and the invoice is listed as collected

  Scenario: Incomplete issuer data is explained in the dialog
    Given a store manager with permission to create invoices, and issuer data without a tax ID
    When she issues an invoice
    Then the dialog says the issuer data is incomplete and nothing is issued
```

## Files to create/modify
- `app/Livewire/Invoices/Index.php`, `resources/views/livewire/invoices.blade.php`,
  `resources/views/components/invoice-row-actions.blade.php` (Download, Send, empty slot).
- `routes/invoices.php` — `invoices.index`.
- `config/modules.php` — item `invoices`; `lang/{en,es}/navigation.php`.
- `app/Actions/Invoices/{ListInvoices, FindInvoiceableOrders}.php`,
  `app/Concerns/InvoiceFilterValidationRules.php`.
- `lang/{en,es}/invoices.php` — `index` and `create` groups.
- Tests below.

## Tests to perform
Amounts are strings in every assertion. Browser waits are polled conditions; each browser test
ends with `assertNoJavaScriptErrors`.

Read side (`tests/Feature/Invoices/`)
- [ ] R-1 `ListInvoicesTest`: row shape; newest first; snapshot fields; corrective `collected`
  derived by join; `state` excludes correctives; `issued_to` inclusive; search escapes `%`, `_`,
  `\`; budget count + page + one batch with 25 and 100 rows.
- [ ] R-2 `ListInvoicesAuthorizationTest`: without `invoices.view` refused and logged.
- [ ] R-3 `FindInvoiceableOrdersTest`: cancelled, out of date, fully invoiced excluded; figures and
  `isLegacy` correct; search by number prefix and customer name; capped at 20; without
  `invoices.create` refused and logged.
- [ ] R-4 `InvoiceFilterValidationTest`: every filter's valid and invalid values.

Component level
- [ ] T-1 Standard, corrective, voided rows: columns, newest first, negative corrective in red, the
  snapshot name after a customer rename, 25 per page.
- [ ] T-2 Without `invoices.view`: route and `Livewire::test` 403; sidebar item absent (parity test
  with the route gate).
- [ ] T-3 Filter dataset (type, state, collected, date range inclusive, number prefix, order
  number, customer name, combinations, `%` and `_` literal).
- [ ] T-3b A corrective of a collected invoice matches collected "yes"; the state filter never
  lists correctives (FD-4).
- [ ] T-4 Every filter change resets to page 1.
- [ ] T-5 Bad URL state (`type=x`, `from=zzz`, `to` before `from`, `q` of 101 characters,
  `page=-3`): reset to defaults, no exception; `to` before `from` shows the date error, list
  unchanged.
- [ ] T-6 `withQueryParams` with valid filters: applied, `assertSet` and rows agree.
- [ ] T-7 26 invoices: 25 rows and a second page; filters keep the paginator on the filtered set.
- [ ] T-8 Query budget with 25 rows independent of row content.
- [ ] T-9 Permissions dataset (view; view + send; view + create): download always; send absent
  without `invoices.send`, disabled with reason when blocked; create disabled (not absent) without
  `invoices.create`.
- [ ] T-10 Empty store: `invoices-empty` with create (only with the permission); filtered to zero:
  `invoices-no-results`, never create.
- [ ] T-11 Picker: ineligible orders absent; figures shown; search by number and customer; 20 cap
  with the refine note.
- [ ] T-12 `selectOrder` with an id the server does not list: no-op; a client write to
  `selectedOrderId` refused.
- [ ] T-13 Amount dataset on 1000.00 with 800.00 invoiced: `200.00`, `200`, `200,00`, `200,5` issue;
  `200.01` refused on `amount` with the maximum; `0`, `0.00`, `-5`, `''` in partial mode refused;
  `abc`, `1e3`, `200.50.1`, `200.123`, `' '` refused; no invoice after any refusal.
- [ ] T-14 `1.000,50` and `1,000.50` refused on `amount`, never converted (FD-3).
- [ ] T-15 Whole mode sends `null`: another manager invoices 100.00 meanwhile, the issued invoice is
  the current uninvoiced amount and the toast names it; a stale partial amount is refused with the
  fresh maximum.
- [ ] T-16 Submit disabled with "Cannot exceed X" above the maximum, enabled at exactly X and at 0.01.
- [ ] T-17 Legacy order: partial disabled with the reason; whole issues a collected invoice.
- [ ] T-18 Issuer incomplete (`issuer`), cancelled, out of date, nothing left (raced), customer
  missing (`invoice`), number collision (`number`): errors in the callout, not under the amount; the
  settings link only with `invoice-settings.view`; nothing issued.
- [ ] T-19 Forged `issueInvoice` without `invoices.create`: 403, logged, nothing issued; the
  component never references `IssueInvoiceForOrder` (architecture test).
- [ ] T-20 Success: dialog closes, toast with number and amount, list on page 1, search and amount
  reset when reopened.
- [ ] T-21 `confirmSendInvoice` with an unknown id: no-op; forged `sendInvoice` without
  `invoices.send`: 403 logged; refusals (out of date, deleted customer) as a toast; original vs copy
  in the toast.
- [ ] T-22 `IndexMutationSurfaceTest`: the public mutating methods are exactly those listed above.
- [ ] T-23 Invoice number and customer name with `<b>` print escaped.
- [ ] T-24 en/es parity of the new keys.

Browser level (`tests/Browser/Invoices/`)
- [ ] B-1 `BillingListTest`: filter by state and search (debounced), page 2, reload: controls,
  rows and URL (`state`, `q`, `page`) agree; clear filters restores all.
- [ ] B-2 Phone viewport: filter bar single column, rows as cards, no horizontal page scroll; dark
  mode badges readable.
- [ ] B-3 `CreateInvoiceTest`: search, pick, partial `200,01` over 200.00 shows "Cannot exceed" and
  disables submit; `200,00` issues, dialog closes, toast, new number in the list.
- [ ] B-4 "Change order" picks another order: figures update; focus returns to the search field.
- [ ] B-5 Send journey from a row; a double click on confirm yields one original.
- [ ] B-6 The download link opens in a new tab and sends no Livewire request.

## Expected outcome
Managers with `invoices.view` find any invoice quickly, download or send it, and holders of
`invoices.create` issue whole or partial invoices for an order from Billing without ever exceeding
what is left to invoice.

## Acceptance criteria
- [ ] Sidebar item and route gated by `invoices.view` (FD-1).
- [ ] List, columns, filters (including FD-4), pagination and URL state as specified.
- [ ] Download and send per row follow permissions and row states.
- [ ] The create dialog follows FD-3, FD-5 and the legacy rule; errors land on the right field.
- [ ] `ListInvoices`, `FindInvoiceableOrders` and the filter rules exist as specified.
- [ ] The listed hooks exist; the mutation surface is pinned; en/es parity.

## Definition of Done
- [ ] Tests written and green (read side, component and browser)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/routes.md` (`invoices.index`), the invoices API
  doc (list and picker reads), the sidebar modules doc
- [ ] Acceptance criteria met
- [ ] `npm run build` passes; Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream: [0101b](done/0101b-issue-standard-invoice-backend.md),
  [0102](done/0102-invoice-pdf-rendering-and-templates-backend.md),
  [0104](in-progress/0104-send-invoice-by-email-backend.md),
  [0106](0106-invoice-collection-order-debt-partially-paid-backend.md),
  [0109a](0109a-orders-list-invoice-send-ui.md), [0109b](0109b-order-detail-invoices-panel-ui.md).
  Downstream: [0110a](0110a-billing-collect-and-undo-ui.md).
- Tampering: filters sanitised every request; page size constant; locked ids re-resolved; every
  action re-authorizes and validates (the amount cap under the order lock); names and numbers print
  with `{{ }}`; the customer e-mail appears only in the send dialog.
- Disclosure: the order link only with `orders.view`; the picker shows customer names to
  `invoices.create` holders (the same data an invoice snapshot carries).
- Concurrency: two managers issuing for one order are serialised by the order lock; the second sees
  the fresh maximum after the refusal.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0110-1 Legacy paid orders: (a) whole amount only, born collected (recommended, accepted
    default); (b) any partial amount; (c) not from Billing.
  - Q-0110-2 Thousands separators in the amount: (a) refused, only one decimal separator accepted
    (recommended: no guessing between `1.000,50` and `1,000.50`); (b) parsed per the UI locale.
  - Q-0110-3 "Issue another invoice" after success: (a) no (recommended); (b) yes.
  - Q-0110-4 Negative amounts shown as `€ -200.00` (recommended, unchanged `<x-money>`) or
    `-€ 200.00` (a formatter change).

## Technical tasks
- [ ] `InvoiceFilterValidationRules`, `ListInvoices`, `FindInvoiceableOrders` with their tests.
- [ ] Route, sidebar item, lang.
- [ ] `Invoices\Index` list, filters, URL state, pagination, row actions, send dialog.
- [ ] Create dialog: picker, figures, modes, amount normalisation, errors, next-number hint.
- [ ] Component and browser tests; mutation-surface test.
- [ ] `npm run build`; Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa. Phase 2 (INVEST) pending.
