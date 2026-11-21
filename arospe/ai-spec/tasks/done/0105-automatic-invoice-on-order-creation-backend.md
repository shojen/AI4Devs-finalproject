# [0105] Automatic issue and send on order creation (backend)

> **Status: Done (Phase 7 closed 2026-11-21; Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](0100-invoice-settings-backend.md) (the `auto_issue_on_order` flag),
> [0101b](0101b-issue-standard-invoice-backend.md) (`IssueInvoiceForOrder`) and
> [0104](0104-send-invoice-by-email-backend.md) (`RecordInvoiceDeliveries`, `InvoiceSent`).
> Related: [0106](../in-progress/0106-invoice-collection-order-debt-partially-paid-backend.md) and
> [0108](../0108-regenerate-invoices-after-order-edit-backend.md) close the stale-totals limitation
> (FD-9).

## Description
When the store setting "issue and send the invoice automatically when an order is created" is
on, every newly created order gets, right after its creation commits, one itemised invoice for
its whole billable amount, issued by the system and queued to the customer as the original.
Failures never undo the order and are reported to the people who can fix them. Off (the default)
changes nothing.

Request item served (owner, 2026-11-14, translated):
> 2. In the orders list, a button on every row sends the invoice; if it was already sent when the
> order was placed, it is sent as a copy.

Binding owner decision:
- U-3 (owner decision, 2026-11-14): "First invoice: a store setting 'issue and send the invoice
  automatically when an order is created', off by default. Off means the manager issues invoices
  by hand."
- Accepted default (owner, 2026-11-15; Q-0105-1, overturnable): correctives (refund,
  regeneration, cancellation) are never sent automatically.

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- `CreateOrder` already registers `DB::afterCommit(fn () => ($this->notifyOrderCreated)($order))`
  after its transaction (story 0046 F-B); that callback can throw after commit (documented F-A).
  Callbacks run in registration order; inside `RefreshDatabase` an after-commit callback runs
  immediately, and inside an outer transaction it waits for the outer commit.
- `NotifyOrderCreated` resolves recipients with `User::permission(...)` and deliberately
  excludes Super Admin, who holds no permission rows.
- `CreateOrder` resolves no tax or shipping region today; `ResolveOrderTaxRegion` and
  `ResolveVirtualOrderSalesRegion` exist with no caller and write `tax_rate`, `tax_amount` and
  `total` without locking the order.
- 0101b: `IssueInvoiceForOrder(Order, ?string $amount, ?User $actor)` is the ungated issue
  transaction (orders `FOR UPDATE` -> settings `FOR SHARE` -> sequence `FOR UPDATE` -> inserts),
  callable only by a fixed list of callers that includes this story's action.

## Decisions
- **FD-1 Hook after commit, before the created-order notification.** `CreateOrder` gains one
  `DB::afterCommit(fn () => ($this->issueAutomaticInvoice)($order->id))`, registered **before**
  the existing `notifyOrderCreated` callback, so an admin-notification failure can never lose the
  invoice. Because it runs after the order transaction (and after any outer transaction), the
  issue chain starts with no stock locks held and an issue failure cannot roll the order back.
  Registered once, after `CreateOrder`'s order-number retry loop has converged.
- **FD-2 Synchronous, one bounded retry (Q-0105-2 (a)).** The hook runs in the same request,
  synchronously; it retries once only on deadlock (1213) or lock wait timeout (1205). Reason:
  failures are visible at once and tests are deterministic; no PDF is rendered here (the queue
  worker renders it in 0104). Alternative recorded: a queued listener.
- **FD-3 Idempotent per order (QA G-2).** The action re-reads the order by id and skips an order
  that already has any invoice, so a repeated hook or a second call never issues twice.
- **FD-4 What it does.** Read the settings (no lock); return when the flag is off. Re-read the
  order; return without effect when it is missing, cancelled, already invoiced (FD-3) or its
  billable amount is 0.00. **Issue** the whole billable amount (itemised, as 0101b decides for a
  whole unrefunded order) with a null actor. **Send** in a separate step: skip with a warning log
  when the customer is soft-deleted; otherwise lock the order, check it is not out of date, and
  record one original delivery with a null actor, which queues the mail after commit.
- **FD-5 Two independent failure scopes; nothing propagates.** An issue failure leaves the order,
  its items, its stock deduction and its created-order notification intact, consumes no number,
  calls `report()` and notifies `stage = issue`. A send failure (including a soft-deleted
  customer or a queueing failure) leaves the invoice issued, calls `report()` and notifies
  `stage = send`; a later manual send is an original. A failing failure-notification only logs.
  `CreateOrder` never sees an invoicing exception.
