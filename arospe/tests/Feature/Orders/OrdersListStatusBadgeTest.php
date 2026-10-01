<?php

// Story 0083, D-5 -- CHARACTERIZATION test of the orders list's status badge. Written BEFORE the
// extraction into <x-order-status-badge> and it must stay green after it: today's orders tests assert
// label text only, so nothing else protects the badge's colour. It also pins the payment-badge cell,
// which stays INLINE (the extraction must not touch it).
//
// The badge is probed inside its own row (data-test="order-row-{id}"): the first flux badge of the row
// is the status cell, the second the payment cell. A colour is read as the family of the badge's
// `text-{colour}-{shade}` class (Flux renders a `text-zinc-700` / `text-lime-800` ... class per colour),
// so the assertion survives a Flux shade tweak but not a colour change.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Index;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @return array{status: array{colour: ?string, label: string}, payment: array{colour: ?string, label: string}}|null
 */
function ordersBadgeCharacterizationRow(string $html, string $orderId): ?array
{
    $start = strpos($html, 'data-test="order-row-'.$orderId.'"');

    if ($start === false) {
        return null;
    }

    $row = substr($html, $start, strpos($html, '</tr>', $start) - $start);

    preg_match_all('/<div[^>]*\bdata-flux-badge\b[^>]*class="([^"]*)"[^>]*>(.*?)<\/div>/is', $row, $badges, PREG_SET_ORDER);

    $parse = function (array $badge): array {
        preg_match('/(?<![\w:-])text-([a-z]+)-\d{3}/', $badge[1], $colour);

        return [
            'colour' => $colour[1] ?? null,
            'label' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($badge[2]), ENT_QUOTES))),
        ];
    };

    return count($badges) === 2 ? ['status' => $parse($badges[0]), 'payment' => $parse($badges[1])] : null;
}

function ordersBadgeCharacterizationActor(string $locale): User
{
    $actor = User::factory()->create(['ui_locale' => $locale]);
    $actor->givePermissionTo(['orders.view', 'customers.view']);

    return $actor;
}

test('the orders list status badge keeps its colour and English label for each of the five statuses', function (OrderStatus $status, string $colour, string $label) {
    $this->actingAs(ordersBadgeCharacterizationActor('en'));
    $order = Order::factory()->create(['status' => $status, 'payment_status' => PaymentStatus::PendingPayment]);

    $badge = ordersBadgeCharacterizationRow(Livewire::test(Index::class)->html(), $order->id);

    expect($badge)->not->toBeNull()
        ->and($badge['status'])->toBe(['colour' => $colour, 'label' => $label]);
})->with([
    'pending is zinc' => [OrderStatus::Pending, 'zinc', 'Pending'],
    'processing is blue' => [OrderStatus::Processing, 'blue', 'Processing'],
    'shipped is amber' => [OrderStatus::Shipped, 'amber', 'Shipped'],
    'delivered is lime' => [OrderStatus::Delivered, 'lime', 'Delivered'],
    'cancelled is red' => [OrderStatus::Cancelled, 'red', 'Cancelled'],
]);

test('the orders list status badge speaks Spanish for an actor whose ui_locale is es', function (OrderStatus $status, string $colour, string $label) {
    $this->actingAs(ordersBadgeCharacterizationActor('es'));
    $order = Order::factory()->create(['status' => $status, 'payment_status' => PaymentStatus::PendingPayment]);

    $html = $this->get(route('orders.index'))->assertOk()->getContent();
    $badge = ordersBadgeCharacterizationRow($html, $order->id);

    expect($badge)->not->toBeNull()
        ->and($badge['status'])->toBe(['colour' => $colour, 'label' => $label]);
})->with([
    'pending is zinc' => [OrderStatus::Pending, 'zinc', 'Pendiente'],
    'processing is blue' => [OrderStatus::Processing, 'blue', 'Procesando'],
    'shipped is amber' => [OrderStatus::Shipped, 'amber', 'Enviado'],
    'delivered is lime' => [OrderStatus::Delivered, 'lime', 'Entregado'],
    'cancelled is red' => [OrderStatus::Cancelled, 'red', 'Cancelado'],
]);

test('the payment badge cell keeps its own colour and label, independent of the status badge', function (PaymentStatus $payment, string $colour, string $enLabel, string $esLabel) {
    $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'payment_status' => $payment]);

    $this->actingAs(ordersBadgeCharacterizationActor('en'));
    $en = ordersBadgeCharacterizationRow(Livewire::test(Index::class)->html(), $order->id);

    $this->actingAs(ordersBadgeCharacterizationActor('es'));
    $es = ordersBadgeCharacterizationRow($this->get(route('orders.index'))->assertOk()->getContent(), $order->id);

    expect($en['payment'])->toBe(['colour' => $colour, 'label' => $enLabel])
        ->and($es['payment'])->toBe(['colour' => $colour, 'label' => $esLabel])
        // The neighbouring status cell is the lime Delivered badge, never the payment colour by accident.
        ->and($en['status'])->toBe(['colour' => 'lime', 'label' => 'Delivered']);
})->with([
    'pending payment is amber' => [PaymentStatus::PendingPayment, 'amber', 'Pending payment', 'Pendiente de pago'],
    'paid is lime' => [PaymentStatus::Paid, 'lime', 'Paid', 'Pagado'],
    'partially refunded is blue' => [PaymentStatus::PartiallyRefunded, 'blue', 'Partially refunded', 'Parcialmente reembolsado'],
    'refunded is zinc' => [PaymentStatus::Refunded, 'zinc', 'Refunded', 'Reembolsado'],
]);
