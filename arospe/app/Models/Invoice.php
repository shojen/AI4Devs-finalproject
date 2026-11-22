<?php

namespace App\Models;

use App\Actions\Orders\ToNumericString;
use App\Enums\InvoiceCorrectionReason;
use App\Enums\InvoiceDeliveryStatus;
use App\Enums\InvoiceType;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An issued invoice (story 0101b): a frozen snapshot of issuer, recipient, look, amounts and tax.
 * Immutable through the application (FD-3): saving a dirty frozen column and every delete throw a
 * LogicException. Only `voided_at`, the five collection columns and `updated_at` may change. The
 * guard lives in model events, so a bulk query-builder write bypasses it; an architecture test
 * keeps code under app/ from doing that.
 *
 * @property string $id
 * @property string $order_id
 * @property string $order_number
 * @property InvoiceType $type
 * @property InvoiceCorrectionReason|null $reason
 * @property string $series
 * @property int $sequence_year
 * @property int $sequence_number
 * @property string $number
 * @property Carbon $issued_at
 * @property string|null $corrects_invoice_id
 * @property Carbon|null $voided_at
 * @property string $template
 * @property string|null $primary_color
 * @property string|null $accent_color
 * @property string|null $logo_media_id
 * @property string $issuer_legal_name
 * @property string $issuer_tax_id
 * @property string|null $issuer_address_line1
 * @property string|null $issuer_address_line2
 * @property string|null $issuer_city
 * @property string|null $issuer_postal_code
 * @property string|null $issuer_province
 * @property string|null $issuer_country
 * @property string $recipient_name
 * @property string|null $recipient_tax_id
 * @property string|null $recipient_address_line1
 * @property string|null $recipient_address_line2
 * @property string|null $recipient_city
 * @property string|null $recipient_postal_code
 * @property string|null $recipient_province
 * @property string|null $recipient_country
 * @property string|null $tax_rate 'decimal:3' casts to a STRING, not a float
 * @property string $subtotal 'decimal:2' casts to a STRING, not a float
 * @property string $shipping_amount 'decimal:2' casts to a STRING, not a float
 * @property string $tax_amount 'decimal:2' casts to a STRING, not a float
 * @property string $total 'decimal:2' casts to a STRING, not a float
 * @property string|null $issued_by
 * @property Carbon|null $collected_at
 * @property string|null $collected_by
 * @property string|null $collection_payment_method_id
 * @property string|null $collection_type
 * @property bool $collection_carried_over
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Invoice|null $corrects
 * @property-read Collection<int, Invoice> $correctives
 * @property-read User|null $issuedBy
 * @property-read User|null $collectedBy
 * @property-read PaymentMethod|null $collectionPaymentMethod
 * @property-read Collection<int, InvoiceDelivery> $deliveries
 */
#[Fillable([])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    /**
     * The only columns a saved invoice may change.
     *
     * @var list<string>
     */
    public const MUTABLE_COLUMNS = [
        'voided_at', 'collected_at', 'collected_by', 'collection_payment_method_id', 'collection_type',
        'collection_carried_over', 'updated_at',
    ];

    /**
     * Refuse the changes the model must never save (FD-3).
     */
    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $frozen = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_COLUMNS);

            if ($frozen !== []) {
                throw new LogicException('Invoices are immutable; refused to change: '.implode(', ', $frozen).'.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Invoices cannot be deleted.');
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
            'type' => InvoiceType::class,
            'reason' => InvoiceCorrectionReason::class,
            'sequence_year' => 'integer',
            'sequence_number' => 'integer',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'collected_at' => 'datetime',
            'tax_rate' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'collection_carried_over' => 'boolean',
        ];
    }

    /**
     * The order this invoice belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The frozen lines of this invoice.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * The invoice this corrective corrects (null on a standard invoice).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_invoice_id');
    }

    /**
     * The correctives issued against this invoice.
     *
     * @return HasMany<Invoice, $this>
     */
    public function correctives(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_invoice_id');
    }

    /**
     * The user who issued the invoice (null when the system issued it).
     *
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * The user who marked the invoice as collected (null when uncollected or carried over).
     *
     * @return BelongsTo<User, $this>
     */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /**
     * The payment method the invoice was collected with.
     *
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function collectionPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'collection_payment_method_id');
    }

    /**
     * Has the invoice been collected (story 0106)?
     */
    public function isCollected(): bool
    {
        return $this->collected_at !== null;
    }

    /**
     * Every e-mail delivery attempt of this invoice (story 0104).
     *
     * @return HasMany<InvoiceDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(InvoiceDelivery::class);
    }

    /**
     * The most recently queued delivery, or null when none exists.
     */
    public function latestDelivery(): ?InvoiceDelivery
    {
        return $this->deliveries()->latest('queued_at')->latest('id')->first();
    }

    /**
     * Whether the invoice has been sent to its customer: true iff a `sent` delivery exists
     * (stories 0102 FD-7 and 0104).
     */
    public function hasBeenSent(): bool
    {
        return $this->deliveries()->where('status', InvoiceDeliveryStatus::Sent->value)->exists();
    }

    /**
     * Only standard invoices.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeStandard(Builder $query): Builder
    {
        return $query->where('type', InvoiceType::Standard->value);
    }

    /**
     * Only corrective invoices.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeCorrective(Builder $query): Builder
    {
        return $query->where('type', InvoiceType::Corrective->value);
    }

    /**
     * Only collected invoices.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeCollected(Builder $query): Builder
    {
        return $query->whereNotNull('collected_at');
    }

    /**
     * Only uncollected invoices.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeUncollected(Builder $query): Builder
    {
        return $query->whereNull('collected_at');
    }

    /**
     * Only invoices that are not voided.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * What is left of this invoice after its correctives: its total plus the (negative) totals of
     * the invoices that correct it, as a scale-2 string.
     */
    public function remainingAmount(): string
    {
        $toNumeric = app(ToNumericString::class);
        $remaining = $toNumeric($this->total);

        foreach ($this->correctives()->pluck('total') as $total) {
            $remaining = bcadd($remaining, $toNumeric((string) $total), 2);
        }

        return bcadd($remaining, '0', 2);
    }
}
