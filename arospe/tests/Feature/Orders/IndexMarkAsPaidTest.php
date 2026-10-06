<?php

// Story 0085 (D-1, D-2, D-4, D-5) -- the "Mark as paid" button and confirmation dialog on the orders
// list (App\Livewire\Orders\Index). Phase 3 red step: the component members (confirmMarkAsPaid,
// dismissMarkAsPaid, markAsPaid, confirmingPaidRow, showMarkPaidConfirm, confirmingPaidOrderId), the
// row key `canMarkPaid`, the button and the dialog do not exist yet.
//
// Every probe works on RENDERED html by data-test hook (never by badge markup or class, D-6) and counts
// occurrences of `data-test="mark-as-paid-` rather than testing mere presence. Every actor is a
// non-Super-Admin (a Super Admin makes "hidden" assertions false negatives) except in the dedicated
// Super Admin cases. Helpers are prefixed `markPaidIdx` (global Pest helpers collide across this directory).

use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Index;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\MarkAsPaidUi;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Three pending-payment orders with distinct numbers, totals and customers, oldest first, so the list
 * renders C, B, A (newest first).
 *
 * @return array{0: Order, 1: Order, 2: Order}
 */
function markPaidIdxThreeOrders(): array
{
    $a = Order::factory()->create(['order_number' => 'ORD-AAA-111', 'total' => '11.11', 'created_at' => '2026-03-01 09:00:00']);
    $b = Order::factory()->create(['order_number' => 'ORD-BBB-222', 'total' => '22.22', 'created_at' => '2026-03-02 09:00:00']);
    $c = Order::factory()->create(['order_number' => 'ORD-CCC-333', 'total' => '33.33', 'created_at' => '2026-03-03 09:00:00']);

    return [$a, $b, $c];
}

function markPaidIdxEditor(): User
{
    $actor = MarkAsPaidUi::actor(['orders.view', 'orders.edit']);
    test()->actingAs($actor);

    return $actor;
}

/**
 * The success toast Flux dispatches for a given text.
 */
function markPaidIdxToast(Testable $component, string $variant, string $text): Testable
{
    return $component->assertDispatched('toast-show', dataset: ['variant' => $variant], slots: ['text' => $text]);
}

// ---------------------------------------------------------------------------------------------
// Visibility (D-1)
// ---------------------------------------------------------------------------------------------

test('the list shows the button only where the literal grid says so, for an orders.view + orders.edit actor', function (PaymentStatus $payment, OrderStatus $status, bool $visible) {
    markPaidIdxEditor();
    $order = Order::factory()->create(['status' => $status, 'payment_status' => $payment]);

    $html = Livewire::test(Index::class)->html();

    expect(MarkAsPaidUi::listButtons($html))->toBe($visible ? 1 : 0)
        ->and(OrdersUi::present($html, 'mark-as-paid-'.$order->id))->toBe($visible);
})->with('mark_as_paid_button_visibility_grid');

test('exactly four of the twenty grid cells render the button', function () {
    $count = 0;

    foreach ([PaymentStatus::PendingPayment, PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded] as $payment) {
        foreach (OrderStatus::cases() as $status) {
            Order::factory()->create(['status' => $status, 'payment_status' => $payment]);
            $count++;
        }
    }

    markPaidIdxEditor();

    $html = Livewire::test(Index::class)->html();

    expect($count)->toBe(20)
        ->and(MarkAsPaidUi::listButtons($html))->toBe(4);
});

test('the button follows the actor permissions on a pending-payment order', function (array $permissions, bool $visible) {
    $this->actingAs(MarkAsPaidUi::actor($permissions));
    Order::factory()->create();

    $html = Livewire::test(Index::class)->html();

    expect(MarkAsPaidUi::listButtons($html))->toBe($visible ? 1 : 0);
})->with([
    'orders.view only is hidden' => [['orders.view'], false],
    'orders.view + orders.refund is hidden' => [['orders.view', 'orders.refund'], false],
    'orders.view + orders.edit is shown' => [['orders.view', 'orders.edit'], true],
    'orders.view + orders.edit + orders.refund is shown' => [['orders.view', 'orders.edit', 'orders.refund'], true],
]);

