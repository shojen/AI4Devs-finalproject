<?php

// Story 0085 (D-1, D-3, D-4, D-5) -- the "Mark as paid" button, confirmation dialog and payment header
// line on the order detail (App\Livewire\Orders\Show). Phase 3 red step: showMarkPaidConfirm,
// canMarkPaid, confirmMarkAsPaid, dismissMarkAsPaid, markAsPaid, the button, the dialog, the
// `payment` error slot and the `payment-info` line do not exist yet.
//
// Probes are by data-test hook (`mark-as-paid`, `payment-info`, `record-refund`), never by badge markup.
// Helpers are prefixed `markPaidShow` (global Pest helpers collide across this directory).

use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Renderless;
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

afterEach(function () {
    Carbon::setTestNow();
});

function markPaidShowEditor(array $extra = []): User
{
    $actor = MarkAsPaidUi::actor(['orders.view', 'orders.edit', ...$extra]);
    test()->actingAs($actor);

    return $actor;
}

function markPaidShowPending(array $attributes = []): Order
{
    return Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, ...$attributes]);
}

function markPaidShowToast(Testable $component, string $variant, string $text): Testable
{
    return $component->assertDispatched('toast-show', dataset: ['variant' => $variant], slots: ['text' => $text]);
}

// ---------------------------------------------------------------------------------------------
// Visibility (D-1)
// ---------------------------------------------------------------------------------------------

test('the detail shows the button only where the literal grid says so, and canMarkPaid agrees with the rendered button', function (PaymentStatus $payment, OrderStatus $status, bool $visible) {
    markPaidShowEditor();
    $order = Order::factory()->withItems(1)->create(['status' => $status, 'payment_status' => $payment]);

    $component = Livewire::test(Show::class, ['order' => $order]);

    expect(OrdersUi::present($component->html(), 'mark-as-paid'))->toBe($visible)
        ->and($component->instance()->canMarkPaid)->toBe($visible);
})->with('mark_as_paid_button_visibility_grid');

test('the button follows the actor permissions on a pending-payment order', function (array $permissions, bool $visible) {
    $this->actingAs(MarkAsPaidUi::actor($permissions));
    $order = markPaidShowPending();

    $component = Livewire::test(Show::class, ['order' => $order]);

    expect(OrdersUi::present($component->html(), 'mark-as-paid'))->toBe($visible)
        ->and($component->instance()->canMarkPaid)->toBe($visible);
})->with([
    'orders.view only is hidden' => [['orders.view'], false],
    'orders.view + orders.refund is hidden' => [['orders.view', 'orders.refund'], false],
    'orders.view + orders.edit is shown' => [['orders.view', 'orders.edit'], true],
    'orders.view + orders.edit + orders.refund is shown' => [['orders.view', 'orders.edit', 'orders.refund'], true],
]);

test('a Super Admin is shown the button on a pending-payment order but not on a cancelled one (the state rule beats Gate::before)', function () {
    $this->actingAs(OrdersUi::superAdmin());
    $pending = markPaidShowPending();
    $cancelled = markPaidShowPending(['status' => OrderStatus::Cancelled]);

    expect(OrdersUi::present(Livewire::test(Show::class, ['order' => $pending])->html(), 'mark-as-paid'))->toBeTrue()
        ->and(OrdersUi::present(Livewire::test(Show::class, ['order' => $cancelled])->html(), 'mark-as-paid'))->toBeFalse();
});

test('a flagged order awaiting payment still shows the button', function () {
    markPaidShowEditor();
    $order = markPaidShowPending(['flagged_for_review' => true, 'flag_reason' => 'mixed_basket']);

    expect(OrdersUi::present(Livewire::test(Show::class, ['order' => $order])->html(), 'mark-as-paid'))->toBeTrue();
});

test('the button opens the confirmation and sits in the totals section, after the totals labels', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect((string) OrdersUi::tag($html, 'mark-as-paid'))->toContain('confirmMarkAsPaid')
        ->and(strpos($html, 'data-test="mark-as-paid"'))->toBeGreaterThan(strpos($html, __('orders.detail.refunded_amount')));
});

// ---------------------------------------------------------------------------------------------
// Confirm flow (D-3)
// ---------------------------------------------------------------------------------------------