- **FD-6 System actor.** `issued_by` and `sent_by` are null, even when an admin created the order
  through the back office; nothing in the path reads the authenticated user (Q-0105-4 (a)).
- **FD-7 No gate, compensated by reachability.** The action checks no permission (it is a system
  follow-on of an authorized order creation); an architecture test fails if anything other than
  `CreateOrder` references it, or if it reads the authenticated user, or if a route, controller,
  Livewire component or job reaches it. Its only input is the id of the order just created.
- **FD-8 Failure notification (QA G-3, G-4, G-6).** `AutomaticInvoiceFailed`, database channel,
  sent to users holding `invoices.create` or `invoices.send` (any of), resolved live, Super Admin
  excluded exactly as the created-order notification does; with no recipient it only logs. The
  payload holds only `order_id`, `order_number`, `stage` and `reason_key`; never the exception
  text (it can contain fiscal data, paths or credentials), which goes to the log through
  `report()`. At most one notification per stage per hour (Q-0105-3 (a), `Cache::add` on a key
  built from the stage).
- **FD-9 Stale-totals limitation, owned elsewhere (QA G-1).** If an order's totals change after
  creation without a line edit (tax or shipping resolved later by a future checkout through the
  region resolvers), an auto-issued invoice would go stale silently. 0106 creates the shared
  after-totals-changed hook and 0108 makes every totals writer, including both resolvers under an
  order lock, mark the order out of date. Until 0108 ships, a future checkout must resolve tax and
  shipping before `CreateOrder` commits. 0105 only pins today's behaviour in a test.
- **FD-10 Toggle never scans.** Switching the setting on or off acts only on orders created
  afterwards; the settings save reads no order. The decision follows the value read when the hook
  runs.
- **FD-11 Seeders and tests.** The flag defaults to off; the demo data seeder never enables it;
  tests that need it switch it on explicitly.

## Class contracts
- `App\Actions\Invoices\IssueAutomaticInvoice::__invoke(string $orderId): void` — never throws.
- `App\Actions\Invoices\NotifyAutomaticInvoiceFailed::__invoke(Order $order, string $stage,
  Throwable $cause): void`.
- `App\Notifications\AutomaticInvoiceFailed` — `database` channel; payload keys above.
- `App\Actions\Orders\CreateOrder` — constructor gains `IssueAutomaticInvoice`.

## Gherkin
```gherkin
Feature: Automatic invoice on order creation

  Scenario: Automatic invoicing is off
    Given a store manager whose store has automatic invoicing switched off
    When an order is created
    Then no invoice is issued

  Scenario: Automatic invoicing issues and sends the invoice
    Given a store manager who switched automatic invoicing on with complete issuer data
    When an order of 100.00 EUR is created
    Then one itemised invoice for 100.00 EUR is issued by the system
    And it is queued to the customer as the original

  Scenario: An issue failure keeps the order
    Given a store manager whose store has automatic invoicing on, and whose issuer legal name was removed
    When an order is created
    Then the order is saved without an invoice
    And the users who can create or send invoices are notified

  Scenario: A send failure keeps the invoice
    Given a store manager whose store has automatic invoicing on, and whose mail queue is unavailable
    When an order is created
    Then the invoice stays issued
    And the users who can create or send invoices are notified so the invoice can be sent by hand

  Scenario: Earlier orders are not invoiced when the setting is switched on
    Given a store manager whose store has 40 orders without invoice
    When she switches automatic invoicing on
    Then none of those orders gets an invoice

  Scenario: A zero order gets no invoice
    Given a store manager whose store has automatic invoicing on
    When an order with a billable amount of 0.00 EUR is created
    Then no invoice is issued

  Scenario: A repeated hook never issues twice
    Given a store manager whose store has automatic invoicing on, and an order that already has its automatic invoice
    When the automatic invoicing runs again for that order
    Then the order still has exactly one invoice
```

## Files to create/modify
- `app/Actions/Invoices/IssueAutomaticInvoice.php`, `NotifyAutomaticInvoiceFailed.php`.
- `app/Notifications/AutomaticInvoiceFailed.php`.
- `app/Actions/Orders/CreateOrder.php` — the hook (FD-1) and its docblock.
- `lang/{en,es}/invoices.php` — group `automatic` (notification copy, reason keys).
- Tests under `tests/Feature/Invoices/Automatic/`; `tests/Feature/Orders/CreateOrderTest.php` and
  `CreateOrderStockTest.php` re-run unscoped (the constructor changed).
