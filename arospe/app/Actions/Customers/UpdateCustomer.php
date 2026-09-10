<?php

namespace App\Actions\Customers;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\CustomerValidationRules;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateCustomer
{
    use CustomerValidationRules;

    /**
     * Constructor injection for the same reason as CreateCustomer:
     * __invoke()'s two domain arguments are this action's whole public
     * signature.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Edit an existing customer.
     *
     * Authorizes `update` on the resolved `$customer` as its own first
     * statement (D-12). The whole record is submitted every time (D-11 --
     * no partial-field PATCH semantics), and the uniqueness rule ignores
     * the target's own id (customerEmailRules($customer->id)), which is
     * what makes saving a customer under its own unchanged email succeed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(Customer $customer, array $attributes): Customer
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $customer,
            targetType: 'customer',
            targetId: $customer->id,
        );

        $attributes = $this->normalizeAttributes($attributes);

        $validated = Validator::make($attributes, $this->customerRules($customer->id))->validate();

        try {
            $customer->update($validated);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                // Last-word race guard behind the Rule::unique()->ignore()
                // check above -- see CreateCustomer's identical catch.
                throw ValidationException::withMessages([
                    'email' => trans('validation.unique', ['attribute' => 'email']),
                ]);
            }

            throw $e;
        }

        return $customer;
    }

    /**
     * Lowercase the email, blank-to-null every optional column, and
     * uppercase the two country codes BEFORE validation runs (D-5, D-9) --
     * see CreateCustomer's identical helper and
     * App\Concerns\CustomerValidationRules::OPTIONAL_FIELDS for why the
     * blank-to-null pass must run before the uppercasing pass.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        if (array_key_exists('email', $attributes) && is_string($attributes['email'])) {
            $attributes['email'] = Str::lower($attributes['email']);
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field]) && trim($attributes[$field]) === '') {
                $attributes[$field] = null;
            }
        }

        foreach (['shipping_country', 'billing_country'] as $countryField) {
            if (array_key_exists($countryField, $attributes) && is_string($attributes[$countryField])) {
                $attributes[$countryField] = Str::upper($attributes[$countryField]);
            }
        }

        return $attributes;
    }
}
