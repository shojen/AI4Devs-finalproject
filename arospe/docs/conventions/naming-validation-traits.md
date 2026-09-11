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

**A note on the numbering above.** Any ordinal attached to a trait in this section ("fourth", "seventh", …) is unreliable — several traits (`ProductValidationRules`, `ProductCategoryValidationRules`, `ShippingZoneValidationRules`) were introduced with no ordinal at all, silently invalidating every later count. Rather than renumber retroactively, this page states a verified fact instead: **thirteen `<Noun>ValidationRules` traits exist in `app/Concerns/` as of story 0041** (`ls app/Concerns/*ValidationRules.php`). Cite a trait by name, not by position.

Note `RoleValidationRules` (task 0010) and `UserValidationRules` are **different traits about different things** despite the near-collision in name: the former validates a *`Role` row's* own fields, the latter validates the *role/status a user is being assigned*. Both are named after the model whose input they describe, which is the rule — not after the screen that submits it.

When adding a new validation concern, follow this exact pattern: `<Noun>ValidationRules` trait, `<noun>Rules()` methods — don't introduce a differently-named alternative (e.g. `getPasswordValidation()`). Traits stay **flat and single-concern**, composed at the consumer (`use ProfileValidationRules, UserValidationRules;` in `App\Livewire\Users\Index`, mirroring `CreateNewUser`'s `use PasswordValidationRules, ProfileValidationRules;`) — no trait in `app/Concerns/` `use`s another.

