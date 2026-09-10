<?php

namespace App\Livewire\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Shipping\CreateShippingRate;
use App\Actions\Shipping\DeleteShippingRate;
use App\Actions\Shipping\ListShippingRatesByCarrier;
use App\Actions\Shipping\ToggleShippingCarrier;
use App\Actions\Shipping\UpdateShippingRate;
use App\Concerns\ShippingRateValidationRules;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice Shipping screen (story 0037): carrier cards with an
 * enable/disable toggle (story 0035) ABOVE a rate table grouped by carrier
 * (story 0036's ShippingRatePolicy/actions, gaining their first real call
 * site here -- D-7). This class extends story 0035's already-shipped
 * component in place rather than introducing a second one -- D-1: the same
 * route, the same view file, no new route.
 *
 * No ShippingCarrierPolicy exists (0035 D-9), so the carrier toggle -- and,
 * per D-7, the row-level "may I edit a rate" UI hint -- authorizes against
 * the bare `shipping.edit` permission string rather than a policy.
 * `ShippingRatePolicy` (story 0036) governs the actual rate mutations.
 *
 * D-4: the carrier select and the rate table both list EVERY carrier,
 * disabled ones included -- `is_active` governs rate RESOLUTION
 * (App\Actions\Shipping\ResolveApplicableShippingRate, D-9: never referenced
 * anywhere on this screen), never rate AUTHORING or LISTING.
 */
#[Title('Shipping')]
class Index extends Component
{
    use ShippingRateValidationRules;

    /** @var array<int, array{id: string, code: string, name: string, description: ?string, isActive: bool}> */
    #[Locked]
    public array $carriers = [];

    /**
     * @var array<int, array{
     *   carrierId: string, carrierCode: string, carrierName: string, carrierIsActive: bool,
     *   rates: array<int, array{id: string, name: string, zoneName: string, minWeightKg: string,
     *     maxWeightKg: string|null, price: string, deliveryEstimate: string}>
     * }>
     */
    #[Locked]
    public array $ratesByCarrier = [];

    public bool $showRateModal = false;

    /**
     * Server-authoritative -- set only from a resolved model's own `id`,
     * never the raw method argument, and re-read from the database again in
     * saveRate() (never trusted from this property alone). Matches
     * App\Livewire\Shipping\Zones::$editingZoneId's identical shape.
     */
    #[Locked]
    public ?string $editingRateId = null;

    public string $rateName = '';

    /**
     * Bound to native form controls -- every one of these is a STRING with a
     * real empty value, never null. Livewire assigns a property's
     * dehydrated value straight onto the DOM element's .value, and a JS
     * null stringifies to "null", desynchronising the browser's own state.
     * See docs/errors-log.md and D-2.
     */
    public string $shippingCarrierId = '';

    public string $shippingZoneId = '';

    public string $minWeightKg = '0';

    public string $price = '';

    public string $deliveryEstimate = '';

    /** '' is the "no maximum" sentinel -- normalised to null before the action runs. See D-2. */
    public string $maxWeightKg = '';

    public bool $showDeleteRateModal = false;

    #[Locked]
    public ?string $deletingRateId = null;

    #[Locked]
    public string $deletingRateName = '';

