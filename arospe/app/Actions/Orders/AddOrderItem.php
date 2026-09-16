<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Story 0048 -- add a line item to an open order. `__invoke()`'s parameter
 * list is a public contract every direct-call test matches verbatim
 * (docs/conventions/code-style.md), so `LogRefusedPrivilegedAttempt` and
 * `RecalculateOrderTotals` are constructor-injected rather than added to
 * the signature.
 *
 * Performs, in this exact order (the ordering is part of the guard, not an
 * implementation detail -- errors-log-archive.md's "two of three security
 * audit rounds found the flaw in the previous round's fix" entry):
 *
 * 1. Reload the order's own state from the database, before anything reads
 *    it -- a caller may hand over a stale instance.
 * 2. `Gate::authorize('update', $order)` (via the refusal-logging wrapper),
 *    against `App\Policies\OrderPolicy::update()`, which gates on
 *    `orders.edit` alone (D-2) -- the permission refusal always wins over
 *    the state refusal (D-6), so this runs strictly before the hard block.
 * 3. The hard-block guard (D-5): a direct `throw`, never a Gate ability
 *    and never an OrderPolicy method, so it binds a Super Admin exactly as
 *    it binds anyone else.
 * 4. Validate: the product exists, the optional variant exists and belongs
 *    to THIS product, the quantity is `>= 1`.
 * 5. Resolve the catalog row from the database and derive `unit_price`,
 *    `product_name`, `product_sku` from it -- the caller supplies only an
 *    id and a quantity, never a price, a name or a SKU (D-4). When a
 *    variant is named, its own price/SKU are used, never the parent
 *    product's -- matching CreateOrder's identical D-15 rule.
 * 6. `DB::transaction()`: re-verify the hard block and the line-item ceiling
 *    against the order's TRUE current state under `lockForUpdate()` (Phase 4
 *    security audit finding F-4), insert the `order_items` row, then
 *    recompute and persist the parent's totals (D-7/D-8) via
 *    RecalculateOrderTotals.
 *
 * Phase 4 security audit fixes applied to this action:
 * - F-2: refuses once the order is already at `OrderValidationRules::MAX_ITEMS`
 *   line items -- previously unbounded on this edit path, unlike CreateOrder's
 *   own `orderItemsRules()` `max:` rule, which only bounds a NEW order.
 * - F-4: the hard block is re-checked a second time, inside the transaction,
 *   against an order re-read under `lockForUpdate()` -- closing the window
 *   between the pre-transaction check (against a merely `refresh()`ed, unlocked
 *   instance) and the write.
 * - F-5: the optional variant is resolved THROUGH `$product->variants()`
 *   rather than a global `ProductVariant::query()`, a second, structural
 *   layer on top of `orderItemProductRules()`'s own scoped `Rule::exists()`
 *   (docs/security/related-id-pair-resolution.md).
 * - F-7: a blank-string `$productVariantId` (Livewire's `''`, never a real
 *   `null` -- see docs/errors-log.md's `maxWeightKg` entry for the identical
 *   mechanism) is normalised to `null` before validation and resolution,
 *   matching CreateOrder's own identical normalisation.
 *
 * Post-condition (Phase 4 re-audit finding NEW-5): the `$order` PARAMETER is
 * never the row this action's own writes end up reflected on -- the
 * transaction re-fetches a distinct `$lockedOrder` PHP object (Laravel has
 * no identity map) and RecalculateOrderTotals mutates THAT instance, not
 * the caller's. The caller's own `$order` therefore keeps its PRE-edit
 * `subtotal`/`tax_amount`/`total` after this call returns; only the
 * returned `OrderItem` is fresh. A caller that needs the order's own
 * updated totals must `$order->refresh()` (or re-fetch) itself -- this is
 * not a bug to fix here, since nothing outside this story's own tests calls
 * this action yet, but story 0055's UI must know it before it renders a
 * total straight off the `$order` instance it passed in.
 */
