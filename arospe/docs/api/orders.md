# Orders Routes

Part of [Routes](routes.md) — see [routes.md](routes.md#why-this-file-exists) for the full app-owned route table and the shared module-gate pattern. This file covers the Orders area's two permission-gated routes, the UI half of PRD §3.2 (the backend, stories 0045 and 0048–0054, is documented in [database/schema-orders.md](../database/schema-orders.md) and [architecture/authorization.md](../architecture/authorization.md)).

## Table of Contents

- [`orders.index` — the eleventh permission-gated route, and a screen with no write method](#ordersindex--the-eleventh-permission-gated-route-and-a-screen-with-no-write-method)
- [`orders.show` — the twelfth permission-gated route, and the first screen governed by three abilities](#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities)
- [Shared pieces this story introduced](#shared-pieces-this-story-introduced)

## `orders.index` — the eleventh permission-gated route, and a screen with no write method

Story 0055. Both routes live in [`routes/orders.php`](../../routes/orders.php), `require`d from `routes/web.php`, and both carry `auth`, `verified` and `can:orders.view` **only**. `orders/{order}` is declared after the literal `orders` segment.

```php
// routes/orders.php
Route::livewire('orders/{order}', OrdersShow::class)
    ->middleware(['can:orders.view'])
    ->name('orders.show');
```

- **The `can:` (never `permission:`) rule is the same as every other gated route's** — see [architecture/authorization.md](../architecture/authorization/how-to-gate.md#gating-a-livewire-route-use-can-never-permission). The file's inline comments restate it deliberately.
- **`App\Livewire\Orders\Index` is read-only, and a reflection test pins it.** Its public surface is exactly `mount`, `orders`, `ordersSummary` and `canViewCustomers` (plus Livewire's `render`); nothing mutates. `mount()` authorizes `viewAny` on `Order::class`.
- **`orders()` is 0045's D-6 list contract**: `created_at desc, id desc`, `with('customer')` only (customer loaded `withTrashed()`, since an order may reference a soft-deleted customer). The list is **unbounded** — no pagination, search or filter yet (task OQ-4).
- **The view is flat**, [`resources/views/livewire/orders.blade.php`](../../resources/views/livewire/orders.blade.php), per the [`Index`-in-a-subfolder exception](../conventions/naming/livewire-components-and-views.md#exception-a-component-named-index-resolves-to-its-parent-folders-name). Seven columns (order, customer, status, payment, total, placed, actions), an explicit empty state, and **no "New order" control** (no creation UI exists this phase).
- **The customer cell links to `customers.show` only when `canViewCustomers` and the customer is not soft-deleted** — the target route gates on `customers.view`, and an `orders.view`-only actor would otherwise be handed a link to a 403.
- **A flagged order shows a "needs attention" marker**, resolved by [`App\Concerns\ResolvesFlagReasonLabel`](../../app/Concerns/ResolvesFlagReasonLabel.php) — shared with the detail callout so the two never word the same flag differently. `flag_reason` is `NULL` for most flagged orders today, so the generic copy is the common case; an unknown token also falls back to it.
- **Sidebar:** [`config/modules.php`](../../config/modules.php) gained a bare top-level `orders` item (`group: null`, `cluster: null`, `current_when: orders.*`, `permissions: ['orders.view']`), highlighted on both screens. Topbar copy lives in `topbar.orders.*`.
- **Hooks:** `sidebar-link-orders`, `view-order-{id}`, `order-customer-{id}`, `order-flagged-{id}`.

## `orders.show` — the twelfth permission-gated route, and the first screen governed by three abilities

**The middleware column understates what protects this route by more than any other.** `can:orders.view` only grants the page; every control is authorized inside [`App\Livewire\Orders\Show`](../../app/Livewire/Orders/Show.php), invisible in the route table:

| Control | Ability | Enforced by |
| --- | --- | --- |
| Add / remove / re-quantify a line item | `orders.edit` (`OrderPolicy::update`) + `Order::isLineItemEditable()` | `Gate::authorize('update', …)` first in each method, then the action |
| Change status (incl. backward, confirmed) | `orders.edit` (`OrderPolicy::transitionStatus`) | `requestStatusChange()` / `applyStatusChange()` |
| Cancel | `orders.edit` **and** `orders.refund` **and** `Order::isManuallyCancellable()` (`OrderPolicy::cancel`) | `confirmCancel()` / `cancelOrder()` |
| Record a refund | `orders.refund` (independent of `orders.edit`) + `Order::isRefundable()` | `openRefundModal()` / `recordRefund()` |

The UI hint for each mirrors the predicate its own guard reads — the component never writes a status set or payment-state comparison of its own. Details of the hint-versus-layer rule: [architecture/authorization.md](../architecture/authorization/domain-invariants.md#the-order-detail-screen--the-first-three-ability-screen).

- **`orderId` is `#[Locked]`** (a client-writable id would let any `orders.view` holder re-point the component at another order); `pendingStatus` and `pendingFromStatus` are `#[Locked]` too, so a forged backward-confirmation target is impossible. The write actions are method-injected (`addLineItem(AddOrderItem $addOrderItem)`, …).
- **Computeds must be read as properties (`$this->order`), never called (`$this->order()`).** A `#[Computed]` is memoised only on property access; `refreshOrderState()` `unset()`s every one after each successful write because the actions never refresh the instance they are handed. See [errors-log.md](../errors-log.md).
- **Every action's domain refusal is rendered, never a 500**: `OrderNotEditableException`, `OrderCancellationBlockedException`, the regression-confirmation exception and every `ValidationException` (refund state, a refunded line's removal, a quantity below the refunded units) become an inline error; the error bag is reset at the top of every action.
- **The status select** offers every status except the current one and `Cancelled`, and is empty on a cancelled order. A backward move is **predicted** with `OrderStatus::isBackwardFrom()` and opens the shared `confirm-dialog`; a forward move applies straight away.
- **The refund control has two treatments for two dimensions**: absent from the DOM when the payment state refuses (`PendingPayment`, `Refunded`, per the PRD), **disabled** when the actor lacks `orders.refund`. Cancel, Apply status and Add line item render disabled (never absent) when refused, and a line's quantity input degrades to plain text. Both branches of every gated control carry the same `data-test` hook.
- **Line items**: Remove is disabled on a line with refunded units (`refunds.order_item_id` is `restrictOnDelete`), and `RemoveOrderItem`/`UpdateOrderItemQuantity` now refuse that case themselves (`ValidationException` on `order_item_id` / `quantity`). `Order::isLineItemEditable()` is true on a `Cancelled` order — it mirrors the shipped guard, and the PRD blocks only `Shipped`/`Delivered`; whether cancelled orders should be editable is an open product question.
- **The interim product picker** is a plain `flux:select` of up to `PRODUCT_PICKER_LIMIT` (50) active products, with a truncation notice; it hands every active product's id, name and SKU to any `orders.edit` holder, even one without `products.view`. Story 0022's searchable component replaces it.
- **The tax panel** shows the resolved region and rate; "not yet resolved" and a genuine 0% render differently (neither inferred from `tax_amount`), and a flagged order shows both the header callout and a tax-panel notice. Money renders through `<x-money>` from the stored decimal strings — no float cast.
- **Hooks**: sections `header-section`, `customer-section`, `line-items-section`, `lifecycle-section`, `totals-section`; `order-flag-callout` (header) vs `tax-flag-notice` / `tax-provisional` / `tax-rate` / `tax-region` / `tax-unresolved` / `order-tax-panel` (tax panel); `add-line-item`, `new-product-id`, `new-variant-id`, `new-quantity`, `remove-line-item-{id}`, `save-quantity-{id}`, `quantity-input-{id}`; `status-select`, `apply-status`, `cancel-order`, `record-refund`, `refund-modal`, `refund-quantity-{id}`, `refund-confirm`; `confirm-dialog-{backward-transition|cancel-order}` with `-confirm` / `-dismiss` suffixes.
- **Not on this screen**: order creation, a per-refund history, and a manual-review workflow to clear `flagged_for_review` — all backlog items in [the story's task file](../../ai-spec/tasks/done/0055-orders-list-detail-editor-ui.md). Customer order-history rows (story 0047) still do not link to `orders.show`; that is 0047's own backlog item.
- **Known concurrency gap (pre-existing, now reachable from one screen):** `RecordRefund` locks the item rows before the `orders` row while the three line-item actions lock the order first, so a concurrent refund and line-item edit can deadlock and surface as a 500. Recommended as a small backend story.

## Shared pieces this story introduced

- **`<x-money :amount>`** ([`money.blade.php`](../../resources/views/components/money.blade.php)) prints `€ {amount}` from the stored decimal string, with no cast or formatting. Story 0047's customer order history was retrofitted to it.
- **`<x-confirm-dialog>`** ([`confirm-dialog.blade.php`](../../resources/views/components/confirm-dialog.blade.php)) — props `show`, `model`, `heading`, `body`, `confirm-label`, `dismiss-label`, `confirm-action`, `dismiss-action`, `test-prefix`, `variant`. It holds no state and decides nothing: the parent owns the bool and both methods. `@close` runs the dismiss method (Flux compiles it to `wire:close` on the `<dialog>`), so Esc, backdrop and the X reach the server like the Cancel button. Its inner content is wrapped in `@if ($show)` and the hooks sit on that inner content, never on `<flux:modal>`. It is the repo's first shared anonymous component that is not navigation chrome.
- **`Order::isLineItemEditable()`** and **`Order::isRefundable()`** ([`app/Models/Order.php`](../../app/Models/Order.php)) — the single copy of the `Shipped`/`Delivered` block and of the `Paid`/`PartiallyRefunded` refund state, read by the actions and by this screen. The three `assertEditable()` bodies still each log and throw (triplicated), but the status set itself now lives once.

_Last updated: 2026-09-23 — Story 0055 (orders list + detail/editor UI). New file._
