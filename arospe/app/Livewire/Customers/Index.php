<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Concerns\CustomerValidationRules;
use App\Models\Customer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice Customers screen: list, create, edit and delete (story 0044).
 *
 * Wires against story 0041's `App\Models\Customer`, `App\Concerns\CustomerValidationRules`,
 * `App\Actions\Customers\CreateCustomer` / `UpdateCustomer` and the D-15 retrieval contract;
 * story 0042's soft-delete semantics and `App\Policies\CustomerPolicy::delete()`; and story
 * 0043's "new customer" notification, dispatched inside `CreateCustomer` and never referenced
 * here. This story adds no migration, model, action, notification or policy of its own — it
 * calls what those three already shipped.
 *
 * Every gate is asked through `App\Policies\CustomerPolicy`, never a raw permission string, and
 * every method that mutates *or discloses* authorizes as its own first statement (see
 * docs/security/livewire-authorization.md). `copyShippingToBilling()` is the one deliberate
 * exception — it moves only client-supplied form state and persists/discloses nothing (D-1).
 *
 * Unlike App\Livewire\Users\Index / App\Livewire\Roles\Index, no `App\Actions\Auth\
 * LogRefusedPrivilegedAttempt` call sites are added here — the task file's own component table
 * specifies plain `Gate::authorize()` throughout, and no step-up authentication applies to this
 * screen at all (D-7): a customer is a passive record with no role, no account status and no
 * authentication identifier of its own.
 */
#[Title('Customers')]
class Index extends Component
{
    use CustomerValidationRules;

    /**
     * The fifteen writable form fields (task file's Component section), in the camelCase shape
     * every `wire:model` target on the view uses. Reused by `openCreateModal()`/`closeModal()`'s
     * `reset()` calls and by `formAttributes()`'s camelCase → snake_case mapping.
     *
     * @var array<int, string>
     */
    private const FORM_FIELDS = [
        'name', 'email', 'phone',
        'shippingAddressLine1', 'shippingAddressLine2', 'shippingCity',
        'shippingPostalCode', 'shippingProvince', 'shippingCountry',
        'billingAddressLine1', 'billingAddressLine2', 'billingCity',
        'billingPostalCode', 'billingProvince', 'billingCountry',
    ];

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $shippingAddressLine1 = '';

    public string $shippingAddressLine2 = '';

    public string $shippingCity = '';

    public string $shippingPostalCode = '';

    public string $shippingProvince = '';

    public string $shippingCountry = '';

    public string $billingAddressLine1 = '';

    public string $billingAddressLine2 = '';

    public string $billingCity = '';

    public string $billingPostalCode = '';

    public string $billingProvince = '';

    public string $billingCountry = '';

    public bool $showModal = false;

    public bool $showDeleteModal = false;

    /**
     * Server-authoritative: the id fed to `customerEmailRules()`'s
     * `Rule::unique()->ignore()` call, so a forged value could otherwise
     * widen the uniqueness exemption to an arbitrary row.
     */
    #[Locked]
    public ?string $editingCustomerId = null;

    #[Locked]
    public ?string $deletingCustomerId = null;

    #[Locked]
    public ?string $deletingCustomerName = null;

    /**
     * Mount the component.
     *
     * `viewAny` is authorized here in addition to the route's `can:customers.view` middleware
     * because Livewire's `/livewire/update` endpoint is a separate entry point that never runs
     * route middleware — mounting the component directly (as every `Livewire::test()` call does)
     * must be denied on its own, mirroring App\Livewire\Users\Index::mount() /
     * App\Livewire\Roles\Index::mount().
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', Customer::class);
    }

    /**
     * Open the create-customer form with every field reset to empty.
     *
     * A disclosure/UI-opening path, so it authorizes independently of save() — per
     * docs/security/livewire-authorization.md's "gate every method that mutates *or discloses*"
     * rule.
     */
    public function openCreateModal(): void
    {
        Gate::authorize('create', Customer::class);

        $this->reset(['editingCustomerId', ...self::FORM_FIELDS]);
        $this->showModal = true;
    }

