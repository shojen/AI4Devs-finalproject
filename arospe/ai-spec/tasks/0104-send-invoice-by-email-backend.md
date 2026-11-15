# [0104] Send invoice by e-mail, copy on resend (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0101b](0101b-issue-standard-invoice-backend.md) (invoices, `IssueInvoice`, out-of-date guard,
> `InvoicePolicy`) and [0102](0102-invoice-pdf-rendering-and-templates-backend.md)
> (`RenderInvoicePdf`, `ResolveInvoiceLocale`, file-name sanitiser, `Invoice::hasBeenSent()`
> stub). Dependents: [0105](0105-automatic-invoice-on-order-creation-backend.md) (automatic send),
> [0109a](0109a-orders-list-invoice-send-ui.md), [0109b](0109b-order-detail-invoices-panel-ui.md) and [0110](0110-billing-section-ui.md) (buttons).

## Description
Send invoices to the customer by e-mail with the PDFs attached, queued, recording every attempt.
The first successful-or-pending send of an invoice is the original; every later one is a copy and
its PDF carries the COPY mark. Two operations: send one invoice (Billing row, order panel
"resend") and send an order's invoices (the orders-list row button), which issues and sends the
invoice when the order has none.

Request item served (owner, 2026-11-14, translated):
> 2. In the orders list, a button on every row sends the invoice; if it was already sent when the
> order was placed, it is sent as a copy. Inside the order the invoice can also be resent or
> downloaded.

Binding decisions:
- Accepted default (owner, 2026-11-15; recorded as question Q-0104-2, overturnable): the
  orders-list row button on an order with no invoice is "Issue and send": after a confirmation it
  issues one invoice for the whole uninvoiced amount and sends it as the original.
- U-3 (owner decision, 2026-11-14) makes the automatic send of 0105 use this story's delivery
  recording as the system.

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- No customer-facing mail exists; `MAIL_MAILER=log` and `QUEUE_CONNECTION=database` in
  `.env.example`. `customers.email` is required and unique; customers are soft-deleted.
- The installed framework's queued-notification job calls the notification's own
  `failed(Throwable)` on final failure, and `NotificationSent` fires on success.
- Locale rule (`docs/conventions/localization.md`): a notification sent off the user notify path
  needs an explicit `->locale()`.
- 0101b lock order: `orders` first; invoice rows after it; the catalog has 47 permissions.

## Table created
`invoice_deliveries` (migration `<ts>_create_invoice_deliveries_table.php`), one row per invoice
per send attempt:

| Column | Type | Null / default | Notes |
| --- | --- | --- | --- |
| `id` | uuid PK | | |
| `invoice_id` | FK `invoices`, restrict | not null | |
| `recipient_email` | varchar(255) | not null | address used, frozen |
| `is_copy` | boolean | not null, default false | |
| `status` | varchar(20) | not null, default `queued` | `InvoiceDeliveryStatus`: `queued`, `sent`, `failed` |
| `sent_by` | FK `users`, restrict | null | null = system (0105) |
| `queued_at` | timestamp | not null | |
| `sent_at`, `failed_at` | timestamp | null | |
| `failure_reason` | text | null | sanitised, at most 500 characters |
| `created_at`, `updated_at` | timestamp | | |

FK-created indexes only. No backfill. `down()`: `dropIfExists`. Rows are never deleted; a `sent`
row never changes status again.

## Decisions
- **FD-1 Original or copy (rule).** A send is the original when the invoice has no delivery other
  than `failed` ones; once a `queued` or `sent` delivery exists, every later send is a copy. The
  decision is taken under the invoice row lock, so a double click or the automatic send racing a
  manual one yields exactly one original. A queued original that finally fails leaves a copy made
  meanwhile marked copy (accepted). A later send after only failures is an original.
- **FD-2 Recipient.** Only the customer's current e-mail, read inside the lock and frozen on the
  row; no action accepts a recipient. The queued payload carries delivery ids only, so a change
  of the customer's address after queueing does not redirect the mail.
- **FD-3 Unusable e-mail (QA G-2).** The address is validated (RFC e-mail rule) at send time; an
  unusable one refuses the send with `invoices.errors.customer_email_unusable` before any row is
  written or any invoice issued.
- **FD-4 Soft-deleted customer.** Sending is refused (`invoices.errors.customer_deleted`);
  download still works. A customer deleted after the mail was queued still receives it (accepted);
  a restored customer is an ordinary send.
