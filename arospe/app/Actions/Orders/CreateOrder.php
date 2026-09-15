<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateOrder
{
    use OrderValidationRules;

    /**
     * D-1: the UNIQUE index on `order_number` has the last word -- a
     * duplicate is caught (SQLSTATE 23000 / MySQL 1062) and the whole
     * write retried with a freshly generated number, rather than
     * surfacing the collision. This is the ceiling on how many times that
     * retry happens before giving up loudly instead of looping forever.
     */
    private const MAX_ORDER_NUMBER_ATTEMPTS = 5;

    /**
     * `order_items.unit_price`/`line_total` and `orders.subtotal`/`total`
     * are all `decimal(10,2)` -- the largest value the column can hold.
     * Money arithmetic below is done with bcmath at scale 2 (F-4) rather
     * than PHP floats, formatting only once at the point of writing to
     * these columns; this constant is the last line of defence against a
     * computed value that would silently overflow the column (F-3's
     * residual case a bare `max:` on `quantity` cannot close alone -- a
     * near-maximum price at MAX_ITEM_QUANTITY can still overflow).
     */
    private const MAX_DECIMAL_COLUMN_VALUE = '99999999.99';

    /**
     * Constructor injection, not method injection: __invoke()'s single
     * domain argument is this action's whole public signature, matched
     * verbatim by every direct-call test, so the collaborator is resolved
     * from the container without widening that signature (D-13's reference
     * example, App\Actions\Shipping\CreateShippingRate, does the same).
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NotifyOrderCreated $notifyOrderCreated,
    ) {}

    /**
     * Create a new order: a customer plus one or more priced line items,
     * with the price frozen at the moment of ordering (PRD §3.2).
     *
     * Self-authorizes `create` on `Order::class` as its own first
     * statement, through the refusal-logging wrapper -- never a bare
     * `Gate::authorize()` (task file step 1). `targetType: 'order'` is
     * passed explicitly, since `LogRefusedPrivilegedAttempt::resolveTarget()`
     * auto-resolves only `User`/`Role` instances or classes. There is no
     * `targetId` -- this is a class-level `create` check with no row yet.
     * This story ships no route and no Livewire component, so this action
     * is the ONLY reachable enforcement point.
     *
     * Every catalog row a line item snapshots from is resolved by reading
     * the database -- a caller supplies only an id and a quantity, never a
     * name, a SKU or a price (step 3). D-15: when a line item names a
     * `product_variant_id`, `unit_price` and `product_sku` are read from
     * the VARIANT's own row, never the parent product's -- a variant's
     * price is `NOT NULL` and fully independent of its parent's, which is
     * the whole point of a variant. `product_name` always comes from the
     * parent product, since a variant carries no name column of its own.
     *
     * F-1 (Phase 4 security audit): a `product_variant_id` is resolved
     * THROUGH its own item's already-resolved `product_id` -- never as an
     * independent lookup -- so a caller cannot pair one product's id
     * (naming the product whose NAME is snapshotted) with a DIFFERENT
     * product's variant id (naming the row whose PRICE is snapshotted) to
     * get one product's name at another's price. `orderItemRules()`'s own
     * `Rule::exists('product_variants', 'id')` only proves the variant id
     * exists somewhere in the table; it cannot express "and belongs to
     * this item's product_id" as a bare rule, since that needs the
     * sibling field's value.
     *
     * F-2 (Phase 4 security audit): every product/variant this loop needs
     * is resolved in exactly two bulk queries (`findOrFail()` given an
     * array of ids), never one query per item -- the array-shape check
     * above (`OrderValidationRules::MAX_ITEMS`, validated in its own early
     * pass before this method even reaches this loop) bounds how large
     * that array can legitimately be.
     *
     * F-5 (Phase 4 security audit): the customer is resolved with
     * `withTrashed()` -- D-12 explicitly allows an order against a
     * soft-deleted customer (so their order history is never orphaned),
     * and a plain `Customer::query()->findOrFail()` would apply the
     * default `SoftDeletingScope` and refuse exactly the case D-12 means
     * to allow.
     *
     * Every row this writes is BUILT INSIDE the transaction's closure via
     * `forceCreate()` -- never a model instantiated outside it and mutated
     * within, which is the silent-lost-update shape recorded in
     * docs/errors-log.md's `DB::transaction($fn, attempts: N)` entry. The
     * derived totals (`subtotal`/`total`) are computed BEFORE the
     * transaction opens, from the already-resolved line items, and passed
     * straight into the `orders` row's own `forceCreate()` call rather than
     * written back onto an existing instance afterward -- so there is
     * nothing to retry-unsafely mutate. Because of that, the retry loop
     * below wraps the WHOLE `DB::transaction()` call rather than using its
     * `attempts:` parameter: each retry opens a brand-new transaction and
     * builds brand-new rows from scratch, which is what makes retrying
     * safe here (see the task file's own note under the action's step 4).
     *
     * Story 0046: `NotifyOrderCreated` is dispatched strictly AFTER the
     * retry loop below has converged and `DB::transaction()` has committed
     * -- never inside the closure, and never inside the `catch` block that
     * handles an `order_number` collision. Dispatching inside either of
     * those would let a real failure from the notification write (an
     * unrelated `QueryException`) be swallowed by the same catch that
     * retries a `1062` collision, risking a second `orders` row for one
     * logical create. `$order` is only ever notified once `order_number`
     * has been definitively assigned.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): Order
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', Order::class, targetType: 'order');

        // F-2a: `items`' own SHAPE is validated in its own early, separate
        // call -- before the full rule set below ever composes a single
        // `items.*` Rule::exists() query -- so an oversized array is
        // refused at zero query cost rather than after paying for every
        // element's existence check. See OrderValidationRules::MAX_ITEMS
        // and docs/security/array-validation-bounds.md.
        Validator::make($attributes, ['items' => $this->orderItemsRules()])->validate();

        Validator::make($attributes, $this->orderRules())->validate();

        // findOrFail() accepts an array id and can therefore return a Collection; every id here is
        // validated as a single 'uuid' string above, so it is cast explicitly before the call --
        // narrowing the static return type to a single model, matching every other findOrFail() call
        // site in this codebase (App\Actions\Users\ConfirmEmailChange).
        //
        // F-5: withTrashed() -- see this method's own docblock.
        $customer = Customer::withTrashed()->findOrFail((string) $attributes['customer_id']);
        $paymentMethodId = (string) $attributes['payment_method_id'];

        /** @var array<int, array{product_id: string, product_variant_id: ?string, quantity: mixed}> $items */
        $items = $attributes['items'];

        // F-2b: two bulk queries for the whole payload, never one per item.
        $productIds = array_values(array_unique(array_map(
            fn (array $item): string => (string) $item['product_id'],
            $items
        )));

        /** @var Collection<string, Product> $products */
        $products = Product::query()->findOrFail($productIds)->keyBy('id');

        $variantIds = array_values(array_unique(array_filter(array_map(
            fn (array $item): ?string => isset($item['product_variant_id']) ? (string) $item['product_variant_id'] : null,
            $items
        ))));

        /** @var Collection<string, ProductVariant> $variants */
        $variants = $variantIds === []
            ? new Collection
            : ProductVariant::query()->findOrFail($variantIds)->keyBy('id');

        /** @var array<int, array{product_id: string, product_variant_id: ?string, product_name: string, product_sku: string, quantity: int, unit_price: numeric-string, line_total: numeric-string}> $resolvedItems */
        $resolvedItems = [];

        foreach ($items as $item) {
            $productId = (string) $item['product_id'];
            $product = $products->get($productId);

            // Defensive rather than reachable: every id here already passed
            // Rule::exists('products', 'id') in the full validate() call
            // above, so the bulk findOrFail() that built $products cannot
            // legitimately have missed it -- this also keeps $product's
            // static type non-nullable for every use below.
            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
            }

            $variant = null;

            // Phase 4 re-audit finding F-8: `isset()` alone treats a blank string as PRESENT --
            // and a blank string, not a real null, is exactly what a `wire:model`-bound <select>
            // with no selection submits, since Livewire opts /livewire/update requests out of
            // Laravel's ConvertEmptyStringsToNull middleware (docs/errors-log.md's
            // maxWeightKg entry is the same mechanism). Without this check a "plain product, no
            // variant" line item from story 0055's own future form would 404 instead of
            // resolving as a plain product -- this still fails CLOSED either way (F-1's own
            // ModelNotFoundException), so it was never an integrity or security gap, only a
            // predictable usability one worth closing before a caller can hit it.
            $variantId = isset($item['product_variant_id']) ? trim((string) $item['product_variant_id']) : '';

            if ($variantId !== '') {
                $candidate = $variants->get($variantId);

                // F-1: the variant must belong to THIS item's own product --
                // never merely exist somewhere in the table. A mismatch is
                // refused exactly as if the variant did not exist at all.
                if ($candidate === null || $candidate->product_id !== $product->id) {
                    throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
                }

                $variant = $candidate;
            }

            $quantity = (int) $item['quantity'];
            // A plain `->` (not `?->`) on the left of `??` is intentional, not an oversight: PHP's
            // `??` uses isset()-like semantics for a bare property-access chain, so it never throws
            // "Attempt to read property on null" even when $variant is null -- the nullsafe operator
            // would be redundant here (Larastan's own nullsafe.neverNull rule).
            $unitPrice = $this->toNumericString((string) ($variant->price ?? $product->price));

            // F-4: bcmath, not PHP floats -- formatting happens once, here,
            // at the point of computing the value this column will store.
            $lineTotal = bcmul($unitPrice, (string) $quantity, 2);
            $this->assertWithinColumnCeiling($lineTotal, 'items');

            $resolvedItems[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'product_sku' => (string) ($variant->sku ?? $product->sku),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = '0.00';

        foreach ($resolvedItems as $resolvedItem) {
            $subtotal = bcadd($subtotal, $resolvedItem['line_total'], 2);
        }

        $this->assertWithinColumnCeiling($subtotal, 'items');

        // D-8: tax_amount and shipping_amount are 0.00 at creation (nothing here resolves either --
        // D-9), but the identity is written out in FULL rather than assigning `total = subtotal`, per
        // a Phase 4 re-audit finding (F-6): a bare `'total' => $subtotal` reads correctly today only
        // because the other two terms happen to be zero, and it would silently stay wrong the moment
        // 0053/0054/0037 populate them, since nothing here would then recombine the three into `total`.
        $taxAmount = '0.00';
        $shippingAmount = '0.00';
        $total = bcadd(bcadd($subtotal, $taxAmount, 2), $shippingAmount, 2);
        $this->assertWithinColumnCeiling($total, 'items');

        $order = null;

        for ($attempt = 1; $attempt <= self::MAX_ORDER_NUMBER_ATTEMPTS; $attempt++) {
            $orderNumber = $this->generateOrderNumber();

            try {
                $order = DB::transaction(function () use ($customer, $paymentMethodId, $resolvedItems, $subtotal, $taxAmount, $shippingAmount, $total, $orderNumber): Order {
                    // tax_rate stays NULL, never '0.000' -- "not configured" and "a
                    // legitimate 0%" must not share a representation. sales_region_id /
                    // shipping_rate_id stay NULL (D-9) -- nothing here resolves either.
                    $order = Order::forceCreate([
                        'order_number' => $orderNumber,
                        'customer_id' => $customer->id,
                        'status' => OrderStatus::Pending,
                        'payment_status' => PaymentStatus::PendingPayment,
                        'sales_region_id' => null,
                        'shipping_rate_id' => null,
                        'payment_method_id' => $paymentMethodId,
                        'tax_rate' => null,
                        'subtotal' => $subtotal,
                        'tax_amount' => $taxAmount,
                        'shipping_amount' => $shippingAmount,
                        'total' => $total,
                        'flagged_for_review' => false,
                        // D-4: the customer's addresses, frozen at order time --
                        // never re-read from the customer afterward.
                        'shipping_address_line1' => $customer->shipping_address_line1,
                        'shipping_address_line2' => $customer->shipping_address_line2,
                        'shipping_city' => $customer->shipping_city,
                        'shipping_postal_code' => $customer->shipping_postal_code,
                        'shipping_province' => $customer->shipping_province,
                        'shipping_country' => $customer->shipping_country,
                        'billing_address_line1' => $customer->billing_address_line1,
                        'billing_address_line2' => $customer->billing_address_line2,
                        'billing_city' => $customer->billing_city,
                        'billing_postal_code' => $customer->billing_postal_code,
                        'billing_province' => $customer->billing_province,
                        'billing_country' => $customer->billing_country,
                    ]);

                    foreach ($resolvedItems as $resolvedItem) {
                        // forceCreate(), not the relation's ordinary create(): OrderItem's
                        // #[Fillable] deliberately omits product_name/product_sku/
                        // unit_price/line_total (a fillable unit_price would hand a caller
                        // the ability to set its own price), so a plain create() would
                        // silently drop every one of them.
                        OrderItem::forceCreate([...$resolvedItem, 'order_id' => $order->id]);
                    }

                    return $order;
                });

                break;
            } catch (QueryException $e) {
                // 1062 = MySQL ER_DUP_ENTRY on order_number's UNIQUE index -- the
                // race D-1/R-1 describe. Retry with a freshly generated number;
                // any other QueryException is a real failure and propagates.
                //
                // Deliberate deviation from the task file's own step 5, which names catching
                // SQLSTATE '23000' -- the driver-specific code 1062 is narrower and correct: 23000
                // also covers foreign-key violations (e.g. a payment method deleted between
                // validation and the write), which must NOT be silently retried five times and
                // swallowed. This project pins MySQL everywhere (phpunit.xml, .env.example), so
                // keying on the MySQL-specific error code is safe. Phase 5 code review: keep this,
                // don't "fix" it back to 23000.
                if (($e->errorInfo[1] ?? null) === 1062 && $attempt < self::MAX_ORDER_NUMBER_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }

        if ($order === null) {
            throw new RuntimeException('Could not generate a unique order_number after '.self::MAX_ORDER_NUMBER_ATTEMPTS.' attempts.');
        }

        // Story 0046: strictly after the retry loop has converged and the transaction has
        // committed -- see this method's own docblock for why this may never move inside the
        // closure or the catch block above.
        ($this->notifyOrderCreated)($order);

        return $order;
    }

    /**
     * F-3's second half: a per-item `max:` on `quantity` alone cannot
     * prevent every overflow of the `decimal(10,2)` `unit_price`/
     * `line_total`/`subtotal`/`total` columns (a near-maximum price at
     * MAX_ITEM_QUANTITY can still overflow) -- this is the residual,
     * cheap-to-add check the task's own audit named as optional. Compared
     * with bcmath (`bccomp`), never a float cast, for the same reason the
     * arithmetic above uses it.
     *
     * @param  numeric-string  $value
     */
    private function assertWithinColumnCeiling(string $value, string $field): void
    {
        if (bccomp($value, self::MAX_DECIMAL_COLUMN_VALUE, 2) > 0) {
            throw ValidationException::withMessages([
                $field => __('orders.errors.total_exceeds_maximum'),
            ]);
        }
    }

    /**
     * F-4: bcmath's stub signatures require `numeric-string`, which
     * Larastan cannot infer for a `decimal:N`-cast Eloquent attribute --
     * that cast's real runtime value IS always numeric, so this is a
     * genuine runtime check (`is_numeric()`), not a suppressed
     * static-analysis assertion. A `decimal:N` cast that somehow produced
     * a non-numeric string would indicate corrupted catalog data, which
     * this action must refuse rather than silently coerce.
     *
     * @return numeric-string
     */
    private function toNumericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new RuntimeException("Expected a numeric decimal value, got: {$value}");
        }

        return $value;
    }

    /**
     * Generate a support-usable order number in the format
     * `ORD-{YYYY}-{NNNNNN}`, zero-padded to six digits (D-1) -- a
     * per-year sequence, computed from how many orders already carry this
     * year's prefix. Recomputed on every retry (see the catch block
     * above), so a duplicate found by the database's own UNIQUE index is
     * resolved against the up-to-date count rather than the stale one.
     * This is the minimum collision protection, not proof of concurrency
     * safety (R-1) -- the UNIQUE index and the retry loop are what make it
     * safe under a genuine race.
     */
    private function generateOrderNumber(): string
    {
        $year = (int) now()->year;
        $prefix = "ORD-{$year}-";

        $count = Order::query()->where('order_number', 'like', $prefix.'%')->count();

        return sprintf('%s%06d', $prefix, $count + 1);
    }
}
