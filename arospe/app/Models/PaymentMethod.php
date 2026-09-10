<?php

namespace App\Models;

use App\Enums\PaymentMethodCode;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property PaymentMethodCode $code
 * @property string|null $iban
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['iban'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'code' => PaymentMethodCode::class,
        ];
    }

    /**
     * Uppercase the IBAN on read.
     *
     * This is a read-only consistency layer for any row that could carry a
     * mixed-case value; it deliberately does not replace the
     * normalise-before-validation step performed by the caller that writes
     * to this column (see App\Livewire\PaymentMethods\Index::save() and
     * App\Actions\PaymentMethods\UpdatePaymentMethodIban).
     *
     * @return Attribute<string|null, never>
     */
    protected function iban(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : strtoupper($value),
        );
    }
}
