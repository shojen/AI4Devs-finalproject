<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A single line item on an order: a quantity of one product (optionally one
 * of its variants), snapshotting the product's name/SKU/price AT THE TIME
 * OF ORDER (story 0045, PRD §3.2) -- the story's core invariant.
 *
 * `#[Fillable]` covers `product_id`, `product_variant_id`, `quantity`.
 * Everything else -- `product_name`, `product_sku`, `unit_price`,
 * `line_total`, `refunded_quantity` -- is deliberately omitted: all five
 * are derived by App\Actions\Orders\CreateOrder from the catalog row, and a
 * fillable `unit_price` would hand a caller the ability to set its own
 * price, which is the single sharpest write in this story.
 *
 * No `SoftDeletes`: a line item's lifecycle is tied to its parent order
 * (cascadeOnDelete), never deleted independently.
 *
 * @property string $id
 * @property string $order_id
 * @property string|null $product_id
 * @property string|null $product_variant_id
 * @property string $product_name
 * @property string $product_sku
 * @property int $quantity
 * @property string $unit_price 'decimal:2' casts to a STRING, not a float
 * @property string $line_total 'decimal:2' casts to a STRING, not a float
 * @property int $refunded_quantity
 * @property int $stock_undeducted_quantity Non-refunded units not currently out of inventory (U)
 * @property int $stock_refunded_not_out_quantity Refunded units not out of inventory (K)
 * @property bool $ordered_as_variant
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Product|null $product
 * @property-read ProductVariant|null $productVariant
 * @property-read Collection<int, Refund> $refunds
 */
#[Fillable(['product_id', 'product_variant_id', 'quantity'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:N casts return a STRING, never a float -- the
            // @property string annotations above document this
            // deliberately rather than as an oversight.
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'stock_undeducted_quantity' => 'integer',
            'stock_refunded_not_out_quantity' => 'integer',
            'ordered_as_variant' => 'boolean',
        ];
    }

    /**
     * The inventory row this line moves stock on (story 0088, D1): the
     * variant when the line names one, otherwise the product; null when the
     * variant (on a variant line) or the product was deleted. Pure, no query.
     *
     * @return array{type: 'variant'|'product', id: string}|null
     */
    public function stockTarget(): ?array
    {
        if ($this->product_variant_id !== null) {
            return ['type' => 'variant', 'id' => $this->product_variant_id];
        }

        if ($this->ordered_as_variant || $this->product_id === null) {
            return null;
        }

        return ['type' => 'product', 'id' => $this->product_id];
    }

    /**
     * Non-refunded units currently out of inventory: R = (Q - F) - U (D8).
     */
    public function returnableStockUnits(): int
    {
        return ($this->quantity - $this->refunded_quantity) - $this->stock_undeducted_quantity;
    }

    /**
     * Refunded units still out of inventory: H = F - K (D8).
     */
    public function refundedUnitsOutOfStock(): int
    {
        return $this->refunded_quantity - $this->stock_refunded_not_out_quantity;
    }

    /**
     * The one copy of the D8 refund rule, applied in memory only (the caller
     * persists): `over = max(0, f - R)`, `U' = U - over`, `K' = K + k + over`.
     * Must run BEFORE `refunded_quantity` is incremented.
     *
     * @throws LogicException when `$restocked` exceeds `min($refunded, R)`
     */
    public function applyRefundToStockCounters(int $refunded, int $restocked): void
    {
        $returnable = $this->returnableStockUnits();

        if ($restocked > min($refunded, $returnable)) {
            throw new LogicException('Restocked units cannot exceed the refunded or returnable units.');
        }

        $over = max(0, $refunded - $returnable);

        $this->stock_undeducted_quantity -= $over;
        $this->stock_refunded_not_out_quantity += $restocked + $over;
    }

    /**
     * The order this line item belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The catalog product this line item references -- null once the
     * product is deleted (nullOnDelete(); the snapshot columns above
     * survive the null, D-2).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The specific variant this line item references, when the ordered
     * item is a variant rather than the plain product -- null once the
     * variant is deleted (nullOnDelete()).
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * The refund events recorded against this line item (story 0051) --
     * `refunded_quantity` above is the fast running total; this is the
     * event log it is derived from (D-2).
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
