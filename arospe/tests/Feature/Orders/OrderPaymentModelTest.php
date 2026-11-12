<?php

use App\Enums\OrderPaymentType;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\App;

// Story 0084 (D-4, reworked), Phase 3 red step: OrderPayment, OrderPaymentType, OrderPaymentFactory
// and Order::payment() do not exist yet.

test('OrderPayment uses HasUuids: a created payment has a 36-character UUIDv7 id', function () {
    $payment = OrderPayment::factory()->create();

    expect($payment->id)->toBeString()
        ->and(mb_strlen($payment->id))->toBe(36)
        ->and($payment->id[14])->toBe('7');
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
