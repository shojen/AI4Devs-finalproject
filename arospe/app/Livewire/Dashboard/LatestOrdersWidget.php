<?php

namespace App\Livewire\Dashboard;

use App\Actions\Dashboard\GetLatestOrders;
use App\Concerns\ChecksAbilitiesSafely;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dashboard widget: the most recent orders (story 0083). Strictly read-only -- it carries no
 * "mark as paid" action, no public property and no mutating method. Every row links to the order
 * detail, which needs only `orders.view`.
 */
class LatestOrdersWidget extends Component
{
    use ChecksAbilitiesSafely;

    /**
     * @return list<array{id: string, orderNumber: string, customerName: ?string, total: string, status: OrderStatus, paymentStatus: PaymentStatus, createdAt: CarbonImmutable}>
     */
    #[Computed]
    public function orders(): array
    {
        if (! $this->allowsSafely('viewAny', Order::class)) {
            return [];
        }

        return app(GetLatestOrders::class)();
    }
}
