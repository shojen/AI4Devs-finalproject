<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A store end-customer — an admin-managed record entirely separate from the
 * Users/Roles/Permissions system (story 0041, D-11). A Customer can never
 * authenticate into the dashboard: it implements no auth contract and uses
 * neither Spatie's HasRoles nor holds any role or permission. Deleting one
 * (story 0042) is a soft delete with no override on delete() — unlike
 * App\Models\User, a Customer has no authentication identifier to recycle,
 * so its email stays reserved and no column is obfuscated (0042, D-1/D-2).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $shipping_address_line1
 * @property string|null $shipping_address_line2
 * @property string|null $shipping_city
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_province
 * @property string|null $shipping_country
 * @property string|null $billing_address_line1
 * @property string|null $billing_address_line2
 * @property string|null $billing_city
 * @property string|null $billing_postal_code
 * @property string|null $billing_province
 * @property string|null $billing_country
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Order> $orders
 */
#[Fillable([
    'name', 'email', 'phone',
    'shipping_address_line1', 'shipping_address_line2', 'shipping_city',
    'shipping_postal_code', 'shipping_province', 'shipping_country',
    'billing_address_line1', 'billing_address_line2', 'billing_city',
    'billing_postal_code', 'billing_province', 'billing_country',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * This customer's orders (story 0047) -- 0041 D-15 and 0045 D-6 both
     * deliberately deferred this relation to its first real reader rather
     * than shipping it ahead of a consumer. No default ordering here
     * (story 0047 D-4): the relation is shared infrastructure every future
     * caller reuses, and a baked-in orderBy() would be a hidden global the
     * next caller has to fight. The story's own order-history screen
     * supplies its own `orderByDesc('created_at')->orderByDesc('id')` at
     * the query call site instead.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
}