    /**
     * Open the edit form prefilled with the target customer's current stored values.
     *
     * Authorized against the resolved `$customer` before any of its attributes are copied into
     * public component state — the same ordering App\Livewire\Users\Index::openEditModal() /
     * App\Livewire\Roles\Index::openEditModal() already use.
     */
    public function openEditModal(string $customerId): void
    {
        $customer = Customer::findOrFail($customerId);

        Gate::authorize('update', $customer);

        $this->editingCustomerId = $customer->id;
        $this->name = $customer->name;
        $this->email = $customer->email;
        $this->phone = $customer->phone ?? '';
        $this->shippingAddressLine1 = $customer->shipping_address_line1 ?? '';
        $this->shippingAddressLine2 = $customer->shipping_address_line2 ?? '';
        $this->shippingCity = $customer->shipping_city ?? '';
        $this->shippingPostalCode = $customer->shipping_postal_code ?? '';
        $this->shippingProvince = $customer->shipping_province ?? '';
        $this->shippingCountry = $customer->shipping_country ?? '';
        $this->billingAddressLine1 = $customer->billing_address_line1 ?? '';
        $this->billingAddressLine2 = $customer->billing_address_line2 ?? '';
        $this->billingCity = $customer->billing_city ?? '';
        $this->billingPostalCode = $customer->billing_postal_code ?? '';
        $this->billingProvince = $customer->billing_province ?? '';
        $this->billingCountry = $customer->billing_country ?? '';

        $this->showModal = true;
    }

    /**
     * Copy the six shipping-address fields into the six billing-address fields, once (D-1).
     *
     * A one-time server round-trip, not a live client-side binding — matching what 0041's D-11
     * actually stores (there is no `billing_same_as_shipping` flag and no read-time fallback).
     * Deliberately **no** `Gate` call: this moves values the client already supplied from six of
     * its own form properties into six others, discloses nothing not already on the client, and
     * persists nothing. The write it precedes is gated in save() below.
     */
    public function copyShippingToBilling(): void
    {
        $this->billingAddressLine1 = $this->shippingAddressLine1;
        $this->billingAddressLine2 = $this->shippingAddressLine2;
        $this->billingCity = $this->shippingCity;
        $this->billingPostalCode = $this->shippingPostalCode;
        $this->billingProvince = $this->shippingProvince;
        $this->billingCountry = $this->shippingCountry;
    }

    /**
     * Validate and persist the create or edit form.
     *
     * Authorization is this method's first statement (against `Customer::class` on the create
     * path, against the resolved `$target` on the edit path), matching the task file's own
     * component table. `CreateCustomer` / `UpdateCustomer` authorize the identical ability again
     * internally — the component's own check here is defence in depth, not a substitute.
     *
     * The submitted payload is normalised via `normalizeCustomerAttributes()` and validated via
     * `Validator::make(...)` + `customerRules()` here, in this component, *before* either action
     * ever sees it — not merely inside the actions. Omitting that would be a real divergence: a
     * blank optional field validates differently once Laravel's validator has already skipped its
     * non-implicit rules for a blank string, and an un-trimmed country code would fail this
     * component's own `size:2` check while the action would have accepted and normalised it. See
     * App\Concerns\CustomerValidationRules::normalizeCustomerAttributes()'s own docblock.
     */
    public function save(CreateCustomer $createCustomer, UpdateCustomer $updateCustomer): void
    {
        $target = null;

        if ($this->editingCustomerId === null) {
            Gate::authorize('create', Customer::class);
        } else {
            $target = Customer::findOrFail($this->editingCustomerId);
            Gate::authorize('update', $target);
        }

        $attributes = $this->normalizeCustomerAttributes($this->formAttributes());

        Validator::make($attributes, $this->customerRules($this->editingCustomerId))->validate();

        if ($target === null) {
            $createCustomer($attributes);
        } else {
            $updateCustomer($target, $attributes);
        }

        unset($this->customers);

        $this->closeModal();
    }

    /**
     * Close the create/edit modal and reset its form fields.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['editingCustomerId', ...self::FORM_FIELDS]);
    }

    /**
     * Open the delete-confirmation modal for the target customer.
     *
     * A disclosure path (the target's name), so it authorizes independently of deleteCustomer()
     * — same reasoning as openEditModal() above.
     */
    public function confirmDelete(string $customerId): void
    {
        $customer = Customer::findOrFail($customerId);

        Gate::authorize('delete', $customer);

        $this->deletingCustomerId = $customer->id;
        $this->deletingCustomerName = $customer->name;
        $this->showDeleteModal = true;
    }

