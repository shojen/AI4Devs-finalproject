<?php

use App\Enums\OrderPaymentType;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

// Story 0084 (D-4, reworked), Phase 3 red step: OrderPayment, OrderPaymentType, OrderPaymentFactory
// and Order::payment() do not exist yet.

test('OrderPayment uses HasUuids: a created payment has a 36-character UUIDv7 id', function () {
    $payment = OrderPayment::factory()->create();

    expect($payment->id)->toBeString()
        ->and(mb_strlen($payment->id))->toBe(36)
        ->and($payment->id[14])->toBe('7');
});

test('a payment round-trips through the factory with every column persisting and reloading', function () {
    $payment = OrderPayment::factory()->create(['type' => OrderPaymentType::Paypal, 'paid_at' => '2026-03-02 08:15:00']);

    $fresh = $payment->fresh();

    expect($fresh->id)->toBe($payment->id)
        ->and($fresh->order_id)->toBe($payment->order_id)
        ->and($fresh->payment_method_id)->toBe($payment->payment_method_id)
        ->and($fresh->type)->toBe(OrderPaymentType::Paypal)
        ->and($fresh->paid_at->format('Y-m-d H:i:s'))->toBe('2026-03-02 08:15:00');
});

test('type is cast to OrderPaymentType and paid_at to a date instance', function () {
    $payment = OrderPayment::factory()->create(['type' => 'card'])->fresh();

    expect($payment->type)->toBe(OrderPaymentType::Card)
        ->and($payment->paid_at)->toBeInstanceOf(CarbonInterface::class)
        ->and(DB::table('order_payments')->where('id', $payment->id)->value('type'))->toBe('card');
});

test('no OrderPayment column is mass-assignable: the fillable set is empty and fill() refuses every column', function () {
    $payment = new OrderPayment;

    expect($payment->getFillable())->toBe([])
        ->and(fn () => $payment->fill([
            'order_id' => 'order-id',
            'payment_method_id' => 'method-id',
            'type' => 'card',
            'paid_at' => '2026-03-02 08:15:00',
            'recorded_by' => 'user-id',
        ]))->toThrow(MassAssignmentException::class)
        ->and($payment->getAttributes())->toBe([]);
});

test('order() and paymentMethod() are BelongsTo relations resolving to the right models', function () {
    $payment = OrderPayment::factory()->create();

    expect($payment->order())->toBeInstanceOf(BelongsTo::class)
        ->and($payment->paymentMethod())->toBeInstanceOf(BelongsTo::class)
        ->and($payment->order)->toBeInstanceOf(Order::class)
        ->and($payment->order->id)->toBe($payment->order_id)
        ->and($payment->paymentMethod)->toBeInstanceOf(PaymentMethod::class)
        ->and($payment->paymentMethod->id)->toBe($payment->payment_method_id);
});

test('Order::payment() is a HasOne returning the order\'s payment, or null for an order without one', function () {
    $paid = OrderPayment::factory()->create();
    $unpaid = Order::factory()->create();

    expect($paid->order->payment())->toBeInstanceOf(HasOne::class)
        ->and($paid->order->fresh()->payment->id)->toBe($paid->id)
        ->and($unpaid->payment)->toBeNull();
});

test('OrderPaymentType has exactly the cases transfer, card and paypal with those backing values', function () {
    expect(array_map(fn (OrderPaymentType $case): string => $case->name, OrderPaymentType::cases()))->toBe(['Transfer', 'Card', 'Paypal'])
        ->and(array_map(fn (OrderPaymentType $case): string => $case->value, OrderPaymentType::cases()))->toBe(['transfer', 'card', 'paypal'])
        ->and(OrderPaymentType::from('transfer'))->toBe(OrderPaymentType::Transfer)
        ->and(OrderPaymentType::tryFrom('cash'))->toBeNull();
});

test('OrderPaymentType::label() resolves orders.payment.types.<value> to a real translation in both locales', function (string $locale, string $typeValue) {
    App::setLocale($locale);
    $type = OrderPaymentType::from($typeValue);

    expect($type->label())->toBe(__('orders.payment.types.'.$type->value))
        ->and($type->label())->not->toBe('orders.payment.types.'.$type->value)
        ->and($type->label())->not->toBe('');
})->with(function () {
    foreach (['en', 'es'] as $locale) {
        foreach (['transfer', 'card', 'paypal'] as $typeValue) {
            yield "$locale $typeValue" => [$locale, $typeValue];
        }
    }
});

test('OrderPaymentFactory default is coherent: a real order, the existing bank-transfer method, type transfer and a paid_at', function () {
    $payment = OrderPayment::factory()->create()->fresh();

    expect($payment->order)->toBeInstanceOf(Order::class)
        ->and($payment->paymentMethod->code->value)->toBe('bank_transfer')
        ->and($payment->type)->toBe(OrderPaymentType::Transfer)
        ->and($payment->paid_at)->toBeInstanceOf(CarbonInterface::class);
});

test('OrderPaymentFactory reuses an already-seeded payment method instead of creating a second one', function () {
    $this->seed(PaymentMethodSeeder::class);

    OrderPayment::factory()->create();
    OrderPayment::factory()->create();

    expect(PaymentMethod::query()->count())->toBe(1);
});

test('OrderPaymentFactory attaches to a given order', function () {
    $order = Order::factory()->create();

    $payment = OrderPayment::factory()->create(['order_id' => $order->id]);

    expect($payment->order_id)->toBe($order->id)
        ->and(Order::query()->count())->toBe(1);
});

test('recordedBy() is a BelongsTo to User resolving to the recorder, and recorded_by is a string id', function () {
    $user = User::factory()->create();
    $payment = OrderPayment::factory()->create(['recorded_by' => $user->id])->fresh();

    expect($payment->recordedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($payment->recordedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($payment->recorded_by)->toBe($user->id)
        ->and($payment->recordedBy)->toBeInstanceOf(User::class)
        ->and($payment->recordedBy->is($user))->toBeTrue();
});

test('OrderPaymentFactory leaves recorded_by null by default, so recordedBy resolves to null', function () {
    $payment = OrderPayment::factory()->create()->fresh();

    expect($payment->recorded_by)->toBeNull()
        ->and($payment->recordedBy)->toBeNull();
});

test('OrderFactory::paid() leaves the recorder empty (legacy-style paid order)', function () {
    $order = Order::factory()->paid()->create();

    expect(DB::table('order_payments')->where('order_id', $order->id)->value('recorded_by'))->toBeNull();
});

test('the OrderPayment docblock declares recorded_by as nullable string', function () {
    $source = (string) file_get_contents(app_path('Models/OrderPayment.php'));

    expect($source)->toContain('@property string|null $recorded_by');
});
