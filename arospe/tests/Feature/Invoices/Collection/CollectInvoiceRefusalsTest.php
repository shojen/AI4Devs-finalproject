<?php

// Story 0106 (T-25, T-26, T-27) -- the state refusals of collect and undo (FD-5), keyed `invoice`,
// and the payment inputs (FD-6). Authorization always comes first (CollectInvoiceAuthorizationTest);
// here the caller is allowed, so every refusal is about the state or the inputs, and nothing is written.

use App\Actions\Invoices\CollectInvoice;
use App\Actions\Invoices\UndoInvoiceCollection;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Collecting::actor();
    $this->method = Collecting::method();
});

/**
 * The invoice of each collect refusal of FD-5.
 */
function collectRefusalInvoice(string $case): Invoice
{
    return match ($case) {
        'voided invoice' => Collecting::invoice(Collecting::order(), '600.00', false, ['voided_at' => now()]),
        'corrective' => Collecting::corrective(Collecting::invoice(Collecting::order(), '600.00'), '-100.00'),
        'already collected' => Collecting::invoice(Collecting::order(), '600.00', true),
        'cancelled order' => Collecting::invoice(Collecting::order('1000.00', ['status' => OrderStatus::Cancelled]), '600.00'),
        'out-of-date order' => Collecting::invoice(Collecting::order('1000.00', ['invoice_out_of_date_at' => now()]), '600.00'),
    };
}

/**
 * The invoice of each undo refusal of FD-5.
 */
function undoRefusalInvoice(string $case): Invoice
{
    return match ($case) {
        'not collected' => Collecting::invoice(Collecting::order(), '600.00'),
        'voided invoice' => Collecting::invoice(Collecting::order(), '600.00', true, ['voided_at' => now()]),
        'has a corrective' => (function (): Invoice {
            $invoice = Collecting::invoice(Collecting::order(), '600.00', true);
            Collecting::corrective($invoice, '-100.00');

            return $invoice;
        })(),
        'order has a refund' => Collecting::invoice(Collecting::order('1000.00', ['refunded_amount' => '100.00', 'payment_status' => PaymentStatus::PartiallyRefunded]), '600.00', true),
        'cancelled order' => Collecting::invoice(Collecting::order('1000.00', ['status' => OrderStatus::Cancelled]), '600.00', true),
        'out-of-date order' => Collecting::invoice(Collecting::order('1000.00', ['invoice_out_of_date_at' => now()]), '600.00', true),
    };
}

// T-25
test('collect refusals are validation errors on invoice and write nothing (T-25)', function (string $case) {
    $invoice = collectRefusalInvoice($case);
    $before = Collecting::snapshot($invoice->order);
    Log::spy();

    $errors = Collecting::errors(fn () => app(CollectInvoice::class)($invoice, $this->method, OrderPaymentType::Card));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBeString()->not->toBeEmpty()
        ->and(Collecting::snapshot($invoice->order))->toBe($before);

    Log::shouldNotHaveReceived('info');
})->with(['voided invoice', 'corrective', 'already collected', 'cancelled order', 'out-of-date order']);

test('collecting an invoice twice counts it once: the second call is refused and the amount is unchanged (T-25)', function () {
    $order = Collecting::order('1000.00');
    $invoice = Collecting::invoice($order, '600.00');
    app(CollectInvoice::class)($invoice, $this->method, OrderPaymentType::Card);
    $afterFirst = Collecting::snapshot($order);

    $errors = Collecting::errors(fn () => app(CollectInvoice::class)($invoice->fresh(), $this->method, OrderPaymentType::Card));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and(Collecting::snapshot($order))->toBe($afterFirst)
        ->and($order->fresh()->collected_amount)->toBe('600.00');
});

// T-26
test('undo refusals are validation errors on invoice and leave the collection columns unchanged (T-26)', function (string $case) {
    $invoice = undoRefusalInvoice($case);
    $before = Collecting::snapshot($invoice->order);
    Log::spy();

    $errors = Collecting::errors(fn () => app(UndoInvoiceCollection::class)($invoice));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBeString()->not->toBeEmpty()
        ->and(Collecting::snapshot($invoice->order))->toBe($before);

    Log::shouldNotHaveReceived('warning');
})->with(['not collected', 'voided invoice', 'has a corrective', 'order has a refund', 'cancelled order', 'out-of-date order']);

test('a collection that was refused an undo after a refund stays collected (T-26)', function () {
    $invoice = undoRefusalInvoice('order has a refund');

    Collecting::errors(fn () => app(UndoInvoiceCollection::class)($invoice));

    expect($invoice->fresh()->isCollected())->toBeTrue();
});

// T-27
test('a payment method that does not exist is refused and nothing is written (T-27)', function (Closure $missing) {
    $invoice = Collecting::invoice(Collecting::order(), '600.00');
    $before = Collecting::snapshot($invoice->order);

    expect(fn () => app(CollectInvoice::class)($invoice, $missing(), OrderPaymentType::Card))->toThrow(ValidationException::class)
        ->and(Collecting::snapshot($invoice->order))->toBe($before);
})->with([
    'never saved' => [fn (): PaymentMethod => new PaymentMethod],
    'unknown id' => [function (): PaymentMethod {
        $method = new PaymentMethod;
        $method->id = (string) Str::uuid7();
        $method->exists = true;

        return $method;
    }],
]);

test('a type that is not an OrderPaymentType is refused and nothing is written (T-27)', function (mixed $type) {
    $invoice = Collecting::invoice(Collecting::order(), '600.00');
    $before = Collecting::snapshot($invoice->order);

    expect(fn () => app(CollectInvoice::class)($invoice, $this->method, $type))->toThrow(TypeError::class)
        ->and(Collecting::snapshot($invoice->order))->toBe($before);
})->with([
    'crypto' => ['crypto'],
    'empty string' => [''],
    'null' => [null],
    'array' => [[OrderPaymentType::Card]],
]);

test('the three payment types are all accepted and stored by value (T-27)', function (OrderPaymentType $type) {
    $invoice = Collecting::invoice(Collecting::order(), '600.00');

    app(CollectInvoice::class)($invoice, $this->method, $type);

    expect($invoice->fresh()->collection_type)->toBe($type->value);
})->with(OrderPaymentType::cases());