- **FD-5 Out of date.** Both operations refuse an order marked "invoice out of date"
  (`AssertInvoicesAreCurrent`).
- **FD-6 Single-invoice send.** `SendInvoice` sends one live standard invoice or one corrective
  (correctives follow the same rules, including a corrective of a voided invoice); a voided
  standard invoice is refused ("nothing to send").
- **FD-7 Order-level send.** `SendOrderInvoices`:
  - with at least one live standard invoice: one mail attaching every live standard invoice and
    every corrective not yet sent ("not yet sent" = no delivery other than `failed`), each
    attachment original or copy by its own row (Q-0104-1 (a));
  - with no live standard invoice (no invoice at all, or all voided, QA G-1) and uninvoiced > 0:
    "Issue and send" — requires `invoices.create` as well; pre-checks (customer present and not
    deleted, usable e-mail, not cancelled, not out of date, issuer data complete, uninvoiced > 0)
    run before issuing; the invoice for the whole uninvoiced amount is issued in its own committed
    transaction, then sent as the original together with any corrective not yet sent;
  - nothing to send (no live invoice, uninvoiced 0.00, no unsent corrective): refused.
  If recording the delivery fails after the issue committed, the invoice stays issued and unsent
  and a later send is an original.
- **FD-8 One operation, one mail (QA G-5).** A send operation produces one mail; if any of its
  PDFs fails to render, the whole mail fails and all its rows become `failed`; the manager can
  send invoices one by one. At most 10 attachments per mail (Q-0104-5); more is refused before
  any row.
- **FD-9 Dispatch failure (QA G-4).** Dispatch is after commit. If dispatching throws (queue
  unavailable), the operation's rows are set `failed` with reason "dispatch failed" and the error
  reaches the caller, so a mail that never left cannot turn later sends into copies. A row queued
  while no worker runs stays `queued` (accepted operational risk; 0109b shows its age).
- **FD-10 Status transitions.** `MarkInvoiceDeliverySent` (listener on `NotificationSent`,
  ignoring every other notification) sets the operation's rows `sent` with `sent_at`; the
  notification's `failed()` sets them `failed` with `failed_at` and a reason truncated to 500
  characters and stripped of credentials, file paths and e-mail addresses. Retries
  (`tries`, `backoff`, `timeout` from `config('invoicing.delivery')`) never leave one row both
  failed and sent.
- **FD-11 Mail.** `InvoiceSent` is a queued, after-commit notification to an on-demand recipient
  with an explicit locale (`ResolveInvoiceLocale`, Q-0104-3 (a)); `toMail()` re-reads the rows,
  renders each PDF with `RenderInvoicePdf($invoice, $row->is_copy)` in the worker (nothing
  stored) and attaches it under 0102's sanitised file name; subject and body are translated
  strings with the invoice number. Transport: queued notification inside existing folders
  (Q-0104-4 (a)).
- **FD-12 Download copy mark.** `Invoice::hasBeenSent()` (stubbed in 0102) now returns whether a
  `sent` delivery exists; a download then carries the COPY mark. The 0102 test for that case is
  owned here.
- **FD-13 Locks.** `orders` row `FOR UPDATE`, then the invoices involved `orderBy('id')
  ->lockForUpdate()`, then insert the rows; no settings or sequence lock (the issue step of FD-7
  runs before, in its own transaction).
- **FD-14 Permission and refusal logs.** New `invoices.send` (catalog 47 -> 48; Administrator and
  Super Admin hold it). Authorization comes before any state is read. Refusal log: single send
  `target_type = invoice` with the invoice id; order-level send `target_type = order` with the
  order id (QA G-6); never an e-mail address in the context.
- **FD-16 Error keys and return values (screen contract).** State refusals of `SendInvoice` and
  `SendOrderInvoices` are `ValidationException`s keyed `invoice` with `invoices.errors.*` messages;
  permission refusals are `AuthorizationException` only. `SendInvoice` returns the
  `InvoiceDelivery` (with `recipient_email`); `SendOrderInvoices` returns `list<InvoiceDelivery>`,
  each with its `invoice`, so "Issue and send" can show the new number. If "Issue and send" commits
  the invoice and the delivery step then fails, the action throws a `ValidationException` keyed
  `delivery` with `invoices.errors.issued_but_not_queued`, so the screen can say the invoice exists
  and must be sent manually.
- **FD-15 Collaborator.** `RecordInvoiceDeliveries` is transaction-bound and ungated (called by
  the two actions and by 0105 as the system).