- No migration, permission, route or config change.

## Tests to perform
Drive the real `CreateOrder`; `Notification::fake()`, `Pdf::fake()`, `Queue::fake()` where the
mail queue matters. Failures are forced by binding a throwing double of exactly one collaborator
(`IssueInvoiceForOrder`, `RecordInvoiceDeliveries`, `NotifyOrderCreated`). Assumes FD-2
(synchronous); if the owner picks the queued listener, T-8, T-10, T-16 and T-23 are redone.

Happy path
- [ ] T-1 Fresh database, flag off by default: no invoice, no delivery, no query on `invoices` or
  `invoice_deliveries`.
- [ ] T-2 Flag on: exactly one itemised standard invoice for the whole billable amount, number
  assigned, `issued_by` null.
- [ ] T-3 Exactly one `queued` original delivery, `sent_by` null, the customer's address; one
  `InvoiceSent` on demand; no authenticated user in the run.
- [ ] T-4 Admin without invoice permissions creates the order: the invoice is still issued with
  `issued_by` null.
- [ ] T-5 Billable 0.00 (dataset): no invoice, no delivery, no notification, no number consumed.
- [ ] T-6 Null tax rate: rate null, tax 0.00; an order created with tax or shipping already set
  prints them.
- [ ] T-7 Sync queue with `Pdf::fake()`: the delivery becomes `sent`, the PDF has no COPY mark.

Edge
- [ ] T-8 Inside an outer transaction: nothing until the outer commits; exactly one invoice after
  commit; nothing at all after an outer rollback (no number consumed).
- [ ] T-9 The order creation itself throws: no invoice, no notification, `CreateOrder`'s own
  exception unchanged.
- [ ] T-10 `NotifyOrderCreated` throws after commit: the invoice and delivery already exist; a
  spy proves the invoice hook runs first; a throwing invoice hook never stops the created-order
  notification.
- [ ] T-11 Issue failure dataset (issuer incomplete, forced exception, number collision, one
  deadlock then success, two deadlocks): order, items, stock and created-order notification
  intact; no invoice, no number consumed; one `report()`; one `stage = issue` notification; one
  deadlock is retried and succeeds.
- [ ] T-12 Send failure: invoice issued, one `stage = send` notification, order intact, a later
  manual send is an original.
- [ ] T-13 Soft-deleted customer at creation: invoice issued, send skipped with one warning log,
  `stage = send` notification, no mail.
- [ ] T-14 Second call for the same order id: still one invoice (FD-3).
- [ ] T-15 Two orders back to back: consecutive numbers; a failure on the first leaves no gap.
- [ ] T-16 Sequence row held by another connection: the 1205 is swallowed after the retry, the
  order stays, failure notified, no partial invoice.
- [ ] T-17 Orders created while off, then on: only new orders invoiced; switching off stops it;
  the settings save reads no order.
- [ ] T-18 Flag flipped just before the hook (on -> off, off -> on): the value read at hook time
  decides; never a partial invoice.
- [ ] T-19 `CreateOrder`'s order-number retry: one hook, one invoice.
- [ ] T-20 Five failures of one stage within an hour: one notification per recipient; another
  stage or a new hour notifies again (cache flushed between tests).

Negative
- [ ] T-21 Recipients are exactly the holders of `invoices.create` or `invoices.send`; view-only
  holders, inactive or deleted admins and Super Admin excluded; no recipient -> no exception.
- [ ] T-22 A cause whose message holds SQL, a path, a tax ID or an SMTP credential: the stored
  payload has exactly the four keys; the message never appears.
- [ ] T-23 The failure notification itself throws: `CreateOrder` still returns normally; the
  cause is logged.
- [ ] T-24 Architecture: only `CreateOrder` references `IssueAutomaticInvoice`; no gate, no
  authenticated-user read, no route/controller/component/job reaches it.
- [ ] T-25 Unknown id, cancelled order, non-UUID: no effect, no exception, no notification for a
  state refusal.
- [ ] T-26 Flag on with incomplete issuer data: three orders created, none invoiced, one
  deduplicated notification, no number consumed.
- [ ] T-27 Installation where nobody holds the permissions: the failure is only logged.
- [ ] T-28 Pin today's limitation (FD-9): a line edit after an automatic invoice is handled by
  0108's mark; a totals write that bypasses the totals recalculation leaves no mark (documented,
  not hidden).

## Expected outcome
With the setting on, every new order gets its invoice issued and queued to the customer without
any manual step, and no invoicing problem ever loses or alters an order; with it off, order
creation behaves exactly as today.

