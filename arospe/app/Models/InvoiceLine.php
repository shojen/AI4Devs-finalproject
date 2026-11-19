<?php

namespace App\Models;

use App\Enums\InvoiceLineKind;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One frozen line of an invoice (story 0101b). Insert-only: every update and delete through the
 * model throws (FD-3). The database's SET NULL on `order_item_id` bypasses model events by
 * design and is the only write a line ever receives.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string|null $order_item_id
 * @property int $position
 * @property InvoiceLineKind $kind
 * @property string $description
 * @property string|null $sku
 * @property int $quantity
 * @property string $unit_price 'decimal:2' casts to a STRING, not a float
 * @property string|null $tax_rate 'decimal:3' casts to a STRING, not a float
 * @property string $line_subtotal 'decimal:2' casts to a STRING, not a float
 * @property string $tax_amount 'decimal:2' casts to a STRING, not a float
 * @property string $line_total 'decimal:2' casts to a STRING, not a float
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
#[Fillable([])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory, HasUuids;

    /**
     * Refuse every update and delete (FD-3).
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Invoice lines are insert-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Invoice lines cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => InvoiceLineKind::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'line_subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * The invoice this line belongs to.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