## Class contracts
- `App\Enums\InvoiceDeliveryStatus`: `Queued='queued'`, `Sent='sent'`, `Failed='failed'`.
- `App\Models\InvoiceDelivery` (+ factory states `queued()`, `sent()`, `failed()`, `copy()`).
- `App\Models\Invoice`: `deliveries()`, `latestDelivery()`, `hasBeenSent(): bool`.
- `App\Policies\InvoicePolicy`: `SEND_PERMISSION = 'invoices.send'`, `send(User, ?Invoice
  $target = null): bool`.
- `App\Actions\Invoices\SendInvoice::__invoke(Invoice $invoice): InvoiceDelivery`.
- `App\Actions\Invoices\SendOrderInvoices::__invoke(Order $order): list<InvoiceDelivery>`.
- `App\Actions\Invoices\RecordInvoiceDeliveries::__invoke(Order $lockedOrder, list<Invoice>
  $invoices, ?User $sentBy): list<InvoiceDelivery>`.
- `App\Notifications\InvoiceSent` (constructor `list<string> $deliveryIds`), `failed(Throwable)`.
- `App\Listeners\MarkInvoiceDeliverySent`.

## Gherkin
```gherkin
Feature: Send invoices by e-mail

  Scenario: A store manager sends an invoice for the first time
    Given a store manager with permission to send invoices, and an invoice that was never sent
    When she sends it
    Then the customer receives the invoice as an original at the customer's e-mail address
    And a delivery is recorded

  Scenario: Sending again produces a copy
    Given a store manager with permission to send invoices, and an invoice already sent once
    When she sends it again
    Then the customer receives it marked COPY
    And a second delivery is recorded as a copy

  Scenario: A double click yields one original
    Given a store manager with permission to send invoices who has just sent an unsent invoice
    When her second click on "Send" arrives
    Then exactly one delivery of that invoice is an original

  Scenario: A failed original does not count
    Given a store manager with permission to send invoices, and an invoice whose only delivery failed
    When she sends it again
    Then the customer receives it as an original

  Scenario: The order's invoices go in one mail
    Given a store manager with permission to send invoices, and an order with two live invoices
      and one corrective invoice not yet sent
    When she sends the order's invoices
    Then the customer receives one mail with three attachments, each marked original or copy on its own

  Scenario: Issue and send for an order without invoice
    Given a store manager with permission to create and send invoices, and an order with no invoice
    When she confirms "Issue and send"
    Then one invoice for the whole uninvoiced amount is issued
    And it is sent as the original

  Scenario: Issue and send needs both permissions
    Given a store manager with permission to send but not to create invoices, and an order with no invoice
    When she asks to issue and send the invoice
    Then it is refused
    And no invoice is issued

  Scenario: A deleted customer receives nothing
    Given a store manager with permission to send invoices, and an invoice of a deleted customer
    When she tries to send it
    Then it is refused and nothing is sent
    And the invoice can still be downloaded

  Scenario: An unusable e-mail address is refused before anything is recorded
    Given a store manager with permission to send invoices, and a customer whose e-mail address is not valid
    When she tries to send the customer's invoice
    Then it is refused with a message about the e-mail address
    And no delivery is recorded

  Scenario: A user without permission cannot send
    Given a sales assistant who may view but not send invoices
    When she tries to send an invoice
    Then it is refused
    And the attempt is logged
```

## Files to create/modify
- `database/migrations/<ts>_create_invoice_deliveries_table.php`.
- `app/Models/InvoiceDelivery.php`, `database/factories/InvoiceDeliveryFactory.php`,
  `app/Enums/InvoiceDeliveryStatus.php`.
- `app/Models/Invoice.php` — relations and `hasBeenSent()`.
- `app/Policies/InvoicePolicy.php` — `send`.
- `app/Actions/Invoices/{SendInvoice, SendOrderInvoices, RecordInvoiceDeliveries}.php`.
- `app/Notifications/InvoiceSent.php`, `app/Listeners/MarkInvoiceDeliverySent.php`.
- `database/seeders/RolePermissionSeeder.php` — `invoices.send`; `lang/{en,es}/roles.php` — the
  `send` segment; catalog-count tests 47 -> 48.
- `config/invoicing.php` — `delivery` (`tries`, `backoff_seconds`, `timeout_seconds`,
  `max_attachments` = 10).
