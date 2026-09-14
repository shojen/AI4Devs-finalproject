<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state: a valid, freshly-placed pending
     * order (story 0045) -- a customer and a payment method, no line items
     * yet (use `withItems()` for a state matching D-5's own "an order must
     * have at least one line item" invariant), no sales region, no
     * shipping rate resolved (matching CreateOrder's own "nothing is
     * resolved that this story does not own" contract), and
     * `flagged_for_review => false`.
     *
     * `order_number` is a short, non-UUID, support-usable token via
     * `fake()->unique()` -- deliberately NOT the exact `ORD-{YYYY}-{NNNNNN}`
     * shape `CreateOrder::generateOrderNumber()` derives under D-1 (Phase 5
     * review finding F-F): `generateOrderNumber()` counts existing rows
     * matching `LIKE 'ORD-{year}-%'` to pick the next sequence number, and a
     * factory row in that exact shape would silently inflate that count for
     * every test using this factory outside CreateOrder. `ORD-########`
     * never matches the dashed per-year prefix, so factory-built orders stay
     * invisible to the sequence -- accidental safety this comment now states
     * on purpose, rather than a claim that the two shapes are the same.
     *
     * `payment_method_id` reuses an EXISTING `payment_methods` row when one
     * is already seeded/created, falling back to a fresh
     * `PaymentMethod::factory()->create()` only when none exists yet --
     * `PaymentMethodFactory::definition()`'s own `code` is a fixed literal
     * (`PaymentMethodCode::BankTransfer`), not `fake()->unique()`, so a
     * bare nested `PaymentMethod::factory()` here would collide on
     * `payment_methods.code`'s UNIQUE index the moment a test creates a
     * SECOND order in the same run (exactly what this story's own
     * acceptance criteria requires -- "creating two orders in the same
     * request produces two different order_number values").
     *
     * `subtotal`/`tax_amount`/`shipping_amount`/`total` all start at
     * `'0.00'` -- matching a genuinely new order with no items yet;
     * `withItems()` below recomputes `subtotal`/`total` from the items it
     * creates, rather than leaving them stale.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.fake()->unique()->numerify('########'),
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::PendingPayment,
            'sales_region_id' => null,
            'shipping_rate_id' => null,
            'payment_method_id' => fn (): string => PaymentMethod::query()->value('id')
                ?? PaymentMethod::factory()->create()->getKey(),
            'tax_rate' => null,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'shipping_amount' => '0.00',
            'total' => '0.00',
            'flagged_for_review' => false,
        ];
    }

    /**
     * Attach `$n` freshly-created line items (`OrderItemFactory`'s own
     * default state) to the order, then recompute `subtotal`/`total` from
     * their real `line_total`s -- never left at the base state's `'0.00'`
     * placeholder, so a test built on this state sees an internally
     * consistent order. `tax_amount`/`shipping_amount` are untouched
     * (still `'0.00'` unless a caller overrides them), matching a newly
     * created order that has not gone through tax/shipping resolution.
     */
    public function withItems(int $n = 1): static
    {
        return $this->afterCreating(function (Order $order) use ($n): void {
            OrderItemFactory::new()->count($n)->for($order)->create();

            $subtotal = $order->items()->get()->sum(fn ($item): float => (float) $item->line_total);

            $order->forceFill([
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'total' => number_format($subtotal + (float) $order->tax_amount + (float) $order->shipping_amount, 2, '.', ''),
            ])->save();
        });
    }

    /**
     * Indicate that the order has been paid in full.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    /**
     * Attach the order to a given, already-resolved customer, rather than
     * a nested `Customer::factory()`.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes): array => [
            'customer_id' => $customer->id,
        ]);
    }
}
