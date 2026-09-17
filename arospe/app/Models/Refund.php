<?php

namespace App\Models;

use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single refund event against one order line item (story 0051, PRD §3.2)
 * -- units of a specific line item coming back, never an arbitrary monetary
 * amount (DR-1). `refunds` is the event log `order_items.refunded_quantity`
 * (0045's D-3) is the fast running total derived from; a test pins the
 * invariant that one always equals the SUM of the other. `created_at` IS
 * the refund's event timestamp -- no separate `refunded_at` column exists
 * (D-2).
 *
 * `#[Fillable]` lists nothing a caller may set that is not already the
 * caller's own input: `order_item_id`, `quantity`, `reason`. `amount` and
 * `refunded_by` are deliberately omitted -- `amount` is derived arithmetic
 * over a snapshotted price (`quantity x order_items.unit_price`, D-8), and
 * `refunded_by` is the acting user's identity, derived from `Auth::id()`
 * and never accepted as input (the 0008a rule). Omission from `#[Fillable]`
 * IS this codebase's mass-assignment guard -- both are written only via
 * `forceFill()`/an explicit key list from App\Actions\Orders\RecordRefund.
 *
 * No `SoftDeletes` (OQ-4): a refund is a recorded, immutable fact.
 *
 * @property string $id
 * @property string $order_item_id
 * @property int $quantity
 * @property string $amount 'decimal:2' casts to a STRING, not a float
 * @property string $refunded_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrderItem $orderItem
 * @property-read User $refundedBy
 */
#[Fillable(['order_item_id', 'quantity', 'reason'])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:N casts return a STRING, never a float -- the
            // @property string annotation above documents this
            // deliberately rather than as an oversight.
            'amount' => 'decimal:2',
        ];
    }

    /**
     * The line item this refund was recorded against.
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * The administrator who performed this refund.
     *
     * @return BelongsTo<User, $this>
     */
    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
