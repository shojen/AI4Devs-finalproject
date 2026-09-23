# Database Schema — Products & Taxes — product_media and product_sales_region

> Part of [Database Schema — Products & Taxes](../schema-products.md). **Read this part when:** the task touches a product's gallery/featured image or its Sales Region assignment pivot. The other parts are listed in the [hub](../schema-products.md#table-of-contents).

### `product_media`

Source: `database/migrations/2026_09_01_142008_create_product_media_table.php` (story 0024) — the ordered gallery pivot between `products` and `media`. Name declared explicitly (`Str::plural`/basename inference would otherwise produce `media_product`, alphabetising both sides' snake-cased names); the stated name reads correctly for the only direction anything traverses ("this product's gallery").

Columns in real physical order (verified with `php artisan db:table product_media`):

| Column | Type | Notes |
| --- | --- | --- |
| `product_id` | uuid FK → `products.id` | `cascadeOnDelete()` — deleting a product removes its own gallery rows. Leading column of the composite primary key below, since every real query is `WHERE product_id = ? ORDER BY position` |
| `media_id` | uuid FK → `media.id` | `constrained('media')->restrictOnDelete()` — see the ⚠️ below |
| `position` | `INT UNSIGNED`, default `0` | the caller's **0-based array index** at the time of the last `SyncProductGallery` sync — see the ⚠️ below. No `timestamps()`: nothing reads them, and this phase has no audit-trail requirement |

**No surrogate `id`; composite primary key `(product_id, media_id)`.** Nothing FKs into a pivot row, so a surrogate key buys nothing and costs a second index — the same shape the vendored `spatie/laravel-permission` pivot tables (`role_has_permissions`, `model_has_roles`) already use in this schema. The composite PK doubles as the "an image cannot appear twice in one product's gallery" invariant.

**No `SoftDeletes`, no `deleted_at`** — a pivot row has no independent identity to retain.

#### `featured_media_id` and this pivot's media FK are deliberately symmetric — and the inverse case of `media.uploaded_by`

Both `products.featured_media_id` and this table's `media_id` are `restrictOnDelete()`: **an image cannot be deleted while any product references it, as its featured image or via the gallery**, matching this project's house pattern for "you cannot delete something in use" ([0024b](../../../ai-spec/tasks/done/0024b-product-category-in-use-delete-guard.md) implements the identical rule for categories). `nullOnDelete()` was rejected for the featured image and never available on the pivot column at all, since it is half the primary key.

This is the **exact inverse** of the trap [`media.uploaded_by`](sales-regions-and-media.md#uploaded_by-and-the-soft-delete-interaction--read-this-before-fixing-the-fk) records: that FK's `nullOnDelete()` essentially never fires, because `users` is soft-deleted and a soft delete is an `UPDATE`. `media` carries **no `deleted_at`**, so when a future media-deletion story implements a real `DELETE`, these two FKs genuinely **will** fire — the same clause shape (`nullOnDelete()` vs. `restrictOnDelete()`) behaves oppositely depending on whether the referenced table is soft-deleted, which is exactly why `tests/Feature/Products/ProductMediaTest.php` drives both FKs with a **raw** `DB::table('media')->delete()` today: no application path deletes media yet, so this is the only executable proof either constraint exists. A future media-delete story must count references across `products.featured_media_id`, `product_media` and (0029) variants before it can ship a working delete at all — a `23000` on every referenced image is the accepted, deliberate cost of choosing `restrictOnDelete()` here.

#### `position` is written only by `App\Actions\Products\SyncProductGallery`, as the caller's array index

`SyncProductGallery` is the **single writer** of this column and of `products.featured_media_id` — no Livewire component, controller, or sibling action writes either, and a reachability test (`tests/Feature/Products/ProductAuthorizationTest.php`) asserts no class under `app/` other than `App\Actions\Products\CreateProduct`/`UpdateProduct` references it. Its contract, in full: the caller passes the **complete, authoritative** ordered gallery on every call (never a delta — ids present are the gallery, ids absent are detached), and `position` is rewritten as the 0-based array index for **every surviving row on every call**, never `MAX(position) + 1`. That full rewrite — not an append-only assignment — is what makes a gallery reorder expressible as an ordinary re-save: the action cannot distinguish a reorder from an add, a removal or a no-op, because it always rewrites the whole set from the array it was given.

`App\Models\Product::gallery()` always tiebreaks `->orderByPivot('position')->orderByPivot('media_id')`. With `default(0)` on the column, a raw insert bypassing the action — the only path left that can still produce a tie, since every action-driven row now carries an explicit index — would otherwise read back in arbitrary order.

#### Indexes — two, both present by requirement rather than choice

`php artisan db:table product_media` reports exactly two: `primary` on `(product_id, media_id)` and `product_media_media_id_foreign`. `product_id`'s own FK index need not be written separately — it is the composite primary key's leading column, which already serves that role; `media_id`'s is InnoDB's auto-created FK index, per [migrations.md](../migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here)'s rule (this table's own FK column is this rule's **fourth** confirming instance in this schema, `products.product_category_id`/`featured_media_id` above being the third). No unique index on `(product_id, position)` — enforcing at-most-one row per position per product would force every reorder through a temporary value or a deferred constraint MySQL 8.4 does not have — and no index on `position` alone, since it is only ever a sort key inside an already-narrow `product_id` range.

### `product_sales_region`

Source: `database/migrations/2026_09_03_150422_create_product_sales_region_table.php` (story 0026) — the Sales Region assignment pivot between `products` and `sales_regions`, and the sixth Epic 2 domain table. Table name **inferred, not overridden**: `HasRelationships::joiningTable()` snake-cases both basenames, sorts them and joins with `_`, which already produces `product_sales_region` — verified against the real vendor grammar (this story's V-1) rather than assumed, so `App\Models\Product::salesRegions()` needs no table override, though it names table and column explicitly anyway (see below).

Columns in real physical order (verified with `php artisan db:table product_sales_region`):

| Column | Type | Notes |
| --- | --- | --- |
| `product_id` | uuid FK → `products.id` | `cascadeOnDelete()` — a product is hard-deleted ([`products`](categories-and-products.md#products) above, no `SoftDeletes`), and an assignment without its product is meaningless. Leading column of the composite primary key below, since the only real query is "this product's regions" |
| `sales_region_id` | uuid FK → `sales_regions.id` | `restrictOnDelete()` — the house pattern for "cannot delete something in use", the identical clause [`product_media`](#product_media) above uses for its own `media_id`. **Currently unreachable**: [`sales_regions`](sales-regions-and-media.md#sales_regions) gives the catalog no delete path at all, only `is_active` — a backstop against a future delete story, the same acknowledged-dead-today situation `product_media`'s two FKs are in until a media-delete story exists |

**No surrogate `id`; composite primary key `(product_id, sales_region_id)`.** Nothing FKs into a pivot row, so a surrogate key buys nothing — the same shape `product_media` and the vendored `spatie/laravel-permission` pivots already use in this schema. The composite PK doubles as the "the same region cannot be assigned to a product twice" invariant, a database impossibility rather than a validation-only one.

**No extra columns at all — more spare than `product_media`.** No `position` (nothing in the PRD orders a product's regions, unlike the gallery *strip* `product_media.position` orders), no `timestamps()`, and specifically **no per-assignment rate override**: a tax rate lives on `sales_regions.rate` and nowhere else. An override column here would add a fourth tax-rate precedence tier and a second place a rate could hide — the resolver below is built on there being exactly one.

**No `SoftDeletes`, no `deleted_at`** — a pivot row has no independent identity to retain, matching `product_media`.

#### Indexes — two, both present by requirement rather than choice

`php artisan db:table product_sales_region` reports exactly two: `primary` on `(product_id, sales_region_id)` and `product_sales_region_sales_region_id_foreign`. **No hand-written `$table->index('sales_region_id')`** — `product_id` needs no index of its own, since it is the composite PK's leftmost prefix, and `sales_region_id` gets InnoDB's own auto-created supporting index for the FK constraint. Adding one explicitly would create a **second**, redundant index on the same column — exactly the `users_uuid_unique` write-amplification shape [errors-log.md](../../errors-log.md) records — and this table is the **fifth** confirming instance of [migrations.md](../migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here)'s "an FK column does not also get an explicit index here" rule. Verified against a live, migrated MySQL instance during the story's own Phase 2 `database-expert` re-review, not read off the migration file.

#### What the pivot enables: `App\Actions\Products\ResolveProductTaxRate`

This table answers only "which regions is a product assigned to" — `App\Models\Product::salesRegions(): BelongsToMany`, table and both column names written explicitly even though every one matches convention, so a future rename would not silently start pointing at nothing. What tax rate applies at a given destination is a separate question, answered by [`App\Actions\Products\ResolveProductTaxRate`](../../../app/Actions/Products/ResolveProductTaxRate.php), returning an [`App\Actions\Products\ResolvedTaxRate`](../../../app/Actions/Products/ResolvedTaxRate.php) value object (`?string $rate` — `decimal:3` casts to a **string**, never `float`; `SalesRegion $region`; `App\Enums\TaxRateResolutionTier $tier`). Exactly two tiers, no third and no ancestor walk in either direction: the destination is matched against the product's own assigned entries by **exact id** (`AssignedRegion`), falling back to the catalog's `is_default` row (`CatalogDefault`) when nothing matches — assigning a fiscal territory never covers its parent, and assigning a parent never covers its fiscal territories. A rate of `'0.000'` is honoured as a real rate at both tiers; an entry with no configured rate resolves to `rate: null` and names itself, rather than falling through to the other tier or fabricating a `0`. No default row existing at all is a genuine invariant violation ([`sales_regions`](sales-regions-and-media.md#sales_regions)'s own `is_default` guarantee, enforced by story 0017) and throws `App\Exceptions\NoDefaultSalesRegionException` rather than returning a silent `null`.

The single writer of this pivot is [`App\Actions\Products\SyncProductSalesRegions`](../../../app/Actions/Products/SyncProductSalesRegions.php) — `$product->salesRegions()->sync($salesRegionIds)`, a declarative full-replace matching `SyncProductGallery`'s own shape for the identical reason: the caller always submits the complete new set, so `attach()`-only growth would make deselecting a region silently do nothing. Assignment itself is enforced at the validation boundary, not by this pivot: `App\Concerns\ProductValidationRules::salesRegionIdRules()` refuses an inactive or child-bearing ("España"-shaped heading) entry for a **newly added** id while exempting an id the product **already carries**, so disabling a region after the fact never silently detaches it.
