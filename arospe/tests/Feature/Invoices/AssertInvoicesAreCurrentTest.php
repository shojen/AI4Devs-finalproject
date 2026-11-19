<?php

// Story 0101b (FD-15, FD-19; T-28b custom key, T-30) -- the out-of-date guard. Only story 0108 will
// ever set or clear invoice_out_of_date_at; here every order reads "not out of date".

use App\Actions\Invoices\AssertInvoicesAreCurrent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Run the guard the way an issuing action does: on the order re-read under its row lock.
 *
 * @return array<string, array<int, string>>|null the validation errors, or null when the guard passed
 */
function currentGuardErrors(Order $order, ?string $key = null): ?array
{
    try {
        DB::transaction(function () use ($order, $key): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $key === null
                ? app(AssertInvoicesAreCurrent::class)($locked)
                : app(AssertInvoicesAreCurrent::class)($locked, $key);
        });
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return null;
}

test('an order built by the factory with no override is not out of date (T-30)', function () {
    $order = Order::factory()->create();

    expect($order->invoice_out_of_date_at)->toBeNull()
        ->and($order->fresh()->isInvoiceOutOfDate())->toBeFalse()
        ->and(DB::table('orders')->where('id', $order->id)->value('invoice_out_of_date_at'))->toBeNull();
});

test('every existing kind of order reads not out of date and passes the guard (T-30)', function (OrderStatus $status, PaymentStatus $payment) {
    $order = Order::factory()->create(['status' => $status, 'payment_status' => $payment]);

    expect($order->isInvoiceOutOfDate())->toBeFalse()
        ->and(currentGuardErrors($order))->toBeNull();
})->with([
    'pending, unpaid' => [OrderStatus::Pending, PaymentStatus::PendingPayment],
    'pending, paid' => [OrderStatus::Pending, PaymentStatus::Paid],
    'cancelled' => [OrderStatus::Cancelled, PaymentStatus::PendingPayment],
]);

test('an order with the out-of-date moment set reports it and the column is cast to a date (T-30)', function () {
    $order = Order::factory()->create();
    $order->forceFill(['invoice_out_of_date_at' => now()])->save();

    expect($order->fresh()->isInvoiceOutOfDate())->toBeTrue()
        ->and($order->fresh()->invoice_out_of_date_at)->toBeInstanceOf(CarbonInterface::class);
});

test('clearing the column makes the order current again (T-30)', function () {
    $order = Order::factory()->create(['invoice_out_of_date_at' => now()]);
    $order->forceFill(['invoice_out_of_date_at' => null])->save();

    expect($order->fresh()->isInvoiceOutOfDate())->toBeFalse()
        ->and(currentGuardErrors($order))->toBeNull();
});

test('the guard refuses an out-of-date order on the default invoice key (T-30, FD-19)', function () {
    $order = Order::factory()->create(['invoice_out_of_date_at' => now()]);

    $errors = currentGuardErrors($order);

    expect($errors)->toBeArray()
        ->and(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBeString()->not->toBe('');
});

test('the guard raises its error on the custom key the caller passes and on no other (T-28b, FD-19)', function (string $key) {
    $order = Order::factory()->create(['invoice_out_of_date_at' => now()]);

    $errors = currentGuardErrors($order, $key);

    expect($errors)->toBeArray()
        ->and(array_keys($errors))->toBe([$key]);
})->with(['items', 'amount', 'status']);

test('a current order passes the guard whatever key is passed (T-28b)', function () {
    $order = Order::factory()->create();

    expect(currentGuardErrors($order, 'items'))->toBeNull();
});
