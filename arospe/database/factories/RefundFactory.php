<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state: a refund of ONE unit against an
     * existing (or freshly created) `OrderItem`, with `amount` equal to
     * that item's own `unit_price` (D-8's `quantity x unit_price`, at
     * `quantity = 1`), `refunded_by` an existing (or freshly created)
     * `User`, and a `null` reason (D-11 -- this story never writes one).
     *
     * This factory may NOT produce a state RecordRefund's own guards would
     * reject -- in particular it must never create a refund whose quantity
     * exceeds its item's outstanding units (`quantity - refunded_quantity`),
     * matching 0045's identical rule for OrderFactory: an over-refund test
     * built on a factory that could itself violate the guard would be
     * meaningless. `configure()` below enforces this by capping the default
     * quantity against the resolved item's real outstanding units, and
     * derives `amount` from the resolved item's real `unit_price` rather
     * than trusting a caller-supplied one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'quantity' => 1,
            'amount' => '0.00',
            'refunded_by' => User::factory(),
            'reason' => null,
        ];
    }

    /**
     * Resolve `amount` from the real, persisted `OrderItem` row AFTER the
     * model is made but BEFORE it is inserted -- matching
     * `OrderItemFactory::configure()`'s own shape. Also clamps `quantity`
     * to the item's real outstanding units, so a caller-supplied quantity
     * greater than what the item has left never silently produces a
     * factory-built refund the action's own over-refund guard would have
     * refused.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Refund $refund): void {
            $orderItem = OrderItem::query()->find($refund->order_item_id)
                ?? OrderItem::factory()->create();
            $refund->order_item_id = $orderItem->id;

            $outstanding = max(0, $orderItem->quantity - $orderItem->refunded_quantity);
            $refund->quantity = min(max($refund->quantity, 1), max($outstanding, 1));

            $refund->amount = number_format(
                ((float) $orderItem->unit_price) * $refund->quantity,
                2,
                '.',
                ''
            );
        });
    }
}