test('an actor with no orders permission is forbidden from the page, so there is no button to hide', function () {
    $this->actingAs(MarkAsPaidUi::actor([]));
    Order::factory()->create();

    $this->get(route('orders.index'))->assertForbidden();
});

test('a Super Admin is shown the button on a pending-payment order', function () {
    $this->actingAs(OrdersUi::superAdmin());
    Order::factory()->create();

    expect(MarkAsPaidUi::listButtons(Livewire::test(Index::class)->html()))->toBe(1);
});

test('the state rule beats Gate::before: a Super Admin is not shown the button on a cancelled pending-payment order', function () {
    $this->actingAs(OrdersUi::superAdmin());
    Order::factory()->create(['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::PendingPayment]);

    expect(MarkAsPaidUi::listButtons(Livewire::test(Index::class)->html()))->toBe(0);
});

test('in a mixed list exactly one row, the one awaiting payment, carries the button', function () {
    markPaidIdxEditor();
    $awaiting = Order::factory()->create();
    Order::factory()->count(2)->create(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing]);
    Order::factory()->create(['payment_status' => PaymentStatus::PendingPayment, 'status' => OrderStatus::Cancelled]);

    $html = Livewire::test(Index::class)->html();

    expect(MarkAsPaidUi::listButtons($html))->toBe(1)
        ->and(OrdersUi::present($html, 'mark-as-paid-'.$awaiting->id))->toBeTrue();
});

test('a flagged order awaiting payment still shows the button', function () {
    markPaidIdxEditor();
    $order = Order::factory()->create(['flagged_for_review' => true, 'flag_reason' => 'mixed_basket']);

    expect(OrdersUi::present(Livewire::test(Index::class)->html(), 'mark-as-paid-'.$order->id))->toBeTrue();
});

test('a row whose customer was soft-deleted still shows the button', function () {
    markPaidIdxEditor();
    $customer = Customer::factory()->create();
    $order = Order::factory()->forCustomer($customer)->create();
    $customer->delete();

    expect(OrdersUi::present(Livewire::test(Index::class)->html(), 'mark-as-paid-'.$order->id))->toBeTrue();
});

test('the button carries the visible label and the order number as screen-reader text, and opens the confirmation for its own row', function () {
    markPaidIdxEditor();
    $order = Order::factory()->create(['order_number' => 'ORD-SR-999']);

    $html = Livewire::test(Index::class)->html();
    $tag = (string) OrdersUi::tag($html, 'mark-as-paid-'.$order->id);

    // The accessible name must contain the visible label (WCAG 2.5.3).
    $start = strpos($html, 'data-test="mark-as-paid-'.$order->id.'"');
    $button = substr($html, $start, strpos($html, '</button>', $start) - $start);

    expect($tag)->toContain('confirmMarkAsPaid')->toContain($order->id)
        ->and($button)->toContain(__('orders.payment.action'))
        ->and($button)->toMatch('/<span[^>]*class="[^"]*sr-only[^"]*"[^>]*>\s*ORD-SR-999\s*<\/span>/');
});

test('the dialog host is in the DOM even when the list is empty, and no dialog content renders while it is closed', function () {
    markPaidIdxEditor();

    $empty = Livewire::test(Index::class)->html();
    expect($empty)->toContain('data-modal="mark-as-paid-modal"');

    Order::factory()->create();
    $closed = Livewire::test(Index::class)->html();

    expect($closed)->toContain('data-modal="mark-as-paid-modal"')
        ->and(MarkAsPaidUi::dialog($closed))->toBe('');
});

test('the list has no paid-date column and no bulk marking control', function () {
    markPaidIdxEditor();
    Order::factory()->count(2)->create();
    Order::factory()->create(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing]);

    $html = Livewire::test(Index::class)->html();

    // Only per-row buttons exist (3 rows, 2 eligible) and nothing selects several rows at once.
    expect(MarkAsPaidUi::listButtons($html))->toBe(2)
        ->and($html)->not->toContain('mark-as-paid-all')
        ->and($html)->not->toContain('type="checkbox"')
        ->and($html)->not->toContain('markSelectedAsPaid');
});

