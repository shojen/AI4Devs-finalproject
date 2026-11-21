<?php

namespace Tests\Support\Invoices;

use App\Actions\Invoices\SyncOrderCollection;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 0106 -- shared fixtures of the invoice collection tests. Orders and invoices are built with
 * the factories (amounts are strings everywhere); the collected state of an invoice uses the
 * `InvoiceFactory::collected()` state, the order states use `OrderFactory::partiallyPaid()` /
 * `withCollectedAmount()`.
 */
final class Collecting
{
    /**
     * Sign in a user holding exactly the given permissions (default: only `invoices.collect`).
     * The caller must have seeded the permission catalog.
     */
    public static function actor(string ...$permissions): User
    {
        return Numbering::actingWith(...($permissions === [] ? ['invoices.collect'] : $permissions));
    }

    /**
     * The payment method collections are recorded with.
     */
    public static function method(): PaymentMethod
    {
        return PaymentMethod::query()->first() ?? PaymentMethod::factory()->create();
    }

    /**
     * A plain order of the given total (no lines), pending and unpaid unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function order(string $total = '1000.00', array $overrides = []): Order
    {
        return Order::factory()->create(array_replace([
            'subtotal' => $total,
            'total' => $total,
        ], $overrides))->refresh();
    }

    /**
     * A standard invoice of the order, optionally collected.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function invoice(Order $order, string $total, bool $collected = false, array $overrides = []): Invoice
    {
        $factory = Invoice::factory()->standard()->forOrder($order);

        if ($collected) {
            $factory = $factory->collected();
        }

        return $factory->create(array_replace(['total' => $total], $overrides));
    }

    /**
     * A corrective of the invoice for the given (negative) total.
     */
    public static function corrective(Invoice $of, string $total): Invoice
    {
        return Invoice::factory()->corrective($of)->create(['total' => $total]);
    }

    /**
     * Run SyncOrderCollection the way its callers do: in a transaction, on the locked order.
     */
    public static function sync(Order $order): Order
    {
        DB::transaction(function () use ($order): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            app(SyncOrderCollection::class)($locked);
        });

        return $order->fresh();
    }

    /**
     * An order with one line of quantity 2, of which `$refundedUnits` units are refunded.
     */
    public static function addLine(Order $order, int $refundedUnits = 0): OrderItem
    {
        $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

        DB::table('order_items')->where('id', $item->id)->update(['refunded_quantity' => $refundedUnits]);

        return $item->refresh();
    }

    /**
     * Build an order from a scenario of the truth table: stored states, amounts, one line (fully
     * or partly refunded) and invoices given as `[total, collected, correctiveTotal?]`.
     *
     * @param  array{status?: OrderStatus, stored?: PaymentStatus, total?: string, refunded?: string, lines?: 'full'|'partial'|'none', invoices?: list<array{0: string, 1: bool, 2?: string}>}  $scenario
     */
    public static function scenario(array $scenario): Order
    {
        $order = self::order($scenario['total'] ?? '1000.00', [
            'status' => $scenario['status'] ?? OrderStatus::Pending,
            'payment_status' => $scenario['stored'] ?? PaymentStatus::PendingPayment,
            'refunded_amount' => $scenario['refunded'] ?? '0.00',
        ]);

        self::addLine($order, match ($scenario['lines'] ?? 'none') {
            'full' => 2,
            'partial' => 1,
            default => 0,
        });

        foreach ($scenario['invoices'] ?? [] as $spec) {
            $invoice = self::invoice($order, $spec[0], $spec[1]);

            if (isset($spec[2])) {
                self::corrective($invoice, $spec[2]);
            }
        }

        return $order->refresh();
    }

    /**
     * The raw row of a table, for before/after comparisons that bypass model casts.
     *
     * @return array<string, mixed>
     */
    public static function row(string $table, string $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    /**
     * A snapshot of an order's row and every one of its invoices' rows.
     *
     * @return array{order: array<string, mixed>, invoices: list<array<string, mixed>>}
     */
    public static function snapshot(Order $order): array
    {
        return [
            'order' => self::row('orders', $order->getKey()),
            'invoices' => DB::table('invoices')->where('order_id', $order->getKey())->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * The validation errors a call raises, or [] when it did not refuse.
     *
     * @param  Closure(): mixed  $callback
     * @return array<string, list<string>>
     */
    public static function errors(Closure $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }

    /**
     * Normalise `Order::latestCollectedInvoice()`, whichever of a relation or a model it returns.
     */
    public static function latestCollected(Order $order): ?Invoice
    {
        $latest = $order->latestCollectedInvoice();

        return $latest instanceof Relation ? $latest->first() : $latest;
    }
}