test('asking to mark opens a dialog that names the order, states bank transfer and shows the amount, and writes nothing', function () {
    markPaidShowEditor();
    // No withItems(): it recomputes the total from the generated lines and would overwrite the 45.60.
    $order = Order::factory()->create(['status' => OrderStatus::Processing, 'order_number' => 'ORD-DET-777', 'total' => '45.60']);

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid');
    $dialog = MarkAsPaidUi::dialog($component->html());

    $component->assertSet('showMarkPaidConfirm', true);

    expect($dialog)->toContain('ORD-DET-777')
        ->toContain('by bank transfer')
        ->toContain(__('orders.payment.dialog_amount'))
        ->toContain('€ 45.60')
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('Cancel, Esc, the backdrop and the X all reach dismissMarkAsPaid, which closes the dialog', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid');
    $html = $component->html();

    expect(preg_match('/<dialog\b[^>]*data-modal="mark-as-paid-modal"[^>]*>/s', $html, $modal))->toBe(1)
        ->and($modal[0])->toContain('wire:close="dismissMarkAsPaid"')
        ->and(OrdersUi::tag($html, 'confirm-dialog-mark-as-paid-dismiss'))->toContain('dismissMarkAsPaid');

    $component->call('dismissMarkAsPaid')->assertHasNoErrors()->assertSet('showMarkPaidConfirm', false);

    // dismissMarkAsPaid is renderless (see its docblock), so the closed modal keeps its last markup; the
    // closed state is the synced property, asserted above.
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('confirming marks the order paid by bank transfer, recorded by the actor, and the page reflects it without a reload', function () {
    $actor = markPaidShowEditor();
    $order = markPaidShowPending(['order_number' => 'ORD-DET-123']);

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid')->call('markAsPaid');
    $html = $component->html();

    $component->assertSet('showMarkPaidConfirm', false)->assertHasNoErrors();
    markPaidShowToast($component, 'success', __('orders.payment.marked', ['number' => 'ORD-DET-123']));

    $payment = $order->payment()->first();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($payment->type)->toBe(OrderPaymentType::Transfer)
        ->and($payment->payment_method_id)->toBe(MarkAsPaidUi::bankTransfer()->id)
        ->and($payment->recorded_by)->toBe($actor->id)
        ->and(OrdersUi::present($html, 'mark-as-paid'))->toBeFalse()
        ->and(MarkAsPaidUi::dialog($html))->toBe('')
        ->and($html)->toContain(__('orders.payment_statuses.paid'))
        ->and(MarkAsPaidUi::text($html, 'payment-info'))->toContain($actor->name)
        ->toContain('Bank transfer');
});

test('an order the actor just marked offers an enabled refund control to an administrator who may refund', function () {
    markPaidShowEditor(['orders.refund']);
    $order = markPaidShowPending();

    $html = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid')->call('markAsPaid')->html();

    expect(OrdersUi::enabled($html, 'record-refund'))->toBeTrue();
});

test('an order the actor just marked offers no usable refund control to an administrator who may not refund', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();

    $html = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid')->call('markAsPaid')->html();

    // Existing refund-control precedent: a missing orders.refund permission renders it disabled, not absent.
    expect(OrdersUi::enabled($html, 'record-refund'))->toBeFalse();
});

test('a no-permission opener is a silent no-op: no dialog, no refusal logged', function () {
    Log::spy();
    $this->actingAs(MarkAsPaidUi::actor(['orders.view']));
    $order = markPaidShowPending();

    Livewire::test(Show::class, ['order' => $order])
        ->call('confirmMarkAsPaid')
        ->assertSet('showMarkPaidConfirm', false)
        ->assertHasNoErrors();

    Log::shouldNotHaveReceived('warning');
});

test('opening the dialog on an order that cannot be marked is a silent no-op', function () {
    markPaidShowEditor();
    $order = Order::factory()->withItems(1)->paid()->create(['status' => OrderStatus::Processing]);

    Livewire::test(Show::class, ['order' => $order])
        ->call('confirmMarkAsPaid')
        ->assertSet('showMarkPaidConfirm', false);
});

// ---------------------------------------------------------------------------------------------
// Payment header line (D-3)
// ---------------------------------------------------------------------------------------------

function markPaidShowPaidOrder(?User $recorder, string $paidAt = '2026-03-04 14:05:00'): Order
{
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
    OrderPayment::factory()->create([
        'order_id' => $order->id,
        'type' => OrderPaymentType::Transfer,
        'paid_at' => Carbon::parse($paidAt, config('app.timezone')),
        'recorded_by' => $recorder?->id,
    ]);

    return $order;
}

test('the payment line reads the exact date (d/m/Y H:i), the type label and the recorder name', function () {
    markPaidShowEditor();
    $olga = User::factory()->create(['name' => 'Olga Pérez']);
    $order = markPaidShowPaidOrder($olga);

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect(MarkAsPaidUi::text($html, 'payment-info'))->toBe('Paid on 04/03/2026 14:05 (Bank transfer), recorded by Olga Pérez');
});

test('a payment with no recorded user shows the date and type without the recorder, and no error', function () {
    markPaidShowEditor();
    $order = markPaidShowPaidOrder(null);

    $component = Livewire::test(Show::class, ['order' => $order])->assertHasNoErrors();

    expect(MarkAsPaidUi::text($component->html(), 'payment-info'))->toBe('Paid on 04/03/2026 14:05 (Bank transfer)');
});

test('a pending order shows no payment line', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();

    expect(OrdersUi::present(Livewire::test(Show::class, ['order' => $order])->html(), 'payment-info'))->toBeFalse();
});

test('a paid order with no payment row shows the badge only: no line and no error', function () {
    markPaidShowEditor();
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);

    $component = Livewire::test(Show::class, ['order' => $order])->assertHasNoErrors()->assertOk();
    $html = $component->html();

    expect(OrdersUi::present($html, 'order-payment-badge'))->toBeTrue()
        ->and(OrdersUi::present($html, 'payment-info'))->toBeFalse();
});