// ---------------------------------------------------------------------------------------------
// Dialog (D-2)
// ---------------------------------------------------------------------------------------------

test('opening row B shows B\'s number and exact amount and neither A\'s nor C\'s, and states bank transfer', function () {
    markPaidIdxEditor();
    [$a, $b, $c] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $b->id);
    $dialog = MarkAsPaidUi::dialog($component->html());

    $component->assertSet('showMarkPaidConfirm', true)->assertSet('confirmingPaidOrderId', $b->id);

    expect($dialog)->toContain('ORD-BBB-222')
        ->toContain('€ 22.22')
        ->toContain(e(__('orders.payment.dialog_heading')))
        ->toContain(__('orders.payment.dialog_amount'))
        ->toContain('by bank transfer')
        ->not->toContain('ORD-AAA-111')->not->toContain('€ 11.11')
        ->not->toContain('ORD-CCC-333')->not->toContain('€ 33.33');

    // Asking to open is not paying: nothing was written.
    expect($b->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('opening A and then B without closing leaves B in the confirmation', function () {
    markPaidIdxEditor();
    [$a, $b] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)
        ->call('confirmMarkAsPaid', $a->id)
        ->call('confirmMarkAsPaid', $b->id);
    $dialog = MarkAsPaidUi::dialog($component->html());

    $component->assertSet('confirmingPaidOrderId', $b->id);

    expect($dialog)->toContain('ORD-BBB-222')->not->toContain('ORD-AAA-111');
});

test('Cancel, Esc, the backdrop and the X all reach dismissMarkAsPaid, which resets both properties without error', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);
    $html = $component->html();

    // Esc / backdrop / X are the native dialog's close event, compiled by Flux to wire:close on the
    // <dialog> OPENING tag; the Cancel button carries the same method name, so scope the assertion to
    // the tag (otherwise it would pass with @close removed).
    expect(preg_match('/<dialog\b[^>]*data-modal="mark-as-paid-modal"[^>]*>/s', $html, $modal))->toBe(1)
        ->and($modal[0])->toContain('wire:close="dismissMarkAsPaid"')
        ->and(OrdersUi::tag($html, 'confirm-dialog-mark-as-paid-dismiss'))->toContain('dismissMarkAsPaid')
        ->and(OrdersUi::tag($html, 'confirm-dialog-mark-as-paid-confirm'))->toContain('markAsPaid');

    $component->call('dismissMarkAsPaid')
        ->assertHasNoErrors()
        ->assertSet('showMarkPaidConfirm', false)
        ->assertSet('confirmingPaidOrderId', null);

    expect(MarkAsPaidUi::dialog($component->html()))->toBe('')
        ->and($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('dismissing a dialog that was never opened is harmless', function () {
    markPaidIdxEditor();
    markPaidIdxThreeOrders();

    Livewire::test(Index::class)
        ->call('dismissMarkAsPaid')
        ->assertHasNoErrors()
        ->assertSet('showMarkPaidConfirm', false)
        ->assertSet('confirmingPaidOrderId', null);
});

test('confirming after opening row B writes B only', function () {
    $actor = markPaidIdxEditor();
    [$a, $b, $c] = markPaidIdxThreeOrders();

    Livewire::test(Index::class)->call('confirmMarkAsPaid', $b->id)->call('markAsPaid');

    expect($b->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and($c->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->pluck('order_id')->all())->toBe([$b->id]);

    $payment = $b->payment()->first();

    // D-2: the action is always called with the server-resolved bank-transfer method and type Transfer.
    expect($payment->type)->toBe(OrderPaymentType::Transfer)
        ->and($payment->payment_method_id)->toBe(MarkAsPaidUi::bankTransfer()->id)
        ->and($payment->recorded_by)->toBe($actor->id);
});

test('the opener refuses to open for an ineligible row but still stores a valid row id', function () {
    markPaidIdxEditor();
    $paid = Order::factory()->create(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing]);

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $paid->id);

    $component->assertSet('showMarkPaidConfirm', false)->assertSet('confirmingPaidOrderId', $paid->id);

    expect($component->instance()->confirmingPaidRow)->toBeNull();
});

test('confirmingPaidRow is the server-built row of the clicked order and null otherwise', function () {
    markPaidIdxEditor();
    [$a, $b] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class);
    expect($component->instance()->confirmingPaidRow)->toBeNull();

    $component->call('confirmMarkAsPaid', $b->id);
    $row = $component->instance()->confirmingPaidRow;

    expect($row)->toBeArray()
        ->and($row['id'])->toBe($b->id)
        ->and($row['orderNumber'])->toBe('ORD-BBB-222')
        ->and($row['total'])->toBe('22.22')
        ->and($row['canMarkPaid'])->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// In place
// ---------------------------------------------------------------------------------------------

test('confirming flips only that row to Paid in place, drops its button, leaves the other rows byte-identical and dispatches the success toast', function () {
    markPaidIdxEditor();
    [$a, $b, $c] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class);
    $before = $component->html();

    $component->call('confirmMarkAsPaid', $b->id)->call('markAsPaid')
        ->assertSet('showMarkPaidConfirm', false)
        ->assertSet('confirmingPaidOrderId', null);
    $after = $component->html();

    $rowB = MarkAsPaidUi::row($after, $b->id);

    expect($rowB)->not->toBe('')
        ->and($rowB)->toContain(__('orders.payment_statuses.paid'))
        ->and($rowB)->not->toContain('mark-as-paid-')
        ->and(MarkAsPaidUi::listButtons($after))->toBe(2)
        ->and(MarkAsPaidUi::row($after, $a->id))->toBe(MarkAsPaidUi::row($before, $a->id))
        ->and(MarkAsPaidUi::row($after, $c->id))->toBe(MarkAsPaidUi::row($before, $c->id))
        ->and(MarkAsPaidUi::dialog($after))->toBe('');

    markPaidIdxToast($component, 'success', __('orders.payment.marked', ['number' => 'ORD-BBB-222']));
});

test('the paid row stays in the list: no filter drops it, so a future filter story must revisit this', function () {
    markPaidIdxEditor();
    [$a, $b, $c] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $b->id)->call('markAsPaid');

    expect(collect($component->instance()->orders)->pluck('id')->all())->toBe([$c->id, $b->id, $a->id])
        ->and($component->instance()->ordersSummary)->toBe(trans_choice('orders.index.summary', 3, ['count' => 3]));
});

