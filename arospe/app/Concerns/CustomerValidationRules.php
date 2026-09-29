<?php

namespace App\Concerns;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Story 0041 — mirrors UserValidationRules/ProfileValidationRules exactly:
 * <Noun>ValidationRules trait, <noun>Rules() methods returning rule arrays,
 * flat and single-concern (see docs/conventions/naming-validation-traits.md#traits-and-their-methods).
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
     * optional field to a real `null` must happen BEFORE `Validator::make()` ever runs -- it cannot
     * be delegated to a validation rule. See normalizeCustomerAttributes() below, this list's single
     * consumer.
     *
     * A trait constant cannot be read as `CustomerValidationRules::OPTIONAL_FIELDS` directly -- PHP
     * throws "Cannot access trait constant ... directly" -- only through a class that `use`s the
     * trait, e.g. `App\Actions\Customers\CreateCustomer::OPTIONAL_FIELDS` (both
     * `App\Actions\Customers\CreateCustomer` and `UpdateCustomer` compose this trait, so either
     * class name resolves the same constant). See
     * App\Actions\Media\GenerateImageConversions::class's own docblock for the identical note
     * against MediaValidationRules::MAX_DIMENSION.
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
            // `null` by this trait's own normalizeCustomerAttributes()
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

    /**
     * Lowercase the email, blank-to-null every OPTIONAL_FIELDS column (trimming whatever string
     * survives), and uppercase the two country codes -- all BEFORE validation runs (D-5, D-9),
     * never as a model mutator (a mutator fires after save() and would let the uniqueness rule and
     * the write see different bytes). Shared by `App\Actions\Customers\CreateCustomer` and
     * `UpdateCustomer` (Phase 5 code-review finding F-6) rather than duplicated verbatim in each --
     * see docs/conventions/base-standards.md's "Move the rule, never copy it" rule.
     *
     * The blank-to-null pass must run before the country-uppercasing pass below it: an
     * un-trimmed/un-nulled '  es  ' would fail `size:2` with a confusing message rather than
     * either clearing to `null` or normalising to `'ES'`, and `'nullable'` only short-circuits the
     * shape rules for a genuine `null` -- see OPTIONAL_FIELDS above.
     *
     * The blank-to-null pass also TRIMS whatever non-blank string survives (Phase 5 code-review
     * finding F-5): every OPTIONAL_FIELDS column is `nullable`+`string`, never `nullable`+`trim`,
     * so a submitted `'  Madrid  '` would otherwise persist with its surrounding whitespace intact,
     * and a submitted `' ES '` would reach the country-uppercase pass un-trimmed and fail `size:2`
     * for a reason invisible in the rendered message.
     *
     * The two country columns deliberately use `strtoupper()`, never `Str::upper()` (Phase 5
     * code-review finding F-4): `Str::upper()` performs Unicode FULL case mapping via
     * `mb_strtoupper()`, under which the single German character `'ß'` -- one character, so it
     * fails `size:2` on its own -- maps to the TWO-character string `'SS'` BEFORE validation ever
     * sees it (this method runs first), so the value validation actually checks is `'SS'`, a real
     * seeded ISO 3166-1 alpha-2 code (South Sudan) the actor never typed, and it passes `size:2`
     * and the `[A-Za-z]{2}` shape regex cleanly. `strtoupper()` is byte-wise and ASCII-only, so
     * `'ß'` (outside `A-Z`/`a-z`) is left untouched and the one-character value is correctly
     * rejected by `size:2` instead of silently expanding into a different country. No other
     * OPTIONAL_FIELDS column is uppercased.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function normalizeCustomerAttributes(array $attributes): array
    {
        if (array_key_exists('email', $attributes) && is_string($attributes['email'])) {
            $attributes['email'] = Str::lower($attributes['email']);
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field])) {
                $trimmed = trim($attributes[$field]);
                $attributes[$field] = $trimmed === '' ? null : $trimmed;
            }
        }

        foreach (['shipping_country', 'billing_country'] as $countryField) {
            if (array_key_exists($countryField, $attributes) && is_string($attributes[$countryField])) {
                $attributes[$countryField] = strtoupper($attributes[$countryField]);
            }
        }

        return $attributes;
    }
}
