<?php

namespace App\Livewire\PaymentMethods;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\PaymentMethods\UpdatePaymentMethodIban;
use App\Concerns\PaymentMethodValidationRules;
use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Payment methods store-settings screen: list the catalog (bank transfer
 * only this phase) and edit its IBAN (story 0038). This component's public
 * surface is a contract the paired frontend story (0039) builds against --
 * see this story's own task file for the full specification.
 *
 * Access is gated on `payment-methods.view` (route middleware, `mount()`),
 * with `payment-methods.edit` re-checked inside `openEditModal()`/`save()`,
 * since Livewire 4's `PersistentMiddleware` allowlist does not carry
 * Spatie's `permission:` middleware -- see docs/architecture/authorization.md.
 *
 * No create/delete method exists on this component at all -- bank transfer
 * is the only payment method this phase, and PaymentMethodPolicy's
 * create()/delete() abilities exist purely as an explicit `false` refusal
 * for a caller that reaches them some other way.
 */
#[Title('Payment methods')]
class Index extends Component
{
    use PaymentMethodValidationRules;

    /**
     * @var array<int, array{id: string, code: PaymentMethodCode, iban: string|null, canEdit: bool}>
     *
     * Locked (Phase 4 security audit finding F-5): entirely server-derived,
     * and nothing in this component reads it back for a decision -- every
     * mutating method re-resolves its target from the database. Locking
     * costs nothing and closes an otherwise-open avenue for a future method
     * to be added that trusts it.
     */
    #[Locked]
    public array $paymentMethods = [];

    /**
     * Written only from $target->id, never the raw method argument -- this
     * is what makes the row save() writes to server-authoritative rather
     * than client-controlled.
     */
    #[Locked]
    public ?string $editingMethodId = null;

    public bool $showModal = false;

    /**
     * Never `null` -- an empty string is the "not configured yet" sentinel
     * for the IBAN <input>. Livewire assigns this property's dehydrated
     * value straight onto the DOM element's `.value`, and a JS `null`
     * stringifies to "null"; see the wire:model desync bug in
     * docs/errors-log.md.
     */
    public string $iban = '';

    /**
     * `viewAny` is authorized here in addition to the route's `can:`
     * middleware because Livewire's `/livewire/update` endpoint is a
     * separate entry point that never runs route middleware -- mounting
     * the component directly (as every `Livewire::test()` call does) must
     * be denied on its own.
     *
     * Deliberately left unlogged, matching every other module screen's
     * identical mount() precedent: the route's own `can:payment-
     * methods.view` gate checks the identical ability, and `can:` -- unlike
     * `permission:` -- IS on Livewire's PersistentMiddleware allow-list, so
     * a real HTTP actor who would fail this check is refused by the route
     * before ever reaching mount(). A refusal here is therefore
     * unreachable over HTTP.
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', PaymentMethod::class);

        $this->loadPaymentMethods();
    }

    /**
     * Open the edit form prefilled with the target method's current IBAN.
     *
     * Reads from the resolved model, never from $paymentMethods -- that
     * array is `#[Locked]` but is still display-only state, so backing the
     * form's values out of it would be the wrong source regardless. $iban
     * falls back to '' rather than null, preserving the never-null
     * invariant above.
     *
     * Routes its refusal through LogRefusedPrivilegedAttempt (Phase 4
     * security audit finding F-3) -- this discloses the stored IBAN, and
     * every other admin screen in this app logs a refusal on a disclosing
     * method, not only a mutating one.
     */
    public function openEditModal(string $methodId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = PaymentMethod::findOrFail($methodId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'payment_method', targetId: $target->id);

        $this->editingMethodId = $target->id;
        $this->iban = $target->iban ?? '';
        $this->showModal = true;

        // Story 0039 finding F4: dismissing the modal via its wire:model-bound $showModal
        // directly (the X control or a click outside) bypasses closeModal() entirely, and
        // Livewire persists the error bag across that round trip -- without this, a stale
        // `iban` error from a previously refused save on one method rendered against the
        // next method opened, with no field and no context. See
        // docs/security/livewire-error-bag-persistence.md rule 2 ("every opener, not only
        // every closer") -- this only has one method to configure this phase, but the fix
        // belongs on the opener regardless of how many methods exist.
        $this->resetValidation('iban');
    }

    /**
     * Validate and persist the IBAN form.
     *
     * Normalisation (uppercase, whitespace stripped) happens here,
     * immediately before validate() and after authorization -- normalising
     * only inside the action would let the validator see the
     * un-normalised value and reject every space-separated IBAN a user
     * pastes from a bank statement. UpdatePaymentMethodIban re-applies the
     * same normalisation defensively (and self-authorizes -- Phase 4
     * security audit finding F-1), so a future second call site that
     * forgets this step, or that calls the action directly, still stores
     * the canonical form and still enforces the gate.
     *
     * Writes a success audit line (Phase 4 security audit finding F-3),
     * matching App\Livewire\SalesRegions\Index/App\Livewire\Shipping\Index's
     * own precedent for customer-facing financial configuration -- the
     * IBAN is the store's payout destination, the single highest-impact
     * field in this application. The IBAN value itself is never logged.
     */
    public function save(UpdatePaymentMethodIban $updatePaymentMethodIban, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = PaymentMethod::findOrFail($this->editingMethodId);

        $logRefusedPrivilegedAttempt->authorize('update', $target, targetType: 'payment_method', targetId: $target->id);

        $this->iban = strtoupper((string) preg_replace('/\s+/u', '', $this->iban));

        $this->validate(['iban' => $this->ibanRules()]);

        $updatePaymentMethodIban($target, $this->iban);

        Log::info('Payment method IBAN updated', [
            'actor_id' => auth()->id(),
            'payment_method_id' => $target->id,
            'code' => $target->code->value,
        ]);

        $this->loadPaymentMethods();
        $this->closeModal();
    }

    /**
     * Close the edit modal and reset its form state.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingMethodId', 'iban']);
        $this->resetValidation('iban');
    }

    /**
     * Reload the payment methods list from the database.
     *
     * `canEdit` mirrors the same PaymentMethodPolicy method save()
     * authorizes against (Gate::allows('update', $method)), so the
     * disabled state cannot drift from what a click would actually do.
     */
    private function loadPaymentMethods(): void
    {
        $this->paymentMethods = PaymentMethod::query()
            ->orderBy('code')
            ->get()
            ->map(fn (PaymentMethod $method): array => [
                'id' => $method->id,
                'code' => $method->code,
                'iban' => $method->iban,
                'canEdit' => Gate::allows('update', $method),
            ])
            ->all();
    }
}