// ---------------------------------------------------------------------------------------------
// Races and refusals (D-2, D-4)
// ---------------------------------------------------------------------------------------------

test('an order paid elsewhere meanwhile is reported with the translated message and keeps the other admin\'s payment', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();
    $omar = User::factory()->create();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);

    Order::query()->whereKey($a->id)->update(['payment_status' => PaymentStatus::Paid->value]);
    OrderPayment::factory()->create(['order_id' => $a->id, 'recorded_by' => $omar->id, 'paid_at' => '2026-01-01 08:00:00']);

    $component->call('markAsPaid')->assertSet('showMarkPaidConfirm', false)->assertSet('confirmingPaidOrderId', null);

    markPaidIdxToast($component, 'danger', __('orders.payment.already_paid'));

    $component->assertNotDispatched('toast-show', dataset: ['variant' => 'success'], slots: ['text' => __('orders.payment.marked', ['number' => 'ORD-AAA-111'])]);

    expect(OrderPayment::query()->where('order_id', $a->id)->count())->toBe(1)
        ->and($a->payment()->first()->recorded_by)->toBe($omar->id);
});

test('an order cancelled meanwhile is reported with the translated message and nothing is written', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);

    Order::query()->whereKey($a->id)->update(['status' => OrderStatus::Cancelled->value]);

    $component->call('markAsPaid')->assertSet('showMarkPaidConfirm', false);

    markPaidIdxToast($component, 'danger', __('orders.payment.cancelled_blocked'));

    expect($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('losing orders.edit after the dialog opened is refused and the refusal is logged against the order', function () {
    Log::spy();
    $actor = markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id)->assertSet('showMarkPaidConfirm', true);

    $actor->revokePermissionTo('orders.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor->unsetRelation('permissions')->unsetRelation('roles');

    expect(fn () => $component->call('markAsPaid'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['ability'] === 'markPaid'
            && $context['target_type'] === 'order'
            && $context['target_id'] === $a->id)
        ->once();

    expect($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('an order removed meanwhile gives the translated not-found message instead of an error page', function () {
    markPaidIdxEditor();
    [$a, $b] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);

    Order::query()->whereKey($a->id)->forceDelete();

    $component->call('markAsPaid')
        ->assertSet('showMarkPaidConfirm', false)
        ->assertSet('confirmingPaidOrderId', null);

    markPaidIdxToast($component, 'danger', __('orders.payment.not_found'));

    expect(OrderPayment::query()->count())->toBe(0)
        ->and($b->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('a queued second confirmation after a success stops at the null guard: one write, one success toast, a not-found message', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();

    // assertDispatched only sees the events of the LAST response, so each toast is asserted right after its call.
    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id)->call('markAsPaid');
    markPaidIdxToast($component, 'success', __('orders.payment.marked', ['number' => 'ORD-AAA-111']));
    $paidAt = $a->payment()->first()->paid_at;

    $component->call('markAsPaid');
    markPaidIdxToast($component, 'danger', __('orders.payment.not_found'));

    expect(OrderPayment::query()->where('order_id', $a->id)->count())->toBe(1)
        ->and($a->payment()->first()->paid_at->equalTo($paidAt))->toBeTrue();
});

test('re-opening and confirming the same, already paid, order is reported as already paid and keeps its original payment', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id)->call('markAsPaid');
    $original = $a->payment()->first();

    $component->call('confirmMarkAsPaid', $a->id)->call('markAsPaid');

    markPaidIdxToast($component, 'danger', __('orders.payment.already_paid'));

    expect(OrderPayment::query()->where('order_id', $a->id)->count())->toBe(1)
        ->and($a->payment()->first()->id)->toBe($original->id);
});

// ---------------------------------------------------------------------------------------------
// Forged calls (D-4)
// ---------------------------------------------------------------------------------------------

test('a view-only actor forging confirmMarkAsPaid then markAsPaid is refused, and the refusal is logged exactly once', function () {
    Log::spy();
    $this->actingAs(MarkAsPaidUi::actor(['orders.view']));
    [$a] = markPaidIdxThreeOrders();
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);

    // D-2: a forged opener by an unauthorized actor opens no dialog, but the id is stored.
    $component->assertSet('showMarkPaidConfirm', false)->assertSet('confirmingPaidOrderId', $a->id);

    expect(fn () => $component->call('markAsPaid'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['ability'] === 'markPaid'
            && $context['target_type'] === 'order'
            && $context['target_id'] === $a->id)
        ->once();

    expect($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('rendering the list logs no refusal, for a view-only actor and for an edit actor alike', function (array $permissions) {
    Log::spy();
    $this->actingAs(MarkAsPaidUi::actor($permissions));
    markPaidIdxThreeOrders();

    Livewire::test(Index::class);
    $this->get(route('orders.index'))->assertOk();

    Log::shouldNotHaveReceived('warning');
})->with([
    'view-only' => [['orders.view']],
    'edit' => [['orders.view', 'orders.edit']],
]);

test('confirmingPaidOrderId is locked: a client cannot write it', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();

    expect(fn () => Livewire::test(Index::class)->set('confirmingPaidOrderId', $a->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a missing, garbage, unknown or foreign id gives the translated not-found message, writes nothing and never errors', function (callable $forged) {
    markPaidIdxEditor();
    markPaidIdxThreeOrders();
    $customer = Customer::factory()->create();

    $component = Livewire::test(Index::class);
    $forged($component, $customer);
    $component->call('markAsPaid')->assertHasNoErrors();

    markPaidIdxToast($component, 'danger', __('orders.payment.not_found'));

    expect(OrderPayment::query()->count())->toBe(0)
        ->and(Order::query()->where('payment_status', PaymentStatus::PendingPayment->value)->count())->toBe(3);
})->with([
    'null (confirmMarkAsPaid never called)' => [fn () => null],
    'not-a-uuid' => [fn ($component) => $component->call('confirmMarkAsPaid', 'not-a-uuid')],
    'an unknown UUID' => [fn ($component) => $component->call('confirmMarkAsPaid', (string) Str::uuid())],
    'a Customer id' => [fn ($component, Customer $customer) => $component->call('confirmMarkAsPaid', $customer->id)],
]);

test('a garbage id leaves the dialog closed and the stored id null', function (string $garbage) {
    markPaidIdxEditor();
    markPaidIdxThreeOrders();

    Livewire::test(Index::class)
        ->call('confirmMarkAsPaid', $garbage)
        ->assertSet('showMarkPaidConfirm', false)
        ->assertSet('confirmingPaidOrderId', null);
})->with(['not-a-uuid', '00000000-0000-7000-8000-000000000000', '', "' OR 1=1 --"]);

// ---------------------------------------------------------------------------------------------
// Fixed method guard (D-2, D-7)
// ---------------------------------------------------------------------------------------------

test('with no bank-transfer payment method the request fails with ModelNotFoundException and nothing is written', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();
    PaymentMethod::query()->update(['code' => 'cash_on_delivery']);
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);

    expect(fn () => $component->call('markAsPaid'))->toThrow(ModelNotFoundException::class)
        ->and($a->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('no client-writable public property can change the payment method or type', function () {
    $properties = collect((new ReflectionClass(Index::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->reject(fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() !== Index::class)
        ->map(fn (ReflectionProperty $property): string => $property->getName())
        ->all();

    expect($properties)->toEqualCanonicalizing(['showMarkPaidConfirm', 'confirmingPaidOrderId']);

    foreach ($properties as $name) {
        expect($name)->not->toMatch('/method|type|payment(?!Paid)/i');
    }
});

// ---------------------------------------------------------------------------------------------
// Query count (D-2): no per-row query
// ---------------------------------------------------------------------------------------------

/**
 * The number of queries a mount + render of the list issues, after a warm-up that fills the
 * permission cache (the first permission lookup of a process would otherwise count against one side).
 */
function markPaidIdxRenderQueries(): int
{
    Livewire::test(Index::class);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::test(Index::class);

    return $queries;
}

test('mounting the list issues the same number of queries for 1 order as for 20, one of them awaiting payment in both', function () {
    markPaidIdxEditor();
    Order::factory()->create();
    $one = markPaidIdxRenderQueries();

    foreach (range(1, 19) as $i) {
        Order::factory()->create(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing]);
    }
    $twenty = markPaidIdxRenderQueries();

    expect($one)->toBeGreaterThan(0)->and($twenty)->toBe($one);
});

test('mounting the list issues the same number of queries for 1 pending order as for 20, every one awaiting payment', function () {
    markPaidIdxEditor();
    Order::factory()->create();
    $one = markPaidIdxRenderQueries();

    Order::factory()->count(19)->create();
    $twenty = markPaidIdxRenderQueries();

    expect($twenty)->toBe($one);
});

test('opening the dialog adds no query beyond the memoised rows', function () {
    markPaidIdxEditor();
    [$a] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $component->call('confirmMarkAsPaid', $a->id);

    // A subsequent request recomputes the memoised rows once (one orders query + one customers
    // eager-load), nothing else: no per-row, no per-dialog query.
    $orderQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from `orders`'))->count();

    expect($orderQueries)->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Public-state audit (D-2)
// ---------------------------------------------------------------------------------------------

test('no public property holds a model or a collection, and the only new public properties are the bool and the locked id', function () {
    $properties = (new ReflectionClass(Index::class))->getProperties(ReflectionProperty::IS_PUBLIC);

    foreach ($properties as $property) {
        $type = (string) $property->getType();

        expect($type)->not->toContain('Collection')->not->toContain('Model')->not->toContain('Order')
            ->and(in_array($type, ['bool', 'string', '?string', 'int', '?int', 'array'], true))->toBeTrue();
    }

    $locked = (new ReflectionProperty(Index::class, 'confirmingPaidOrderId'))->getAttributes(Locked::class);

    expect((string) (new ReflectionProperty(Index::class, 'showMarkPaidConfirm'))->getType())->toBe('bool')
        ->and((string) (new ReflectionProperty(Index::class, 'confirmingPaidOrderId'))->getType())->toBe('?string')
        ->and($locked)->toHaveCount(1);
});

test('each row array adds only the canMarkPaid bool to the existing shape', function () {
    markPaidIdxEditor();
    Order::factory()->create();

    $row = Livewire::test(Index::class)->instance()->orders[0];

    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'orderNumber', 'customerId', 'customerName', 'customerLinkable', 'status', 'statusLabel',
        'paymentStatus', 'paymentStatusLabel', 'total', 'createdAt', 'isFlagged', 'flagReasonLabel', 'canMarkPaid',
    ])->and($row['canMarkPaid'])->toBeTrue();
});

test('the serialized snapshot carries no total or customer name of the confirmed row', function () {
    markPaidIdxEditor();
    $customer = Customer::factory()->create(['name' => 'Zacarias Unmistakable']);
    $order = Order::factory()->forCustomer($customer)->create(['order_number' => 'ORD-SNAP-1', 'total' => '987.65']);

    $snapshot = json_encode(Livewire::test(Index::class)->call('confirmMarkAsPaid', $order->id)->getData());

    expect($snapshot)->toContain($order->id)
        ->not->toContain('987.65')
        ->not->toContain('Zacarias Unmistakable')
        ->not->toContain('ORD-SNAP-1');
});

// ---------------------------------------------------------------------------------------------
// Locale (D-5): the actor's stored ui_locale, never app()->setLocale picked by the test
// ---------------------------------------------------------------------------------------------

test('a Spanish administrator gets the button in Spanish on the page and not the English label', function () {
    $this->actingAs(MarkAsPaidUi::actor(['orders.view', 'orders.edit'], 'es'));
    $order = Order::factory()->create();

    $html = $this->get(route('orders.index'))->assertOk()->getContent();
    $start = strpos($html, 'data-test="mark-as-paid-'.$order->id.'"');
    $button = substr($html, $start, strpos($html, '</button>', $start) - $start);

    expect($button)->toContain('Marcar como pagado')->not->toContain('Mark as paid');
});

test('a Spanish administrator gets the dialog, the success toast and both refusals from the Spanish lang file', function () {
    $actor = MarkAsPaidUi::actor(['orders.view', 'orders.edit'], 'es');
    $this->actingAs($actor);
    // Livewire::test() runs without middleware, so SetUiLocale never executes: apply the locale it would.
    app()->setLocale($actor->ui_locale);
    [$a, $b] = markPaidIdxThreeOrders();

    $component = Livewire::test(Index::class)->call('confirmMarkAsPaid', $a->id);
    $dialog = MarkAsPaidUi::dialog($component->html());

    expect($dialog)->toContain('¿Marcar este pedido como pagado?')
        ->toContain('Esto registra que el pedido ORD-AAA-111 se pagó por transferencia bancaria.')
        ->toContain('Importe recibido')
        ->toContain('Cancelar')
        ->not->toContain('Mark this order as paid')
        ->not->toContain('Amount received');

    $component->call('markAsPaid');
    markPaidIdxToast($component, 'success', 'Pedido ORD-AAA-111 marcado como pagado.');

    // Already paid (re-open the paid order) and not found (a missing id), both Spanish.
    $component->call('confirmMarkAsPaid', $a->id)->call('markAsPaid');
    markPaidIdxToast($component, 'danger', __('orders.payment.already_paid', [], 'es'));

    $component->call('markAsPaid');
    markPaidIdxToast($component, 'danger', 'Este pedido ya no existe.');

    expect(__('orders.payment.already_paid', [], 'es'))->not->toBe(__('orders.payment.already_paid', [], 'en'));
});
