<?php

namespace App\Models;

use Database\Factories\ShippingCarrierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A seeded shipping carrier (story 0035): SEUR, Correos, MRW, DHL Express --
 * the four integrated carriers this prototype ships with. Carriers are
 * seeded, not admin-creatable; the only mutation is the active/inactive
 * toggle (App\Actions\Shipping\ToggleShippingCarrier).
 *
 * `is_active` is deliberately OMITTED from #[Fillable] -- the same
 * mass-assignment-guard convention `users.status`/`sales_regions.is_active`
 * already use (see docs/conventions/base-standards.md#model-conventions).
 * ToggleShippingCarrier is this column's single writer, via forceFill().
 *
 * `code` is ALSO omitted, for the identical reason `App\Models\SalesRegion`
 * omits its own `slug`: it is the seeder's idempotency key (never `name`,
 * which is editable display text), and a form that could change it would
 * make the next re-seed insert a duplicate row -- proven by Phase 4 security
 * audit execution: a mass-assigned `code` change took a 4-row catalog to 5
 * on the next `db:seed`, with the resurrected row landing ACTIVE via the
 * column's own default, silently undoing an administrator's disable
 * decision. See database/seeders/ShippingCarrierSeeder.php.
 *
 * No ShippingCarrierPolicy: no per-target rule exists to justify one, so
 * `shipping.view`/`shipping.edit` are authorized directly as permission
 * strings, the same mechanism `can:shipping.view` route middleware already
 * relies on (Spatie's PermissionRegistrar registers every seeded permission
 * name as its own Gate ability). Revisit only if a later story introduces a
 * per-carrier business rule.
 *
 * The two permission names are named once here, on the model, per
 * naming.md's "name a permission once on the class that owns the rule"
 * convention -- the same shape every policy on this page's Permission
 * catalog table uses, applied to the one class that owns the concept when
 * no policy exists to hold the constant instead.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class ShippingCarrier extends Model
{
    /** @use HasFactory<ShippingCarrierFactory> */
    use HasFactory, HasUuids;

    public const VIEW_PERMISSION = 'shipping.view';

    public const EDIT_PERMISSION = 'shipping.edit';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * This carrier's rate rules (story 0036). Deliberately unfiltered by
     * `is_active` -- a disabled carrier's rate rules survive untouched
     * (D-6) and this relation is configuration, not resolution; only
     * App\Actions\Shipping\ResolveApplicableShippingRate filters on the
     * carrier's active state.
     *
     * The foreign key is passed EXPLICITLY -- see
     * App\Models\ShippingZone::shippingRates()'s identical docblock for why
     * relying on `hasMany()`'s class_basename()-derived default is unsafe.
     *
     * @return HasMany<ShippingRate, $this>
     */
    public function shippingRates(): HasMany
    {
        return $this->hasMany(ShippingRate::class, 'shipping_carrier_id');
    }
}
