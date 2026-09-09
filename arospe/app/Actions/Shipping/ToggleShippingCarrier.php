<?php

namespace App\Actions\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\ShippingCarrier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Set a shipping carrier's active/inactive state -- the only mutation this
 * story ships. Carriers are seeded, not admin-creatable (story 0035).
 *
 * Self-authorizes `shipping.edit` as its own first statement, per
 * docs/conventions/base-standards.md's "an authorization rule belongs to
 * the action, not to one of its callers" -- matching every other action in
 * this folder (App\Actions\Shipping\RenameShippingZone, etc). No
 * ShippingCarrierPolicy exists (see ShippingCarrier's own docblock for why):
 * the permission string is authorized directly against the target carrier,
 * the same mechanism the `can:shipping.edit` route middleware and every
 * seeded permission already use via Spatie's PermissionRegistrar
 * registering each permission name as its own Gate ability.
 *
 * Phase 4 security-audit finding F-2: takes the DESIRED state, never a
 * blind flip -- matching App\Actions\SalesRegions\SetSalesRegionActive's
 * own `bool $active` shape. A flip computed from a caller-supplied instance
 * cannot tell "the operator clicked disable" from "the operator clicked
 * disable against a row that was already disabled by someone else a moment
 * earlier", and would silently re-enable it instead. Re-reads the row under
 * `lockForUpdate()` inside its own transaction rather than trusting the
 * caller's instance, per docs/security/model-instance-trust.md -- so two
 * concurrent requests serialize on the row lock rather than racing, and the
 * write always applies to the row's true current state. No `attempts:`
 * retry: a single-row lock cannot deadlock with itself, unlike
 * SetSalesRegionActive's two-row lock set.
 */
class ToggleShippingCarrier
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    public function __invoke(ShippingCarrier $shippingCarrier, bool $active): ShippingCarrier
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            ShippingCarrier::EDIT_PERMISSION,
            $shippingCarrier,
            targetType: 'shipping_carrier',
            targetId: $shippingCarrier->id,
        );

        $key = $shippingCarrier->getKey();

        return DB::transaction(function () use ($key, $active): ShippingCarrier {
            $fresh = ShippingCarrier::query()->whereKey($key)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(ShippingCarrier::class, [$key]);

            $fresh->forceFill(['is_active' => $active])->save();

            return $fresh;
        });
    }
}