    /**
     * Authorize and delete the confirmed customer.
     *
     * D-4: there is no dedicated `DeleteCustomer` action, so this guard lives here by necessity
     * rather than by design — the same placement App\Livewire\Users\Index::deleteUser() records
     * for the identical reason. If a later story extracts the action, the guard moves with it.
     * Deletes through the model **instance** (`$customer->delete()`), never the query builder, so
     * `App\Models\Customer`'s `SoftDeletes` trait (story 0042) is always honoured.
     */
    public function deleteCustomer(): void
    {
        if ($this->deletingCustomerId === null) {
            return;
        }

        $customer = Customer::findOrFail($this->deletingCustomerId);

        Gate::authorize('delete', $customer);

        $customer->delete();

        unset($this->customers);

        $this->closeDeleteModal();
    }

    /**
     * Close the delete-confirmation modal and reset its state.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset(['deletingCustomerId', 'deletingCustomerName']);
    }

    /**
     * The customers list — 0041's D-15 retrieval contract implemented literally:
     * `Customer::query()->orderBy('name')->get()`, no eager loading (no relation exists) and no
     * query-level permission filter (the route gate and this component own access). Mapped to
     * per-row arrays carrying only what the list view needs to render.
     *
     * `canEdit` / `canDelete` (D-3) are `Gate::allows('update'|'delete', $customer)` — the
     * identical abilities `openEditModal()` / `confirmDelete()` authorize with, flat, with no
     * self-row or ownership carve-out, since a customer can never be the acting user. This is a
     * UI hint layered on top of this component's own `Gate::authorize()` calls, never a
     * substitute for them.
     *
     * A `#[Computed]` property is memoised per request (D-5) — every mutating method above
     * `unset($this->customers)` after its write so a re-read in the same round trip is not stale.
     *
     * @return array<int, array{id: string, name: string, email: string, phone: string|null, shippingCity: string|null, shippingCountry: string|null, canEdit: bool, canDelete: bool}>
     */
    #[Computed]
    public function customers(): array
    {
        return Customer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'shippingCity' => $customer->shipping_city,
                'shippingCountry' => $customer->shipping_country,
                'canEdit' => Gate::allows('update', $customer),
                'canDelete' => Gate::allows('delete', $customer),
            ])
            ->all();
    }

    /**
     * The header's live customer count, `trans_choice()`-resolved (naming.md's plural
     * convention) — never a PHP ternary and never two separate keys.
     */
    #[Computed]
    public function customersSummary(): string
    {
        $count = count($this->customers);

        return trans_choice('customers.index.summary', $count, ['count' => $count]);
    }

    /**
     * Whether the actor holding this session may create a customer — drives the header button's
     * enabled/disabled/tooltip branch, asking the identical ability `openCreateModal()`
     * authorizes with (naming.md's boolean-predicate convention).
     */
    #[Computed]
    public function canCreateCustomer(): bool
    {
        return Gate::allows('create', Customer::class);
    }

    /**
     * Whether the create/edit modal is currently in its "edit" state — drives the modal's
     * title/button copy.
     */
    #[Computed]
    public function isEditing(): bool
    {
        return $this->editingCustomerId !== null;
    }

    /**
     * Map this component's fifteen camelCase form properties onto the snake_case column names
     * `App\Concerns\CustomerValidationRules` and the two write actions both expect.
     *
     * @return array<string, string>
     */
    private function formAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'shipping_address_line1' => $this->shippingAddressLine1,
            'shipping_address_line2' => $this->shippingAddressLine2,
            'shipping_city' => $this->shippingCity,
            'shipping_postal_code' => $this->shippingPostalCode,
            'shipping_province' => $this->shippingProvince,
            'shipping_country' => $this->shippingCountry,
            'billing_address_line1' => $this->billingAddressLine1,
            'billing_address_line2' => $this->billingAddressLine2,
            'billing_city' => $this->billingCity,
            'billing_postal_code' => $this->billingPostalCode,
            'billing_province' => $this->billingProvince,
            'billing_country' => $this->billingCountry,
        ];
    }
}
