<?php

namespace App\Actions\Customers;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\CustomerValidationRules;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateCustomer
{
    use CustomerValidationRules;

    /**
     * Constructor injection, not method injection: __invoke()'s single
     * domain argument is this action's whole public signature, called that
     * way by every direct-call test -- see
     * docs/conventions/code-style.md's constructor-injection exception.
     * NotifyCustomerCreated (story 0043) is injected the same way for the
     * identical reason.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NotifyCustomerCreated $notifyCustomerCreated,
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

        $attributes = $this->normalizeCustomerAttributes($attributes);

        $validated = Validator::make($attributes, $this->customerRules())->validate();

        try {
            $customer = Customer::create($validated);
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

        // Dispatched only after Customer::create() has committed (there is
        // no surrounding DB::transaction() here, so "after the insert
        // succeeds" already satisfies the after-commit constraint -- see
        // errors-log.md's "wrapping existing code in a DB::transaction()"
        // entry) and only on this success path: a rejected/invalid
        // creation never reaches this line (story 0043).
        //
        // Swallowed rather than rethrown (Phase 4 security-audit finding
        // F-1): a notification-dispatch failure -- e.g. a transient DB
        // error inserting one of N recipient rows -- must not turn an
        // already-persisted, already-committed customer into a failed
        // request. This notification has no reader anywhere in the app yet
        // (R-3/OQ-3), so failing the visible operation for it would be
        // strictly worse than losing the notification; logged so the
        // failure is not silent.
        try {
            ($this->notifyCustomerCreated)($customer);
        } catch (Throwable $e) {
            Log::warning('CustomerCreated notification dispatch failed', [
                'customer_id' => $customer->id,
                'exception' => $e::class,
            ]);
        }

        return $customer;
    }
}
