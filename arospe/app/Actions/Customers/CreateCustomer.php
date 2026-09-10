<?php

namespace App\Actions\Customers;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\CustomerValidationRules;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCustomer
{
    use CustomerValidationRules;

    /**
     * Constructor injection, not method injection: __invoke()'s single
     * domain argument is this action's whole public signature, called that
     * way by every direct-call test -- see
     * docs/conventions/code-style.md's constructor-injection exception.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Create a new customer.
     *
     * Authorizes `create` on `Customer::class` as its own first statement
     * (D-12), routed through App\Policies\CustomerPolicy -- the identical
     * self-authorizing shape App\Actions\SalesRegions/ProductCategories
     * actions already use, so a future Artisan command, queued job or
     * future UI story inherits the same refusal. `targetType: 'customer'`
     * is passed explicitly since LogRefusedPrivilegedAttempt::resolveTarget()
     * auto-resolves only User and Role instances/classes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): Customer
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', Customer::class, targetType: 'customer');

        $attributes = $this->normalizeAttributes($attributes);

        $validated = Validator::make($attributes, $this->customerRules())->validate();

        try {
            return Customer::create($validated);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                // The unique index is the last-word RACE guard behind the
                // customerEmailRules() Rule::unique() check above, never the
                // primary defence (D-5) -- the same shape
                // App\Actions\Users\CreateUser already uses for `email`.
                throw ValidationException::withMessages([
                    'email' => trans('validation.unique', ['attribute' => 'email']),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Lowercase the email and uppercase the two country codes BEFORE
     * validation runs (D-5, D-9), never as a model mutator -- a mutator
     * fires after save() and would let the uniqueness rule and the write
     * see different bytes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        if (array_key_exists('email', $attributes) && is_string($attributes['email'])) {
            $attributes['email'] = Str::lower($attributes['email']);
        }

        foreach (['shipping_country', 'billing_country'] as $countryField) {
            if (array_key_exists($countryField, $attributes) && is_string($attributes[$countryField])) {
                $attributes[$countryField] = Str::upper($attributes[$countryField]);
            }
        }

        return $attributes;
    }
}
