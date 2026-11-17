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
- Open: interim picker disclosure, unbounded list — backlog items 8-12 in the story file. RecordRefund/line-item lock-order inversion: resolved by story 0092a, 2026-11-12 (`RecordRefund` now locks `orders` then items, like the three line-item actions) — story 0055.

## Story 0084 — Order mark-as-paid (backend, reworked to `order_payments`)

- `MarkOrderAsPaid::__invoke(Order, PaymentMethod, OrderPaymentType): Order` — one `DB::transaction()`: status-aware compare-and-set `UPDATE orders`, then (only on 1 row) the `order_payments` insert; no route yet — story 0084.
- `orders` has **no** `paid_at`; the payment is one `order_payments` row (unique `order_id`, `payment_method_id`, `type` varchar(20) cast to `OrderPaymentType` transfer/card/paypal, `paid_at`), reached via `Order::payment()` (null for legacy paid orders); `OrderPayment` has empty `#[Fillable]` — story 0084.
- Migration precedent: declare `->unique()` before `->constrained()` so MySQL reuses the unique index as the FK index; both FKs restrict — story 0084.
- Open for 0085: validate type with `Rule::enum`, resolve method with `findOrFail`, add a code-to-type mapping before a second method; no actor column (F-1, owner decision) — story 0084.
- 0085 UI contract: both `Orders\Index` (`markAsPaid()`, `#[Locked] ?string $confirmingPaidOrderId`, `showMarkPaidConfirm`, `confirmingPaidRow`, per-row `canMarkPaid`) and `Orders\Show` (`canMarkPaid`, `confirmMarkAsPaid()`/`markAsPaid()`) call `MarkOrderAsPaid` unconditionally with the server-resolved bank-transfer method and `OrderPaymentType::Transfer`; the button is absent from the DOM when ineligible — story 0085.
- Lang group `orders.payment.*` (`action`, `dialog_*`, `marked`, `info`, `info_by`, `not_found`); test support `tests/Support/Orders/MarkAsPaidUi.php` and dataset `mark_as_paid_button_visibility_grid` — story 0085.

## Story 0093 — Confirm dialog second choice

- `<x-confirm-dialog>` takes optional `secondary-action` + `secondary-label` (both required to render the `-secondary` button); the stacked three-button layout (`flex-col-reverse`, `min-h-11`) applies only then, two-button dialogs keep their markup; `@close` still runs dismiss, and both action buttons target both methods in `wire:target` — story 0093. Interactive checks (Esc/backdrop/X, 375px layout) belong to its first consumer, 0094.

## Story 0088 — Order stock-adjustment foundation (backend)

