<?php

// Story 0085: the "Mark as paid" control on the orders list and the order detail, driven in a real
// browser. SELECTOR STRATEGY: data-test hooks only -- the label "Mark as paid" is both the row button and
// the dialog title/confirm label, so text selectors are ambiguous. Each journey asserts the IN-PLACE state
// first (the toast is the most flake-prone assertion, so it comes last) and follows every DOM assertion
// with a server-side check. Multi-step flows are wrapped in retry(3, ..., 250), this repo's mitigation for
// the click -> Livewire round trip occasionally exceeding the fixed 5000ms Playwright ceiling; preconditions
// are rebuilt inside the closure so a retry starts from a clean state. Never networkidle.

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\MarkAsPaidUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    // A non-Super-Admin holding orders.edit (mark as paid) and orders.refund (so the refund control is
    // offered once the order is paid).
    $this->actingAs(MarkAsPaidUi::actor(['orders.view', 'orders.edit', 'orders.refund']));
});

/**
 * Three pending-payment orders, oldest first: the list renders C, B, A, so B is the SECOND row.
 *
 * @return array{0: Order, 1: Order, 2: Order}
 */
function markPaidBrowserThreeOrders(): array
{
    return [
        Order::factory()->create(['order_number' => 'ORD-AAA-111', 'total' => '11.11', 'created_at' => '2026-03-01 09:00:00']),
        Order::factory()->create(['order_number' => 'ORD-BBB-222', 'total' => '22.22', 'created_at' => '2026-03-02 09:00:00']),
        Order::factory()->create(['order_number' => 'ORD-CCC-333', 'total' => '33.33', 'created_at' => '2026-03-03 09:00:00']),
    ];
}

test('the list dialog names the clicked row, and Escape closes it without paying anything', function () {
    retry(3, function () {
        Order::query()->delete();
        [$a, $b, $c] = markPaidBrowserThreeOrders();

        visit('/orders')
            ->assertNoJavaScriptErrors()
            ->click('@mark-as-paid-'.$b->id)
            ->assertVisible('@confirm-dialog-mark-as-paid')
            ->assertSeeIn('@confirm-dialog-mark-as-paid', 'ORD-BBB-222')
            ->assertSeeIn('@confirm-dialog-mark-as-paid', '€ 22.22')
            ->keys('@confirm-dialog-mark-as-paid-dismiss', 'Escape')
            ->assertMissing('@confirm-dialog-mark-as-paid')
            ->assertNoJavaScriptErrors();

        foreach ([$a, $b, $c] as $order) {
            expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment);
        }
        expect(OrderPayment::query()->count())->toBe(0);
    }, 250);
});

test('confirming on the list flips only that row to paid in place', function () {
    retry(3, function () {
        Order::query()->delete();
        [$a, $b, $c] = markPaidBrowserThreeOrders();

        $page = visit('/orders')->assertNoJavaScriptErrors();
        // A marker that a full page load would wipe: proves the row flipped in place.
        $page->script('window.__markPaidNoReload = true');

        $page
            ->click('@mark-as-paid-'.$b->id)
            ->assertVisible('@confirm-dialog-mark-as-paid')
            ->click('@confirm-dialog-mark-as-paid-confirm')
            ->assertMissing('@mark-as-paid-'.$b->id)
            ->assertMissing('@confirm-dialog-mark-as-paid')
            ->assertVisible('@mark-as-paid-'.$a->id)
            ->assertVisible('@mark-as-paid-'.$c->id)
            ->assertSeeIn('@order-row-'.$b->id, __('orders.payment_statuses.paid'))
            ->assertSee(__('orders.payment.marked', ['number' => 'ORD-BBB-222']))
            ->assertNoJavaScriptErrors();

        expect($page->script('window.__markPaidNoReload === true'))->toBeTrue();
        expect($b->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
            ->and($c->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
            ->and(OrderPayment::query()->where('order_id', $b->id)->count())->toBe(1);
    }, 250);
});

test('confirming on the detail shows the payment info and the refund control without navigating', function () {
    retry(3, function () {
        Order::query()->delete();
        OrderPayment::query()->delete();
        $order = Order::factory()->withItems(1)->create(['order_number' => 'ORD-DET-444']);

        visit('/orders/'.$order->id)
            ->assertNoJavaScriptErrors()
            ->assertMissing('@payment-info')
            ->assertMissing('@record-refund')
            ->click('@mark-as-paid')
            ->assertVisible('@confirm-dialog-mark-as-paid')
            ->click('@confirm-dialog-mark-as-paid-confirm')
            ->assertVisible('@payment-info')
            ->assertVisible('@record-refund')
            ->assertMissing('@mark-as-paid')
            ->assertPathIs('/orders/'.$order->id)
            ->assertSee(__('orders.payment.marked', ['number' => 'ORD-DET-444']))
            ->assertNoJavaScriptErrors();

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and(OrderPayment::query()->where('order_id', $order->id)->count())->toBe(1);
    }, 250);
});

test('confirming on a stale page shows the already-paid message instead of an error page', function () {
    retry(3, function () {
        Order::query()->delete();
        OrderPayment::query()->delete();
        $order = Order::factory()->withItems(1)->create(['order_number' => 'ORD-STALE-555']);

        $page = visit('/orders/'.$order->id)
            ->assertNoJavaScriptErrors();

        // The dialog opens while the order is still pending; someone else then marks it paid.
        $page->click('@mark-as-paid')
            ->assertVisible('@confirm-dialog-mark-as-paid');
        $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

        $page
            ->click('@confirm-dialog-mark-as-paid-confirm')
            ->assertSee(__('orders.payment.already_paid'))
            ->assertMissing('@confirm-dialog-mark-as-paid')
            ->assertPathIs('/orders/'.$order->id)
            ->assertNoJavaScriptErrors();

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and(OrderPayment::query()->where('order_id', $order->id)->count())->toBe(0);
    }, 250);
});