test('the order eager-loads payment.recordedBy so the line causes no lazy load, before and after marking', function () {
    markPaidShowEditor();
    $olga = User::factory()->create();
    $paid = markPaidShowPaidOrder($olga);

    $order = Livewire::test(Show::class, ['order' => $paid])->instance()->order;

    expect($order->relationLoaded('payment'))->toBeTrue()
        ->and($order->payment->relationLoaded('recordedBy'))->toBeTrue();

    $pending = markPaidShowPending();
    $component = Livewire::test(Show::class, ['order' => $pending])->call('confirmMarkAsPaid')->call('markAsPaid');
    $reloaded = $component->instance()->order;

    expect($reloaded->relationLoaded('payment'))->toBeTrue()
        ->and($reloaded->payment->relationLoaded('recordedBy'))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Races, stale page and refusals (D-3, D-4)
// ---------------------------------------------------------------------------------------------

test('a stale page whose order another admin paid first reports already paid in the payment slot and shows the winner\'s badge and line', function () {
    markPaidShowEditor();
    $omar = User::factory()->create(['name' => 'Omar Ruiz']);
    $order = markPaidShowPending();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid')->assertSet('showMarkPaidConfirm', true);

    Order::query()->whereKey($order->id)->update(['payment_status' => PaymentStatus::Paid->value]);
    OrderPayment::factory()->create(['order_id' => $order->id, 'recorded_by' => $omar->id, 'paid_at' => Carbon::parse('2026-01-02 09:30:00', config('app.timezone'))]);

    $component->call('markAsPaid')->assertSet('showMarkPaidConfirm', false)->assertHasErrors(['payment']);
    $html = $component->html();

    expect($html)->toContain(e(__('orders.payment.already_paid')))
        ->and(OrdersUi::present($html, 'mark-as-paid'))->toBeFalse()
        ->and(MarkAsPaidUi::text($html, 'payment-info'))->toContain('02/01/2026 09:30')->toContain('Omar Ruiz')
        ->and(OrderPayment::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('an order cancelled meanwhile is reported in the payment slot and nothing is written', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid');

    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Cancelled->value]);

    $component->call('markAsPaid')->assertSet('showMarkPaidConfirm', false)->assertHasErrors(['payment']);

    expect($component->html())->toContain(e(__('orders.payment.cancelled_blocked')))
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('a later successful attempt clears the previous payment error', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid');
    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Cancelled->value]);
    $component->call('markAsPaid')->assertHasErrors(['payment']);

    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Processing->value]);
    $component->call('confirmMarkAsPaid')->call('markAsPaid')->assertHasNoErrors();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('losing orders.edit after the dialog opened is refused and logged, and the order does not change', function () {
    Log::spy();
    $actor = markPaidShowEditor();
    $order = markPaidShowPending();
    $this->withoutExceptionHandling();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid')->assertSet('showMarkPaidConfirm', true);

    $actor->revokePermissionTo('orders.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor->unsetRelation('permissions')->unsetRelation('roles');

    expect(fn () => $component->call('markAsPaid'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['ability'] === 'markPaid'
            && $context['target_type'] === 'order'
            && $context['target_id'] === $order->id)
        ->once();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('a view-only actor forging showMarkPaidConfirm and markAsPaid is refused and the refusal is logged exactly once', function () {
    Log::spy();
    $this->actingAs(MarkAsPaidUi::actor(['orders.view']));
    $order = markPaidShowPending();
    $this->withoutExceptionHandling();

    $component = Livewire::test(Show::class, ['order' => $order])->set('showMarkPaidConfirm', true);

    expect(fn () => $component->call('markAsPaid'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['ability'] === 'markPaid'
            && $context['target_type'] === 'order'
            && $context['target_id'] === $order->id)
        ->once();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('rendering the detail logs no refusal, for a view-only actor and for an edit actor alike', function (array $permissions) {
    Log::spy();
    $this->actingAs(MarkAsPaidUi::actor($permissions));
    $order = markPaidShowPending();

    Livewire::test(Show::class, ['order' => $order]);
    $this->get(route('orders.show', $order))->assertOk();

    Log::shouldNotHaveReceived('warning');
})->with([
    'view-only' => [['orders.view']],
    'edit' => [['orders.view', 'orders.edit']],
]);

test('the order id is locked: a client cannot re-point markAsPaid at another order', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();
    $other = markPaidShowPending();

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->set('orderId', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// ---------------------------------------------------------------------------------------------
// Fixed method guard (D-3, D-7)
// ---------------------------------------------------------------------------------------------

test('with no bank-transfer payment method the request fails with ModelNotFoundException and nothing is written', function () {
    markPaidShowEditor();
    $order = markPaidShowPending();
    PaymentMethod::query()->update(['code' => 'cash_on_delivery']);
    $this->withoutExceptionHandling();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid');

    expect(fn () => $component->call('markAsPaid'))->toThrow(ModelNotFoundException::class)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(OrderPayment::query()->count())->toBe(0);
});

test('the only new public property is the dialog bool, and no public property can change the payment method or type', function () {
    $properties = collect((new ReflectionClass(Show::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->reject(fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() !== Show::class)
        ->map(fn (ReflectionProperty $property): string => $property->getName())
        ->all();

    expect($properties)->toContain('showMarkPaidConfirm')
        ->and((string) (new ReflectionProperty(Show::class, 'showMarkPaidConfirm'))->getType())->toBe('bool');

    foreach ($properties as $name) {
        expect($name)->not->toMatch('/method|payment|^type$|Type$/i');
    }
});

// ---------------------------------------------------------------------------------------------
// Locale (D-5)
// ---------------------------------------------------------------------------------------------

test('a Spanish administrator gets the button on the page in Spanish', function () {
    $this->actingAs(MarkAsPaidUi::actor(['orders.view', 'orders.edit'], 'es'));
    $order = markPaidShowPending();

    $html = $this->get(route('orders.show', $order))->assertOk()->getContent();
    $start = strpos($html, 'data-test="mark-as-paid"');
    $button = substr($html, $start, strpos($html, '</button>', $start) - $start);

    expect($button)->toContain('Marcar como pagado')->not->toContain('Mark as paid');
});

test('a Spanish administrator gets the dialog, the toast, the payment line and a refusal from the Spanish lang file', function () {
    $actor = MarkAsPaidUi::actor(['orders.view', 'orders.edit'], 'es');
    $this->actingAs($actor);
    // Livewire::test() runs without middleware, so SetUiLocale never executes: apply the locale it would.
    app()->setLocale($actor->ui_locale);
    $order = markPaidShowPending(['order_number' => 'ORD-ES-1']);

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmMarkAsPaid');
    $dialog = MarkAsPaidUi::dialog($component->html());

    expect($dialog)->toContain('¿Marcar este pedido como pagado?')
        ->toContain('Esto registra que el pedido ORD-ES-1 se pagó por transferencia bancaria.')
        ->toContain('Importe recibido')
        ->not->toContain('Amount received');

    $component->call('markAsPaid');
    markPaidShowToast($component, 'success', 'Pedido ORD-ES-1 marcado como pagado.');

    expect(MarkAsPaidUi::text($component->html(), 'payment-info'))
        ->toMatch('/^Pagado el \d{2}\/\d{2}\/\d{4} \d{2}:\d{2} \(Transferencia bancaria\), registrado por /');

    // A refusal in Spanish: another admin cancels a second order meanwhile.
    $second = markPaidShowPending();
    $stale = Livewire::test(Show::class, ['order' => $second])->call('confirmMarkAsPaid');
    Order::query()->whereKey($second->id)->update(['status' => OrderStatus::Cancelled->value]);
    $stale->call('markAsPaid');

    expect($stale->html())->toContain(e(__('orders.payment.cancelled_blocked', [], 'es')))
        ->not->toContain(e(__('orders.payment.cancelled_blocked', [], 'en')));
});

test('dismissing the dialog is renderless so it cannot wipe the payment error a refusal just added', function () {
    $attributes = (new ReflectionMethod(Show::class, 'dismissMarkAsPaid'))->getAttributes(Renderless::class);

    expect($attributes)->toHaveCount(1);
});
