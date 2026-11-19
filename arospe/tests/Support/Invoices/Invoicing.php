<?php

namespace Tests\Support\Invoices;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreLanguage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Story 0101b -- shared fixtures of the issuing tests: complete issuer settings, a permitted actor
 * and orders whose stored amounts are arranged explicitly (the invoice must copy what the order
 * stores, never recompute it, FD-7). All amounts are strings.
 */
final class Invoicing
{
    /**
     * The settings row with complete issuer data and a recognisable look.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function settings(array $overrides = []): InvoiceSetting
    {
        return InvoiceSetting::factory()->withIssuer()->create(array_replace([
            'issuer_address_line2' => 'Piso 2',
            'issuer_province' => 'Madrid',
            'template' => 'modern',
            'primary_color' => '#112233',
            'accent_color' => '#AABBCC',
        ], $overrides));
    }

    /**
     * Re-point the seeded default store language (Spanish) at another code, so the language an
     * invoice is written in is explicit in the test.
     */
    public static function defaultStoreLanguage(string $code): StoreLanguage
    {
        $language = StoreLanguage::query()->where('is_default', true)->firstOrFail();
        $language->forceFill(['code' => $code])->save();

        return $language;
    }

    /**
     * Sign in a user who may create (and view) invoices and view orders (FD-17).
     */
    public static function manager(): User
    {
        return Numbering::actingWith('invoices.view', 'invoices.create', 'orders.view');
    }

    /**
     * An order whose stored amounts are arranged here: a customer with a tax ID, a billing
     * snapshot, the given lines, tax = subtotal x rate / 100 half-up (unless `tax_amount` is
     * overridden) and an untaxed shipping amount. `total` = subtotal + tax + shipping.
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: string}>  $lines  name, SKU, quantity, unit price
     * @param  array<string, mixed>  $overrides  Order columns; `tax_amount`, `refunded_amount`, `status` ... win over the computed ones.
     */
    public static function order(array $lines = [['Ceramic mug', 'MUG-1', 2, '10.00']], ?string $taxRate = '21.000', string $shipping = '0.00', array $overrides = [], ?Customer $customer = null): Order
    {
        $customer ??= Customer::factory()->withTaxId('B-12345678')->create(['name' => 'Lucia Perez']);

        $subtotal = '0.00';

        foreach ($lines as [, , $quantity, $unitPrice]) {
            $subtotal = bcadd($subtotal, bcmul($unitPrice, (string) $quantity, 2), 2);
        }

        $tax = $taxRate === null ? '0.00' : bcadd(bcdiv(bcmul($subtotal, $taxRate, 6), '100', 6), '0.005', 2);
        $tax = (string) ($overrides['tax_amount'] ?? $tax);
        $total = bcadd(bcadd($subtotal, $tax, 2), $shipping, 2);

        $order = Order::factory()->forCustomer($customer)->create(array_replace([
            'tax_rate' => $taxRate,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'shipping_amount' => $shipping,
            'total' => $total,
            'billing_address_line1' => 'Calle Falsa 123',
            'billing_address_line2' => 'Puerta B',
            'billing_city' => 'Sevilla',
            'billing_postal_code' => '41001',
            'billing_province' => 'Sevilla',
            'billing_country' => 'ES',
        ], $overrides));

        foreach ($lines as [$name, $sku, $quantity, $unitPrice]) {
            $item = OrderItem::factory()->for($order)->create(['quantity' => $quantity]);
            $item->forceFill([
                'product_name' => $name,
                'product_sku' => $sku,
                'unit_price' => $unitPrice,
                'line_total' => bcmul($unitPrice, (string) $quantity, 2),
            ])->save();
        }

        return $order->refresh();
    }

    /**
     * The 1000.00 order of the story: base 800.00, tax 168.00 (21 %), shipping 32.00.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function thousandOrder(array $overrides = []): Order
    {
        return self::order([['Large vase', 'VASE-1', 1, '800.00']], '21.000', '32.00', $overrides);
    }

    /**
     * Sum a decimal column over a collection of invoices, in bcmath.
     *
     * @param  Collection<int, Invoice|InvoiceLine>  $invoices
     */
    public static function sum(Collection $invoices, string $column): string
    {
        return $invoices->reduce(fn (string $carry, Invoice|InvoiceLine $row): string => bcadd($carry, (string) $row->{$column}, 2), '0.00');
    }

    /**
     * The order's invoices, oldest first.
     *
     * @return Collection<int, Invoice>
     */
    public static function invoicesOf(Order $order): Collection
    {
        return Invoice::query()->where('order_id', $order->getKey())->orderBy('issued_at')->orderBy('sequence_number')->get();
    }
}
