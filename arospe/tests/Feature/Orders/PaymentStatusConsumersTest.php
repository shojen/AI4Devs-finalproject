<?php

// Story 0106 (T-8, T-22, FD-9) -- every screen that prints a payment status shows the new
// "Partially paid" label and an orange badge (distinct from pending's amber), with no match error;
// the zinc fallback of the badge arms stays. Assertions are against the rendered HTML.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Orders\Index;
use App\Livewire\Orders\Show;
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
 * The colour family of the flux badge carrying a data-test hook, read from its `text-{colour}-{shade}` class.
 */
function paymentBadgeColour(string $html, string $hook): ?string
{
    if (preg_match('/<div[^>]*data-test="'.preg_quote($hook, '/').'"[^>]*>/i', $html, $tag) !== 1) {
        return null;
    }

    return preg_match('/(?<![\w:-])text-([a-z]+)-\d{3}/', $tag[0], $colour) === 1 ? $colour[1] : null;
}

function paymentConsumerActor(string $locale = 'en'): User
{
    $actor = User::factory()->create(['ui_locale' => $locale]);
    $actor->givePermissionTo(['orders.view', 'customers.view']);

    return $actor;
}

// T-8
test('PartiallyPaid has en and es payment_statuses labels (T-8)', function () {
    $en = require lang_path('en/orders.php');
    $es = require lang_path('es/orders.php');

    expect($en['payment_statuses']['partially_paid'])->toBe('Partially paid')
        ->and($es['payment_statuses']['partially_paid'])->toBe('Parcialmente pagado');
});

test('every PaymentStatus case has a non-empty label in both locales (T-8)', function (PaymentStatus $status, string $locale) {
    $label = __('orders.payment_statuses.'.$status->value, [], $locale);

    expect($label)->not->toBe('orders.payment_statuses.'.$status->value)->and($label)->not->toBe('');
})->with(PaymentStatus::cases())->with(['en', 'es']);

test('the orders list renders an orange badge with the label for a partially paid order (T-8, T-22)', function () {
    $order = Order::factory()->partiallyPaid()->create();
    $this->actingAs(paymentConsumerActor());

    $html = Livewire::test(Index::class)->html();
    $row = substr($html, (int) strpos($html, 'data-test="order-row-'.$order->id.'"'));
    $row = substr($row, 0, (int) strpos($row, '</tr>'));

    expect($row)->toContain('Partially paid')
        ->and($row)->toMatch('/(?<![\w:-])text-orange-\d{3}/')
        ->and($row)->not->toMatch('/(?<![\w:-])text-amber-\d{3}[^>]*>\s*Partially paid/');
});

test('the order detail renders an orange payment badge with the label, and the order is awaiting payment (T-8, T-22)', function () {
    $order = Order::factory()->partiallyPaid()->withItems()->create(['status' => OrderStatus::Pending]);
    $this->actingAs(paymentConsumerActor());

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect($html)->toContain('Partially paid')
        ->and(paymentBadgeColour($html, 'order-payment-badge'))->toBe('orange')
        ->and($order->isAwaitingPayment())->toBeTrue();
});

test('the dashboard latest-orders widget renders an orange payment badge with the label (T-8, T-22)', function () {
    $order = Order::factory()->partiallyPaid()->create();
    $this->actingAs(paymentConsumerActor());

    $html = Livewire::test(Overview::class)->html();

    expect($html)->toContain(__('orders.payment_statuses.partially_paid'))
        ->and(paymentBadgeColour($html, "dashboard-order-payment-{$order->id}"))->toBe('orange');
});

test('the three screens render the Spanish label for a partially paid order (T-8)', function () {
    $order = Order::factory()->partiallyPaid()->withItems()->create();
    $this->actingAs(paymentConsumerActor('es'));
    app()->setLocale('es');

    expect(Livewire::test(Index::class)->html())->toContain('Parcialmente pagado')
        ->and(Livewire::test(Show::class, ['order' => $order])->html())->toContain('Parcialmente pagado')
        ->and(Livewire::test(Overview::class)->html())->toContain('Parcialmente pagado');
});

test('a pending-payment badge keeps its amber colour next to the new orange one (FD-9)', function () {
    $order = Order::factory()->create(['payment_status' => PaymentStatus::PendingPayment]);
    $this->actingAs(paymentConsumerActor());

    $html = Livewire::test(Overview::class)->html();

    expect(paymentBadgeColour($html, "dashboard-order-payment-{$order->id}"))->toBe('amber');
});
