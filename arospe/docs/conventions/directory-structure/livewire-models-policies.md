# Directory Structure — Livewire, Models, Notifications, Policies, Providers, Rules

> Part of [Directory Structure](../directory-structure.md). **Read this part when:** you add a Livewire component, model, notification, policy, provider or custom rule and need to know where it goes. The other parts are listed in the [hub](../directory-structure.md#table-of-contents).

### `app/Livewire/`, `Models/`, `Notifications/`, `Policies/`, `Providers/` and `Rules/`

```
app/
  Livewire/            Livewire components, grouped by area (Users/, Roles/, SalesRegions/,
                       Media/, ProductCategories/, Products/, Products/AttributeTypes/, Components/,
                       Settings/, Settings/TwoFactor/, Actions/, Shipping/ — Zones.php (story 0033/
                       0034), Index.php (story 0035, a real routed screen shipped with a
                       placeholder view, mirroring Users/Index.php's own 0004→0006 split),
                       PaymentMethods/ — Index.php (story 0038, a real routed screen shipped with
                       a placeholder view, the same 0004→0006 split), Customers/ — Index.php
                       (story 0044, a real routed screen shipped with its real view in the same
                       story, consuming story 0041/0042's Customer model/actions/validation trait
                       and soft delete; its deleteCustomer() authorization guard lives in this
                       component rather than in a dedicated DeleteCustomer action, since none
                       exists — the same placement Users\Index::deleteUser() already establishes),
                       Show.php (story 0047, a second class in the SAME Customers/ folder as
                       Index.php but one level deeper in its view — the ordinary mirror rule, since
                       the class is not named Index, the naming.md "second real instance" of the
                       Index-flat/other-nested depth asymmetry Products/'s Index/Editor pair
                       already established; read-only, no public method mutates anything, gates
                       `customers.view` for the whole page and `orders.view` — OrderPolicy's own
                       first real caller — for the order-history section alone).
                       Orders/ — Index.php (story 0055, read-only, flat view livewire/orders.blade.php,
                       reflection-pinned public surface) and Show.php (the detail/editor, nested view
                       livewire/orders/show.blade.php; the only screen consuming every 0048-0052 write
                       action; `#[Locked]` orderId, method-injected actions, computeds read as
                       properties) — the Index-flat / other-nested depth asymmetry's second shipped
                       Index/Show pair, see naming.md.
                       Dev/ (story 0020, the media-gallery-harness
                       scaffolding) was RETIRED by story 0027 once Products/Editor supplied a real
                       host page — see below. Components/ (story 0021, extended by 0022) is not a module area
                       like the others — it holds reusable, content-agnostic components a screen
                       embeds rather than one screen's own logic (WysiwygEditor, SearchableMultiSelect)
                       plus the one supporting interface a consumer implements
                       (MultiSelectOptionsResolver) rather than a component itself; see the
                       wire:ignore section below. Products/VariantBuilder.php (story 0031) is
                       `Products/`'s third class and the app's first NESTED child component embedded
                       inside another module's own routed page (`Products/Editor`, never its own
                       route) rather than beside it — it re-declares `#[Locked] public string
                       $productId` and re-reads the parent `Product` with `findOrFail()` at the top
                       of every method, the 0022 D6 precedent applied verbatim; see
                       architecture/authorization.md for why it needs no `ProductVariantPolicy`
  Models/              Eloquent models (User, SalesRegion, Media, ProductCategory, Product,
                       ProductAttributeType, ProductAttributeValue, ProductVariant, GeographyEntry
                       — story 0032, the only bigint-PK model in this app; ShippingZone — story
                       0033; ShippingCarrier — story 0035, a second standalone catalog with no
                       relationships at all, the identical starting shape ProductCategory shipped
                       in; ShippingRate — story 0036, with two FKs (shipping_carrier_id,
                       shipping_zone_id) and the null-aware scopeCoveringWeight() bracket scope,
                       the one place the "and above" weight comparison may live; PaymentMethod —
                       story 0038, a third standalone catalog with no relationships at all, the
                       identical starting shape ProductCategory/ShippingCarrier shipped in; and
                       Customer — story 0041, Epic 3's first domain model, a fourth instance of
                       that same no-relationships-at-birth shape, with all fifteen writable
                       columns fillable and none withheld (D-7 — there is no seeder-owned or
                       server-derived column split here, unlike SalesRegion/Media); Order —
                       story 0045, Epic 3's second domain model and this app's first with FOUR
                       BelongsTo relations at once (customer, paymentMethod, salesRegion nullable,
                       shippingRate nullable) plus a hasMany (items), and the mass-assignment
                       guard's largest omission list yet (order_number, both status columns, all
                       four totals, tax_rate, flagged_for_review, sales_region_id,
                       shipping_rate_id — eleven columns, every one derived or a status a later
                       story owns); OrderItem — story 0045, five columns omitted
                       (product_name/product_sku/unit_price/line_total/refunded_quantity), since a
                       fillable unit_price would hand a caller the ability to set its own price;
                       Refund — story 0051, the refund event log
                       order_items.refunded_quantity/orders.refunded_amount derive from, with
                       amount/refunded_by omitted (derived arithmetic over a snapshotted price, and
                       the acting user's identity, respectively -- both written only via
                       App\Actions\Orders\RecordRefund's forceCreate());
                       BlogCategory — story 0058, the blog taxonomy and a standalone catalog
                       sharing no table, model or namespace with ProductCategory; `name` is the
                       only fillable column, `normalized_name` (the UNIQUE folded key) is derived
                       by a `saving` hook in booted() and never mass-assignable;
                       BlogTag — story 0059, a second standalone taxonomy with the same stored-key
                       shape (`name` 100, `normalized_name` 255, the folded length bounded in
                       validation); deliberately no `posts()` relation until story 0061 adds the pivot;
                       Role, which
                       subclasses
                       the package's role model). product_media, product_sales_region and
                       (story 0029) product_variant_values all have no model class of their own —
                       each reached only through the owning models' BelongsToMany (e.g.
                       Product::gallery(), ProductVariant::values()), the same shape the vendored
                       permission pivots use
  Notifications/       Notification classes (PendingEmailVerification, UserInvitation,
                       CustomerCreated — story 0043, `database` channel only, not ShouldQueue,
                       dispatched by Actions/Customers/NotifyCustomerCreated; OrderCreated —
                       story 0046, the same shape as CustomerCreated: `database` channel only,
                       not ShouldQueue, dispatched by Actions/Orders/NotifyOrderCreated)
  Policies/            Eloquent model policies (UserPolicy, RolePolicy, SalesRegionPolicy,
                       MediaPolicy, ProductCategoryPolicy, BlogCategoryPolicy — story 0058, four abilities on
                       the seeded `blog.*` permissions (D-8) with real call sites on all three
                       Blog actions from day one, BlogTagPolicy — story 0059, the same four
                       `blog.*` abilities on a second model (one `blog.*` tier gates every Blog
                       taxonomy; a shared BlogPolicy would not be auto-discovered for any of
                       them), ProductPolicy,
                       ProductAttributeTypePolicy, ShippingZonePolicy — story 0033, a pre-existing
                       gap in this listing closed here rather than left stale, ShippingRatePolicy
                       — story 0036, matching ShippingZonePolicy's shape exactly: four abilities,
                       no per-target rule, and real call sites on all three write actions from
                       day one, CustomerPolicy — story 0041's `viewAny`/`create`/`update`, joined
                       by story 0042's `delete()` (a fourth flat, tier-free ability, no
                       privilege-tier or ownership branch — a customer is a passive record, unlike
                       a `User` row), no per-target rule, modelled on SalesRegionPolicy), auto-discovered by name.
                       No ShippingCarrierPolicy exists (story
                       0035's D-9): `shipping.view`/`.edit` are authorized directly as permission
                       strings, named once on the model itself, since no per-target rule
                       justifies a policy. PaymentMethodPolicy — story 0038, four abilities;
                       create()/delete() explicitly return false (bank transfer is the only
                       method this phase), a documented exception for a Super Admin actor via
                       the Gate::before bypass. OrderPolicy — story 0045, the twelfth policy,
                       modelled directly on ShippingRatePolicy (D-13, a Phase 2 reversal of this
                       story's own original "no policy" recommendation): four flat abilities, no
                       per-target branch on any of them today. `create` is the only ability with
                       a real caller in this story (CreateOrder, self-authorizing); viewAny/
                       update/delete ship with no caller yet ON PURPOSE, since stories 0048-0052
                       add genuinely row-state-dependent rules (editing blocked once Shipped, a
                       refund refused outside Paid/PartiallyRefunded) as branches to update()'s/
                       delete()'s EXISTING body rather than relocating every call site's target.
                       Story 0049 grew OrderPolicy's ability roster to FIVE, adding
                       transitionStatus (reusing EDIT_PERMISSION, no new constant), with a real
                       caller from day one (TransitionOrderStatus). Story 0050 grew it to SIX,
                       adding cancel (CancelOrder) -- this policy's first ability requiring TWO
                       permissions (EDIT_PERMISSION AND the new ORDER_REFUND_PERMISSION
                       constant, D-6) and the first whose boolean genuinely depends on the
                       target row (Order::isManuallyCancellable()) -- see
                       architecture/authorization.md for the full, re-counted caller roster
  Providers/           Service providers (AppServiceProvider, FortifyServiceProvider)
  Rules/               Stock Laravel location (`make:rule`), not a new base folder — Iban.php,
                       story 0038, ISO 13616 structure plus the ISO 7064 mod-97 checksum,
                       computed with a chunked modulo (no new Composer dependency)
```
