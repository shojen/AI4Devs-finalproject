# Resolving a related pair of ids: validate the *relationship*, not two existences

Established by story 0045's Phase 4 audit (Orders core CRUD backend), finding **F-1**.

A payload that carries **two ids naming rows that must belong together** — a product and one of *its*
variants, an order and one of *its* line items, a shipping zone and one of *its* rates — has a failure
mode that neither `Rule::exists()` nor a re-read from the database closes on its own: both ids can be
individually real and still name rows from two unrelated parents. Every rule this repo already has
about not trusting a caller ([model-instance-trust.md](model-instance-trust.md) — the instance is
untrusted; [derived-column-invariants.md](derived-column-invariants.md) — re-read the subject of the
operation) is about *one* row at a time, and all of them are satisfied by the vulnerable shape below.

**The review question this page adds: when a payload names two ids, is there anything that checks they
belong together — or only that each of them exists?**

## Why this is sharper than it looks

The vulnerable shape passes every check a reviewer naturally runs:

- both ids are validated with `Rule::exists()` against their own tables;
- both rows are re-read from the database rather than trusted from the payload;
- every persisted value is copied from a real catalog row, not from the request;
- the action self-authorizes before its first write.

What it does not check is that the two rows are related — and when the two rows contribute *different
columns to the same written record*, the caller gets to choose which row supplies which column. On a
priced record that is direct price manipulation, and the result is internally consistent to every
downstream reader: nothing in the row says the name and the price came from different products.

## ❌ As found in story 0045 (CLOSED — fixed in the same Phase 4 pass)

`App\Actions\Orders\CreateOrder` resolves the product and the variant independently, then snapshots
`product_name` from one and `unit_price`/`product_sku` from the other (per **D-15**, which is correct
about *which row* each column comes from and silent about whether the two rows belong together):

```php
// app/Actions/Orders/CreateOrder.php — the two resolutions are unrelated
$product = Product::query()->findOrFail((string) $item['product_id']);
$variant = isset($item['product_variant_id'])
    ? ProductVariant::query()->findOrFail((string) $item['product_variant_id'])
    : null;

$unitPrice = (string) ($variant->price ?? $product->price);

$resolvedItems[] = [
    'product_name' => $product->name,               // from product A
    'product_sku'  => (string) ($variant->sku ?? $product->sku),   // from product B's variant
    'unit_price'   => $unitPrice,                   // from product B's variant
    // ...
];
```

```php
// app/Concerns/OrderValidationRules.php — existence only, no relationship
'product_id' => ['required', 'uuid', Rule::exists('products', 'id')],
'product_variant_id' => ['nullable', 'uuid', Rule::exists('product_variants', 'id')],
```

A caller holding only `orders.create` submits an expensive product's id together with a cheap,
unrelated product's variant id, and the line item is written with the expensive product's **name** at
the cheap variant's **price**.

## ✅ Resolve the child *through* the parent — and why the shipped fix is not the one-line version above

The simplest fix — reproduced below because it is the right shape for a **single-item** caller and is
worth keeping as the mental model — is to make the relationship the only way to reach the child row at
all, so a mismatch is a `ModelNotFoundException` from a scoped query rather than a silently mis-priced
row:

```php
// The single-item shape -- correct, but NOT what CreateOrder ships (see below)
$variant = isset($item['product_variant_id'])
    ? $product->variants()->findOrFail((string) $item['product_variant_id'])
    : null;
```

**`App\Actions\Orders\CreateOrder` cannot use this shape directly, because of a second Phase 4 finding
(F-2) on the same method: every product/variant an order's line items need is resolved in exactly two
*bulk* queries for the whole payload, never one query per item** — `$product->variants()->findOrFail()`
run inside the per-item loop would reintroduce exactly the N+1 that F-2 exists to close, for every order
with more than one line item. The two findings pull in opposite directions on the obvious fix, and the
shipped resolution satisfies both by moving the relationship check into memory, after the bulk fetch:

```php
// app/Actions/Orders/CreateOrder.php -- both product and variant collections
// are already bulk-fetched (F-2) before this loop runs
if (isset($item['product_variant_id'])) {
    $variantId = (string) $item['product_variant_id'];
    $candidate = $variants->get($variantId);

    // F-1: the variant must belong to THIS item's own product -- never merely
    // exist somewhere in the table. A mismatch is refused exactly as if the
    // variant did not exist at all.
    if ($candidate === null || $candidate->product_id !== $product->id) {
        throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
    }

    $variant = $candidate;
}
```

This closes the identical hole the relation-query shape closes — a variant naming a different parent is
refused with the same `ModelNotFoundException`, never silently accepted — without paying for a query per
item. The two shapes are equivalent in effect and different only in mechanism: the single-item shape
lets the database enforce the relationship via the query's own `WHERE`; the bulk shape enforces it in PHP
against rows the database has already returned. **Prefer the relation-query shape (`$parent->children()
->findOrFail(...)`) whenever the caller resolves one child at a time — it is simpler and the database
does the work. Reach for the bulk-fetch-then-compare shape only when a Phase 4/F-2-style bulk-query
requirement already rules the per-item relation query out**, and say so in a comment at the point of
divergence, exactly as `CreateOrder`'s own docblock does.

Two properties this shape has that a validator-only fix does not, true of either mechanism:

- **It cannot be bypassed by a future caller.** A `Rule::exists(..., 'id')->where('product_id', …)`
  rule lives in the validator, and the validator is one caller's choice; resolving the relationship —
  whether via a scoped relation query or an in-memory comparison against a bulk-fetched collection —
  lives in the action that performs the write — the placement
  [directory-structure.md](../conventions/directory-structure.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)
  already requires for authorization rules, applied to a domain rule for the same reason.
- **It cannot drift from the write.** The row the relationship check ran against *is* the row whose
  columns get snapshotted, because it is read from the same collection (or the same scoped query) that
  supplies the snapshot.

A validation rule scoping the child to the parent (`Rule::exists('product_variants', 'id')
->where('product_id', $productId)`) is a fine **additional** layer — it produces a field-keyed message
instead of a 404 — but it is not a substitute for the scoped resolution or its bulk-safe equivalent.

## The regression test that can actually fail

Fixtures must make the two rows genuinely unrelated **and** differently priced — a test whose variant
belongs to the named product, or whose prices match, passes against the vulnerable code:

```
Given product A priced 2000.00 and product B priced 5.00 with a variant priced 5.00
When an order is created with items: [{ product_id: A, product_variant_id: B-variant, quantity: 1 }]
Then the creation is refused
And no `orders` row and no `order_items` row exist
```

Note the shape of the trap: `Database\Factories\OrderItemFactory::forVariant()` sets **both**
`product_id` (from `$productVariant->product_id`) and `product_variant_id`, so every factory-built
fixture in the suite is consistent by construction. A consistent factory is correct — but it means the
mismatch is unreachable from any test that builds its payload the ordinary way, which is exactly why
this case needs a hand-built payload rather than a factory state.

## Where else to check this

Grep for any action or validator that reads two ids out of one payload element. As of story 0045 the
other candidates are already safe for structural reasons rather than by an explicit check, and are
worth re-confirming rather than assumed:

- `product_variant_values` (story 0029) — the attribute-value ids are re-scoped against a fresh
  `$type->values()->pluck('id')` read, which is this page's rule applied correctly.
- `shipping_rates.shipping_carrier_id` / `.shipping_zone_id` (story 0036) — a carrier and a zone are
  independent by design; there is no parent/child relationship to violate.
