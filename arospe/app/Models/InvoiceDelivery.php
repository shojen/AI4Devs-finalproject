<?php

namespace App\Models;

use App\Enums\InvoiceDeliveryStatus;
use Database\Factories\InvoiceDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One e-mail send attempt of an invoice (story 0104). Written only through the delivery actions,
 * hence no mass-assignable columns.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $recipient_email
 * @property bool $is_copy
 * @property InvoiceDeliveryStatus $status
 * @property string|null $sent_by
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 * @property-read User|null $sender
 */
#[Fillable([])]
class InvoiceDelivery extends Model
{
    /** @use HasFactory<InvoiceDeliveryFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_copy' => 'boolean',
            'status' => InvoiceDeliveryStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The invoice this delivery sends.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The user who sent it (null when the system did).
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
