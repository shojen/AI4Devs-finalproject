<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Story 0083 -- a Gate check that never throws on a missing permission row.
 *
 * Spatie's `hasPermissionTo()` throws `PermissionDoesNotExist` when the permission row is absent
 * (for instance in a database RolePermissionSeeder never ran on). The ungated dashboard must render
 * for any authenticated user, so a missing row simply means the actor does not hold the ability:
 * the check answers `false`, never grants. Only that one exception is caught; anything else
 * propagates. A Super Admin still passes through `Gate::before`, which runs before any policy.
 */
trait ChecksAbilitiesSafely
{
    protected function allowsSafely(string $ability, mixed $arguments = []): bool
    {
        try {
            return Gate::allows($ability, $arguments);
        } catch (Throwable $exception) {
            // Gate's thrown types are opaque to static analysis (the policy, not Gate, throws), so
            // the narrowing happens here: only a missing permission row is absorbed.
            if ($exception instanceof PermissionDoesNotExist) {
                return false;
            }

            throw $exception;
        }
    }
}
