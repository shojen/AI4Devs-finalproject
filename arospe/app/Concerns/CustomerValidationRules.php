<?php

namespace App\Concerns;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Story 0041 — mirrors UserValidationRules/ProfileValidationRules exactly:
 * <Noun>ValidationRules trait, <noun>Rules() methods returning rule arrays,
 * flat and single-concern (see docs/conventions/naming.md#traits-and-their-methods).
 */
trait CustomerValidationRules
{
    /**
     * Every optional customer column — `phone` plus the twelve shipping/billing address columns
     * (`*_country` included). Shared by `App\Actions\Customers\CreateCustomer` and `UpdateCustomer`
     * so both actions normalise the identical field list: a blank string (`''`) submitted for any
     * of these must be treated as "not provided" and persisted as `null` (D-3/D-9), never as a
     * literal empty string.
     *
     * Both `ConvertEmptyStringsToNull`/`TrimStrings` are Laravel HTTP middleware that never run for
     * a direct `__invoke()` call, and Laravel's own validator skips every NON-implicit rule
     * (`string`/`size`/`regex`/`max` included) once a field's submitted value is a blank string --
     * see docs/errors-log.md's "Livewire skips ConvertEmptyStringsToNull ... and Laravel skips
     * non-implicit rules for a blank string" entry for the exact mechanism. So normalising a blank
     * optional field to a real `null` must happen in the action, BEFORE `Validator::make()` ever
     * runs -- it cannot be delegated to a validation rule.
     *
     * @var array<int, string>
     */
    public const OPTIONAL_FIELDS = [
        'phone',
        'shipping_address_line1', 'shipping_address_line2', 'shipping_city',
        'shipping_postal_code', 'shipping_province', 'shipping_country',
        'billing_address_line1', 'billing_address_line2', 'billing_city',
        'billing_postal_code', 'billing_province', 'billing_country',
    ];

    /**
     * The whole payload's rules — name, email, phone and both address
     * blocks (shipping_* / billing_*). $customerId is forwarded to
     * customerEmailRules() so the uniqueness check ignores the record's own
     * current address on an update (the ->ignore() regression case).
     *
     * @return array<string, array<int, ValidationRule|\Closure|array<mixed>|string>>
     */
    protected function customerRules(?string $customerId = null): array
    {
        return [
            'name' => $this->customerNameRules(),
            'email' => $this->customerEmailRules($customerId),
            'phone' => $this->customerPhoneRules(),
            ...$this->customerAddressRules('shipping'),
            ...$this->customerAddressRules('billing'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function customerNameRules(): array
    {
        return ['required', 'string', 'max:150'];
    }

    /**
     * customers.email is scoped to the `customers` table alone (D-6) — a
     * customer and a dashboard `users` row may legitimately share an
     * address, so this deliberately differs from
     * ProfileValidationRules::emailRules(), which also spans
     * users.pending_email. There is no pending-email mechanism here at all
     * (D-13): a customer's email is contact data, not an authentication
     * identifier, so a change takes effect immediately with no
     * mailbox-confirmation step.
     *
     * @return array<int, ValidationRule|string>
     */
    protected function customerEmailRules(?string $customerId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $customerId === null
                ? Rule::unique(Customer::class)
                : Rule::unique(Customer::class)->ignore($customerId),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function customerPhoneRules(): array
    {
        // D-4: a single nullable column, no format rule -- international
        // numbering plans vary and the PRD gives no rule to enforce.
        return ['nullable', 'string', 'max:30'];
    }

    /**
     * The six columns for one address block (`shipping_*` or `billing_*`),
     * keyed by their full column name so the result can be spread directly
     * into customerRules()'s payload-wide rule array.
     *
     * @return array<string, array<int, string>>
     */
    protected function customerAddressRules(string $prefix): array
    {
        return [
            "{$prefix}_address_line1" => ['nullable', 'string', 'max:255'],
            "{$prefix}_address_line2" => ['nullable', 'string', 'max:255'],
            "{$prefix}_city" => ['nullable', 'string', 'max:100'],
            "{$prefix}_postal_code" => ['nullable', 'string', 'max:20'],
            "{$prefix}_province" => ['nullable', 'string', 'max:100'],
            // D-9: ISO 3166-1 alpha-2 shape only, never membership in the
            // seeded sales_regions catalog. No 'filled' rule here (Phase 4
            // audit F-1): a blank submitted value is normalised to a real
            // `null` by CreateCustomer/UpdateCustomer's normalizeAttributes()
            // BEFORE this rule set ever runs -- see OPTIONAL_FIELDS above --
            // so by the time this rule sees the field it has already been
            // reduced to either `null` (accepted, 'nullable' short-circuits)
            // or a real, non-blank candidate country code that the shape
            // rules below must still validate. 'filled' would reject that
            // already-normalised `null` as if it were a forgotten field,
            // which is exactly the wrong outcome for D-3's "name and email
            // only" flow.
            "{$prefix}_country" => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }
}