- `lang/{en,es}/invoices.php` — `mail.*` and `errors.*` (customer deleted, e-mail unusable,
  nothing to send, too many attachments, dispatch failed, issued but not queued).
- `tests/Feature/Invoices/Pdf/DownloadInvoiceTest.php` — the "sent invoice downloads as a copy"
  case.
- New tests below. No route or `modules.php` change.

## Tests to perform
Fakes: `Notification::fake()` (`assertSentOnDemand`), `Queue::fake()` for dispatch, `Pdf::fake()`
always. Levels: **A** action, **N** notification and listener directly, **Q** one end-to-end case
on the sync queue. Files under `tests/Feature/Invoices/Delivery/`.

Happy path
- [ ] T-1 (A) First send: one `queued` original row with the customer's address, actor and
  `queued_at`; one notification on demand to that address only.
- [ ] T-2 (A) Matrix of earlier deliveries (none, only failed, queued, sent, queued + failed,
  failed + sent, two failed): original for none and only-failed, copy otherwise.
- [ ] T-3 (N) Two sends in a row: original then copy; only the copy's PDF has the COPY mark.
- [ ] T-4 (N) `toMail()`: one attachment per row, sanitised name, `application/pdf`,
  `RenderInvoicePdf` called with each row's `is_copy`; subject/body in `es` and `en`.
- [ ] T-5 (N) Listener sets the operation's rows `sent`; other notifications ignored.
- [ ] T-6 (A) Corrective (also of a voided invoice): same original/copy rules.
- [ ] T-7 (A) Order with two live invoices and an unsent corrective: three rows, one mail.
- [ ] T-8 (A) Order with no invoice, both permissions: the invoice is issued first (itemised,
  whole uninvoiced amount), then one original row.
- [ ] T-9 (A) `invoices.send` alone sends a single invoice; Super Admin allowed; catalog 48 after
  re-seed with Administrator holding it.
- [ ] T-9b (A) Download of an invoice with a `sent` delivery carries the COPY mark (FD-12).

Edge
- [ ] T-10 Two concurrent sends (second connection holds `orders FOR UPDATE`): the waiter fails
  with 1205; after commit it is a copy; exactly one original. Top risk.
- [ ] T-11 Back-to-back sends and the order-level send racing a single send: one original per
  invoice in total.
- [ ] T-12 A queued original that finally fails while a copy was sent: the next send is a copy.
- [ ] T-13 (N) `failed()` sets `failed`, `failed_at`, reason; a transient failure then success
  ends `sent` only.
- [ ] T-14 (N) Failure messages with an SMTP DSN with credentials, a storage path, an e-mail
  address, 5000 characters: stored reason at most 500 characters, none of those leaked.
- [ ] T-15 Customer address changed after queueing: mail goes to the row's address; the queued
  payload carries only delivery ids.
- [ ] T-16 Soft-deleted customer: both operations refused, no row, no mail, no invoice issued;
  download still works.
- [ ] T-17 Address dataset (empty, whitespace, no `@`, `a@b`, 300 characters, unusual-but-valid
  `a+b@x.co`): unusable refused before any row or issue; the valid one sends.
- [ ] T-18 Dispatch throws after commit: rows `failed` with "dispatch failed", error surfaced;
  the next send is an original.
- [ ] T-19 Rollback after inserting rows: no row, nothing sent.
- [ ] T-20 Out-of-date order: both operations refused; succeeds once cleared.
- [ ] T-21 Order with all invoices voided and uninvoiced > 0: "Issue and send" (and unsent
  correctives attached); with uninvoiced 0.00 and correctives already sent: refused.
- [ ] T-22 11 attachments: refused before any row.
- [ ] T-22b One PDF of a three-attachment mail fails to render: the mail fails, all three rows
  `failed`.

Negative
- [ ] T-23 Without `invoices.send` (none, view, create, settings manage): refused before any
  state read, same refusal on any order state, nothing written.
- [ ] T-24 One refusal warning: single send `target_type = invoice`; order-level `target_type =
  order`; no e-mail address in the context; unauthenticated caller refused and logged.
- [ ] T-25 No invoice with `send` but not `create`: refused and logged, no invoice; `create`
  only: refused; both: succeeds.
- [ ] T-26 No invoice and not eligible (cancelled, uninvoiced 0.00, out of date, issuer
  incomplete, customer deleted): matching refusal, no invoice, no number consumed.
