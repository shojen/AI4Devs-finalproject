<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Authorization rules for invoices (story 0101b, FD-17). Three abilities over two permissions;
 * there is deliberately no update or delete ability, invoices are immutable (FD-3).
 */
class InvoicePolicy
{
    public const VIEW_PERMISSION = 'invoices.view';

    public const CREATE_PERMISSION = 'invoices.create';

    /**
     * Determine whether the user can list invoices.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can view one invoice.
     */
    public function view(User $actor, Invoice $invoice): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can issue an invoice.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }
}