## Acceptance criteria
- [x] Flag off: `CreateOrder` behaves exactly as before, without touching invoice tables.
- [x] Flag on: one itemised invoice for the whole billable amount, issued and queued as the
  original by the system (FD-1, FD-4, FD-6).
- [x] Idempotent per order (FD-3); zero orders skipped; earlier orders never touched (FD-10).
- [x] Issue and send failures follow FD-5 and notify per FD-8 (recipients, keys-only payload,
  hourly dedupe).
- [x] Reachability enforced (FD-7).
- [x] The stale-totals limitation and its owners are documented (FD-9).
- [x] en/es `invoices.automatic.*` keys exist with parity.

## Definition of Done
- [x] Tests written and green
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor) — an ungated system path issues fiscal documents
- [x] Documentation updated (docs-keeper): the orders API doc (`CreateOrder` side effect and
  callback order), the invoices API doc, the stale-totals limitation and its owners
- [x] Acceptance criteria met
- [x] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0100](0100-invoice-settings-backend.md), [0101b](0101b-issue-standard-invoice-backend.md),
  [0104](0104-send-invoice-by-email-backend.md). The stale-totals limitation is closed by
  [0106](../in-progress/0106-invoice-collection-order-debt-partially-paid-backend.md) (hook) and
  [0108](../0108-regenerate-invoices-after-order-edit-backend.md) (mark).
- Contention: automatic issues of one series serialise on its sequence row (short critical
  section); a bulk import issues sequentially.
- Incomplete issuer data with the flag on makes every order fail to invoice; the hourly dedupe
  keeps the inbox usable, and 0100 refuses blanking issuer data while the flag is on.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0105-1 Correctives sent automatically: (a) no, they are sent by hand (recommended,
    accepted default: U-3 covers order creation only); (b) yes.
  - Q-0105-2 Execution: (a) synchronous after commit with one bounded retry (recommended:
    visible failures, deterministic tests); (b) a queued listener (free retries, but the invoice
    itself then needs a running worker and a new event class).
  - Q-0105-3 Failure notifications: (a) at most one per stage per hour (recommended: avoids a
    flood when issuer data is missing); (b) one per order.
  - Q-0105-4 Author of an automatic invoice created from the back office: (a) the system, null
    (recommended: the automation acted, not the admin); (b) the admin who created the order.

## Technical tasks
- [ ] Failing tests for off, on, zero, toggle and idempotence.
- [ ] `IssueAutomaticInvoice` with the two failure scopes and the bounded retry.
- [ ] `CreateOrder` hook before the notify callback; docblock.
- [ ] `NotifyAutomaticInvoiceFailed`, `AutomaticInvoiceFailed` (keys only, hourly dedupe).
- [ ] Reachability, ordering and failure-matrix tests; lang en/es.
- [ ] Pint, Larastan, targeted then full suite (the `CreateOrder` suite unscoped); hand docs
  items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, backend-qa.
- Phase 2 (INVEST) approved on 2026-11-19 by code-reviewer — INVEST met; Gherkin follows the template; dependency signatures of 0100/0101b/0104 verified against the merged code (no mismatch). Phase 3 notes: wrap the send transaction itself to catch RecordInvoiceDeliveries' after-commit RuntimeException; deadlock/lock-wait retry lives in IssueAutomaticInvoice; issuer-incomplete state is test-only (forceFill).
- Phase 4 (appsec-auditor, 2026-11-19): APPROVED — no Critical/High/Medium findings; reachability enforced by architecture tests, keys-only payload, per-order idempotency re-checked under row lock. Low: stage-wide dedupe hides later failures for 1h (accepted, Q-0105-3); architecture test compares file names only (fixed in Phase 5); concurrent manual issue surfaces as 'unexpected' (follow-up, noise only).
- Phase 5 (code review) approved on 2026-11-19 by code-reviewer — AC 1–7 and FD-1..FD-11 verified in code; T-1..T-28 present; Larastan L7 and Pint clean; conditional on the full suite being green (orchestrator).
- Phase 6 (docs) done on 2026-11-19: automatic invoicing documented (commit `docs(invoicing)`).
- Phase 7 (closure) complete 2026-11-21: all Acceptance criteria and Definition of Done boxes ticked; file moved from `ai-spec/tasks/in-progress/` to `ai-spec/tasks/done/`; two-direction link-integrity check run. Full suite in directory chunks: Unit + Feature A 3802 tests (3795 passed, 7 skipped), Feature B 3535 passed, Browser 333 (330 passed, 3 skipped), 0 failures; Pint unscoped clean; Larastan level 7 clean (Phase 5).
