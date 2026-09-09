<?php

namespace App\Livewire\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Shipping\ToggleShippingCarrier;
use App\Models\ShippingCarrier;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice Shipping screen (story 0035): lists the seeded carrier catalog
 * and lets a shipping administrator flip each carrier's active/inactive
 * state. Ships with a placeholder view -- real markup (carrier cards,
 * toggle control, ACTIVO/INACTIVO badge) belongs to the paired Epic 2
 * frontend story, mirroring how App\Livewire\Users\Index (task 0004)
 * shipped ahead of task 0006's UI.
 *
 * No ShippingCarrierPolicy: no per-target rule exists to justify one (see
 * App\Models\ShippingCarrier's own docblock) -- `shipping.view`/
 * `shipping.edit` are authorized directly as permission strings, the same
 * mechanism the `can:shipping.view` route middleware already relies on.
 */
#[Title('Shipping')]
class Index extends Component
{
    /** @var array<int, array{id: string, code: string, name: string, description: ?string, isActive: bool}> */
    #[Locked]
    public array $carriers = [];

    /**
     * `shipping.view` is authorized here in addition to the route's `can:`
     * middleware because Livewire's `/livewire/update` endpoint never runs
     * route middleware -- mounting the component directly (as every
     * Livewire::test() call does) must be denied on its own. Unlogged,
     * matching App\Livewire\Shipping\Zones::mount()'s own reasoning: the
     * route's `can:shipping.view` gate checks the identical ability, so a
     * real HTTP actor who would fail this is refused by the route before
     * ever reaching here -- a refusal here is unreachable over HTTP. No
     * second argument -- a bare permission-string ability never consults
     * one (Phase 5 code-review finding N5).
     */
    public function mount(): void
    {
        Gate::authorize(ShippingCarrier::VIEW_PERMISSION);

        $this->loadCarriers();
    }

    /**
     * Set the target carrier's active/inactive state to $active.
     *
     * Authorizes BEFORE resolving the carrier (Phase 4 security-audit
     * finding F-3): `shipping.edit` is a target-independent permission
     * string, so the gate can run against the raw, unresolved id at zero
     * extra cost -- unlike a per-target ability, resolving first here would
     * only give an unauthorized actor a 404-vs-403 existence oracle and a
     * free query per refused attempt, for no benefit. Re-authorizes as a
     * second layer even though ToggleShippingCarrier already self-
     * authorizes -- defence in depth, matching every mutating method on
     * App\Livewire\Shipping\Zones.
     */
    public function toggleCarrier(
        string $carrierId,
        bool $active,
        ToggleShippingCarrier $toggle,
        LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ): void {
        $logRefusedPrivilegedAttempt->authorize(
            ShippingCarrier::EDIT_PERMISSION,
            ShippingCarrier::class,
            targetType: 'shipping_carrier',
            targetId: $carrierId,
        );

        $carrier = ShippingCarrier::query()->findOrFail($carrierId);

        $toggle($carrier, $active);

        $this->loadCarriers();
    }

    /**
     * One query, no N+1 -- the whole catalog is four rows.
     */
    private function loadCarriers(): void
    {
        $this->carriers = ShippingCarrier::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ShippingCarrier $carrier): array => [
                'id' => $carrier->id,
                'code' => $carrier->code,
                'name' => $carrier->name,
                'description' => $carrier->description,
                'isActive' => $carrier->is_active,
            ])
            ->all();
    }
}
