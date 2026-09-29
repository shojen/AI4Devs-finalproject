<?php

namespace App\Actions\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\ShippingZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteShippingZone
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Delete a shipping zone -- hard-blocked while any shipping rate rule
     * still references it (story 0036, D-5, discharging 0033's own D-1
     * hand-off).
     *
     * Corrected at Phase 4 security audit (finding F-1): this docblock
     * previously claimed "D-9: this action deliberately self-authorizes
     * nothing" -- see CreateShippingZone's docblock for the full
     * correction. Authorizes `delete` on `$shippingZone` as its own first
     * statement, the identical self-authorizing shape
     * App\Actions\ProductCategories\DeleteProductCategory already uses.
     *
     * UNCHANGED and NOT OPTIONAL: this call MUST remain the FIRST
     * statement, above both the in-use count below and the transaction. A
     * reversed order leaks the rate count to an actor who does not even
     * hold `shipping.delete` -- the identical ordering rule
     * DeleteProductCategory's own docblock states.
     *
     * D-5: the count is UNFILTERED by carrier and by carrier state -- a
     * disabled carrier's rates still block the zone's deletion, because a
     * disabled carrier's rates survive untouched (D-6) and deleting the
     * zone out from under them would destroy configuration that returns
     * the moment the carrier is re-enabled.
     *
     * The count-and-delete run inside ONE DB::transaction() -- a knowing
     * divergence from DeleteProductCategory, which counts outside any
     * transaction of its own; this is the atomicity 0033 pre-shaped this
     * wrapper for, and do NOT "align" it back.
     *
     * Sound only because neither model soft-deletes (D-5): ShippingZone
     * has no SoftDeletes (0033 D-7) so the restrictOnDelete() FK actually
     * fires, and ShippingRate must not gain SoftDeletes either (D-14) or
     * this count silently starts excluding trashed rates with no edit
     * here.
     */
    public function __invoke(ShippingZone $shippingZone): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'delete',
            $shippingZone,
            targetType: 'shipping_zone',
            targetId: $shippingZone->id,
        );

        return DB::transaction(function () use ($shippingZone): bool {
            $inUseCount = $shippingZone->shippingRates()->count();

            if ($inUseCount > 0) {
                throw $this->blockedByRates($shippingZone, $inUseCount);
            }

            try {
                // deleteOrFail(), NEVER delete(). Larastan level 7 flags a plain
                // ->delete() in this position as a dead catch: Eloquent's
                // Model::delete() carries no @throws annotation Larastan can
                // trace, so the QueryException branch below would be statically
                // unreachable. deleteOrFail() is Laravel's own documented
                // `@throws \Throwable` sibling with no behavioural difference --
                // this is DeleteProductCategory's exact, already-shipped fix.
                return (bool) $shippingZone->deleteOrFail();
            } catch (QueryException $e) {
                // Narrowed to 1451 (ER_ROW_IS_REFERENCED_2), NOT the whole 23000
                // SQLSTATE class (D-5's 2026-09-09 correction). 1451 here is
                // shipping_rates.shipping_zone_id refusing under
                // restrictOnDelete(): a rate was created for this zone between
                // the count above and this delete. The count is the primary
                // guard; the FK is the last word.
                if (($e->errorInfo[1] ?? null) !== 1451) {
                    throw $e;
                }

                // ALWAYS throws a ValidationException on this branch -- never
                // falls through to a bare `throw $e`. Re-counting inside a
                // rolled-back transaction can legitimately read 0
                // (deleteOrFail() wraps its DELETE in a transaction, so the
                // 1451 rolls back the racing writer's view), and a 0 that
                // reached a `$count > 0` test would fall through and surface
                // the raw QueryException as a 500 -- the exact outcome D-5
                // says must never happen. The floor lives in blockedByRates();
                // this branch just always throws it.
                throw $this->blockedByRates($shippingZone, $shippingZone->shippingRates()->count());
            }
        });
    }

    /**
     * Build (never throw) the refusal, so both call sites above are a bare
     * `throw $this->blockedByRates(...)` and neither can forget to throw.
     *
     * Mirrors DeleteProductCategory::blockedByProducts() property for
     * property:
     *
     * - `max(1, $count)` is a PRESENTATION floor, not a correctness claim
     *   about how many rates reference the row. It is what turns an
     *   always-0 recount on the rolled-back race path into a coherent
     *   "used by 1 shipping rate" instead of a 500. The primary call site
     *   never needs it -- it only runs once the count is already positive.
     * - The domain refusal is LOGGED via LogRefusedPrivilegedAttempt::log()
     *   (never ->authorize(), which would re-run the already-passed Gate
     *   check above) with the snake_case reason 'zone_in_use', matching the
     *   non-Gate refusal convention DeleteProductCategory's
     *   'category_in_use' and SetSalesRegionActive's own domain-invariant
     *   refusals established. This is a domain-invariant refusal, not an
     *   authorization one: the actor may hold shipping.delete and the
     *   answer is still no.
     *
     * @return ValidationException keyed on 'shippingZoneId' -- a hand-off
     *                             contract story 0034's zone-delete modal
     *
     *                              @error block binds to (NOT 0037's; see
     *                              D-5's 2026-08-19 correction).
     */
    private function blockedByRates(ShippingZone $shippingZone, int $count): ValidationException
    {
        $count = max(1, $count);

        $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'zone_in_use', 'shipping_zone', $shippingZone->id);

        return ValidationException::withMessages([
            'shippingZoneId' => trans_choice('shipping.zones.delete_blocked', $count, ['count' => $count]),
        ]);
    }
}
