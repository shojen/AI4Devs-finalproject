<?php

namespace App\Models;

use App\Enums\OrderPaymentType;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Story 0084 (D-4): the single payment recorded against an order (UNIQUE `order_id`).
 *
 * Nothing is mass-assignable: a payment is written only by App\Actions\Orders\MarkOrderAsPaid
 * (via `forceFill()`/`forceCreate()`), never from request input.
 *
 * @property string $id
 * @property string $order_id
 * @property string $payment_method_id
 * @property OrderPaymentType $type
 * @property Carbon $paid_at
 * @property string|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read PaymentMethod $paymentMethod
 * @property-read User|null $recordedBy
 */
#[Fillable([])]
class OrderPayment extends Model
{
    /** @use HasFactory<OrderPaymentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderPaymentType::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
