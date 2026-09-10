<?php

namespace App\Actions\Customers;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\CustomerValidationRules;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
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
     * statement (D-12). A key omitted from `$attributes` is never validated
     * and never appears in `$validated`, so `$customer->update($validated)`
     * leaves that column untouched -- omission means "not being changed",
     * not "clear it" (see docs/errors-log.md's 2026-09-01 entry on this
     * exact ambiguity for a partial-field update payload). The uniqueness
     * rule ignores the target's own id (customerEmailRules($customer->id)),
     * which is what makes saving a customer under its own unchanged email
     * succeed.
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

        $attributes = $this->normalizeCustomerAttributes($attributes);

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
}