class AddOrderItem
{
    use OrderValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly RecalculateOrderTotals $recalculateOrderTotals,
        private readonly ToNumericString $toNumericString,
        private readonly AssertWithinColumnCeiling $assertWithinColumnCeiling,
    ) {}

    public function __invoke(Order $order, string $productId, ?string $productVariantId, int $quantity): OrderItem
    {
        $order->refresh();

        $this->logRefusedPrivilegedAttempt->authorize('update', $order, targetType: 'order', targetId: $order->id);

        $this->assertEditable($order);

        // F-7: treat a blank string exactly like a real null, before validation ever runs --
        // the normalised value, never the raw parameter, is what both the validation call and
        // the resolution below use.
        $productVariantId = $this->normalizeVariantId($productVariantId);

        Validator::make(
            [
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
            ],
            [
                ...$this->orderItemProductRules($productId),
                'quantity' => $this->orderItemQuantityRules(),
            ]
        )->validate();

        $product = Product::query()->findOrFail($productId);

        // F-5: resolve THROUGH the parent product's own relation, never a global query -- a
        // second, structural layer on top of orderItemProductRules()'s already-scoped
        // Rule::exists()->where('product_id', ...) above, not a replacement for it.
        $variant = $productVariantId !== null
            ? $product->variants()->findOrFail($productVariantId)
            : null;

        // A plain `->` (not `?->`) on the left of `??` is intentional, not an oversight --
        // matching App\Actions\Orders\CreateOrder's own identical pattern: PHP's `??` uses
        // isset()-like semantics for a bare property-access chain, so it never throws even when
        // $variant is null (Larastan's own nullsafe.neverNull rule would flag a redundant `?->`).
        $unitPrice = ($this->toNumericString)((string) ($variant->price ?? $product->price));
        $productSku = (string) ($variant->sku ?? $product->sku);
        $lineTotal = bcmul($unitPrice, (string) $quantity, 2);

        // F-8 (Phase 4 security audit, informational): $lockedOrder below must stay entirely
        // closure-local, re-fetched INSIDE this transaction rather than mutated from the
        // outer-scope $order. RecalculateOrderTotals mutates the Order instance it is given via
        // forceFill()->save() -- if a future edit hoisted the order fetch back outside this
        // closure and this transaction ever gained an `attempts: N` retry, a retried attempt
        // could silently skip the write, the exact shape App\Actions\Products\UpdateProduct's own
        // docblock describes (docs/security/derived-column-invariants.md, "What the remediation
        // introduced"). No `attempts:` is used here today -- this is defensive documentation.
        return DB::transaction(function () use ($order, $product, $variant, $quantity, $unitPrice, $productSku, $lineTotal): OrderItem {
            // F-4: re-verify against the order's TRUE current state under lock, not the
            // possibly-stale instance the caller handed over (the plain refresh() above is not
            // itself a lock, so a concurrent status change could still race it).
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            $this->assertEditable($lockedOrder);

            // F-2: refuse once the order is already at the line-item ceiling, checked against
            // the same locked instance above -- no second, redundant lock. This count carries no
            // lockForUpdate() of its own and is safe only because the `orders` row lock taken
            // just above is already held to commit, which serializes any concurrent
            // AddOrderItem/RemoveOrderItem call against this same order (Phase 4 re-audit
            // finding NEW-2) -- this check must stay AFTER the order-row lock above, and any
            // future order_items writer must lock the parent order first too, or this ceiling
            // becomes racy.
            if ($lockedOrder->items()->count() >= self::MAX_ITEMS) {
                $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'order_item_limit_reached', 'order', $lockedOrder->id);

                throw ValidationException::withMessages([
                    'items' => __('orders.errors.too_many_line_items', ['max' => self::MAX_ITEMS]),
                ]);
            }

            // F-1: the same decimal(10,2) column-overflow guard CreateOrder already applies to
            // every line_total it computes -- this edit path had none.
            ($this->assertWithinColumnCeiling)($lineTotal, 'items');

            $item = OrderItem::forceCreate([
                'order_id' => $lockedOrder->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'product_sku' => $productSku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            ($this->recalculateOrderTotals)($lockedOrder);

            return $item;
        });
    }

    /**
     * F-7: `isset()`/`!== null` alone treats a blank string as present --
     * and a blank string, not a real null, is exactly what a `wire:model`-
     * bound `<select>` with no selection submits, since Livewire opts
     * `/livewire/update` requests out of Laravel's `ConvertEmptyStringsToNull`
     * middleware. Matches `CreateOrder`'s own identical per-item
     * normalisation (see that action's own docblock, Phase 4 re-audit
     * finding F-8).
     */
    private function normalizeVariantId(?string $productVariantId): ?string
    {
        if ($productVariantId === null) {
            return null;
        }

        $trimmed = trim($productVariantId);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * D-5: a direct throw, never a Gate ability, duplicated identically
     * across all three of this story's actions rather than extracted --
     * three call sites in one folder do not yet justify the indirection
     * (the same threshold D-5 states explicitly; extract once a fourth
     * appears).
     */
    private function assertEditable(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
            // F-6 (Phase 4 security audit): logged as a refused privileged attempt, matching
            // this project's story 0015b convention -- immediately before the existing throw, so
            // the log entry and the refusal it describes can never disagree.
            $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'order_not_editable', 'order', $order->id);

            throw new OrderNotEditableException;
        }
    }
}
