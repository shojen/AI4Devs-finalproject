<?php

namespace App\Actions\Dashboard;

use App\Concerns\ChecksAbilitiesSafely;
use App\Enums\UserStatus;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;

/**
 * Story 0082 (D-1, D-7) -- the three dashboard counters: active users, products and images.
 *
 * The one deliberate exception to the authorize-and-throw pattern of the other dashboard actions:
 * it spans three modules, so it never throws. Each counter is guarded by its own Gate check
 * (`users.view`, `products.view`, `media.view`) and is `null` when the actor lacks it -- and then
 * NO query runs against that counter's table and nothing is logged, because a hidden counter is
 * not a privileged attempt. A missing permission row (Spatie's `PermissionDoesNotExist`) counts as
 * "not permitted" through `ChecksAbilitiesSafely` (story 0083), so that counter is `null` too.
 * The dashboard route itself stays ungated.
 *
 * `Media::count()` is every `media` row (there is no kind column: each row is a gallery image);
 * products include drafts and virtual products; soft-deleted users are excluded by the default scope.
 */
class GetDashboardCounters
{
    use ChecksAbilitiesSafely;

    /**
     * @return array{users: ?int, products: ?int, images: ?int}
     */
    public function __invoke(): array
    {
        return [
            'users' => $this->allowsSafely('viewAny', User::class)
                ? User::query()->where('status', UserStatus::Active)->count()
                : null,
            'products' => $this->allowsSafely('viewAny', Product::class)
                ? Product::query()->count()
                : null,
            'images' => $this->allowsSafely('viewAny', Media::class)
                ? Media::query()->count()
                : null,
        ];
    }
}
