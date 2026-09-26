# Directory Structure — app/Actions/ — domain actions per area

> Part of [Directory Structure](../directory-structure.md). **Read this part when:** you add or place an action class: which `app/Actions/<Area>/` folder it belongs to, and what each area's actions authorize. The other parts are listed in the [hub](../directory-structure.md#table-of-contents).

### `app/Actions/` — domain actions per area

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
                       DeleteBlogTag, FindOrCreateBlogTag — story 0059; CreateBlogPost, UpdateBlogPost,
                       DeleteBlogPost (a soft delete), RestoreBlogPost, SyncBlogPostTags (the single writer of
                       blog_post_tag; authorizes nothing, its callers did) — story 0061; each other action self-authorizes as its first
                       statement through LogRefusedPrivilegedAttempt, unlike ProductCategories/
                       above). FindOrCreateBlogTag is the area's one resolve-or-create action: it validates
                       format only (an existing name is a hit, not a refusal), asks `viewAny` on its reuse
                       branch and `create` on its insert branch, and resolves a lost insert race by
                       re-fetching the winner rather than refusing. DeleteBlogTag is complete as shipped —
                       unlike DeleteBlogCategory, no later story extends it. An AREA folder, not an entity folder: one `blog.*` permission tier
                       gates categories, tags and posts alike, so the folder mirrors the gate and
                       posts actions join it rather than opening sibling folders. Story 0061
                       extended DeleteBlogCategory in place with the in-use hard block (a
                       withTrashed() count). NotifyBlogPostPublished is a no-op PLACEHOLDER created by
                       0061 because CreateBlogPost/UpdateBlogPost must call it and story 0065, which
                       owns the real notification, depends on 0061; 0065 keeps its `__invoke(BlogPost): void`
                       signature and fills the body in. PublishScheduledBlogPost (story 0064) is the area's ONE
                       action that does not self-authorize: system-triggered and deliberately ungated, one
                       conditional UPDATE per post, reached only from the scheduled command (see
                       architecture/authorization/domain-invariants.md#a-system-triggered-write-may-be-ungated--autocancelfullyrefundedorder-and-the-three-conditions-that-make-it-safe)
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
```
