<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The gap-free counter of one invoice series (story 0101a). Exactly two rows exist, created by the
 * migration. Written only by App\Actions\Invoices\AllocateInvoiceNumber and
 * App\Actions\Invoices\SetInvoiceSequenceStart.
 *
 * @property string $id
 * @property string $series
 * @property int $last_number
 * @property int|null $last_year
 * @property Carbon|null $last_issued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class InvoiceSequence extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
            'last_year' => 'integer',
            'last_issued_at' => 'immutable_datetime',
        ];
    }
}
