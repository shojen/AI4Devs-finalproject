# Epic 3 decision digest (Orders, Payments & Fulfilment)

Append-only. See [workflow.md#decision-digest-per-epic](../../../docs/workflow/agents-and-epic-digests.md#decision-digest-per-epic)
for what belongs here and what doesn't — facts and decisions a later story in this epic must not
re-derive, never the full prose of a finalized story. This is the digest's first entry (created by
story 0049, the first Epic 3 story to need one); stories 0045–0048 shipped before this file existed
and are not backfilled here — see their own task files in `ai-spec/tasks/done/` for their decisions.

## Story 0049 — Order status transition backend

- `App\Policies\OrderPolicy` (created by story 0045) already has a **fifth** ability,
  `transitionStatus(User $actor, Order $order): bool`, reusing the existing `EDIT_PERMISSION`
  constant (`'orders.edit'`) — **do not** declare a new permission or a new constant for a status
  transition; `transitionStatus` is a real, callable ability today, not a placeholder — story 0049.
- `App\Actions\Orders\TransitionOrderStatus` — invokable, `__invoke(Order $order, OrderStatus
  $newStatus, bool $confirmed = false): Order`, resolved from the container, never `new`-ed. Runs, in
  this fixed order: (1) `Gate::authorize('transitionStatus', $order)` — a **bare** `Gate::authorize()`
  call, not `LogRefusedPrivilegedAttempt::authorize()`, so a refused transition is not yet logged to
  the `'Privileged action refused'` channel (OQ-1, deferred, backlog item 3); (2) refuse any
  transition touching `OrderStatus::Cancelled` in either direction, no confirmation path, as a
  `ValidationException` on `status` (`orders.transitions.cancellation_unsupported`) — **story 0050
  owns everything else about `Cancelled`, and must not find this branch already half-built around**;
  (3) refuse a same-status transition as a `ValidationException` on `status`
  (`orders.transitions.same_status`) — `$confirmed` is never consulted; (4) refuse an unconfirmed
  backward move by throwing `OrderStatusRegressionRequiresConfirmationException`; (5) write via
  `forceFill(['status' => $newStatus])->save()` (`status` is omitted from `Order`'s `#[Fillable]`) —
  story 0049.
- **The confirmation-parameter pattern, not step-up.** `$confirmed` is a plain `bool` action
  parameter, satisfied per-call, remembered nowhere. `EnsureRecentPasswordConfirmation` is **never**
  called for this refusal, no password is re-requested, and `config('auth.password_timeout')` is
  irrelevant to it. If a later Epic 3 story (0050's cancellation, 0051/0052's refunds) needs its own
  "are you sure" gate, follow this shape (a `bool $confirmed`/similar parameter on the action, not a
  step-up re-authentication) unless the refusal is genuinely about the actor's own identity — story
  0049, D-2.
- **The 409-for-conflict, not-403/not-423 convention.** A refusal that is *"the actor may do this, and
  will, on the very next call — the request just conflicts with the row's current state until they
  confirm"* renders **409 Conflict**, via a `RuntimeException` subclass shaped exactly like
  `App\Exceptions\RoleInUseException` (constant, never-interpolated message; `render()` branches on
  `$request->expectsJson()`). It is **not** 423 (`PasswordConfirmationRequiredException` owns that,
  for a credential-freshness problem) and **not** 403 (the actor is authorized; refusing with a 403
  would tell a caller "you may not do this" when the true answer is "not yet, without confirming").
  `App\Exceptions\OrderNotEditableException` (story 0048) already established this same 409 shape for
  a different refusal; `OrderStatusRegressionRequiresConfirmationException` is the app's **second**
  confirmation-shaped exception, not a novel status choice — story 0049.
- `App\Enums\OrderStatus::rank(): int` and `::isBackwardFrom(self $current): bool` now exist.
  `rank()` covers **only** `Pending`(0)/`Processing`(1)/`Shipped`(2)/`Delivered`(3) — its `match` has
  **no `Cancelled` arm**, so `OrderStatus::Cancelled->rank()` throws `\UnhandledMatchError` rather than
  returning a number. **Any later story must refuse a `Cancelled` value before it can reach `rank()`
  or `isBackwardFrom()`** — do not add a `Cancelled` arm to `rank()` "to make it sortable"; that is a
  silent regression this story's own R-1 names explicitly. `isBackwardFrom()` is a non-throwing
  predicate asked of the **target** (`$newStatus->isBackwardFrom($order->status)`); the throwing guard
  in `TransitionOrderStatus` is a thin wrapper around exactly this call, so a future UI hint (story
  0055) must call the same predicate rather than re-deriving the comparison — story 0049.
- A forward transition may **skip** an intermediate status with no confirmation (`Pending →
  Delivered` in one call is legitimate) — the PRD rule is about *direction*, not *distance* (D-8). A
  backward transition may also skip (`Delivered → Pending`) and still only needs one `confirmed:
  true` — story 0049.
- **Nothing here is about `Cancelled`.** No per-state allow-list, no auto-cancel interaction, no
  reachability rule for `Cancelled` exists in this story or in `OrderStatus::rank()`/`isBackwardFrom()`
  — story 0050 starts from a genuinely untouched problem, not a half-built one. `orders.status`'s
  `Cancelled` value is refused outright by `TransitionOrderStatus`, in both directions, confirmed or
  not, with no retry path around it.
- **No `order_status_history` table, no migration, no schema change of any kind (D-1).** Enforcing the
  backward-confirmation rule is a pure request-time comparison between the row's current status and
  the requested one; nothing about it requires remembering a prior transition. If a later Epic 3 story
  needs "who moved this order back, and when", that is a new, additive `create_*` migration for a
  genuinely new "order timeline" story — not something to retrofit into this one.
- `lang/{en,es}/orders.php` gained a `transitions` key group (`requires_confirmation`, `same_status`,
  `cancellation_unsupported`) — three keys, no screen copy. Story 0055 extends this same group with
  button/dialog text and must not rename these three keys.

## Story 0055 — Orders list + detail/editor UI

- Routes `orders.index` / `orders.show` gate on `can:orders.view` only; `orders.edit`, `orders.edit` + `orders.refund` (cancel) and `orders.refund` are enforced in-method in `App\Livewire\Orders\Show` — story 0055.
- `Order::isLineItemEditable()` (the one copy of the Shipped/Delivered block, true on Cancelled) and `Order::isRefundable()` are the shared predicates the actions and the screen both read; the three `assertEditable()` bodies still log and throw — story 0055.
- `RemoveOrderItem` refuses a line with refunded units and `UpdateOrderItemQuantity` refuses `quantity < refunded_quantity` (both `ValidationException`) — story 0055.
- `<x-money>` (no cast) and `<x-confirm-dialog>` (parent owns the flag and both methods; `@close` runs the dismiss method) are the first shared anonymous non-chrome components; `App\Concerns\ResolvesFlagReasonLabel` is shared by list and detail — story 0055.
- A `#[Computed]` is memoised only when read as a property; a typed `int` bound to a number input is unset by a cleared box (bind a string); reset the error bag at the top of every action — story 0055 (see docs/errors-log.md).
- Refund control: row state = absent from DOM, permission = disabled; Super Admin sees Cancel enabled on a Shipped order (documented drift, 409 rendered) — story 0055.
- Open: RecordRefund/line-item lock-order inversion, interim picker disclosure, unbounded list — backlog items 8-12 in the story file.
