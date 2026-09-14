<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Product|null $product
 * @property-read ProductVariant|null $productVariant
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
        ];
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
}
