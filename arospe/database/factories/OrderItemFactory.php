<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state: a line item for a plain product
     * (no variant), with `product_name`/`product_sku`/`unit_price` all
     * resolved from that product's CURRENT catalog values in `configure()`
     * below -- once the model is made (so `product_id`, whether supplied
     * directly or resolved from a nested `Product::factory()`, is already
     * a real, persisted id) but before it is inserted, so the row is
     * written once with its final, derived values rather than
     * created-then-corrected.
     *
     * `product_name`/`product_sku`/`unit_price`/`line_total` are all set
     * to a placeholder here and overwritten in `configure()` -- they must
     * appear as real array keys so Eloquent's mass-assignment (factories
     * build through `Model::unguarded()`) has something to overwrite.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'product_name' => '',
            'product_sku' => '',
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => '0.00',
            'line_total' => '0.00',
        ];
    }

    /**
     * Resolve `product_name`/`product_sku`/`unit_price`/`line_total` from
     * the real catalog row, AFTER the model is made but BEFORE it is
     * inserted -- matching `ProductVariantFactory::configure()`'s own
     * shape.
     *
     * When `product_variant_id` is set (via `forVariant()` below),
     * `unit_price`/`product_sku` are read from the VARIANT's own row, per
     * D-15 -- `product_variants.price` is fully independent of its
     * parent's price, so a factory that priced a variant line item from
     * the parent product would make D-15's regression test pass against
     * the very implementation it exists to catch. `product_name` always
     * comes from the parent product, since a variant carries no name of
     * its own.
     *
     * Phase 5 code review finding F-C: this OVERWRITES `product_name`/
     * `product_sku`/`unit_price`/`line_total` UNCONDITIONALLY, even when a
     * caller explicitly passed one of them to `create([...])` -- matching
     * `ProductVariantFactory::configure()`'s own precedent, which overwrites
     * `sku` the same way so its derivation formula "genuinely runs on every
     * factory-created row rather than being bypassed by a hand-typed
     * string". A test needing a SPECIFIC `unit_price` (a refund fixture in
     * 0051/0052, say) must set it on the underlying `Product`/`ProductVariant`
     * row BEFORE creating the line item, never via
     * `OrderItem::factory()->create(['unit_price' => ...])`, which is
     * silently discarded here.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (OrderItem $orderItem): void {
            $product = Product::query()->find($orderItem->product_id)
                ?? Product::factory()->create();
            $orderItem->product_id = $product->id;

            $variant = $orderItem->product_variant_id !== null
                ? ProductVariant::query()->find($orderItem->product_variant_id)
                : null;

            $orderItem->product_name = $product->name;
            // A plain `->` (not `?->`) on the left of `??` is intentional, not an oversight -- matching
            // App\Actions\Orders\CreateOrder's own identical pattern and its docblock: PHP's `??` uses
            // isset()-like semantics for a bare property-access chain, so it never throws "Attempt to
            // read property on null" even when $variant is null. The nullsafe operator would be
            // redundant here (Larastan's own nullsafe.neverNull rule).
            $orderItem->product_sku = $variant->sku ?? $product->sku;
            $orderItem->unit_price = $variant->price ?? $product->price;
            $orderItem->line_total = number_format(
                ((float) $orderItem->unit_price) * $orderItem->quantity,
                2,
                '.',
                ''
            );
        });
    }

    /**
     * Price the line item against a specific product variant (D-15) --
     * sets BOTH `product_id` (the variant's parent) and
     * `product_variant_id`; `configure()` above then reads
     * `unit_price`/`product_sku` from the variant's own row.
     */
    public function forVariant(ProductVariant $productVariant): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $productVariant->product_id,
            'product_variant_id' => $productVariant->id,
        ]);
    }
}
