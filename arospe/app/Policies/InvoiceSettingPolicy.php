<?php

namespace App\Policies;

use App\Models\InvoiceSetting;
use App\Models\User;

/**
 * Authorization rules for the store-wide invoice settings (story 0100). Two abilities; the
 * settings are a singleton, so `update` authorizes against the class before the row may exist.
 */
class InvoiceSettingPolicy
{
    public const VIEW_PERMISSION = 'invoice-settings.view';

    public const MANAGE_PERMISSION = 'invoice-settings.manage';

    /**
     * Determine whether the user can read the invoice settings.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can change the invoice settings.
     */
    public function update(User $actor, ?InvoiceSetting $target = null): bool
    {
        return $actor->hasPermissionTo(self::MANAGE_PERMISSION);
    }
}