- `order_items` gained `ordered_as_variant` (bool), `stock_undeducted_quantity` (U) and `stock_refunded_not_out_quantity` (K), all unsigned/default-safe; migration `2026_11_10_184845_add_stock_tracking_columns_to_order_items_table.php` — story 0088.
- `OrderItem` helpers, the one copy of D8: `stockTarget(): ?array{type,id}` (variant, else product, else null for an orphaned variant or deleted product), `returnableStockUnits()` R = (Q-F)-U, `refundedUnitsOutOfStock()` H = F-K, and `applyRefundToStockCounters(int $refunded, int $restocked)` (in memory, before `refunded_quantity` increments, `LogicException` when k > min(f, R); `over = max(0, f-R)`, U-over, K+k+over) — story 0088.
- Create/add write lines stock-neutral (`U = quantity`, `K = 0`); `RecordRefund` applies the rule with `k = 0`; `UpdateOrderItemQuantity` moves only U (`+d` / `max(0, U-d)`); no stock is read or written yet (stories 0088a/0090-0092b do that) — story 0088.
- Backfill D6 uses `GREATEST(CAST(quantity AS SIGNED) - CAST(refunded_quantity AS SIGNED), 0)` so a legacy over-refunded row cannot underflow the unsigned column; D9 `ordered_as_variant` backfill has two accepted blind spots (edited product SKU flagged; deleted variant sharing its product's SKU missed) — story 0088.
- Factory precedent: `OrderItemFactory::legacyStockUntracked()` (U = Q-F, K = F, computed in `afterMaking`) and `orphanedVariant()`; `forVariant()` sets `ordered_as_variant`; `DemoDataSeeder` uses `legacyStockUntracked()` — story 0088.

## Story 0088a — Order stock auto-deduct on create and add (backend)

- `App\Actions\Orders\AdjustOrderStock::__invoke(Order, list<array{order_item: OrderItem, delta: int}>): list<string>` is the single stock writer: transaction precondition and same-order guard throw `LogicException`, no gate (D7, orders actions only), per-target aggregation, one relative `increment('stock')` per target, no lower bound; skips null targets, vanished rows and net-zero targets — story 0088a.
- D10 / FD-7 lock order, project-wide: `orders` -> `products` (by id, incl. a variant line's parent) -> `product_variants` (by id) -> UPDATE stock -> `order_items` insert -> totals. Reason: the order_items FK takes an S lock on the parent, a later `FOR UPDATE` is an upgrade and deadlocks (1213) — story 0088a.
- `CreateOrder` / `AddOrderItem` build the `OrderItem` unsaved (uuid via `newUniqueId()`), call `AdjustOrderStock` (one call for all lines in `CreateOrder`), then `save()`; new lines are written `U = 0`, `K = 0`, `ordered_as_variant`; lines from 0088 and earlier keep `R = 0` — story 0088a.
- Forward rule for 0090 / 0091 / 0092b: call `AdjustOrderStock` BEFORE any `order_items` `FOR UPDATE` (cross-order gap-lock deadlock, appsec L-1) — story 0088a.
- Follow-ups: R-2 / appsec L-2 product and variant editors save `stock` from a form value and lose a concurrent deduction (needs its own story); R-5 stock is a signed `INT`, overflow surfaces as 1264 (accepted); 1213 is not retried — story 0088a.

## Story 0090 — Order line quantity change and removal, opt-in stock (backend)

- `UpdateOrderItemQuantity` and `RemoveOrderItem` gained a last parameter `bool $adjustStock = false`; default never moves stock, no new permission (D-9), delta computed from locked rows — story 0090.
- Rules: raise d with `true` = stock `-d`, U unchanged (without: U `+d`); lower d = U' `max(0, U-d)` in both modes, stock `+max(0, d-U)` only with `true`; removal with `true` = stock `+R`, `R = (Q-F)-U`; unchanged quantity and null-target lines move no stock; K never touched — story 0090.
- Lock order realised (0088a FD-7/L-1): lock `orders` + hard block, plain read of the line via `$lockedOrder->items()`, guards on plain reads, `AdjustOrderStock` (when `adjustStock` and delta != 0), then item lock (+ range count in `RemoveOrderItem`), re-verify Q/F/U and guards (mismatch = `LogicException`, rollback incl. stock), write/delete, totals — story 0090.
- Follow-ups unchanged: UI exposure of the opt-in belongs to a later frontend story; 1213 still not retried — story 0090.

## Story 0091 — Order cancellation, opt-in stock return (backend)

- `CancelOrder::__invoke(Order $order, bool $adjustStock = false): Order`; constructor now injects `AdjustOrderStock`. Default never moves stock; no new permission, route or UI — story 0091.
- First transaction in `CancelOrder` (0050 had none, a single-column write): `Gate::authorize('cancel')` outside it, then lock `orders` and re-run both guards (Cancelled -> `ValidationException` on `status`, blocked -> `OrderCancellationBlockedException`) on the locked row — story 0091.
- Lock order realised (0088a FD-7/L-1): plain read of all lines via `$lockedOrder->items()`, R = `returnableStockUnits()` per line, one `AdjustOrderStock` call with `+R` deltas (skipped when none), then `orderBy('id')->lockForUpdate()` on the lines and re-verify the id set and Q/F/U (mismatch = `LogicException`, rollback incl. stock), write `stock_undeducted_quantity = U + R` for applied ids only (K untouched), then status — story 0091.
- After the transaction the caller's instance gets `forceFill(['status' => Cancelled])->syncOriginalAttribute('status')` (the write went through the locked copy), so it is not left dirty — story 0091.
- Known pre-existing gap, follow-up story candidate: a `Cancelled` order stays line-editable (`isLineItemEditable()` blocks only `Shipped`/`Delivered`), so `AddOrderItem` / a quantity increase can deduct stock after cancellation; there is no cancellation audit log — story 0091.
- Docs: `docs/api/orders.md#cancelorder--opt-in-stock-return-on-cancellation-story-0091`; the 0050 "no transaction" note in `docs/architecture/authorization/domain-invariants.md` was rewritten — story 0091.

## Story 0092b — Refund with optional stock return (backend)

- `RecordRefund::__invoke(Order, array $items, ?string $reason = null, array $stockReturns = [])`; `$stockReturns` is `order_item_id => units`, default `[]` moves no stock, no new permission — story 0092b.
- Three field-level `stock_returns` refusals: `orders.refunds.stock_return_not_in_refund` (key not in `$items`, checked before a `0` is skipped), `stock_return_exceeds_refunded`, `stock_return_not_out_of_stock` (cap `R = returnableStockUnits()` read before the refund); shape rules `refundStockReturnRules()` / `refundStockReturnUnitRules()` in `OrderValidationRules` — story 0092b.
- Lock order realised: `orders` lock, plain items read, guards, `AdjustOrderStock` (positive deltas), items lock + re-verify Q/F/U/K (mismatch or missing line = `LogicException`, stock rolled back), writes on locked rows. `k` is credited only for ids `AdjustOrderStock` reports (deleted catalog row = refund recorded, no stock moved) — story 0092b.
- Forward rule from 0088a is now satisfied by 0090, 0091 and 0092b — story 0092b.
- Follow-ups for 0096: cast `items` / `stockReturns` to int after validation (Livewire sends strings); catch the concurrent-change `LogicException` with a retry message; tell the admin when a refunded line moved no stock — story 0092b.

## Story 0094 — Order line quantity change asks about stock (frontend)

- `Orders\Show::updateLineItemQuantity` is replaced by `requestQuantityChange` -> dialog -> `applyStockChoice(bool $adjust, UpdateOrderItemQuantity)` / `dismissStockConfirm`; the shared state is `#[Locked]` `pendingStockAction`, `pendingItemId`, `pendingQuantity`, `pendingFromQuantity` plus `showStockConfirm`, all cleared by one private `clearPendingStock()` on every exit path — story 0094. 0094a adds a second concretely typed injected parameter (`RemoveOrderItem`); 0095 reuses `stockPreview`.
- `stockPreview` is a fresh read; decrease units = `max(0, R - (newQty - F))` clamped to `[0, R]`, so invalid input never previews more than the line holds; a null `stockTarget()` line saves directly with `adjustStock: false` — story 0094.
- `x-confirm-dialog` always renders `-confirm` and has no hide prop, so the zero-units variant is the two-button form with `-confirm` = "Save without changing stock" (0094a does the same with "Remove without changing stock") — story 0094.
- The three-button form now always stacks full width: with long labels the `sm:flex-row` row pushed the dismiss button off the `max-w-md` panel (found by the browser test, not by the markup) — story 0094.
- The concurrent-change `LogicException` from `UpdateOrderItemQuantity` is caught in `saveQuantity` and rendered as `orders.stock.changed_meanwhile`; the stale guard still only compares `quantity` outside the action's transaction (security note, low): passing the expected counters into the action is a follow-up — story 0094.
- Test support: Livewire `ShowLineItemStockTest`, browser `OrderLineItemStockDialogTest` (scope the X locator to `dialog[open]`: the order page holds several flux modals) — story 0094.

## Story 0094a — Order line removal asks about stock (frontend)

- `removeLineItem` is replaced by `requestRemoveLineItem(string, RemoveOrderItem)` -> the same `line-stock` dialog -> `applyStockChoice(bool, UpdateOrderItemQuantity, RemoveOrderItem)` with `pendingStockAction = 'remove'`; a private `removeLine()` mirrors `saveQuantity()` and maps `ModelNotFoundException|LogicException` to `changed_meanwhile` — story 0094a. 0095 chooses its cancel preview mode only when `pendingStockAction === ''`.
- The dialog's wording follows the pending mode (new keys `orders.stock.remove_heading`, `remove_body`, `remove_without_stock`; confirm reuses `orders.stock.return`), and `changed_meanwhile` was reworded mode-neutral — story 0094a. Zero-units removal is the two-button form with `-confirm` = "Remove without changing stock" — story 0094a.
- Latent bug found by the browser tests and fixed for both flows: the modal's `@close` runs `dismissStockConfirm`, whose re-render wiped the refusal error (Livewire does not persist the error bag); the method is now `#[Renderless]`, like `dismissMarkAsPaid`. A browser test for a refused quantity change guards the 0094 path too — story 0094a.
- Follow-ups (low, not done): the stale guard compares only `quantity` outside the action's transaction, so a change of `refunded_quantity` or `stock_undeducted_quantity` can make the applied units differ from the previewed ones (record them as extra locked pending props or pass expected units to the actions); the catch of `LogicException` is broad (`report()` it or throw a dedicated exception) — story 0094a.
- Test pattern: after a click that triggers a Livewire write with no visible dialog to wait on, poll the database with `\Pest\Browser\Execution::instance()->wait(0.1)` between reads (`waitForLineRemoval`, `waitForLineQuantity`). Never `usleep()`: the browser tests' HTTP server runs in the test process and only advances while the test awaits a browser call, so sleeping blocks the very request being waited for (it passed locally by timing luck and failed in CI) — story 0094a.

## Story 0095 — Order cancellation asks about stock (frontend)

- `cancelOrder(bool $adjust, CancelOrder)` replaces the argument-less call; `confirmCancel()` records `#[Locked] pendingCancelFromStatus` (stale guard, cleared by one private `clearPendingCancel()` on every exit path; an empty guard is refused like a stale one) and the dialog keeps its own flag `showCancelConfirm` and prefix `cancel-order`, gaining the 0093 secondary action — story 0095.
- `stockPreview` now has a cancel mode, chosen only when `pendingStockAction === ''` and the guard is set (one row per returnable line with a live target, same-target lines show the combined resulting stock); every row carries `itemId`. Zero-returnable orders use the two-button form (`-confirm` = "Cancel without changing stock") — story 0095.
- `dismissCancelConfirm()` is `#[Renderless]` like `dismissStockConfirm()`; a Livewire test that simulates a race must use `forceFill()` for `status` (it is not mass-assignable), and a non-super-admin cannot apply a cancel on an order already cancelled (the policy answers 403 before the guard) — story 0095.

## Story 0096 — Refund modal returns stock (frontend)

- `refundStockReturn` (`none|all|partial`) and `refundStockQuantities` are deferred bindings reset by `resetRefundStockState()` (open + `syncRefundInputs()`); the server maps them in `mapRefundStockReturns()` (silent clamp to the refunded units, unknown mode refused, zero `partial` total refused) and passes `RecordRefund`'s `$stockReturns` — story 0096.
- Because the refund quantities stay deferred, the per-line stock inputs are always rendered for stock-target lines (`lineItems[].hasStockTarget`) and toggled client-side with `x-show="$wire.refundStockReturn === 'partial'"`; Livewire tests assert DOM presence, the browser test asserts visibility.
- `ModelNotFoundException|LogicException` from `RecordRefund` render `orders.refunds.changed_meanwhile`; the UI does not pre-clamp to `returnableStockUnits()`, the backend refusal is shown — story 0096. After a restock, `refundedUnitsOutOfStock()` is 0 but `stock_refunded_not_out_quantity` equals the refunded units (K counts units back in inventory).

