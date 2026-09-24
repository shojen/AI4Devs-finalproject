# Authorization

Cross-cutting concern — single source of truth for roles & permissions. Other documents link here instead of re-explaining it.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Overview, permission catalog and seeding](authorization/overview-catalog-seeding.md) | you need the stack, the current authorization state, the permission catalog, the seeded roles and their grants, how they are seeded, or the Super Admin bootstrap. | Stack; Current state; Permission catalog; Seeded roles and their grants; Seeding; Super Admin bootstrap |
| [Super Admin bypass and role invariants](authorization/super-admin.md) | you touch `Gate::before`, the Super Admin bypass and its coverage gap, or the Super Admin role's structural invariants. | The Super Admin bypass; The Super Admin role's invariants |
| [Administrator tier and middleware aliases](authorization/administrator-tier.md) | you touch the Administrator tier's identity/immutability rules or the route middleware aliases. | The Administrator tier's identity; Middleware aliases |
| [Policies: overview, User and Role](authorization/policies-users-roles.md) | you need the policy roster overview, `UserPolicy` abilities or `RolePolicy`. | Policies |
| [Policies: SalesRegion, Media, ProductCategory, blog](authorization/policies-sales-media-categories.md) | you touch `SalesRegionPolicy`, `MediaPolicy` (the first policy behind no route), `ProductCategoryPolicy`, or the blog taxonomy policies (`BlogCategoryPolicy`, `BlogTagPolicy`, and `FindOrCreateBlogTag`'s branch-dependent ability). | `SalesRegionPolicy`; `MediaPolicy`; `ProductCategoryPolicy`; The blog policies |
| [Policies: Product and ProductAttributeType](authorization/policies-products.md) | you touch `ProductPolicy` or `ProductAttributeTypePolicy`. | `ProductPolicy`; `ProductAttributeTypePolicy` |
| [Policies: ShippingZone, ShippingRate, PaymentMethod](authorization/policies-shipping-payment.md) | you touch `ShippingZonePolicy`, `ShippingRatePolicy` or `PaymentMethodPolicy` (including the policy-vs-permission-string reconciliation). | `ShippingZonePolicy`; `ShippingRatePolicy`; `PaymentMethodPolicy` |
| [Policies: Customer and Order](authorization/policies-customers-orders.md) | you touch `CustomerPolicy` or `OrderPolicy` (the three-ability order screen, state-based refusals, the accepted Super Admin/Cancel drift). | `CustomerPolicy`; `OrderPolicy` |
| [Product variants and routeless components](authorization/policies-variants-and-routeless.md) | you gate product variant actions (parent-product gating) or a Livewire component that has no route. | Product variant actions gate against the parent product, not...; A routeless Livewire component has no per-request authorizati... |
| [Grant meta-rules and UI hints](authorization/grant-meta-rules-and-ui-hints.md) | you touch who may grant a permission, `Gate::authorize` at the call site, or `Gate::allows()` used as a list-query UI hint. | Who may *grant* a permission; The second grant meta-rule: you cannot grant what you do not...; `Gate::authorize` at the call site, not only at the route; `Gate::allows()` in a list query is a UI hint, not a layer |
| [Step-up authentication and refusal logging](authorization/step-up-and-refusal-logging.md) | you touch password-confirmation step-up guards or `LogRefusedPrivilegedAttempt` refusal logging. | Step-up authentication; Recording a refusal |
| [Domain invariants vs authorization rules](authorization/domain-invariants.md) | you must decide whether a rule is an authorization rule or a domain invariant (direct `throw`, state-based refusals). | A domain invariant is not an authorization rule, and does not... |
| [Configuration, how to gate, where it lives](authorization/how-to-gate.md) | you add a new gated module route/sidebar entry/policy: the copyable module-gate pattern, the sidebar registry, and where each piece lives. | Configuration; How to gate something; Where it lives |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
