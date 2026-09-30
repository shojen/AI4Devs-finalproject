<?php

namespace App\Actions\Dashboard;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Story 0082 (D-5) -- the 5 most recent orders (every status, cancelled included), for the
 * dashboard's "latest orders" widget. `__invoke()` takes no argument, so `LogRefusedPrivilegedAttempt`
 * is constructor-injected (docs/conventions/code-style.md).
 *
 * Authorizes first (`viewAny` on Order, i.e. `orders.view`); a refusal is logged and throws before
 * any query runs. A caller should check the ability itself and call this only when permitted.
 *
 * Ordered by `created_at` then `id`, both descending, so orders placed in the same second keep a
 * stable order. Exactly two queries: the orders (only the columns the widget needs) and their
 * customers, eager-loaded `withTrashed()` -- an order may reference a soft-deleted customer, which
 * must not become a null that crashes the widget. `total` stays the decimal string. Returns
 * scalars, never models, so no relation or address snapshot leaks into a component's public state.
 */
class GetLatestOrders
{
    private const LIMIT = 5;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * @return list<array{id: string, orderNumber: string, customerName: ?string, total: string, status: OrderStatus, paymentStatus: PaymentStatus, createdAt: CarbonImmutable}>
     */
    public function __invoke(): array
    {
        $this->logRefusedPrivilegedAttempt->authorize('viewAny', Order::class, targetType: 'order');

        $orders = Order::query()
            ->select(['id', 'order_number', 'customer_id', 'total', 'status', 'payment_status', 'created_at'])
            ->with(['customer' => fn ($query) => $query->withTrashed()->select('id', 'name')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $rows = [];

        foreach ($orders as $order) {
            /** @var Customer|null $customer */
            $customer = $order->customer;

            $rows[] = [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'customerName' => $customer?->name,
                'total' => $order->total,
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'createdAt' => CarbonImmutable::instance($order->created_at ?? now()),
            ];
        }

        return $rows;
    }
}
