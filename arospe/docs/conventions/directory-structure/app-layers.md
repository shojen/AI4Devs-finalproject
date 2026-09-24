# Directory Structure — Concerns, Enums, Exceptions, Events, Listeners

> Part of [Directory Structure](../directory-structure.md). **Read this part when:** you add a validation trait, backed enum, domain exception, event or listener and need to know where it goes. The other parts are listed in the [hub](../directory-structure.md#table-of-contents).

### `app/Concerns/`, `Enums/`, `Exceptions/`, `Events/` and `Listeners/`

```
app/
  Concerns/            Shared traits (validation rule sets, incl. BlogCategoryValidationRules — story 0058; BlogTagValidationRules — story 0059 (two name-rule methods, `nameFormatRules()` and `nameRules()`, because create and find-or-create disagree about what an existing name means); BlogPostValidationRules — story 0061 (field-named methods, two of them status-parameterised: `bodyRules()` and `publishedAtRules()`); ResolvesSalesRegionFromAddress — the country/Spain-postal-prefix → Sales Region mapping shared by the physical and virtual tax-region resolvers; ResolvesFlagReasonLabel — story 0055, the `flag_reason` → copy resolution shared by the orders list marker and the detail callout, so the two never word one flag differently)
  Console/Commands/    Artisan commands
  Enums/               Backed enums for domain value sets (UserStatus, RoleName, SalesRegionKind,
                       BlogPostStatus — story 0061, draft/published/scheduled, no label() until a second
                       consumer appears, ProductType, ProductStatus — exactly two persisted cases — and
                       ProductDisplayStatus, a badge-only third enum never persisted, never
                       validated and carrying no column or cast of its own; GeographyLevel, story
                       0032 — deliberately no label(), since this story ships no rendering site at
                       all, per naming.md's "add label() when a second consumer appears" rule;
                       OrderStatus / PaymentStatus, story 0045 — two SEPARATE value sets rather
                       than one enum or one lang group, since PRD §3.2 treats fulfilment status
                       and payment status as independently-evolving dimensions; NEITHER declared
                       label() at story 0045 (corrected here rather than left stale --
                       OrderStatus gained label() at story 0047, its order-history screen being
                       the first real rendering consumer, ahead of the originally-planned story
                       0055; PaymentStatus still has none, deferred, for the identical
                       GeographyLevel/naming.md "add label() when a second consumer appears"
                       reason) -- both already shipped their lang/{en,es}/orders.php leaves from
                       story 0045, pinned by a test, since a translation file is only ever correct
                       relative to the value set it covers; OrderStatus gained two more methods at
                       story 0049, rank()/isBackwardFrom(), covering only the four linear statuses
                       -- Cancelled deliberately has no rank, see architecture/authorization.md)
  Exceptions/          Domain exceptions that render their own response (ImmutableRoleException → 403,
                       RoleInUseException → 409, PasswordConfirmationRequiredException → 423,
                       OrderNotEditableException → 409 since story 0048 -- the state-based hard
                       block on order line-item editing, a direct throw from each of
                       AddOrderItem/RemoveOrderItem/UpdateOrderItemQuantity rather than a Gate
                       ability, see architecture/authorization.md; OrderStatusRegressionRequires
                       ConfirmationException → 409 since story 0049 -- thrown by
                       TransitionOrderStatus when a backward status move is not confirmed,
                       following RoleInUseException's shape exactly; deliberately not 423 (not a
                       credential-freshness problem) and not 403 (not an authorization failure) --
                       see architecture/authorization.md's "Order status regression confirmation"
                       section; OrderCancellationBlockedException → 409 since story 0050 -- a
                       DIRECT THROW from CancelOrder when Order::isManuallyCancellable() is
                       false, never a Gate check, so it binds a Super Admin actor too; a
                       DIFFERENT class from OrderStatusRegressionRequiresConfirmationException
                       despite both rendering 409 -- that one is retryable (confirmed: true),
                       this one is terminal, since CancelOrder takes no confirmation parameter
                       at all -- see architecture/authorization.md's "Manual order cancellation"
                       section) — plus, since story 0022, one
                       that deliberately does NOT: UnresolvedSelectionException carries no
                       render() at all, because it must never reach the HTTP layer as a status
                       code (see below)
  Http/Controllers/    Abstract base + domain controllers used as HTTP boundaries in front of actions
  Events/              Domain events dispatched by actions (OrderFullyRefunded — story 0052, the
                       app's first: carries only `string $orderId`, never a hydrated Order, not
                       queued, dispatched by RecordRefund AFTER its transaction commits). A stock
                       Laravel location (`make:event`), no approval needed
  Listeners/           Event listeners (ActivateVerifiedUser; CancelFullyRefundedOrder — story
                       0052, a thin synchronous adapter to Actions/Orders/AutoCancelFullyRefunded
                       Order). ActivateVerifiedUser is registered in AppServiceProvider; the new
                       listener is NOT — Laravel's listener auto-discovery already registers any
                       app/Listeners handle() that type-hints an event, and an explicit
                       Event::listen() on top would fire it twice (verified with `event:list`)
```
