# Arospe PRD — Epic 3 — Customers & Orders

> Part of [Arospe PRD](../PRD.md). **Read this part when:** the task is a Customers or Orders story. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Epic 3 — Customers & Orders

**Priority: 3 (depends on Products / Taxes / Shipping existing first).** This epic was confirmed
after the notification-event list implied it (a "new customer" and a "new order" cannot be
notified about if the panel can't manage them). It gives the backoffice the ability to manage
store end-customers and their orders.

**No design prototype exists for this epic** — the Claude-Design handoff did not cover Customers
or Orders. The UI should follow the **same list + detail/editor visual patterns** established in
Users and Products (list with status badges, a detail/editor view, modals for quick create/edit,
the shared topbar).

**Boundary restatement (unchanged from the original scope).** There is still **no public
storefront or checkout** here. Orders and customers are assumed to **originate from an external
or future channel** — a future public storefront, a POS, or a manual admin-created record — that
is itself out of scope for this PRD. This epic covers only the backoffice's ability to **manage
that data once it exists**, including creating it manually when needed.

### 3.1 Customers

Customers are store end-customers, a **full CRUD entity entirely separate** from the
Users/Roles/Permissions system in Epic 1. **Critically, customers cannot log into the Arospe
dashboard at all** — there is no customer-facing auth or portal in this phase. A customer is a
purely admin-managed record: name, email, contact info, shipping/billing address, and a
read-only view of that customer's order history. Admins can create, edit, and **soft-delete**
customer records manually from the panel — deletion never physically removes the record, so a
customer's orders are never orphaned.

```gherkin
Feature: Customer management

  Scenario: Create a customer record manually
    Given a customer administrator
    When they create a customer with a name, email, contact info, and shipping/billing address
    Then the customer appears in the customer list
    And a "new customer created" notification is generated

  Scenario: A customer is not a dashboard user
    Given a customer administrator, with an existing customer record
    When they look for that customer among dashboard users, roles, and login accounts
    Then the customer has no dashboard login, no role, and no permissions
    And the customer cannot authenticate into the panel

  Scenario: View a customer's order history
    Given a customer administrator, with a customer who has one or more orders
    When they open that customer's detail view
    Then they see a read-only list of that customer's orders

  Scenario: A duplicate customer email is rejected
    Given a customer administrator, with an existing customer whose email is "cliente@example.com"
    When they try to create another customer with the email "cliente@example.com"
    Then creation is rejected with a validation message

  Scenario: Deleting a customer soft-deletes the record
    Given a customer administrator, with a customer who has at least one existing order
    When they delete that customer
    Then the customer is soft-deleted (marked deleted, not physically removed) so
      their orders are not orphaned
    And the customer no longer appears in the active customers list
```

**Acceptance criteria — Customers**

- [ ] Customers are a full-CRUD, admin-managed entity separate from Users/Roles/Permissions.
- [ ] Customers have **no** dashboard login, role, or permissions and cannot authenticate.
- [ ] A customer record holds name, email, contact info, and shipping/billing address, and shows
      a read-only order-history view.
- [ ] Customer email is validated; duplicates are rejected.
- [ ] Customers are **soft-deleted** (marked deleted, not physically removed) so their orders are
      never orphaned.
- [ ] Creating a customer generates the confirmed "new customer" notification.

### 3.2 Orders

Full order management from the backoffice: list and detail views, plus full editing. An admin
can edit line items, change the order status, and handle payment/refund state. An order
references: a **Customer**, one or more **Product/Variant line items** (each with a quantity and
the price at the time of order), the **Sales Region** used for tax resolution on that order, and
the **Shipping rate/carrier** selected for delivery.

**Sales Region resolution for tax.** How an order's Sales Region (which determines the tax rate)
is resolved depends on the order's **product type** (see [2.2 Products](epic-2-products-taxes-shipping.md#22-products)):

- **Physical** product → the Sales Region is resolved from the order's **shipping address**.
- **Virtual** (digital) product → the Sales Region is resolved from the **billing address**, and
  that billing address must first be **validated to match the purchaser's IP-address-derived
  location** (a geo/fraud check). If the billing country/region does **not** match the IP-derived
  location, the order is **flagged for manual review** rather than auto-resolving tax. *(Flagging
  for manual review is a conservative default chosen here — a reasonable safe behavior, not a
  re-ask; adjust if the business prefers hard-reject or hold.)*

**Order status vocabulary (explicit, standard e-commerce set):**
`Pendiente → Procesando → Enviado → Entregado`, plus `Cancelado` as a terminal state reachable
from earlier stages. **Payment/refund state** is tracked as a separate dimension:
`Pendiente de pago`, `Pagado`, `Reembolsado`, `Parcialmente reembolsado`.

The payment/refund field is a **manual admin-set status only** — it is **not** backed by any
payment gateway. The admin selects the state by hand; the panel performs no charge, capture, or
refund calls to a payment processor this phase (consistent with there being no public checkout).
The order's **payment method** references one of the configured methods from
[2.5 Payment Methods](epic-2-products-taxes-shipping.md#25-payment-methods-store-settings) — this phase, always bank transfer.

```gherkin
Feature: Order management

  Scenario: Open an order's detail
    Given an order administrator, with an existing order
    When they open that order's detail
    Then they see its customer, line items (product/variant, quantity, and price at
      time of order), the sales region used for tax, and the shipping rate/carrier

  Scenario: A new order generates a notification
    Given an order administrator, with a new order arriving from an external/future
      channel or created manually
    When the order lands in the backoffice
    Then a "new order received" notification is generated

  Scenario: Advance an order to the next status
    Given an order administrator, with an order in "Procesando"
    When they change its status to "Enviado"
    Then the order reflects the "Enviado" status

  Scenario Outline: Edit the line items of an open order
    Given an order administrator, with an order in "Pendiente" or "Procesando"
    When they <line_item_change>
    Then the order totals and tax recalculate accordingly

    Examples:
      | line_item_change              |
      | add a line item               |
      | remove a line item            |
      | change a line item's quantity |

  Scenario: Editing line items after an order has shipped is hard-blocked
    Given an order administrator, with an order already "Enviado" or "Entregado"
    When they attempt to edit its line items
    Then the edit is always blocked, with no confirmation path around it

  Scenario: Moving an order's status backward requires explicit confirmation
    Given an order administrator, with an order in "Enviado"
    When they move its status back to "Pendiente"
    Then they must explicitly confirm the action before it is applied
    And it is not flatly forbidden

  Scenario: Manually cancel an order in an early status
    Given an order administrator, with an order in "Pendiente" or "Procesando"
    When they set its status to "Cancelado"
    Then the order is marked cancelled

  Scenario Outline: Manual cancellation is blocked in guarded states
    Given an order administrator, with an order in "<status>"
    When they try to manually cancel it
    Then manual cancellation is blocked

    Examples:
      | status                   |
      | Enviado                  |
      | Entregado                |
      | Parcialmente reembolsado |

  Scenario: Fully refunding all line items auto-cancels the order
    Given an order administrator, with an order in any status, including "Enviado" or "Entregado"
    When every line item of the order becomes fully refunded (a 100%-refund event)
    Then the order automatically transitions to "Cancelado" as a system-triggered side effect
    And this is distinct from the manual cancel action, which stays blocked in those states

  Scenario: Record a full refund from a paid order
    Given an order administrator, with an order whose payment state is "Pagado"
    When they record a full refund
    Then the payment state becomes "Reembolsado"

  Scenario: Record a partial refund from a paid order
    Given an order administrator, with an order whose payment state is "Pagado"
    When they record a partial refund
    Then the payment state becomes "Parcialmente reembolsado"

  Scenario: A partially-refunded order can still be refunded
    Given an order administrator, with an order whose payment state is "Parcialmente reembolsado"
    When they record a further refund
    Then the refund is accepted

  Scenario Outline: The refund action is hidden in non-refundable payment states
    Given an order administrator, with an order whose payment state is "<state>"
    When they view the order
    Then the refund action does not render

    Examples:
      | state             |
      | Pendiente de pago |
      | Reembolsado       |

  Scenario Outline: The backend rejects a refund in a non-refundable payment state
    Given an order administrator, with an order whose payment state is "<state>"
    When a refund is attempted directly against the backend (bypassing the hidden UI control)
    Then the server rejects it, independently of the UI

    Examples:
      | state             |
      | Pendiente de pago |
      | Reembolsado       |

  Scenario: An order's payment method references a configured method
    Given an order administrator, with bank transfer configured as a payment method
    When they view an order's payment method
    Then it references a configured payment method, which is bank transfer this phase

  Scenario: A physical product's order resolves tax from the shipping address
    Given an order administrator, with an order for a physical product
    When the order's Sales Region is resolved for tax
    Then it is derived from the order's shipping address

  Scenario: A virtual product's order resolves tax from the validated billing address
    Given an order administrator, with an order for a virtual product whose billing
      address matches the purchaser's IP-derived location
    When the order's Sales Region is resolved for tax
    Then it is derived from the billing address

  Scenario: A virtual product's mismatched billing address is flagged for review
    Given an order administrator, with an order for a virtual product whose billing
      address country/region does not match the purchaser's IP-derived location
    When the order's Sales Region resolution runs
    Then the order is flagged for manual review instead of auto-resolving tax

  Scenario: The resolved region's rate is used, with default fallback
    Given an order administrator, with an order whose resolved Sales Region has its own rate
    When the order's tax is computed
    Then that region entry's rate is used, falling back to the default entry when no
      matching entry applies
```

**Acceptance criteria — Orders**

- [ ] Orders have list and detail views, and are fully editable from the backoffice.
- [ ] An order references a customer, one or more product/variant line items (quantity + price
      at time of order), a sales region for tax, and a shipping rate/carrier.
- [ ] Order status follows the explicit set `Pendiente → Procesando → Enviado → Entregado`, with
      `Cancelado` as a state; backward status transitions require explicit admin confirmation
      (not flatly forbidden).
- [ ] Editing line items after an order is `Enviado`/`Entregado` is **always hard-blocked** (no
      confirmation path).
- [ ] Manual cancellation is blocked while the order is `Enviado`, `Entregado`, or
      `Parcialmente reembolsado`.
- [ ] When **all** line items are fully refunded (100%-refund event), the order **automatically**
      transitions to `Cancelado` regardless of its current status — a system side effect, distinct
      from the (blocked) manual cancel.
- [ ] Payment/refund state is tracked separately (`Pendiente de pago`, `Pagado`, `Reembolsado`,
      `Parcialmente reembolsado`) as a manual admin-set status, with no payment gateway.
- [ ] A refund is only permitted from `Pagado` or `Parcialmente reembolsado`: the refund action
      does not render in `Pendiente de pago`/`Reembolsado` (UI), **and** the backend independently
      rejects a refund attempted in those states (defense in depth).
- [ ] An order references a payment method drawn from the configured methods
      ([2.5 Payment Methods](epic-2-products-taxes-shipping.md#25-payment-methods-store-settings)) — bank transfer this phase.
- [ ] The order's tax **Sales Region is resolved by product type**: physical → shipping address;
      virtual → billing address validated against the purchaser's IP-derived location, with a
      mismatch flagged for manual review. Once resolved, the region's rate is used with default
      fallback (consistent with Epic 2).
- [ ] A new order generates the confirmed "new order received" notification.
- [ ] Orders and customers are assumed to originate from an out-of-scope external/future channel
      (or manual admin entry); no storefront/checkout is built here.

---