    /**
     * Maps a snake_case ShippingRateValidationRules key (the shape
     * saveRate()'s own Validator::make() call validates against, and the
     * shape CreateShippingRate/UpdateShippingRate's own internal FK-race
     * catch throws with -- see database/schema.md's shipping_rates section)
     * onto the camelCase property each field is actually bound to.
     *
     * Phase 4/5 finding (both appsec-auditor F-1 and code-reviewer L1):
     * Livewire's SupportValidation::dehydrate() persists a ValidationException
     * error into the NEXT round trip's error bag only when its key passes
     * Utils::hasProperty($component, $key) -- a plain property_exists(), no
     * snake<->camel mapping. Left unmapped, six of this form's seven keys
     * (every one except `price`, which happens to already equal its own
     * camelCase property) silently vanish the instant the modal re-renders
     * for any reason other than the throwing request itself -- see
     * docs/security/livewire-error-bag-persistence.md, which this exact
     * failure mode already documents against App\Livewire\Shipping\Zones's
     * sibling screen.
     *
     * @var array<string, string>
     */
    private const RATE_FIELD_MAP = [
        'name' => 'rateName',
        'shipping_carrier_id' => 'shippingCarrierId',
        'shipping_zone_id' => 'shippingZoneId',
        'min_weight_kg' => 'minWeightKg',
        'max_weight_kg' => 'maxWeightKg',
        'price' => 'price',
        'delivery_estimate' => 'deliveryEstimate',
    ];

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
     *
     * Also authorizes `viewAny` on ShippingRate::class -- discharging
     * ShippingRatePolicy's own documented hand-off (its docblock and
     * docs/architecture/shipping.md both name this screen as that
     * ability's consumer; Phase 4/5 finding, both appsec-auditor F-6 and
     * code-reviewer L3). Functionally the same permission as the carrier
     * check above (`shipping.view`), so this costs nothing extra and
     * matches App\Livewire\Shipping\Zones::mount()'s own two-ability shape.
     */
    public function mount(): void
    {
        Gate::authorize(ShippingCarrier::VIEW_PERMISSION);
        Gate::authorize('viewAny', ShippingRate::class);

        $this->loadCarriers();
        $this->loadRates();
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
     *
     * D-11: disabling a carrier that still has rate rules asks for nothing
     * and destroys nothing -- ToggleShippingCarrier never touches
     * shipping_rates at all, so no confirmation/warning is added here.
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
     * Open the create-rate form. D-4: the carrier select lists every
     * carrier, disabled included -- a rate rule may be authored for a
     * carrier that is currently disabled (0036 D-6).
     */
    public function openCreateRateModal(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize('create', ShippingRate::class, targetType: 'shipping_rate');

        $this->reset([
            'editingRateId', 'rateName', 'shippingCarrierId', 'shippingZoneId',
            'minWeightKg', 'maxWeightKg', 'price', 'deliveryEstimate',
        ]);
        $this->minWeightKg = '0';
        $this->resetValidation();
        $this->showRateModal = true;
    }

    /**
     * Open the edit form, prefilled from a freshly re-read row -- never
     * from $ratesByCarrier, which is client-writable by construction
     * (see security/livewire-authorization.md's "a modal must read
     * authoritative values from the model rather than back them out of a
     * client-writable array" rule).
     *
     * Resolve-then-authorize, deliberately NOT the "authorize against the
     * raw id" shape toggleCarrier() uses above. A Phase 4/5 finding
     * (appsec-auditor F-2 / code-reviewer's echo of it) suggested mirroring
     * toggleCarrier() here; verified by execution that this does not
     * generalise: `ShippingRatePolicy::update(User $actor, ShippingRate
     * $target)` requires a REAL ShippingRate INSTANCE (unlike
     * toggleCarrier()'s bare `shipping.edit` PERMISSION STRING, which Spatie
     * resolves without ever touching a policy), so
     * `Gate::authorize('update', ShippingRate::class)` throws a raw
     * ArgumentCountError -- "Too few arguments ... exactly 2 expected" --
     * reproduced live, for EVERY caller regardless of permission, not an
     * AuthorizationException for an unauthorized one. The 404-vs-403 oracle
     * F-2 worried about is real in principle but not fixable this way for a
     * policy ability that takes a model parameter; it would need a
     * differently-shaped ability (a nullable-target one, the way
     * UserPolicy::promoteToAdministrator() takes ?User) to authorize before
     * resolving safely.
     *
     * The weight fields are trimmed of trailing zeros before display (L6):
     * the raw decimal:3 cast ("5.000") is the value CreateShippingRate/
     * UpdateShippingRate persist, but it is not what an administrator typed
     * -- showing it back verbatim in a field whose whole point (D-5) is
     * comma/plain-decimal friendliness reads as a formatting bug.
     */
    public function openEditRateModal(string $rateId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = ShippingRate::findOrFail($rateId);

        $logRefusedPrivilegedAttempt->authorize(
            'update',
            $target,
            targetType: 'shipping_rate',
            targetId: $target->id,
        );

        $this->editingRateId = $target->id;
        $this->rateName = $target->name;
        $this->shippingCarrierId = $target->shipping_carrier_id;
        $this->shippingZoneId = $target->shipping_zone_id;
        $this->minWeightKg = self::trimTrailingZeros($target->min_weight_kg);
        $this->maxWeightKg = $target->max_weight_kg === null ? '' : self::trimTrailingZeros($target->max_weight_kg);
        $this->price = $target->price;
        $this->deliveryEstimate = $target->delivery_estimate;
        $this->resetValidation();
        $this->showRateModal = true;
    }

    /**
     * Validate and persist the create/edit rate form.
     *
     * D-2's whole finding lives here: every value is trimmed and
     * comma-normalised BEFORE either action ever runs, deliberately upstream
     * of CreateShippingRate/UpdateShippingRate's own validation. Livewire
     * skips ConvertEmptyStringsToNull/TrimStrings on /livewire/update, and
     * Laravel's validator silently SKIPS every non-implicit rule
     * (numeric/decimal/min/max/gte) against a blank string rather than
     * rejecting it -- so a raw '' for `max_weight_kg` would sail through
     * `maxWeightRules()` untouched and reach a DECIMAL(8,3) column as a raw
     * empty string. Corrected against this repo's real state, not the task
     * file's own stale premise: this app pins `DB_CONNECTION=mysql` in
     * every environment including tests (verified against phpunit.xml and
     * .env.example directly) -- there is no SQLite-green/MySQL-500
     * divergence here, a raw '' would 500 with a real QueryException in
     * every environment alike. The fix is unchanged regardless: normalising
     * '' to a real `null` here is what makes `nullable` the rule that
     * actually decides, rather than depending on
     * presentOrRuleIsImplicit() behaviour no future reader has any reason
     * to know (D-2, finding 2 -- see this story's own errors-log.md entry
     * for the corrected write-up).
     *
     * D-5: a Spanish-locale decimal comma is normalised to a dot here, for
     * every decimal field (weight AND price) -- never relied on the
     * action's own validation to accept it, since `decimal:0,N` has no
     * comma branch.
     *
     * Validated here, at the component level, against an explicit snake_case
     * data array matching the action's own `$attributes` shape -- reusing
     * ShippingRateValidationRules' rule methods VERBATIM, never a parallel
     * camelCase rule set. This is deliberately `Validator::make($attributes, [...])`,
     * never `$this->validate([...])`: Livewire's own validate() reads its data
     * from this component's PUBLIC PROPERTIES by name, and `maxWeightRules()`'s
     * `gte:min_weight_kg` rule looks up its comparison field by that EXACT
     * key in the data array it validates against -- calling `$this->validate()`
     * with camelCase keys would silently break that cross-field lookup, since
     * no `min_weight_kg` key would exist in Livewire's own property-derived
     * data. The one consequence a consumer of this view must know: the
     * resulting ValidationException's error bag carries these same
     * snake_case keys, not the camelCase properties each field is bound to
     * -- rethrowMappedValidationException() below re-keys it onto
     * RATE_FIELD_MAP before it ever reaches Livewire's own exception
     * handling, so the persisted bag (and Flux's automatic per-field error
     * display) work exactly as they would for an ordinary $this->validate()
     * call (Phase 4/5 finding, appsec-auditor F-1 / code-reviewer L1).
     *
     * The `attributes` translation block passed as Validator::make()'s 4th
     * argument (Phase 5 finding M1) is what stops `:attribute` humanizing
     * the raw column name ("The shipping carrier id field is required.")
     * instead of the field's real label -- matching lang/en/sales-regions.php's
     * own `attributes` block precedent (naming.md's documented exception).
     *
     * Authorizes via LogRefusedPrivilegedAttempt, matching
     * App\Livewire\Shipping\Zones::save()'s identical shape -- a second
     * layer on top of CreateShippingRate/UpdateShippingRate's own
     * self-authorization (0036 D-11), discharging that story's zero-call-
     * site hand-off (D-7). Resolve-then-authorize on the edit branch, NOT
     * the "authorize against the raw id" shape -- see openEditRateModal()'s
     * own docblock for why that does not generalise to `update`/`delete`
     * abilities on this policy (verified by execution: it throws a raw
     * ArgumentCountError, not an AuthorizationException).
     */
    public function saveRate(CreateShippingRate $create, UpdateShippingRate $update, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = $this->editingRateId === null ? null : ShippingRate::findOrFail($this->editingRateId);

        $logRefusedPrivilegedAttempt->authorize(
            $target === null ? 'create' : 'update',
            $target ?? ShippingRate::class,
            targetType: 'shipping_rate',
            targetId: $target?->id,
        );

        $trimmedMaxWeight = trim($this->maxWeightKg);

        $attributes = [
            'name' => trim($this->rateName),
            'shipping_carrier_id' => $this->shippingCarrierId,
            'shipping_zone_id' => $this->shippingZoneId,
            'min_weight_kg' => str_replace(',', '.', trim($this->minWeightKg)),
            // Blank means "and above" (D-4/D-2): normalised to a real null
            // BEFORE validation and before the action runs, never left as
            // '' for `nullable` to (mis)handle by accident.
            'max_weight_kg' => $trimmedMaxWeight === '' ? null : str_replace(',', '.', $trimmedMaxWeight),
            'price' => str_replace(',', '.', trim($this->price)),
            'delivery_estimate' => trim($this->deliveryEstimate),
        ];

        try {
            Validator::make($attributes, [
                'name' => $this->shippingRateNameRules(),
                'shipping_carrier_id' => $this->shippingCarrierIdRules(),
                'shipping_zone_id' => $this->shippingZoneIdRules(),
                'min_weight_kg' => $this->minWeightRules(),
                'max_weight_kg' => $this->maxWeightRules(),
                'price' => $this->priceRules(),
                'delivery_estimate' => $this->deliveryEstimateRules(),
            ], [], __('shipping.rates.attributes'))->validate();

            $rate = $target === null ? $create($attributes) : $update($target, $attributes);
        } catch (ValidationException $e) {
            $this->rethrowMappedValidationException($e);
        }

        Log::info($target === null ? 'Shipping rate created' : 'Shipping rate updated', [
            'actor_id' => Auth::id(),
            'rate_id' => $rate->id,
            'shipping_carrier_id' => $rate->shipping_carrier_id,
            'shipping_zone_id' => $rate->shipping_zone_id,
            'price' => $rate->price,
        ]);

        $this->loadRates();
        $this->closeRateModal();
    }

    /**
     * Re-key a ValidationException's snake_case error bag onto the
     * camelCase RATE_FIELD_MAP before rethrowing -- see RATE_FIELD_MAP's
     * own docblock for why this is necessary at all. Applies to BOTH the
     * component-level Validator::make() call above and
     * CreateShippingRate/UpdateShippingRate's own internal FK-race catch
     * (`shipping_carrier_id`/`shipping_zone_id`), which validates and
     * throws with the identical snake_case shape.
     */
    private function rethrowMappedValidationException(ValidationException $e): never
    {
        throw ValidationException::withMessages(
            collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [self::RATE_FIELD_MAP[$key] ?? $key => $messages])
                ->all()
        );
    }

    /**
     * Close the create/edit rate modal and reset its form fields.
     *
     * Reached both by a successful save and by dismissing the modal
     * directly (the X control or a click outside), so resetValidation()
     * here matches every other screen's identical opener/closer pattern --
     * a stale error from a previously refused save must not render against
     * the next freshly opened form.
     */
    public function closeRateModal(): void
    {
        $this->showRateModal = false;
        $this->reset([
            'editingRateId', 'rateName', 'shippingCarrierId', 'shippingZoneId',
            'minWeightKg', 'maxWeightKg', 'price', 'deliveryEstimate',
        ]);
        $this->minWeightKg = '0';
        $this->resetValidation();
    }

    /**
     * Open the delete-confirmation modal for the target rate.
     *
     * resetValidation() as the last statement (Phase 5 finding L2, matching
     * App\Livewire\Shipping\Zones::confirmDelete()'s identical hygiene): a
     * stale error from a previously refused save on a DIFFERENT rate must
     * not render against this freshly opened confirmation.
     */
    public function confirmDeleteRate(string $rateId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = ShippingRate::findOrFail($rateId);

        $logRefusedPrivilegedAttempt->authorize(
            'delete',
            $target,
            targetType: 'shipping_rate',
            targetId: $target->id,
        );

        $this->deletingRateId = $target->id;
        $this->deletingRateName = $target->name;
        $this->showDeleteRateModal = true;
        $this->resetValidation();
    }

    /**
     * Delete the confirmed rate. D-9/0036 D-5: no in-use guard exists for a
     * rate -- deleting it blocks on nothing.
     *
     * Re-authorizes here too, even though DeleteShippingRate already
     * self-authorizes and confirmDeleteRate() already gated the same
     * ability before this modal ever opened -- defence in depth, matching
     * App\Livewire\Shipping\Zones::deleteZone()'s identical shape.
     */
    public function deleteRate(DeleteShippingRate $delete, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->deletingRateId === null) {
            return;
        }

        $target = ShippingRate::findOrFail($this->deletingRateId);

        $logRefusedPrivilegedAttempt->authorize(
            'delete',
            $target,
            targetType: 'shipping_rate',
            targetId: $target->id,
        );

        $delete($target);

        Log::info('Shipping rate deleted', [
            'actor_id' => Auth::id(),
            'rate_id' => $target->id,
            'shipping_carrier_id' => $target->shipping_carrier_id,
            'shipping_zone_id' => $target->shipping_zone_id,
        ]);

        $this->loadRates();
        $this->closeDeleteRateModal();
    }

    /**
     * Close the delete-confirmation modal and reset its state.
     */
    public function closeDeleteRateModal(): void
    {
        $this->showDeleteRateModal = false;
        $this->reset(['deletingRateId', 'deletingRateName']);
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

    /**
     * Grouped by carrier via ListShippingRatesByCarrier's own eager-loaded
     * query -- never a PHP ->groupBy() over a flat rate list, which would
     * silently drop a zero-rate carrier's own empty group.
     *
     * D-8: `maxWeightKg` stays `null` in this array's own shape (never
     * coerced to a string here) so the view's own null-check renders "N kg
     * and above" rather than string-concatenating "N-null".
     */
    private function loadRates(): void
    {
        $this->ratesByCarrier = app(ListShippingRatesByCarrier::class)()
            ->map(fn (ShippingCarrier $carrier): array => [
                'carrierId' => $carrier->id,
                'carrierCode' => $carrier->code,
                'carrierName' => $carrier->name,
                'carrierIsActive' => $carrier->is_active,
                'rates' => $carrier->shippingRates
                    ->map(fn (ShippingRate $rate): array => [
                        'id' => $rate->id,
                        'name' => $rate->name,
                        'zoneName' => $rate->zone->name,
                        'minWeightKg' => $rate->min_weight_kg,
                        'maxWeightKg' => $rate->max_weight_kg,
                        'price' => $rate->price,
                        'deliveryEstimate' => $rate->delivery_estimate,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * D-4: every carrier, disabled included -- a rate rule may be created
     * for a carrier that is currently disabled (0036 D-6). OQ-B
     * (recommended, adopted): an inline "(Inactive)" suffix distinguishes a
     * disabled carrier in the select, so an administrator does not create a
     * rate for a carrier that will never quote with no signal at all.
     *
     * @return array<int, array{id: string, label: string}>
     */
    #[Computed]
    public function carrierOptions(): array
    {
        return array_map(
            fn (array $carrier): array => [
                'id' => $carrier['id'],
                'label' => $carrier['isActive']
                    ? $carrier['name']
                    : __('shipping.carriers.editor.carrier_option_inactive', ['name' => $carrier['name']]),
            ],
            $this->carriers,
        );
    }

    /**
     * D-3: a plain bounded dropdown over the existing zone catalog, never
     * 0022's searchable multi-select -- a shipping zone catalog is
     * admin-curated, realistically in the tens, not the ~8,100-row
     * geography catalog 0022 exists for, and a rate carries exactly one
     * zone, not a multi-select array.
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function zoneOptions(): array
    {
        return ShippingZone::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ShippingZone $zone): array => ['id' => $zone->id, 'name' => $zone->name])
            ->all();
    }

    /**
     * D-6/D-7: a SCREEN-LEVEL flag, evaluated once per render -- never a
     * per-row Gate::allows() -- because ShippingRatePolicy carries no
     * per-target rule (its update()/delete() ignore $target entirely), so
     * N per-row checks would say exactly as much as one. Governs both the
     * carrier toggle's disabled state AND a rate row's edit-icon disabled
     * state: D-7 deliberately uses the bare `shipping.edit` permission
     * string here rather than ShippingRatePolicy::update() -- that ability
     * requires a non-nullable ShippingRate target, and at render time,
     * before any specific rate is being edited, there is no row to pass.
     * The actual mutation always authorizes against a concrete $rate
     * (saveRate()/deleteRate() above); this flag is the UI hint only.
     */
    #[Computed]
    public function canEditShipping(): bool
    {
        return Gate::allows(ShippingCarrier::EDIT_PERMISSION);
    }

    #[Computed]
    public function canCreateRate(): bool
    {
        return Gate::allows('create', ShippingRate::class);
    }

    #[Computed]
    public function canDeleteRate(): bool
    {
        return Gate::allows('delete', new ShippingRate);
    }

    /**
     * Display-only trimming of a decimal:3-cast weight string ("5.000" ->
     * "5", "0.500" -> "0.5") -- reused by both openEditRateModal()'s prefill
     * (L6) and the rate table's own weight cell (shipping.blade.php).
     *
     * Phase 5 code-review finding L4: guarded by str_contains($value, '.')
     * rather than assuming every input has a decimal point. The unguarded
     * form (`rtrim(rtrim($value, '0'), '.')`) silently mistreats a plain
     * '10' or '100' as trailing zeros to strip -- '10' -> '1', a 10x
     * understatement -- and was only safe here because decimal:3
     * guarantees a decimal point on every value THIS class ever passes it;
     * a public helper another caller might reuse should not depend on that.
     */
    public static function trimTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
