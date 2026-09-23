# Validation Trait Naming Conventions

Part of [Naming Conventions](naming.md) — see [naming.md](naming.md#classes) for class/file naming and the rest of this project's naming conventions. This file covers the `<Noun>ValidationRules` trait-and-method naming convention on its own, since it had grown into the largest section of `naming.md`.

## Table of Contents

- [Traits and their methods](#traits-and-their-methods)

## Traits and their methods

Validation-rule traits are suffixed `ValidationRules`, and every public method on them is suffixed `Rules` and returns a rule array — no exceptions in the current codebase:

```php
// app/Concerns/PasswordValidationRules.php — trait name ends in "ValidationRules"
trait PasswordValidationRules
{
    protected function passwordRules(): array { /* ... */ }        // ends in "Rules"
    protected function currentPasswordRules(): array { /* ... */ } // ends in "Rules"
}
```

```php
// app/Concerns/ProfileValidationRules.php
trait ProfileValidationRules
{
    protected function profileRules(?string $userId = null): array { /* ... */ }
    protected function nameRules(): array { /* ... */ }
    protected function emailRules(?string $userId = null): array { /* ... */ }
}
```

```php
// app/Concerns/UserValidationRules.php
trait UserValidationRules
{
    protected function roleRules(): array { /* ... */ }
    protected function statusRules(): array { /* ... */ }
}
```

```php
// app/Concerns/RoleValidationRules.php
trait RoleValidationRules
{
    protected function roleNameRules(?int $roleId = null): array { /* ... */ }
    protected function rolePermissionRules(): array { /* ... */ }
}
```

```php
// app/Concerns/MediaValidationRules.php
trait MediaValidationRules
{
    public const MAX_UPLOAD_KB = 8192;       // a constant, not a rule method — see below
    public const MAX_DIMENSION = 4000;

    protected function imageUploadRules(): array { /* ... */ }
    protected function mediaDetailsRules(): array { /* ... */ }
}
```

```php
// app/Concerns/SalesRegionValidationRules.php
trait SalesRegionValidationRules
{
    protected function rateRules(): array { /* ... */ }
    protected function codeRules(): array { /* ... */ }
    protected function descriptionRules(): array { /* ... */ }
    protected function replacementDefaultRules(): array { /* ... */ }
}
```

`SalesRegionValidationRules` (task 0017) is the convention applied unchanged to a fourth trait — `<Noun>ValidationRules`, every method `<noun>Rules()`, flat and single-concern, composed at the consumer (`use SalesRegionValidationRules;` in `App\Livewire\SalesRegions\Index`). One thing it demonstrates that the others do not: **a `<noun>Rules()` method's noun is the *field*, not the model**, so a trait named after the model can hold `rateRules()` / `codeRules()` — the model name is not repeated in each method. `replacementDefaultRules()` names the field it validates (`$replacementDefaultId`) rather than the operation that submits it.

```php
// app/Concerns/ProductValidationRules.php
trait ProductValidationRules
{
    protected function productNameRules(): array { /* ... */ }
    protected function productSkuRules(?string $productId = null): array { /* ... */ }
    protected function productCategoryIdRules(): array { /* ... */ }
    protected function productTypeRules(): array { /* ... */ }
    protected function productStatusRules(): array { /* ... */ }
    protected function productPriceRules(): array { /* ... */ }
    protected function productStockRules(): array { /* ... */ }
    protected function productDescriptionRules(): array { /* ... */ }
    protected function productFeaturedMediaIdRules(): array { /* ... */ }
    protected function productGalleryMediaIdsRules(): array { /* ... */ }
}
```

`ProductValidationRules` (story 0024) is a **deliberate, reasoned exception to the field-not-model rule directly above** — every method is entity-prefixed (`productNameRules()`, `productSkuRules()`, …), not `nameRules()`/`skuRules()`. This is not a style preference; it is forced by a real, verified PHP fatal error. `App\Concerns\ProductCategoryValidationRules` and `App\Concerns\ProfileValidationRules` **already both** declare `nameRules()`, and `App\Concerns\SalesRegionValidationRules` **already** claims `descriptionRules()` — PHP raises a fatal error the moment two traits composed onto one class declare the same method name. **Corrected 2026-09-04 (story 0027) — the sentence naming the "obvious future consumer" was wrong about which trait that consumer composes, and is quoted here rather than silently rewritten, per this project's audit-authored-page convention.** It used to read: *"…and the obvious future consumer (story 0027's product editor, which needs a create-a-category-on-the-fly control) composes exactly `ProductValidationRules` with `ProductCategoryValidationRules`."* Story 0027 shipped with no create-a-category-on-the-fly control at all — `App\Livewire\Products\Editor`'s category field is a plain `<flux:select>` populated from `categoryOptions()`, reading the existing catalog, with no inline "create a new category" affordance — so its class composes only `ProductValidationRules` (`use ProductValidationRules;`, verified against the shipped file), never `ProductCategoryValidationRules`. The collision the entity-prefix exception forestalls (`ProductCategoryValidationRules`/`ProfileValidationRules` already claiming `nameRules()`, `SalesRegionValidationRules` already claiming `descriptionRules()`) is therefore still real and still the reason for the prefix — it has simply not been exercised by any shipped consumer yet, and the rule was written defensively rather than reactively. The prefix is applied **uniformly across every method in the trait, not selectively** — an earlier draft exempted `descriptionRules()`, which is precisely the one name that collides today, and a blanket rule reviewed in one glance is safer than a per-method judgement about which names *might* collide with a trait that does not exist yet. Every method still ends in `Rules`, so the half of the convention that governs discoverability at the call site is unchanged; only the noun gains a prefix, and only in this trait.

✅ Good — the real, shipped naming: `productDescriptionRules()`, which would otherwise collide with `SalesRegionValidationRules::descriptionRules()` the moment both traits are composed onto one class.
❌ Bad — the field-not-model form this trait would otherwise use, and the one collision that is real today (adapted to illustrate; not present in the repo): `descriptionRules()` on `ProductValidationRules` fatals with `PHP Fatal error: Trait method descriptionRules has not been applied, because there are collisions with other trait method names` the instant a consumer also `use`s `SalesRegionValidationRules`.

> ⚠️ **Narrowed 2026-09-03 (story 0026, Phase 5 finding N-8) — the paragraph above's claim that the prefix is "applied uniformly across every method in the trait, not selectively" is no longer true of the whole file, and is quoted here rather than silently rewritten, per this project's audit-authored-page convention.** Story 0026 added two methods to this same trait, `salesRegionIdsRules()` and `salesRegionIdRules()`, and neither is entity-prefixed — they are not `productSalesRegionIdsRules()`/`productSalesRegionIdRules()`. That is correct, not an inconsistency: the two methods name the **related Sales Region entity**, not one of the product's own fields, so 0024's collision-driven exception was never a candidate rule for them in the first place — no other trait `ProductValidationRules` composes with declares `salesRegionIdsRules()`/`salesRegionIdRules()` (verified, not assumed), so the plain field-not-model rule at the top of this section governs them instead. Read "applied uniformly across every method in the trait" as scoped to what it always meant in practice — **uniform within the product-field group**, every method naming one of the product's own fields — rather than literally every method the file will ever hold. Nothing about the reasoning for *why* the product-field methods are prefixed has changed.

`App\Concerns\ProductAttributeValidationRules` (story 0028) reads as entity-prefixed at a glance — `attributeTypeNameRules()`, `attributeValueListRules()`, `attributeValueRowRules()`, `attributeValueIdRules()`, `attributeValueRules()` — but it isn't `ProductValidationRules`'s collision exception applied again: none of the five names an existing sibling trait method (`nameRules()`/`valueRules()`/`typeNameRules()` are all free), so the prefix here follows the plain *field-not-model* rule — `attributeTypeName`/`attributeValue` are the actual submitted field names, not the model name repeated. The two row-shape methods (`attributeValueRowRules()`, `attributeValueIdRules()`) exist only to close a security finding (see [database/schema-products.md](../database/schema-products.md#product_attribute_values)) and are named after the wildcard path they gate (`values.*`, `values.*.id`), not after any business field.

`App\Concerns\ProductVariantValidationRules` (story 0029) is entity-prefixed for the same collision reason as `ProductValidationRules` — its methods (`variantCombinationRules()`, `variantCombinationValueRules()`, `variantPriceRules()`, `variantStockRules()`, `variantFeaturedMediaIdRules()`) would otherwise collide with `ProductValidationRules`'s own `productPriceRules()`/`productStockRules()`/`productFeaturedMediaIdRules()` the moment a future variant editor composes both traits. It deliberately does **not** `use ProductValidationRules`, and declares **no** `skuRules()` at all — `product_variants.sku` is server-derived, never typed, so exposing `productSkuRules()` here would let a caller validate an input that must never exist. `variantCombinationRules()`/`variantCombinationValueRules()` are a **two-pass** split — bounding the submitted array's own shape/size, then validating each element — named for the *pass*, not for a submitted field (`attributeValueIds` is the field, and there is no `attributeValueIdsRules()`/`attributeValueIdRules()` pair the way `ProductAttributeValidationRules` names its own row-shape methods). Story 0029b appended two more methods to this same trait rather than opening an eighth: `variantAttributeTypeIdsRules()` (bounding `attributeTypeIds` itself) and `variantAttributeTypeIdRules()` (bounding each element) — the generator's own two-pass pair, named for the identical reason. A second `ProductVariantGeneratorValidationRules` trait was considered and rejected — it would just duplicate the same `variant`-scoped concern this section's flat, single-concern rule already argues against.

`App\Concerns\ShippingZoneValidationRules` (story 0033) was missing from this page until this pass — closed in place rather than carried forward. Its name method, `shippingZoneNameRules()`, is entity-prefixed for the same `nameRules()` collision `ProductValidationRules` avoids. Its second method, `geographyEntryIdsRules()`, deliberately **diverges** from [security/array-validation-bounds.md](../security/array-validation-bounds.md)'s two-pass `max:` + `.*` shape in favour of one bounded `whereKey($ids)->count()` query over the whole array — a documented choice, not an oversight.

`App\Concerns\ShippingRateValidationRules` (story 0036) is entity-prefixed only on its name method, `shippingRateNameRules()`, for the same `nameRules()` collision; its six other methods (`minWeightRules()`, `maxWeightRules()`, `priceRules()`, `deliveryEstimateRules()`, `shippingCarrierIdRules()`, `shippingZoneIdRules()`) are plain field names, since none collides today. `maxWeightRules()` is a load-bearing null-aware pair: `'nullable'` must be its **first** rule (an absent `max_weight_kg` means "and above," not "invalid"), and `'gte:min_weight_kg'` is written on the *max* field rather than `'lte:max_weight_kg'` on the *min* field, so the comparison never runs when there is nothing on the other side of it. See [database/schema-shipping.md](../database/schema-shipping.md#shipping_rates) and [architecture/shipping.md](../architecture/shipping.md).

`App\Concerns\CustomerValidationRules` (story 0041) mirrors `UserValidationRules`/`ProfileValidationRules` in shape, but its four rule methods are **entity-prefixed** — `customerNameRules()`, `customerEmailRules()`, `customerPhoneRules()`, `customerAddressRules(string $prefix)` — because `ProfileValidationRules` already declares `nameRules()`/`emailRules()` and `ProductCategoryValidationRules` already declares `nameRules()`. The prefix applies uniformly across all four, not only the two that collide today, on the same reviewed-in-one-glance reasoning `ProductValidationRules` uses. `customerAddressRules()` extends `SalesRegionValidationRules`'s "the noun is the field, not the model" shape one step further: it takes a `$prefix` (`'shipping'`/`'billing'`) and returns rules for six columns at once, so one method serves both address blocks rather than duplicating the six rules twice.

**`CustomerValidationRules` also carries this section's first method that does not end in `Rules` and returns no rule array — `normalizeCustomerAttributes()`.** Normalising a blank optional field to a real `null` (and uppercasing the two country codes) must happen **before** `Validator::make()` runs, since Laravel skips every non-implicit rule for a blank string rather than rejecting it (see [errors-log.md](../errors-log.md#livewire-skips-convertemptystringstonulltrimstrings-and-laravel-skips-non-implicit-rules-for-a-blank-string--the-two-combine-to-let-a-raw--reach-a-decimal-column--2026-09-10)). That is a data transformation, not a rule, so `<noun>Rules()` cannot express it. The trait also carries `public const OPTIONAL_FIELDS` (the field list this method iterates) — a constant, not a rule method, mirroring `MediaValidationRules::MAX_UPLOAD_KB`/`MAX_DIMENSION` — readable only through a consuming class (e.g. `App\Actions\Customers\CreateCustomer::OPTIONAL_FIELDS`), since PHP refuses a direct trait-constant reference.

`App\Concerns\OrderValidationRules` (story 0045) mirrors `UserValidationRules`/`CustomerValidationRules` exactly — no entity-prefixing needed, since none of its four method names (`orderCustomerRules()`, `orderPaymentMethodRules()`, `orderItemsRules()`, `orderItemRules()`) collides with a sibling trait. It carries this section's first pair of `public const` bounds on an *array's own shape* used purely for input-size defence rather than a business rule — `MAX_ITEMS` (100, the ceiling on how many line items one order may carry) and `MAX_ITEM_QUANTITY` (10000, the ceiling on one line item's `quantity`) — read directly by `App\Actions\Orders\CreateOrder` rather than only by the trait's own rule methods, the same "constant readable through a consuming class" shape `MediaValidationRules::MAX_UPLOAD_KB`/`CustomerValidationRules::OPTIONAL_FIELDS` already establish. `orderRules()` is also this section's clearest instance of the "one per-item rule set fanned out under `items.*.<field>`" shape: `orderItemRules()` returns its rules keyed by the item's own bare field name, and `orderRules()` prefixes each key once rather than duplicating the per-item rule array — see [security/array-validation-bounds.md](../security/array-validation-bounds.md) for why the array-level `max:` rule (`orderItemsRules()`) must be validated in its own early, separate `Validator::make()` call before the fanned-out `.*` rules ever run, rather than bounding cost merely by appearing in the same rule set.

**Story 0048 appends three more methods to this same trait rather than opening a second one** — `orderItemQuantityRules()`, `orderItemProductRules(string $productId)`, `orderItemOwnershipRules(string $orderId)` — none entity-prefixed, since none collides with a sibling trait's method name either. `orderItemQuantityRules()` is **extracted, not duplicated**: story 0045's `orderItemRules()` already inlined the identical `['required', 'integer', 'min:1', 'max:...']` array, and the task file's own Phase 3 instruction was to pull it out into its own method and have `orderItemRules()` call it, so the create path and the edit path (`AddOrderItem`/`UpdateOrderItemQuantity`) share one rule rather than risk it drifting silently between the two. `orderItemProductRules(string $productId)` is the edit path's counterpart to `orderItemRules()`'s own product/variant pair, and the reason it differs — a **scoped** `Rule::exists('product_variants', 'id')->where('product_id', $productId)` rather than a bare existence check — is that `AddOrderItem` receives `$productId` as a plain scalar argument already known before validation runs, where `orderItemRules()`'s own `items.*` array element cannot see its sibling `product_id` at rule-construction time (see `CreateOrder`'s own F-1 docblock and [security/related-id-pair-resolution.md](../security/related-id-pair-resolution.md)). `$productId` has **no default** — Phase 5 code review finding F-B — since one would silently degrade the scoped check back to an unscoped `Rule::exists()` for any future caller that omits it, the exact [omission-ambiguity failure mode](../errors-log.md#an-actions-own-parameter-default-reintroduced-the-omission-ambiguity-its-stricter-collaborator-was-built-to-close--2026-09-01) already logged. `orderItemOwnershipRules(string $orderId)` is what turns a cross-order line-item id into a **validation** failure (`Rule::exists('order_items', 'id')->where('order_id', $orderId)`) rather than a 404 or a silent no-op — without it, `RemoveOrderItem($orderA, $itemFromOrderB)` would delete B's row and recompute A's totals, corrupting two orders from one call (story 0048's R-5).

`App\Concerns\BlogCategoryValidationRules` (story 0058) declares plain `nameRules()` and `blogCategoryRules()` — the same `nameRules()` name `ProfileValidationRules` and `ProductCategoryValidationRules` already declare, so composing it into one class with either is the same fatal trait-method collision described above; the blog category actions (and the future UI component) use it alone. It departs from `Rule::unique()` in two ways worth knowing before copying it: uniqueness is a closure over the stored `normalized_name` column (the value compared must be the candidate's *normalised* form, which `Rule::unique()` cannot express), and a second closure refuses any name whose folded form would not fit that column. It also carries a non-rule helper, `trimName()`, a Unicode-aware trim the actions call before validating — PHP's `trim()` leaves non-breaking and zero-width spaces that the normaliser later folds to a plain space.

**A note on the numbering above.** Any ordinal attached to a trait in this section ("fourth", "seventh", …) is unreliable — several traits (`ProductValidationRules`, `ProductCategoryValidationRules`, `ShippingZoneValidationRules`) were introduced with no ordinal at all, silently invalidating every later count. Rather than renumber retroactively, this page states a verified fact instead: **sixteen `<Noun>ValidationRules` traits exist in `app/Concerns/` as of story 0058** (`ls app/Concerns/*ValidationRules.php`, recounted rather than incremented blind — the prior "thirteen … as of story 0041" count on this line was itself already one short even at 0041, since it did not count `PaymentMethodValidationRules` from the earlier story 0038). Cite a trait by name, not by position.

Note `RoleValidationRules` (task 0010) and `UserValidationRules` are **different traits about different things** despite the near-collision in name: the former validates a *`Role` row's* own fields, the latter validates the *role/status a user is being assigned*. Both are named after the model whose input they describe, which is the rule — not after the screen that submits it.

When adding a new validation concern, follow this exact pattern: `<Noun>ValidationRules` trait, `<noun>Rules()` methods — don't introduce a differently-named alternative (e.g. `getPasswordValidation()`). Traits stay **flat and single-concern**, composed at the consumer (`use ProfileValidationRules, UserValidationRules;` in `App\Livewire\Users\Index`, mirroring `CreateNewUser`'s `use PasswordValidationRules, ProfileValidationRules;`) — no trait in `app/Concerns/` `use`s another.


_Last updated: 2026-09-16 — Story 0048 (Order line-item editing backend). Extended `App\Concerns\OrderValidationRules` with three more methods (`orderItemQuantityRules()` — extracted out of story 0045's `orderItemRules()` per the task file's own "Phase 3 must extract rather than duplicate" instruction, `orderItemProductRules(string $productId)`, `orderItemOwnershipRules(string $orderId)`), none entity-prefixed. Trait **count is unchanged** at fifteen — this story extends an existing trait, it does not add one.

_Previously: 2026-09-14 — Story 0045 (Orders core CRUD backend). Added `App\Concerns\OrderValidationRules` to the roster: no entity-prefixing needed, and this section's first `public const` array-shape bounds used purely for input-size defence (`MAX_ITEMS`, `MAX_ITEM_QUANTITY`). Recounted the trait total from thirteen to fifteen (`ls app/Concerns/*ValidationRules.php`) — the prior count was already one short at story 0041, missing `PaymentMethodValidationRules` (story 0038). This file had no footer of its own since its split out of `naming.md` on 2026-09-11 — this is its first.