- [ ] T-27 Issue succeeds, delivery recording throws: invoice issued and unsent; the action
  throws on `delivery` with `invoices.errors.issued_but_not_queued`; a later send is an original.
- [ ] T-27b Every state refusal is keyed `invoice`; return values per FD-16 (single delivery with
  its address; list of deliveries each with its invoice).
- [ ] T-28 Reflection: no action accepts a recipient argument.
- [ ] T-29 Voided standard invoice sent singly: refused, no row.
- [ ] T-30 (N) A delivery id deleted or of another send: clear exception, no wrong PDF, row not
  marked sent.
- [ ] T-31 Delivery rows are never deleted; a `sent` row never reverts; `sent_by` null for system.
- [ ] T-32 (Q) Sync queue end to end: row ends `sent` with the attachment; a render exception
  ends `failed` after the configured tries.

## Expected outcome
A holder of `invoices.send` can send one invoice or an order's invoices to the customer; the
first delivery is the original and later ones are marked COPY; every attempt and its outcome is
recorded; an order without invoice can be issued and sent in one confirmed step by a holder of
both permissions.

## Acceptance criteria
- [ ] `invoice_deliveries` exists as specified.
- [ ] Original/copy follows FD-1 and holds under concurrency.
- [ ] Recipient, e-mail validation, deleted customer and out-of-date rules hold (FD-2..FD-5).
- [ ] Single and order-level sends follow FD-6..FD-8, including "Issue and send".
- [ ] Dispatch failure and status transitions follow FD-9 and FD-10.
- [ ] Mails are queued, localised and carry the right PDFs (FD-11); downloads of sent invoices
  carry the COPY mark (FD-12).
- [ ] `invoices.send` seeded (catalog 48); refusals logged per FD-14; en/es parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): ER (`invoice_deliveries`), the invoicing schema doc,
  authorization catalog (48), the invoices API doc, the queue-worker operational note (a worker
  must run; `MAIL_MAILER=log` writes attachments to the log in development; production needs a
  TLS mailer)
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0101b](0101b-issue-standard-invoice-backend.md),
  [0102](0102-invoice-pdf-rendering-and-templates-backend.md). Downstream:
  [0105](0105-automatic-invoice-on-order-creation-backend.md) calls `RecordInvoiceDeliveries` as
  the system; [0109a](0109a-orders-list-invoice-send-ui.md), [0109b](0109b-order-detail-invoices-panel-ui.md) / [0110](0110-billing-section-ui.md) call
  the two actions.
- Privacy: the PDF carries the customer's tax ID and address; the transport is the operator's
  responsibility; no customer data in logs or `failure_reason`.
- Operations: a stuck worker leaves rows `queued` and later sends become copies (accepted risk,
  FD-9); Chromium must exist on the worker host (0102).
- Open technical: whether `Pdf::fake()` yields bytes for `attachData` (settled by 0102's spike);
  otherwise the notification test asserts on the render collaborator.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0104-2 Orders-list button on an order with no invoice: (a) "Issue and send" after a
    confirmation, for holders of both `invoices.create` and `invoices.send` (recommended,
    accepted default: matches "a button on every row sends the invoice" while automatic invoicing
    is off by default); (b) disabled with a "no invoice yet" hint.
  - Q-0104-1 Several invoices: (a) one mail with all live invoices and unsent correctives
    (recommended); (b) only the most recent; (c) the manager picks.
  - Q-0104-3 Mail language: (a) store default language, as the PDF (recommended); (b) the
    acting admin's locale.
  - Q-0104-4 Transport: (a) queued notification inside existing folders (recommended);
    (b) mailable plus queued job (new `app/Mail`, `app/Jobs` folders need owner approval).
  - Q-0104-5 Attachments per mail: (a) at most 10 (recommended: real orders rarely exceed 3);
    (b) no cap.

## Technical tasks
- [ ] Migration, model, factory, enum, `Invoice` relations and `hasBeenSent()`.
- [ ] `invoices.send`, `InvoicePolicy::send`, roles segment, count tests.
- [ ] `RecordInvoiceDeliveries` with the matrix and concurrency tests.
- [ ] `InvoiceSent` (locale, attachments, `failed()`), `MarkInvoiceDeliverySent`.
- [ ] `SendInvoice`, `SendOrderInvoices` (issue-and-send, refusals, dispatch failure) with
  authorization and logging tests.
- [ ] Config keys, lang en/es; Pint, Larastan, targeted then full suite; hand docs items to
  docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
