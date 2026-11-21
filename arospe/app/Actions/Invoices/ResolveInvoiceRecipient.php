<?php

namespace App\Actions\Invoices;

use App\Concerns\InvoiceValidationRules;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only address an invoice mail goes to (story 0104 FD-2, FD-3, FD-4): the current e-mail of the
 * order's customer, read when called. A missing or soft-deleted customer and an unusable address
 * refuse with a validation error keyed `invoice`. It takes no address from the caller.
 */
class ResolveInvoiceRecipient
{
    use InvoiceValidationRules;

    /**
     * @throws ValidationException
     */
    public function __invoke(Order $order): string
    {
        $customer = Customer::withTrashed()->find($order->customer_id);

        if ($customer === null) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.customer_missing')]]);
        }

        if ($customer->trashed()) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.customer_deleted')]]);
        }

        $address = (string) $customer->email;

        if (Validator::make(['email' => $address], ['email' => $this->invoiceRecipientEmailRules()])->fails()) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.customer_email_unusable')]]);
        }

        return $address;
    }
}
