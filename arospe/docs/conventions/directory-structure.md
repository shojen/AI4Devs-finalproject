# Directory Structure

Part of [Base Standards](base-standards.md) — see [base-standards.md](base-standards.md#stack-versions) for stack versions and the rest of this project's baseline conventions. This file covers the real `app/`/`routes/`/`database/`/`resources/`/`tests/` directory layout — what goes where, and why — plus the three directory-layout-adjacent conventions it enforces: config-file-as-registry, controllers-in-front-of-actions, and authorization-rule-belongs-to-the-action.

## Table of Contents

- [Directory structure](#directory-structure)
  - [An app-owned config file is a registry, and must survive `config:cache`](#an-app-owned-config-file-is-a-registry-and-must-survive-configcache)
  - [Controllers sit in front of actions, not instead of them](#controllers-sit-in-front-of-actions-not-instead-of-them)
  - [An authorization rule belongs to the action, not to one of its callers](#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)

## Directory structure

Real top-level layout — stick to it; don't create new base folders without approval (per project `CLAUDE.md`):

```
app/
  Actions/NormalizeForSearch.php  The one class directly under Actions/, no subfolder (story 0022) —
                       a shared search-term normalizer belonging to no single domain; the "or
                       directly under app/Actions/ if it belongs to none" branch of the rule below,
                       with its first real occupant
  Actions/Auth/        Cross-cutting auth-state actions (EnsureRecentPasswordConfirmation — the
                       step-up freshness guard; LogRefusedPrivilegedAttempt — the refusal audit
                       line; not an area, and not Fortify's)
  Actions/Customers/   Domain actions for the Customers area (CreateCustomer, UpdateCustomer —
                       story 0041; both self-authorize their own operation as their own first
                       statement from Phase 1, matching SalesRegions/ProductCategories' shape
                       rather than ProductCategories' own initial no-caller gap, since this
                       story's own D-12 wrote the self-authorizing requirement into its task file
                       before implementation began; NotifyCustomerCreated — story 0043, the
                       recipient-resolution + dispatch action CreateCustomer calls after a
                       successful create, authorizing NOTHING of its own -- the codebase's next
                       confirmed instance of "a collaborator invoked only by an already-authorized
                       action needs no gate", after SyncProductGallery/SyncProductSalesRegions/
                       SyncProductAttributeValues/EnforceGrantorPermissionScope)
  Actions/Fortify/    Fortify contract implementations (CreatesNewUsers, ResetsUserPasswords)
  Actions/Media/       Domain actions for the Media Library area (StoreUploadedImage — the atomic
                       upload/convert/insert; GenerateImageConversions — the only class in the app
                       that imports the imaging library; UpdateMediaDetails — the inline
                       title/description write, and MediaPolicy::update()'s first caller)
  Actions/Orders/      Domain actions for the Orders area (CreateOrder — story 0045, the sole
                       reachable enforcement point for this story since it ships no route or
                       component: self-authorizes `create` on Order::class as its own first
                       statement, resolves every catalog row it snapshots from the database
                       rather than trusting the payload -- reading a named variant's price
                       and SKU THROUGH its own item's already-resolved product, never as an
                       independent lookup, closing a related-id-pair price-manipulation finding
                       from this story's own Phase 4 audit (see
                       docs/security/related-id-pair-resolution.md) -- and wraps the whole
                       DB::transaction() in its own retry loop rather than using `attempts:`,
                       since every row it writes is BUILT INSIDE the closure via forceCreate();
                       NotifyOrderCreated — story 0046, the recipient-resolution + dispatch
                       action CreateOrder calls after its own persistence transaction commits
                       and after order_number is finalized, authorizing NOTHING of its own --
                       the codebase's next confirmed instance of "a collaborator invoked only by
                       an already-authorized action needs no gate", after NotifyCustomerCreated
                       (0043) and SyncProductGallery/SyncProductSalesRegions/
                       SyncProductAttributeValues (Products); AddOrderItem, RemoveOrderItem,
                       UpdateOrderItemQuantity — story 0048, each self-authorizing `update` on
                       the Order via LogRefusedPrivilegedAttempt as its own first check, strictly
                       BEFORE its own state-based hard block (assertEditable(), duplicated
                       identically across all three per D-5 rather than extracted -- extract
                       once a fourth call site appears), re-verified a SECOND time inside the
                       transaction under lockForUpdate() (Phase 4 finding F-4);
                       CalculateTaxAmount — story 0053a, the ONE `subtotal × (tax_rate ÷ 100)`
                       computation (percentage, half-up) composed by RecalculateOrderTotals and
                       ResolveOrderTaxRegion so the two cannot drift;
                       RecalculateOrderTotals — the shared D-7/D-8 totals-recomputation
                       collaborator all three call from inside their own transaction, authorizing
                       NOTHING of its own for the identical already-authorized-caller reason;
                       ToNumericString and AssertWithinColumnCeiling — two pure, dependency-free,
                       never-`new`-ed collaborators (a decimal-string narrower for bcmath, and
                       the decimal(10,2) column-overflow guard) mirroring CreateOrder's own
                       private methods of the same names rather than moving or duplicating them,
                       since this story's scope fences forbid refactoring CreateOrder beyond its
                       one named trait extraction; TransitionOrderStatus — story 0049, the
                       five-step ordered action moving an order along PRD 3.2's linear ladder
                       (permission -> Cancelled guard -> same-status guard -> unconfirmed-
                       regression guard -> forceFill write), self-authorizing `transitionStatus`
                       on OrderPolicy as its own first statement with a bare Gate::authorize()
                       rather than LogRefusedPrivilegedAttempt; RecordRefund — story 0051, the
                       eleven-step refund action (permission -> validate shape -> transaction ->
                       lock order+items -> payment-state guard -> ownership guard -> over-refund
                       guard -> write refunds rows + refunded_quantity -> increment
                       refunded_amount -> derive+write payment_status -> return), gating on a
                       BARE `Gate::authorize('orders.refund')` rather than an OrderPolicy ability
                       (DR-2) -- the state-based refusal is a ValidationException raised inside
                       the action itself, never a policy method; CancelOrder — story 0050, the
                       four-step action (permission -> already-Cancelled guard -> blocked-state
                       guard -> forceFill write) self-authorizing `cancel` on OrderPolicy as its
                       own first statement with a bare Gate::authorize(), NOT a call into or out
                       of TransitionOrderStatus (D-5) -- takes exactly one parameter, an Order,
                       with NO confirmation parameter (D-7, pinned by a reflection test); its
                       blocked-state guard (Order::isManuallyCancellable(), reading BOTH status
                       and payment_status) is a DIRECT THROW of OrderCancellationBlockedException
                       rather than a second Gate check, so it binds a Super Admin too -- unlike
                       an ordinary actor, whose Gate::authorize('cancel', ...) call already
                       refuses them via OrderPolicy::cancel()'s own parallel state clause before
                       this guard is ever reached, see architecture/authorization.md's Manual
                       order cancellation section)
                       ; AutoCancelFullyRefundedOrder — story 0052, the system-triggered
                       cancel a full refund causes: deliberately UNGATED (no Gate, no actor
                       read) and deliberately past both TransitionOrderStatus and CancelOrder,
                       see architecture/authorization.md
                       ; ResolveOrderTaxRegion — story 0053, resolves a physical order's tax
                       Sales Region from its OWN frozen shipping address, snapshots tax_rate and
                       derives tax_amount/total in one write. Deliberately UNGATED (D-11, callable
                       from a queued job) and deliberately NOT layered on Products\ResolveProductTaxRate
                       (D-1): that resolver answers a per-product display question, this one an
                       order's destination-based tax — two resolvers, neither calling the other
                       ; ResolveVirtualOrderSalesRegion — story 0054, the virtual-product sibling
                       of ResolveOrderTaxRegion: resolves tax from the order's OWN frozen BILLING
                       address instead of its shipping one, after a geo/fraud check comparing
                       `billing_country` against a captured `ip_derived_country` (mandatory per
                       this story's own D-9, overriding the task file's interim default) --
                       missing or mismatched IP data flags the order and resolves no tax, never
                       both (D-5). Composes the same ResolvesSalesRegionFromAddress trait as its
                       sibling and the same CalculateTaxAmount collaborator; a mixed physical/
                       virtual basket is flagged by whichever of the two actions runs (D-2, the
                       identical REASON_MIXED_BASKET token on both). Deliberately UNGATED, same
                       reasoning as ResolveOrderTaxRegion
  Actions/ProductCategories/ Domain actions for the Product Categories area (CreateProductCategory,
                       RenameProductCategory, DeleteProductCategory) — one action per operation
                       (story 0023). Unlike every other area's actions, none of the three authorize
                       their own operation; that is a deliberate, recorded hand-off to the not-yet-
                       built UI story (0025), not an oversight — see ProductCategoryPolicy below
  Actions/Blog/        Domain actions for the Blog area (CreateBlogCategory, RenameBlogCategory,
                       DeleteBlogCategory — story 0058; CreateBlogTag, RenameBlogTag,
                       DeleteBlogTag, FindOrCreateBlogTag — story 0059; each self-authorizes as its first
                       statement through LogRefusedPrivilegedAttempt, unlike ProductCategories/
                       above). FindOrCreateBlogTag is the area's one resolve-or-create action: it validates
                       format only (an existing name is a hit, not a refusal), asks `viewAny` on its reuse
                       branch and `create` on its insert branch, and resolves a lost insert race by
                       re-fetching the winner rather than refusing. DeleteBlogTag is complete as shipped —
                       unlike DeleteBlogCategory, no later story extends it. An AREA folder, not an entity folder: one `blog.*` permission tier
                       gates categories, tags and posts alike, so the folder mirrors the gate and
                       posts actions join it rather than opening sibling folders. Story 0061
                       extends DeleteBlogCategory in place with the in-use hard block
  Actions/Products/    Domain actions for the Products area (CreateProduct, UpdateProduct,
                       DeleteProduct — each self-authorizes, unlike ProductCategories/ above;
                       SyncProductGallery — the single writer of featured_media_id and every
                       product_media row, which deliberately authorizes NOTHING because it is a
                       collaborator invoked only by the two actions that already authorized the
                       whole operation, never an independently-reachable entry point (story 0024);
                       SanitizeProductDescription — the only class in the app that imports
                       symfony/html-sanitizer, mirroring how GenerateImageConversions confines the
                       imaging library to one class; constructor-injected into CreateProduct/
                       UpdateProduct as their third collaborator, sanitizing `description` before
                       validation (story 0024a); SyncProductSalesRegions — the single writer of the
                       product_sales_region pivot, authorizing NOTHING for the identical structural
                       reason SyncProductGallery does; ResolveProductTaxRate + ResolvedTaxRate — the
                       two-tier tax-rate resolver and its answer value object; SearchSalesRegions —
                       story 0022's MultiSelectOptionsResolver implementation for the region picker
                       (story 0026); CreateProductAttributeType, UpdateProductAttributeType,
                       DeleteProductAttributeType — each self-authorizes, matching CreateProduct/
                       UpdateProduct/DeleteProduct's shape; SyncProductAttributeValues — the single
                       writer of every product_attribute_values row for a type, authorizing NOTHING
                       for the identical structural reason SyncProductGallery/SyncProductSalesRegions
                       do (story 0028); CreateProductVariant, UpdateProductVariant,
                       DeleteProductVariant — each self-authorizes `update` on the variant's PARENT
                       Product, never a ProductVariantPolicy; HashVariantCombination,
                       DeriveVariantSku, TranslateProductVariantUniqueViolation — three pure,
                       dependency-free, never-`new`-ed classes (the combination hash, the SKU
                       derivation formula plus its `checked()` validating entry point, and the
                       shared disambiguator for product_variants' two unique-index race guards)
                       filed here rather than under an unapproved app/Support/ base folder, per the
                       "or directly under app/Actions/ if it belongs to none" branch below applied
                       one level narrower — domain-scoped rather than app-wide, so app/Actions/
                       Products/ rather than app/Actions/ itself (story 0029); GenerateProductVariant
                       Combinations — the cartesian "generate all combinations" batch action (story
                       0029b), which authorizes update on the parent Product ONCE, up front for the
                       whole batch, rather than once per generated row (see
                       architecture/authorization.md), re-implements nothing (every combination is
                       created through the ordinary CreateProductVariant, never a bulk insert()), and
                       constructor-injects all FOUR of LogRefusedPrivilegedAttempt/
                       CreateProductVariant/HashVariantCombination/DeriveVariantSku — the
                       code-style.md constructor-injection exception's next confirming instance after
                       CreateProductVariant's own four-collaborator shape, two of the four again pure
                       and dependency-free, with nothing new to decide there
  Actions/Roles/       Domain actions for the Roles area (EnforceAdministratorPermissionGrant,
                       EnforceGrantorPermissionScope — both pure transformers over a save payload)
  Actions/SalesRegions/ Domain actions for the Sales Regions area (UpdateSalesRegion,
                       SetDefaultSalesRegion, SetSalesRegionActive — each the single named writer
                       of the columns it owns; all three authorize their own operation)
  Actions/Shipping/    Domain actions for the Shipping area (CreateShippingZone,
                       RenameShippingZone, DeleteShippingZone, SyncShippingZoneGeography,
                       SearchGeographyEntries — story 0033; ToggleShippingCarrier — story 0035,
                       the single named writer of `shipping_carriers.is_active`, taking the
                       DESIRED state and re-reading its row under `lockForUpdate()` inside its
                       own transaction rather than trusting a caller-supplied instance;
                       CreateShippingRate, UpdateShippingRate, DeleteShippingRate — story 0036,
                       each self-authorizing against ShippingRatePolicy as its own first
                       statement, since this story ships no route or component and the action
                       is the only reachable enforcement point; ResolveApplicableShippingRate +
                       ShippingRateResolution — the ancestry-walk rate-precedence resolver and
                       its never-bare-null result object (see architecture/shipping.md);
                       ListShippingRatesByCarrier — the grouped-by-carrier query, deliberately
                       gating nothing of its own, 0037's gating consumer)
  Actions/Users/       Domain actions for the Users area (RequestEmailChange, ConfirmEmailChange,
                       CreateUser, UpdateUser — the last two authorize their own operation)
  Actions/PaymentMethods/ Domain actions for the Payment Methods area (UpdatePaymentMethodIban —
                       story 0038, the single writer of `payment_methods.iban`; self-authorizes
                       `update` as its own first statement, corrected during this story's own
                       Phase 4 security audit after shipping ungated on a since-disproven premise
                       — see database/schema-other.md#payment_methods)
  Concerns/            Shared traits (validation rule sets, incl. BlogCategoryValidationRules — story 0058; BlogTagValidationRules — story 0059 (two name-rule methods, `nameFormatRules()` and `nameRules()`, because create and find-or-create disagree about what an existing name means); ResolvesSalesRegionFromAddress — the country/Spain-postal-prefix → Sales Region mapping shared by the physical and virtual tax-region resolvers; ResolvesFlagReasonLabel — story 0055, the `flag_reason` → copy resolution shared by the orders list marker and the detail callout, so the two never word one flag differently)
  Console/Commands/    Artisan commands
  Enums/               Backed enums for domain value sets (UserStatus, RoleName, SalesRegionKind,
                       ProductType, ProductStatus — exactly two persisted cases — and
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
config/                Laravel + package config (fortify.php, permission.php,
                        intervention-image.php, livewire.php, ...), plus modules.php — the one
                        app-owned config file (see below)
database/
  data/                 Bundled, version-controlled fixture data a seeder reads — not seeder
                        classes (iso-3166-countries.json, shared read-only with story 0032's own
                        GeographyCatalogSeeder; es-municipalities.csv, story 0032's ~8,130-row
                        Spanish municipio fixture; plus its own README stating provenance for both)
  factories/
  migrations/
  seeders/
lang/                   Published translation files, one folder per locale (en/, es/), plus
                        app-owned domain files kept key-for-key identical across both
                        (users.php, roles.php, navigation.php, sales-regions.php, media.php,
                        components.php, products.php — the latter's categories.index subgroup is
                        story 0025's copy for the product categories screen; shipping.php;
                        payment-methods.php since story 0038; customers.php since story 0044 —
                        the file 0041 deliberately deferred to this story, D-14; orders.php since
                        story 0045 — statuses/payment_statuses, errors, transitions, refunds, cancellation,
                        flag_reasons, then (story 0055) the screen copy: index, detail, line_items, lifecycle;
                        en/es key parity pinned by tests/Feature/Orders/OrdersLangParityTest.php)
resources/
  views/
    components/        Blade components — all anonymous (no app/View/Components/ in this repo). Story
                        0055 added the first two shared ones that are not navigation chrome: money.blade.php
                        (`<x-money :amount>`, `€ {amount}` with no cast) and confirm-dialog.blade.php
                        (a stateless confirmation dialog whose parent owns the flag and both methods)
    layouts/            Auth/app layout shells
    livewire/           Views for Livewire components AND plain auth Blade views (see naming.md).
                        products/variant-builder.blade.php (story 0031) is the ordinary mirror-rule
                        pairing for Products/VariantBuilder.php above (naming.md's exception does not
                        apply — the class is not named Index), embedded from
                        products/editor.blade.php below a flux:separator, rendered only when
                        $productId !== null
    partials/
routes/                 web.php, plus one file per functional area that web.php requires
                        (settings.php, roles.php, users.php, sales-regions.php,
                        product-categories.php, product-attribute-types.php, products.php,
                        shipping.php, payment-methods.php, customers.php since story 0044, orders.php since story 0055) — no
                        api.php yet. web.php no longer
                        holds the story 0020/0021 environment-gated dev route (story 0020's
                        browser-test harness) — story 0027 retired it once Products/Editor
                        supplied a real host page; see ../api/routes.md
tests/
  Feature/              Feature tests, mirrors app structure (Actions/Auth/, Auth/, Settings/,
                        Seeders/, Users/, Roles/, SalesRegions/, Media/, ProductCategories/,
                        Products/, Components/, Models/, Policies/, Authorization/,
                        Navigation/, PaymentMethods/ since story 0038 — the story's own
                        Datasets.php holds the valid/invalid IBAN pairs shared across its four
                        test files, per Pest's own directory-scoped dataset convention (see
                        tests/Feature/ShippingRates/Datasets.php for the mechanism)). Dev/
                        (story 0020's MediaGalleryHarnessRouteTest.php) was
                        deleted by story 0027 along with its subject. Story 0031 adds five files to
                        Products/ for the nested VariantBuilder component, of which
                        VariantBuilderTest.php (create/duplicate-combination/sku-collision refusal/
                        delete/re-create round trip) and VariantBuilderAuthorizationTest.php (an
                        allow-and-deny pair per gated method, matching D-10's six-call-site fixture)
                        are the two to read first; VariantBuilderQueryTest.php pins the
                        featuredImage/values.type eager-load with no N+1 as either axis grows,
                        VariantBuilderRenderingTest.php covers refusal placement, the disabled-row
                        hints and the empty-state/no-attribute-types dead ends, and
                        VariantBuilderSkuPreviewTest.php pins the live #[Computed] preview against
                        0029's own derivation formula
  Unit/                 Mirrors app structure too (Actions/ itself — NormalizeForSearchTest.php,
                        story 0022, sits directly here with no subfolder, matching the app class it
                        tests — plus Actions/Auth/, Actions/Media/, Actions/Products/ (story 0029's
                        HashVariantCombinationTest.php/DeriveVariantSkuTest.php, unit-testing the two
                        pure-function collaborators directly rather than only through the actions
                        that inject them), Concerns/ (story 0023's
                        BlogCategoryValidationRulesTest.php (story 0058), BlogTagValidationRulesTest.php (story 0059), ProductCategoryValidationRulesTest.php, the first trait-level unit test in
                        this folder, joined by story 0024's ProductValidationRulesTest.php and
                        story 0038's PaymentMethodValidationRulesTest.php, which drives the real
                        Iban rule through ibanRules() rather than re-deriving mod-97 as abstract
                        math),
                        Enums/ (ProductStatusTest.php / ProductTypeTest.php since story 0024;
                        PaymentMethodCodeTest.php since story 0038),
                        Exceptions/, Listeners/, Models/, Seeders/ (story 0032's
                        GeographyCatalogSeederParsingTest.php — the CSV-parsing generator exercised
                        via reflection, no database — and GeographyFixtureIntegrityTest.php, which
                        parses the real bundled fixtures directly, its own file so a fast run can
                        exclude it), plus ArchitectureTest.php
  Support/              Test-only support code, not app code (story 0022, the suite's first use of
                        this base folder) — Livewire/ArrayMultiSelectOptionsResolver.php,
                        a conforming MultiSelectOptionsResolver double three later stories (0026,
                        0027, 0034) pattern-match their real resolvers against, and (story 0032)
                        Seeders/TestableGeographyCatalogSeeder.php, a GeographyCatalogSeeder
                        subclass whose fixture paths redirect to tests/Fixtures/geography/ — the
                        real seeder's own fixturePath() override hook exists specifically so this
                        test double can exist; autoloaded via composer.json's existing
                        "Tests\\": "tests/" mapping, no new autoload entry
  Browser/              Pest browser tests. Mirrors app structure (Auth/, Media/, Components/,
                        Products/ since story 0027) — but four of the eleven files sit flat instead
                        (UsersIndexTest.php, RolesIndexTest.php, SalesRegionsIndexTest.php,
                        ProductCategoriesIndexTest.php; the previous "three of eight" count here
                        missed ProductCategoriesIndexTest.php entirely, a gap present since story
                        0025 and corrected by story 0027's own pass); see
                        ../testing/frontend/playwright-setup.md#folder-structure. Story 0055 adds
                        Browser/Orders/ (six files, one per concern) and Support/Orders/OrdersUi.php
                        (shared permission profiles and rendered-HTML probes, a class of static methods
                        rather than global Pest helpers, which would redeclare-fatal across the many
                        Feature/Orders files)
  Browser/Fixtures/     Real, checked-in binary fixtures a browser test needs as bytes on disk
                        (sample-upload.jpg) — never generated at runtime
  Fixtures/geography/   Real, checked-in CSV fixtures for story 0032's seeder tests — a small
                        (521-row, all 17 comunidades represented) municipality CSV plus malformed/
                        duplicate/quoting variants, so a test never has to seed the whole ~8,300-row
                        real catalog to exercise the seeder's own logic
  Pest.php, TestCase.php
```

`app/Enums/`, `app/Exceptions/`, `app/Listeners/`, `app/Notifications/`, `app/Policies/`, `app/Rules/` and `lang/` are all **stock Laravel locations** (`make:enum`, `make:exception`, `make:listener`, `make:notification`, `make:policy`, `make:rule`, `lang:publish`), not new base folders — creating one of them needs no approval; inventing a folder Laravel doesn't ship does. `app/Rules/` is story 0038's own confirmation, per `php artisan list`, which carries `make:rule` with `app/Rules/` as its stub target.

`app/Policies/` in particular is **registration-free**: Laravel 13 auto-discovers `App\Policies\<Model>Policy` for `App\Models\<Model>`, so `UserPolicy` binds to `User` by naming alone. This repo has no `AuthServiceProvider` and does not need one — do not add one to register a conventionally-named policy. What each ability means lives in [architecture/authorization.md](../architecture/authorization.md#policies), not here.

`database/data/` is the one folder here that Laravel does **not** ship, so it needed the approval `CLAUDE.md` requires — it exists because [PRD §2.4](../PRD/PRD.md) mandates that the country list ship as a bundled fixture in this repository rather than as a Composer dependency (`league/iso3166`, `symfony/intl`). It holds **data a seeder reads**, never a seeder class and never generated output: today one JSON file plus [`database/data/README.md`](../../database/data/README.md), which states the fixture's provenance, its shape, what is deliberately excluded from it, and how to refresh it. A new file lands here only under the same test — bundled, reviewable in a diff, and read by something in `database/seeders/`.

**`app/Livewire/Dev/` (story 0020) was retired by story 0027, and this paragraph now records the retirement rather than the folder it used to describe.** It held `MediaGalleryHarness`, a throwaway host page whose only purpose was to give `App\Livewire\Media\Gallery` and (since story 0021) `App\Livewire\Components\WysiwygEditor` — both modal/embedded components with no route of their own — a URL a browser test could `visit()`. The four rules that separated that scaffolding from surface (a *registration*-time environment gate rather than middleware; `auth`+`verified` kept anyway as defence in depth; a test asserting absence from the route *collection*, not a 404; a named deletion trigger in every file it occupied) are recorded in this project's history rather than repeated here, since there is no longer a live instance to point them at. Story 0027's `App\Livewire\Products\Editor` — a real, routed page (`products.create`/`products.edit`) — turned out to be a strict superset of the harness (it embeds two `Gallery` instances and one `WysiwygEditor`, exactly the shape the harness mounted for its own tests), so both harness browser test files were re-pointed at it and made green **before** `App\Livewire\Dev\MediaGalleryHarness`, its view, its `routes/web.php` registration block and `tests/Feature/Dev/MediaGalleryHarnessRouteTest.php` were all deleted. **The scaffolding's own text said "if 0027 has shipped and this section still exists, it was not removed" — it does not, and it was.** See [api/products.md](../api/products.md#productsindex-productscreate-and-productsedit--the-fifth-permission-gated-route-family) for the migration itself.

`routes/` follows the same one-per-area shape: `web.php` declares only the app-wide routes (`home`, `dashboard`) and then `require`s one file per functional area — `settings.php`, `roles.php`, and `users.php` since task 0040, which moved `users.index` out of `web.php` so it stops being the one route that didn't follow the pattern, plus `sales-regions.php` since task 0017, `product-categories.php` since story 0025, `product-attribute-types.php` since story 0028, `products.php` since story 0027 (the first area file to register **two** routes, `products.create`/`products.edit`, onto one component), `shipping.php` since story 0033 (extended by story 0035 to a second route on the same file, `shipping.index` alongside `shipping.zones.index`), and `payment-methods.php` since story 0038. A new area's routes go in a new `routes/<area>.php` with its own middleware group, appended as another `require` line rather than inlined into `web.php`; what each route contract actually is belongs to [api/routes.md](../api/routes.md).

Task 0017 is the first area file written *from* this convention rather than into it, and it is worth reading as the copyable case: [`routes/sales-regions.php`](../../routes/sales-regions.php) is [`routes/roles.php`](../../routes/roles.php) with three strings changed, `web.php`'s entire diff is one `require` line, and and the `Index` class is imported **aliased** (`use App\Livewire\SalesRegions\Index as SalesRegionsIndex;`), matching what both existing area files already do: each file's own `use` statements can't actually collide, but `Index::class` read on its own line says nothing about which of the three areas it belongs to, and these files are read one at a time.

`app/Actions/` groups by concern, one subfolder per area: `Fortify/` holds the framework-contract implementations, `Users/`, `Roles/` and `SalesRegions/` the app's own domain actions for those areas. A new action goes in the subfolder for its domain (or directly under `app/Actions/` if it belongs to none) — never nested under an unrelated one. `Roles/` (task 0009) is the pattern to copy when a new module needs its first action: create the subfolder for the domain, even for a single class, rather than parking it in the nearest existing one. `SalesRegions/` (task 0017) is that rule applied unremarkably to a third module, which is the point — three classes, one folder, no discussion needed. `Media/` (story 0019, extended by 0020) is the fourth: three classes, one folder, and the split between them chosen for a reason worth copying rather than for tidiness — `GenerateImageConversions` is the **only** class in the application that imports the imaging library, so every other class is unaware of which package provides it, and a future "re-encode the whole library" Artisan command reuses it without touching `StoreUploadedImage`'s transaction, cleanup and row-insert logic. Story 0020's `UpdateMediaDetails` is the folder's third and the plainest possible instance of the rule below it: a two-column write that could have lived in the Livewire component, given its own class so that a non-Livewire caller inherits the same `Gate` check and the same `mediaDetailsRules()` validation.

`ProductCategories/` (story 0023) is the fifth: three classes, one folder, following `SalesRegions/`'s unremarkable shape exactly. **Corrected 2026-09-03 (story 0025) — the paragraph below described a deliberate, temporary gap that this story closed; it is quoted in full rather than silently rewritten, per this project's audit-authored-page convention.** It used to read: *"with one deliberate difference worth reading against the other four. Every action in `Users/`, `Roles/`, `SalesRegions/` and `Media/` authorizes its own operation (task 0008a's convention below); **none of `CreateProductCategory`, `RenameProductCategory` or `DeleteProductCategory` do**, because this story ships no caller at all — no route, no Livewire component — so there is nothing yet to authorize *against*. `App\Policies\ProductCategoryPolicy` is created and fully tested regardless (per [security/livewire-authorization.md](../security/livewire-authorization.md)'s "a rule enforced only in a component is bypassed by every other caller" reasoning), and the Definition of Done records the gap as an explicit hand-off: the not-yet-built UI story (0025) must call `Gate::authorize()` before invoking each action, the same way `App\Livewire\Users\Index` already does for `CreateUser`/`UpdateUser`."* Story 0025 is that hand-off, discharged: `CreateProductCategory`, `RenameProductCategory` and `DeleteProductCategory` now each authorize their own operation as their own first statement — `Users/`, `Roles/`, `SalesRegions/`, `Media/` and `ProductCategories/` all follow the same convention today, with `Products/` below the only folder whose fourth class (`SyncProductGallery`) is a documented exception rather than a gap. `App\Livewire\ProductCategories\Index` is `ProductCategoryPolicy`'s first and only call site, and it re-checks the same abilities in its own mutating/disclosing methods (`openCreateModal`, `openEditModal`, `save`, `confirmDelete`, `deleteProductCategory`) as defence in depth on top of the actions' own gates, matching the shape every other module screen on this page already uses.

`Products/` (story 0024) is **not** a repeat of `ProductCategories/`'s gap. `CreateProduct`, `UpdateProduct` and `DeleteProduct` **do** each authorize their own operation, exactly like `Users/`/`Roles/`/`SalesRegions/`/`Media/`. The fourth class in the folder, `SyncProductGallery`, authorizes nothing — but not because nobody built the caller: it has real callers today (`CreateProduct`/`UpdateProduct`, inside the same transaction), and it authorizes nothing *because* both of them already authorized the whole operation before calling it. A reflexive `update` check on its own target would in fact be **wrong**: `CreateProduct` inserts a row and calls it inside the same transaction, so `update` would be asked of an actor who legitimately holds only `products.create`, refusing a correct create halfway through. A collaborator invoked only by an already-authorized action needs no gate of its own — the same pattern `App\Actions\Media\GenerateImageConversions` (constructor-injected into `StoreUploadedImage`) and `App\Actions\Roles\EnforceGrantorPermissionScope` (called from `Roles\Index::saveRole()`, which authorizes first) already use. What makes the omission structural rather than an oversight: the class's own docblock states it, its two callers are its only callers, and `tests/Feature/Products/ProductAuthorizationTest.php` asserts that no other class under `app/` references it — if a later story ever calls `SyncProductGallery` directly, that story owns adding the gate.

`SyncProductSalesRegions` (story 0026) is the same pattern applied to the `product_sales_region` pivot, invoked only inside `App\Livewire\Products\Editor::save()` (story 0027)'s already-authorized transaction — `tests/Feature/Products/ProductSalesRegionAssignmentTest.php`'s reachability assertion names `Editor.php` as the one additional allowed file. The folder's other two 0026 classes self-authorize nothing for a *different*, docblock-stated reason: `ResolveProductTaxRate` is a pure read of values already visible to anyone holding `products.view`/`sales-regions.view` and may run from a queued job with no acting user at all, and `SearchSalesRegions` (the region picker's search/options resolver) treats the catalog data it discloses — name, active state, has-children — as uniformly visible to any authenticated admin reaching it.

Story 0028 adds four more classes to `Actions/Products/`. `CreateProductAttributeType`, `UpdateProductAttributeType` and `DeleteProductAttributeType` each self-authorize as their own first statement, applied at Phase 1 rather than found at audit. `SyncProductAttributeValues`, the fourth, is the single writer of every `product_attribute_values` row for a given type and deliberately authorizes **nothing**, invoked only inside the two type actions' already-authorized transaction — the same collaborator pattern as `SyncProductGallery`/`SyncProductSalesRegions` above, with the matching reachability assertion in `tests/Feature/Products/SyncProductAttributeValuesTest.php`. Unlike those two, which own a pivot, `SyncProductAttributeValues` owns a diff over a plain child table — it re-scopes every submitted value id against a fresh `$type->values()->pluck('id')` read before writing, which is what makes editing a type's value list never re-key a value that was not itself removed, the id-stability guarantee story 0029's variant combinations depend on; see [database/schema-products.md](../database/schema-products.md#product_attribute_values).

**Story 0022's [`App\Actions\NormalizeForSearch`](../../app/Actions/NormalizeForSearch.php) is the first real class to exercise the parenthetical half of the rule above** — "or directly under `app/Actions/` if it belongs to none" had named that branch since this convention was first written, with no example until now. It normalizes a search comparison value (trim → lowercase → accent-fold → collapse whitespace) for use by this codebase's shared searchable multi-select component and, per the task file's own consumer contract, by every later Epic 2 resolver that searches text (0026, 0032, 0033, 0034) — a concern that belongs to no single module area, so no subfolder is the correct shape rather than an omission. Its own docblock states the rule the class exists to enforce: no consumer may reimplement lowercasing, accent-stripping, trimming or whitespace collapsing inline — both sides of a search comparison route through this one class.

`Auth/` (task 0015a) is the first subfolder here that is **not** a module area, and it is worth reading as its own precedent. `EnsureRecentPasswordConfirmation` is called from three places in two different areas (`Actions/Users/UpdateUser`, `Actions/Users/CreateUser`, and `Livewire/Users/Index::deleteUser()`) and is expected to be called from more as later screens adopt step-up, so filing it under `Users/` would have made the next caller's import read as a cross-area dependency on a module it has nothing to do with. Note it is also **not** `Fortify/`: that folder is reserved for classes implementing a Fortify contract, and this one implements none — it only *reads* the session key Fortify's own controller writes. **The rule: a subfolder is either a module area or a named cross-cutting concern, and a class serving two areas belongs to the concern, not to whichever area called it first.** Do not add a `Shared/` or `Common/` folder for this — name the concern.

Task 0015b is the folder's second inhabitant and the case that confirms the rule rather than merely following it: `LogRefusedPrivilegedAttempt` is imported by **seven** classes across both module areas (`Livewire/Users/Index`, `Livewire/Roles/Index`, all three `Actions/Users/*` write actions, and both `Actions/Roles/*` transformers). Under an "it goes wherever the first caller lives" rule it would have landed in `Actions/Users/`, and the Roles side would import a Users class to record a Roles refusal. What it does — and the shape it shares with its folder-mate, a throwing wrapper around a non-throwing recorder — is in [architecture/authorization.md](../architecture/authorization.md#recording-a-refusal--what-every-gate-owes-the-audit-trail).

### An app-owned config file is a registry, and must survive `config:cache`

Every other file in `config/` is Laravel's or a package's. [`config/modules.php`](../../config/modules.php) (task 0013) is the first one this app wrote itself, and it establishes when that shape is right: **a config file is for a declarative registry that a later story extends by appending data — never for behavior, and never as a home for a value that has one caller.** The alternative considered and not taken was a PHP class or a service-provider `Gate::define()` loop; config won because appending an entry must not require reading code.

Two hard constraints come with it, both cheap to violate:

- **No closures, ever.** `php artisan config:cache` serialises the merged config with `var_export()`, which cannot represent a `Closure` — one closure anywhere in `config/` makes the command fail and, in a deployment that caches config, takes the whole app down. Every value must be a scalar, array, or `null`. Where a closure is the obvious reach (`'expanded_when' => fn () => request()->routeIs('roles.*')`), store the **data** instead (`'expanded_when' => 'roles.*'`) and let the consumer apply it. `tests/Feature/Navigation/SidebarModuleGatingTest.php` runs `config:cache` as an actual assertion rather than trusting review.
- **Store keys, not copy.** A registry entry holds a translation key (`'label' => 'navigation.items.users'`), resolved with `__()` at render. A literal English string in `config/` is unreachable from `lang/es/` — see [naming.md](naming.md#translation-keys).

✅ Good — the real registry entry, quoted verbatim; every value is a scalar or array, `label` is a translation key rather than copy, and `current_when` is the *pattern* (the consumer applies `request()->routeIs()` to it at render):

```php
// config/modules.php
'roles' => [
    'group' => 'settings',
    'label' => 'navigation.items.roles',
    'icon' => 'shield-check',
    'route' => 'roles.index',
    'current_when' => 'roles.*',
    'permissions' => ['roles.manage'],
],
```

❌ Bad — the same entry written the way it is tempting to (adapted to illustrate; not present in the repo). It breaks `config:cache` outright, and hardcodes English into a file `lang/es/` cannot reach:

```php
// anti-pattern — do not write this in any config/ file
'roles' => [
    'label' => 'Roles & permissions',
    'current_when' => fn () => request()->routeIs('roles.*'),
    'visible' => fn () => auth()->user()?->can('roles.manage'),
],
```

> ✅ **Task 0018 is the first story to extend this file, and it is the evidence for the paragraph above.** The Sales Regions screen's whole navigation change is two array literals appended to `config/modules.php` — a `groups.taxes` group and an `items.sales_regions` entry — plus one leaf per locale in `lang/{en,es}/navigation.php`. **No PHP class changed, and neither did the component that reads the registry**: `resources/views/components/sidebar-nav.blade.php` and `resources/views/layouts/app/sidebar.blade.php` are untouched by the story, verified against the diff. Both constraints above held on first contact — every appended value is a scalar or array (the entry's `expanded_when` is a literal `null`, never a closure over `request()`), and both `heading` and `label` are translation keys rather than copy, so `lang/es/` reaches them. The one thing the story had to *decide* rather than copy is the entry's key: `sales_regions` is this registry's first genuinely multi-word key, and [naming.md](naming.md#translation-keys) owns why it is snake_case on both sides.

> ⚠️ **[`config/html-sanitizer.php`](../../config/html-sanitizer.php) (story 0024a) is the app's second app-owned config file, and it does not fit the "registry a later story extends by appending data" shape this section describes — say so explicitly rather than forcing it in.** `config/modules.php` exists to be *appended to*: every later epic adds its own group/item entry, and the file's whole value is that appending never touches behavior. `config/html-sanitizer.php` is the opposite kind of thing — a **fixed security allow-list**. Its own task file states the rule directly: when Epic 4's blog body needs the identical sanitizer, it must **reuse this configuration exactly, not fork or extend it** with a second allow-list, because two allow-lists for the same trust boundary drift apart silently. There is no "later story adds a row" shape here at all — a later story is a *second consumer* of the whole file, never a *second contributor* to it. Both hard constraints from the paragraph above still hold and were verified rather than assumed: **no closures** — every value in the file is a scalar, string, or array of scalars/strings (`allowed_elements` maps tag names to attribute-name arrays, `dropped_elements`/`allowed_link_schemes`/`allowed_media_schemes` are plain string lists, `default_action` and `max_input_length` are a string and an int) — and **no user-facing copy**, which the file's own top-of-file comment states explicitly does not need the "translation key, not literal copy" half of the rule at all, since this file carries no copy of any kind, translatable or not. `App\Actions\Products\SanitizeProductDescription` is the one class that reads it, matching the "one config file, one reading component" shape `config/modules.php` established.

What this particular registry *means* — the gating rules, the per-entry ability requirement, and how a later epic plugs its module in — belongs to [architecture/authorization.md](../architecture/authorization.md#the-second-half-of-a-module-gate-the-sidebar-registry), not here.

### Controllers sit in front of actions, not instead of them

`App\Http\Controllers\ConfirmEmailChangeController` is this repo's first domain controller, and it exists for a specific reason worth generalizing: **a controller is added only when there is an HTTP-specific concern — route-parameter binding, building a redirect response — that an `app/Actions/` class should not absorb.** The action stays a plain domain operation; the controller adapts HTTP to it.

✅ Good — the real controller: it turns the URL's `{hash}` segment into a verified address, delegates, and branches on the action's `bool` result to pick a redirect:

```php
// app/Http/Controllers/ConfirmEmailChangeController.php
public function __invoke(User $user, string $hash, ConfirmEmailChange $confirmEmailChange): RedirectResponse
{
    if ($user->pending_email === null || ! hash_equals(sha1($user->pending_email), $hash)) {
        return redirect()->route('profile.edit')->with('status', __('users.email_change.refused'));
    }

    if (! $confirmEmailChange($user, $user->pending_email)) {
        return redirect()->route('profile.edit')->with('status', __('users.email_change.refused'));
    }

    return redirect()->route('profile.edit')->with('status', __('users.email_change.confirmed'));
}
```

Note the action is injected as a **trailing container-resolved parameter**, after the route parameters — the same per-method action-injection convention the Livewire components use (see [code-style.md](code-style.md#inject-single-purpose-actions-per-method)).

❌ Bad — routing the action class directly (adapted to illustrate; this is what the controller exists to avoid):

```php
// anti-pattern — do not do this
Route::get('settings/email/confirm/{user}/{hash}', ConfirmEmailChange::class);
```

`ConfirmEmailChange::__invoke(User $user, string $email)` takes the *address*, while the URL's second segment is `{hash}`. Laravel binds non-class-typed parameters positionally against the remaining route parameters, so the hash would land in `$email` and the equality check could never succeed — silently, with no error. On top of that, the action returns `bool`, which cannot be a response.

Corollary: don't invert this either. A controller that re-implements the domain logic instead of delegating to an action puts business rules somewhere the Livewire components and future admin screens can't reuse them.

### An authorization rule belongs to the action, not to one of its callers

Task 0008a established this by removing a real gap: the Administrator-tier guards lived only in `App\Livewire\Users\Index`, so `CreateUser` / `UpdateUser` were **completely ungated** for any other caller — a future API endpoint, Artisan command or queued job would have inherited nothing. The rule: **if an operation must not happen without a permission, the check lives in the class that performs the operation.** A caller may authorize too (defence in depth), but it may not be the only place the rule exists.

> **The converse is not true, and task 0015 is the case that shows it.** A check that guards something the *caller alone* does — a Livewire opener copying a target's attributes into public component state — belongs in the caller, and there is no action to move it to: no `app/Actions/` class performs that disclosure. `App\Livewire\Users\Index::openEditModal()`'s `Gate::authorize('updateSensitiveAttributes', $target)` is such a check, and it is **not** a regression of the rule above. Read it as: the rule follows the *operation*, and "hand these attributes to the client" is an operation the component owns. What still may not reappear in a component is a re-derivation of *who the target is* — the tier lookup 0008a deleted; see [security/livewire-authorization.md](../security/livewire-authorization.md#the-shipped-disclosure-gates-and-why-the-disclosure-check-is-the-stronger-ability).
>
> **Task 0015a adds the second, weaker case, and it is weaker on purpose.** Its step-up guard lives in `UpdateUser` and `CreateUser` — the actions — for role, status, email and Administrator-tier creation, exactly as the rule above demands. For **deletion** it lives in `App\Livewire\Users\Index::deleteUser()`, and only because there is no `DeleteUser` action to move it to: that method calls `$target->delete()` on the model directly. That is an accepted placement pending a class to hold it, not a second exception to the rule — **if a later story extracts a `DeleteUser` action, the guard moves with it.** Record a placement like this in the method's own docblock (as `deleteUser()` does) so the next reader can tell "this is where it belongs" from "this is where it is until something better exists".

✅ Good — the action authorizes as its own first statements, before opening any transaction:

```php
// app/Actions/Users/CreateUser.php
public function __invoke(string $name, string $email, string $roleId, UserStatus $status): User
{
    Gate::authorize('create', User::class);
    // ...
}
```

❌ Bad — the shape this replaced (adapted from the deleted `Index::createNewUser()`; the action itself checked nothing):

```php
// anti-pattern — the rule is a property of one caller, not of the operation
if ((int) $validated['roleId'] === $this->administratorRoleId()) {
    Gate::authorize('promoteToAdministrator', User::class);
}

$createUser(/* ... */);
```

Three constraints that come with it, each learned from this story's audits:

- **Move the rule, never copy it.** Two implementations of one rule is drift waiting to happen; `Index::authorizeRoleChange()` and `administratorRoleId()` were *deleted*, not converted.
- **Derive a security-relevant flag internally; never take it as a parameter.** `UpdateUser` used to receive `bool $applyRoleAndStatus` — the self-lockout guard — from its caller. Once an action is independently callable, that is a one-argument bypass, so the action now derives it from `Auth::user()` itself.
- **Authorize before the first write, and re-read what you authorize against.** Every check sits above the action's `DB::transaction()`, and any relation an authorization decision consults is reloaded before the first check that reads it — see [security/authorization-patterns.md](../security/authorization-patterns.md#authorization-that-consults-a-relation-must-reload-it-before-the-first-check-reads-it).

> **Task 0017 is the first story where this convention cost nothing, because it was applied at Phase 1 rather than found at Phase 4.** All three `app/Actions/SalesRegions/` actions authorize `update` as their own first statement, and `SetSalesRegionActive` additionally authorizes the **replacement default** row — the second row its operation writes — so a non-dashboard caller inherits the whole rule and not just the part about its named target. Two things generalise from it. **(a) Authorize every row the operation writes, not only the one it is named after** — the row-level counterpart of the [attribute-level rule](../security/authorization-patterns.md#an-ability-must-cover-every-attribute-that-achieves-its-effect-not-only-the-operation-it-is-named-after) task 0004 established. **(b) The component authorizing too is defence in depth, not duplication to remove.** `App\Livewire\SalesRegions\Index` re-checks the same ability on both rows before calling either action; the action's check is what a queued job or Artisan caller inherits, and the component's is what fails fast before a transaction opens and what makes the per-row `canEdit` hint honest. A reviewer who deletes one of the two has removed a layer, not a redundancy.
>
> ⚠️ **"Authorize before the first write" and "re-read what you authorize against" pull in opposite directions once an action locks its own rows,** and task 0017 is where they first meet. These actions authorize against the **caller-supplied** instance, *outside* the transaction — deliberately, so a refusal never opens one — and only then re-fetch the row under `lockForUpdate()`. That is safe only while `SalesRegionPolicy::update()` ignores its target entirely. The day any policy grows a branch that reads a target attribute, that branch must be evaluated against a re-fetched row; see [architecture/authorization.md](../architecture/authorization.md#salesregionpolicy--the-third-policy-and-the-first-with-no-target-branch) and [security/model-instance-trust.md](../security/model-instance-trust.md).

What the rules themselves say, and why a rule that must bind a Super Admin actor is a direct `throw` rather than a `Gate` check, belongs to [architecture/authorization.md](../architecture/authorization.md#the-guard-belongs-to-the-action-not-to-the-caller), not here.


_Last updated: 2026-09-23 — Story 0055 (orders list + detail/editor UI). Added `app/Livewire/Orders/` (Index flat, Show nested), `routes/orders.php`, the `ResolvesFlagReasonLabel` concern, the first two shared non-chrome anonymous components (`money`, `confirm-dialog`), `tests/Browser/Orders/` and `tests/Support/Orders/`, and corrected the `lang/orders.php` entry (it now carries screen copy). Earlier history folded: 0054/0053 added the two tax-region resolver actions and their shared trait; 0052/0051/0050/0049/0048 grew `app/Actions/Orders/`, `app/Exceptions/` and `OrderPolicy`, and corrected the `OrderStatus::label()` note. Each is described in its own entry above._
