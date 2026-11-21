<?php

namespace Tests\Support\Invoices;

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Notifications\AutomaticInvoiceFailed;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\LaravelPdf\FakePdfBuilder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Story 0105 -- fixtures of the automatic invoicing tests: the store setup (flag, issuer data,
 * faked PDF engine), orders created through the real CreateOrder, and readers of the stored
 * AutomaticInvoiceFailed notifications.
 */
final class Automating
{
    /**
     * Seed roles and the store language, create the settings row and fake the PDF engine.
     *
     * @param  bool  $on  The auto_issue_on_order flag.
     * @param  bool  $issuer  Complete issuer data; false leaves the issuer legal name, tax ID and address empty.
     * @param  bool  $fakeNotifications  Fake every notification (InvoiceSent included); false keeps the database channel real.
     */
    public static function prepare(bool $on = true, bool $issuer = true, bool $fakeNotifications = true): FakePdfBuilder
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        test()->seed(RolePermissionSeeder::class);
        test()->seed(StoreLanguageSeeder::class);

        $factory = InvoiceSetting::factory();
        $factory = $issuer ? $factory->withIssuer() : $factory;
        $factory = $on ? $factory->autoIssue() : $factory;
        $factory->create();

        if ($fakeNotifications) {
            Notification::fake();
        }

        return FakesPdf::fake();
    }

    /**
     * Sign in an administrator who may only create orders (no invoice permission at all).
     */
    public static function orderCreator(): User
    {
        return Numbering::actingWith('orders.create');
    }

    /**
     * A user holding exactly the given permissions (not signed in).
     */
    public static function holder(string ...$permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * A CreateOrder payload of one line: the given quantity of a product priced at `$price`.
     *
     * @return array<string, mixed>
     */
    public static function payload(string $price = '100.00', int $quantity = 1, ?Customer $customer = null): array
    {
        $customer ??= Customer::factory()->create();
        $product = Product::factory()->create(['price' => $price, 'stock' => 50]);

        return [
            'customer_id' => $customer->id,
            'payment_method_id' => (PaymentMethod::query()->first() ?? PaymentMethod::factory()->create())->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ];
    }

    /**
     * Create an order through the real CreateOrder (signing in an order creator when nobody is).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function createOrder(?array $payload = null): Order
    {
        if (auth()->guest()) {
            self::orderCreator();
        }

        return app(CreateOrder::class)($payload ?? self::payload());
    }

    /**
     * Every stored AutomaticInvoiceFailed (database channel), oldest first, optionally of one stage.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public static function failures(?string $stage = null): Collection
    {
        return DatabaseNotification::query()
            ->where('type', AutomaticInvoiceFailed::class)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => $stage === null || ($notification->data['stage'] ?? null) === $stage)
            ->values();
    }
}
