<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A store end-customer — an admin-managed record entirely separate from the
 * Users/Roles/Permissions system (story 0041, D-11). A Customer can never
 * authenticate into the dashboard: it implements no auth contract, uses
 * neither Spatie's HasRoles nor SoftDeletes (0042's addition, not this
 * story's), and holds no role and no permission.
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
    use HasFactory, HasUuids;
}
