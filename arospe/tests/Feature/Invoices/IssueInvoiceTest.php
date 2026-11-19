<?php

// Story 0101b -- issuing a standard invoice through the gated entry point (FD-6..FD-13, FD-19).
// Real actions and database; only the clock is arranged. Amounts are strings in every assertion.

use App\Actions\Invoices\AssertInvoicesAreCurrent;
use App\Actions\Invoices\IssueInvoice;
use App\Concerns\InvoiceValidationRules;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    Invoicing::defaultStoreLanguage('en');
    $this->travelTo(Numbering::at('2026-06-15 10:00:00'));
    $this->settings = Invoicing::settings();
    $this->actor = Invoicing::manager();
});

/**
 * Issue and return the validation errors of a refusal, or [] when it was issued.
 *
 * @return array<string, list<string>>
 */
function issueErrors(Order $order, ?string $amount = null): array
{
    try {
        app(IssueInvoice::class)($order, $amount);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

// T-1
test('issuing without an amount invoices the whole uninvoiced amount with the next number, the actor and a blank lifecycle (T-1)', function () {
    $order = Invoicing::thousandOrder();

    $invoice = app(IssueInvoice::class)($order);

    expect($invoice->type)->toBe(InvoiceType::Standard)
        ->and($invoice->total)->toBe('1000.00')
        ->and($invoice->number)->toBe('F2026-00001')
        ->and($invoice->series)->toBe('standard')
        ->and($invoice->sequence_year)->toBe(2026)
        ->and($invoice->sequence_number)->toBe(1)
        ->and($invoice->issued_at->equalTo(now()))->toBeTrue()
        ->and($invoice->issued_by)->toBe($this->actor->id)
        ->and($invoice->order_id)->toBe($order->id)
        ->and($invoice->reason)->toBeNull()
        ->and($invoice->corrects_invoice_id)->toBeNull()
        ->and($invoice->voided_at)->toBeNull()
        ->and($invoice->collected_at)->toBeNull()
        ->and($invoice->collected_by)->toBeNull()
        ->and($invoice->collection_payment_method_id)->toBeNull()
        ->and($invoice->collection_type)->toBeNull()
        ->and($invoice->collection_carried_over)->toBeFalse()
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1);
});

// T-2
test('an invoice for the whole order is itemised and copies the order stored amounts exactly (T-2)', function () {
    $order = Invoicing::order([['Ceramic mug', 'MUG-1', 2, '10.00'], ['Teapot', 'POT-9', 1, '45.50']], '21.000', '4.95');

    $invoice = app(IssueInvoice::class)($order);
    $lines = $invoice->lines()->orderBy('position')->get();

    expect($invoice->subtotal)->toBe($order->subtotal)
        ->and($invoice->tax_amount)->toBe($order->tax_amount)
        ->and($invoice->shipping_amount)->toBe('4.95')
        ->and($invoice->total)->toBe($order->total)
        ->and($invoice->tax_rate)->toBe('21.000')
        ->and($lines)->toHaveCount(2)
        ->and($lines->pluck('kind')->all())->toBe([InvoiceLineKind::Item, InvoiceLineKind::Item])
        ->and($lines->pluck('position')->all())->toBe([1, 2])
        ->and($lines->pluck('description')->sort()->values()->all())->toBe(['Ceramic mug', 'Teapot'])
        ->and($lines->pluck('sku')->sort()->values()->all())->toBe(['MUG-1', 'POT-9'])
        ->and($lines->pluck('order_item_id')->filter()->count())->toBe(2)
        ->and(Invoicing::sum($lines, 'line_subtotal'))->toBe($invoice->subtotal)
        ->and(Invoicing::sum($lines, 'tax_amount'))->toBe($order->tax_amount);

    $mug = $lines->firstWhere('sku', 'MUG-1');
    expect($mug->quantity)->toBe(2)
        ->and($mug->unit_price)->toBe('10.00')
        ->and($mug->line_subtotal)->toBe('20.00');
});

// T-3
test('five invoices of 200.00 on a 1000.00 order are numbered, concept lines, add up per component, and a sixth is refused (T-3)', function () {
    $order = Invoicing::thousandOrder();

    $invoices = collect(range(1, 5))->map(fn (): Invoice => app(IssueInvoice::class)($order, '200.00'));

    expect($invoices->pluck('number')->all())->toBe(['F2026-00001', 'F2026-00002', 'F2026-00003', 'F2026-00004', 'F2026-00005'])
        ->and(Invoicing::sum($invoices, 'total'))->toBe('1000.00')
        ->and(Invoicing::sum($invoices, 'subtotal'))->toBe('800.00')
        ->and(Invoicing::sum($invoices, 'tax_amount'))->toBe('168.00')
        ->and(Invoicing::sum($invoices, 'shipping_amount'))->toBe('32.00');

    foreach ($invoices as $invoice) {
        $line = $invoice->lines()->sole();

        expect($line->kind)->toBe(InvoiceLineKind::Adjustment)
            ->and($line->order_item_id)->toBeNull()
            ->and($line->quantity)->toBe(1)
            ->and($line->description)->toBe("Payment on account of order {$order->order_number}")
            ->and($line->line_subtotal)->toBe($invoice->subtotal)
            ->and($invoice->subtotal)->toBe(bcsub(bcsub($invoice->total, $invoice->tax_amount, 2), $invoice->shipping_amount, 2));
    }

    expect(issueErrors($order, '200.00'))->toHaveKey('invoice')
        ->and(Invoice::query()->count())->toBe(5);
});

test('a partial invoice of 200.00 on a 1000.00 order splits base, tax and shipping in proportion (T-3)', function () {
    $invoice = app(IssueInvoice::class)(Invoicing::thousandOrder(), '200.00');

    expect($invoice->subtotal)->toBe('160.00')
        ->and($invoice->tax_amount)->toBe('33.60')
        ->and($invoice->shipping_amount)->toBe('6.40')
        ->and($invoice->total)->toBe('200.00');
});

// T-4
test('invoices of an order add up to its base, tax and shipping to the cent whatever the partial amounts (T-4)', function (array $lines, string $shipping, array $amounts) {
    $order = Invoicing::order($lines, '20.000', $shipping);
    $invoices = collect($amounts)->map(fn (string $amount): Invoice => app(IssueInvoice::class)($order, $amount));

    foreach ($invoices as $invoice) {
        expect(bcadd(bcadd($invoice->subtotal, $invoice->tax_amount, 2), $invoice->shipping_amount, 2))->toBe($invoice->total);
    }

    expect(Invoicing::sum($invoices, 'total'))->toBe($order->total)
        ->and(Invoicing::sum($invoices, 'subtotal'))->toBe($order->subtotal)
        ->and(Invoicing::sum($invoices, 'tax_amount'))->toBe($order->tax_amount)
        ->and(Invoicing::sum($invoices, 'shipping_amount'))->toBe($order->shipping_amount);
})->with([
    'thirds' => [[['Rug', 'RUG', 1, '100.00']], '0.00', ['40.00', '40.00', '40.00']],
    'one cent then the rest' => [[['Rug', 'RUG', 1, '100.00']], '5.00', ['0.01', '124.99']],
    'a cent short then a cent' => [[['Rug', 'RUG', 1, '833.33']], '0.00', ['999.99', '0.01']],
    'a euro at a time on 7.00' => [[['Rug', 'RUG', 1, '5.00']], '1.00', ['1.00', '1.00', '1.00', '1.00', '1.00', '1.00', '1.00']],
]);

// T-5b
test('an order whose stored tax differs from a fresh recomputation is invoiced with the stored value (T-5b)', function () {
    $order = Invoicing::order([['Rug', 'RUG', 1, '100.00']], '21.000', '0.00', ['tax_amount' => '19.99']);

    $invoice = app(IssueInvoice::class)($order);

    expect($invoice->tax_amount)->toBe('19.99')
        ->and($invoice->total)->toBe('119.99')
        ->and(Invoicing::sum($invoice->lines()->get(), 'tax_amount'))->toBe('19.99');
});

// T-6
test('an order without a tax rate is invoiced with zero tax on the invoice and its lines and a null rate (T-6)', function () {
    $order = Invoicing::order([['Rug', 'RUG', 2, '30.00']], null, '3.00');

    $invoice = app(IssueInvoice::class)($order);
    $line = $invoice->lines()->sole();

    expect($invoice->tax_rate)->toBeNull()
        ->and($invoice->tax_amount)->toBe('0.00')
        ->and($invoice->total)->toBe('63.00')
        ->and($line->tax_rate)->toBeNull()
        ->and($line->tax_amount)->toBe('0.00')
        ->and($line->line_total)->toBe('60.00');
});

// T-7
test('a partially refunded order invoiced for its whole billable amount gets one concept line (T-7)', function () {
    $order = Invoicing::order([['Mug', 'MUG', 3, '10.00']], '10.000', '0.00', ['refunded_amount' => '10.00']);

    $invoice = app(IssueInvoice::class)($order);
    $line = $invoice->lines()->sole();

    expect($invoice->total)->toBe('23.00')
        ->and($line->kind)->toBe(InvoiceLineKind::Adjustment)
        ->and($line->order_item_id)->toBeNull()
        ->and($line->quantity)->toBe(1);
});

// T-8
test('when every earlier invoice is voided the order is invoiced whole again and itemised (T-8)', function () {
    $order = Invoicing::thousandOrder();
    $earlier = Invoice::factory()->standard()->forOrder($order)->create([
        'subtotal' => '800.00', 'tax_amount' => '168.00', 'shipping_amount' => '32.00', 'total' => '1000.00',
        'voided_at' => now()->subDay(),
    ]);
    Invoice::factory()->corrective($earlier)->forOrder($order)->create([
        'subtotal' => '-800.00', 'tax_amount' => '-168.00', 'shipping_amount' => '-32.00', 'total' => '-1000.00',
    ]);

    $invoice = app(IssueInvoice::class)($order);

    expect($invoice->total)->toBe('1000.00')
        ->and($invoice->lines()->sole()->kind)->toBe(InvoiceLineKind::Item)
        ->and($invoice->lines()->sole()->description)->toBe('Large vase');
});

// T-10
test('the invoice freezes issuer, recipient, address, look and order number as they are at issue time (T-10)', function () {
    $order = Invoicing::thousandOrder();

    $invoice = app(IssueInvoice::class)($order);

    expect($invoice->issuer_legal_name)->toBe('Arospe Handmade Sociedad Limitada')
        ->and($invoice->issuer_tax_id)->toBe('ESB12345678')
        ->and($invoice->issuer_address_line1)->toBe('Calle Mayor 1')
        ->and($invoice->issuer_address_line2)->toBe('Piso 2')
        ->and($invoice->issuer_city)->toBe('Madrid')
        ->and($invoice->issuer_postal_code)->toBe('28001')
        ->and($invoice->issuer_province)->toBe('Madrid')
        ->and($invoice->issuer_country)->toBe('ES')
        ->and($invoice->recipient_name)->toBe('Lucia Perez')
        ->and($invoice->recipient_tax_id)->toBe('B-12345678')
        ->and($invoice->recipient_address_line1)->toBe('Calle Falsa 123')
        ->and($invoice->recipient_address_line2)->toBe('Puerta B')
        ->and($invoice->recipient_city)->toBe('Sevilla')
        ->and($invoice->recipient_postal_code)->toBe('41001')
        ->and($invoice->recipient_province)->toBe('Sevilla')
        ->and($invoice->recipient_country)->toBe('ES')
        ->and($invoice->template)->toBe('modern')
        ->and($invoice->primary_color)->toBe('#112233')
        ->and($invoice->accent_color)->toBe('#AABBCC')
        ->and($invoice->logo_media_id)->toBeNull()
        ->and($invoice->order_number)->toBe($order->order_number);
});

test('the recipient address comes from the order billing snapshot, not from the customer current address (T-10)', function () {
    $order = Invoicing::thousandOrder();
    $order->customer->forceFill(['billing_address_line1' => 'Avenida Nueva 9', 'billing_city' => 'Bilbao'])->save();

    $invoice = app(IssueInvoice::class)($order);

    expect($invoice->recipient_address_line1)->toBe('Calle Falsa 123')
        ->and($invoice->recipient_city)->toBe('Sevilla');
});

// T-14
test('a valid amount format is accepted and normalised to two decimals (T-14)', function (string $given, string $expected) {
    $invoice = app(IssueInvoice::class)(Invoicing::thousandOrder(), $given);

    expect($invoice->total)->toBe($expected);
})->with([
    'one cent' => ['0.01', '0.01'],
    'the whole amount' => ['1000.00', '1000.00'],
    'no decimals' => ['200', '200.00'],
    'one decimal' => ['200.5', '200.50'],
]);

test('a malformed or out of range amount is refused on amount and moves no counter (T-14)', function (string $given) {
    $order = Invoicing::thousandOrder();
    $before = Numbering::snapshot();

    expect(array_keys(issueErrors($order, $given)))->toBe(['amount'])
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
})->with([
    'zero' => '0.00',
    'negative' => '-0.01',
    'above the order' => '1000.01',
    'three decimals' => '200.005',
    'leading space' => ' 200.00',
    'exponent' => '2e2',
    'comma decimal' => '200,50',
    'letters' => 'abc',
    'empty string' => '',
    'infinity' => 'INF',
    'above the column ceiling' => '100000000.00',
]);

// T-15
test('an order with nothing billable is refused as nothing left to invoice (T-15)', function (array $overrides) {
    $order = Invoicing::order([['Mug', 'MUG', 2, '10.00']], '0.000', '0.00', $overrides);

    expect(array_keys(issueErrors($order)))->toBe(['invoice'])
        ->and(Invoice::query()->count())->toBe(0);
})->with([
    'fully refunded merchandise' => [['refunded_amount' => '20.00']],
    'zero order' => [['subtotal' => '0.00', 'total' => '0.00']],
]);

// T-16
test('an order refunded below what is already invoiced has nothing left to invoice (T-16)', function () {
    $order = Invoicing::order([['Mug', 'MUG', 12, '10.00']], '0.000');
    app(IssueInvoice::class)($order);
    $order->forceFill(['refunded_amount' => '50.00'])->save();

    expect(array_keys(issueErrors($order->refresh(), '0.01')))->toBe(['invoice'])
        ->and(Invoice::query()->count())->toBe(1);
});

// T-17
test('the issue moment decides issued_at and the number year across a year turn (T-17)', function () {
    $this->travelTo(Numbering::at('2026-12-31 23:59:59'));
    $first = app(IssueInvoice::class)(Invoicing::thousandOrder(), '100.00');
    $this->travelTo(Numbering::at('2027-01-01 00:00:00'));
    $second = app(IssueInvoice::class)(Invoicing::thousandOrder(), '100.00');

    expect($first->number)->toBe('F2026-00001')
        ->and($first->issued_at->format('Y-m-d H:i:s'))->toBe('2026-12-31 23:59:59')
        ->and($second->number)->toBe('F2027-00001')
        ->and($second->issued_at->format('Y-m-d H:i:s'))->toBe('2027-01-01 00:00:00')
        ->and((new ReflectionMethod(IssueInvoice::class, '__invoke'))->getNumberOfParameters())->toBe(2);
});

// T-21
test('a soft deleted customer does not stop issuing and a null customer tax ID leaves the recipient tax ID null (T-21)', function () {
    $customer = Customer::factory()->create(['name' => 'Gone Customer', 'tax_id' => null]);
    $order = Invoicing::order([['Large vase', 'VASE-1', 1, '800.00']], '21.000', '32.00', [], $customer);
    $customer->delete();

    $invoice = app(IssueInvoice::class)($order->refresh());

    expect($invoice->recipient_name)->toBe('Gone Customer')
        ->and($invoice->recipient_tax_id)->toBeNull();
});

// T-28
test('missing issuer data is refused on issuer with the dedicated message and consumes no number (T-28)', function (array $settingsOverride) {
    $this->settings->forceFill($settingsOverride)->save();
    $order = Invoicing::thousandOrder();
    $before = Numbering::snapshot();

    $errors = issueErrors($order);

    expect(array_keys($errors))->toBe(['issuer'])
        ->and($errors['issuer'][0])->toBe(__('invoices.errors.issuer_incomplete'))
        ->and($errors['issuer'][0])->not->toBe('invoices.errors.issuer_incomplete')
        ->and(Numbering::snapshot())->toBe($before);
})->with([
    'legal name' => [['issuer_legal_name' => null]],
    'tax ID' => [['issuer_tax_id' => null]],
    'address line 1' => [['issuer_address_line1' => null]],
    'empty legal name' => [['issuer_legal_name' => '']],
]);

test('a store without a settings row is refused on issuer (T-28)', function () {
    $this->settings->delete();

    expect(array_keys(issueErrors(Invoicing::thousandOrder())))->toBe(['issuer']);
});

test('an empty city and country do not stop issuing (T-28)', function () {
    $this->settings->forceFill(['issuer_city' => null, 'issuer_country' => null, 'issuer_postal_code' => null])->save();

    $invoice = app(IssueInvoice::class)(Invoicing::thousandOrder());

    expect($invoice->issuer_city)->toBeNull()->and($invoice->issuer_country)->toBeNull();
});

// T-28b
test('each refusal raises exactly its own error key (T-28b)', function (string $scenario, string $expectedKey) {
    $order = Invoicing::thousandOrder();
    $amount = null;

    match ($scenario) {
        'issuer' => $this->settings->forceFill(['issuer_tax_id' => null])->save(),
        'cancelled' => $order->forceFill(['status' => 'cancelled'])->save(),
        'out of date' => $order->forceFill(['invoice_out_of_date_at' => now()])->save(),
        'nothing left' => app(IssueInvoice::class)($order),
        'amount above remaining' => $amount = '1000.01',
        'amount below minimum' => $amount = '0.00',
        'collision' => Invoice::factory()->standard()->forOrder(Invoicing::thousandOrder())->create(['number' => 'F2026-00001']),
    };

    expect(array_keys(issueErrors($order->refresh(), $amount)))->toBe([$expectedKey]);
})->with([
    ['issuer', 'issuer'],
    ['cancelled', 'invoice'],
    ['out of date', 'invoice'],
    ['nothing left', 'invoice'],
    ['amount above remaining', 'amount'],
    ['amount below minimum', 'amount'],
    ['collision', 'number'],
]);

test('AssertInvoicesAreCurrent raises on the invoice key by default and on a custom key when given one (T-28b)', function () {
    $order = Invoicing::thousandOrder(['invoice_out_of_date_at' => now()]);
    $assert = fn (?string $key): array => (function () use ($order, $key): array {
        try {
            DB::transaction(function () use ($order, $key): void {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $key === null ? app(AssertInvoicesAreCurrent::class)($locked) : app(AssertInvoicesAreCurrent::class)($locked, $key);
            });
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    })();

    expect($assert(null))->toBe(['invoice'])
        ->and($assert('items'))->toBe(['items']);
});

test('AssertInvoicesAreCurrent passes silently for an order that is not out of date (T-28b)', function () {
    $order = Invoicing::thousandOrder();

    DB::transaction(function () use ($order): void {
        app(AssertInvoicesAreCurrent::class)(Order::query()->lockForUpdate()->findOrFail($order->getKey()));
    });

    expect($order->refresh()->isInvoiceOutOfDate())->toBeFalse();
});

// FD-6 locale
test('the concept line is written in the default store language, never in the actor UI locale (FD-6)', function (string $uiLocale, string $storeLanguage, string $expected) {
    Invoicing::defaultStoreLanguage($storeLanguage);
    $this->actor->forceFill(['ui_locale' => $uiLocale])->save();
    $order = Invoicing::thousandOrder();
    $ambient = app()->getLocale();

    $line = app(IssueInvoice::class)($order, '200.00')->lines()->sole();

    expect($line->description)->toBe($expected.$order->order_number)
        ->and(app()->getLocale())->toBe($ambient);
})->with([
    'es UI, en store' => ['es', 'en', 'Payment on account of order '],
    'en UI, es store' => ['en', 'es', 'Pago a cuenta del pedido '],
]);

// T-24
test('a number collision is a validation error on number, rolls everything back and consumes no counter (T-24)', function () {
    Invoice::factory()->standard()->forOrder(Invoicing::thousandOrder())->create(['number' => 'f2026-00001']);
    $order = Invoicing::thousandOrder();
    $before = Numbering::snapshot();
    $invoicesBefore = Invoice::query()->count();

    $errors = issueErrors($order, '100.00');

    expect(array_keys($errors))->toBe(['number'])
        ->and(Invoice::query()->count())->toBe($invoicesBefore)
        ->and(InvoiceLine::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
});

// T-24
test('a unique violation on another index propagates as a QueryException, not a validation error (T-24)', function () {
    $order = Invoicing::thousandOrder();
    $armed = true;

    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'insert into `invoices`')) {
            $armed = false;

            throw new QueryException(
                DB::getDefaultConnection(),
                $query->sql,
                $query->bindings,
                new PDOException("Duplicate entry 'x' for key 'invoices.some_other_unique'", 1062),
            );
        }
    });

    $thrown = null;

    try {
        app(IssueInvoice::class)($order, '100.00');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and(Invoice::query()->count())->toBe(0);
});

// T-13
test('an exception thrown after a successful issue leaves invoice, lines and counter committed and consistent (T-13)', function () {
    $order = Invoicing::thousandOrder();

    try {
        app(IssueInvoice::class)($order, '100.00');

        throw new RuntimeException('caller failed after the transaction returned');
    } catch (RuntimeException) {
        // The caller's failure happens outside the issuing transaction.
    }

    expect(Invoice::query()->count())->toBe(1)
        ->and(InvoiceLine::query()->count())->toBe(1)
        ->and(Numbering::row(InvoiceType::Standard)->last_number)->toBe(1);
});

// T-14
test('the amount rules reject an array amount on the amount key (T-14)', function () {
    $rules = (new class
    {
        use InvoiceValidationRules;

        /** @return array<int, mixed> */
        public function rules(): array
        {
            return $this->invoiceAmountRules();
        }
    })->rules();

    $validator = Validator::make(['amount' => ['1']], ['amount' => $rules]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toBe(['amount']);
});

// T-29
test('a cancelled order is refused, also when a full refund left billable above zero (T-29)', function (array $overrides) {
    $order = Invoicing::order([['Mug', 'MUG', 2, '10.00']], '21.000', '5.00', array_replace(['status' => 'cancelled'], $overrides));

    expect(array_keys(issueErrors($order)))->toBe(['invoice'])
        ->and(Invoice::query()->count())->toBe(0);
})->with([
    'cancelled' => [[]],
    'cancelled after a full merchandise refund' => [['refunded_amount' => '20.00']],
]);

// T-31
test('an amount above what is left is refused and the message states the remaining amount (T-31)', function (string $priorInvoice, string $attempt, string $remaining) {
    $order = Invoicing::thousandOrder();

    if ($priorInvoice !== '0.00') {
        app(IssueInvoice::class)($order, $priorInvoice);
    }

    $errors = issueErrors($order, $attempt);

    expect(array_keys($errors))->toBe(['amount'])
        ->and($errors['amount'][0])->toContain($remaining);
})->with([
    'just above' => ['0.00', '1000.01', '1000.00'],
    'double' => ['0.00', '2000.00', '1000.00'],
    'one cent above after a prior invoice' => ['900.00', '100.01', '100.00'],
    'the original amount after a prior invoice' => ['900.00', '200.00', '100.00'],
]);

// T-32
test('a missing customer row is refused and leaves no partial rows (T-32)', function () {
    $order = Invoicing::thousandOrder();
    $before = Numbering::snapshot();

    Schema::disableForeignKeyConstraints();
    DB::table('customers')->where('id', $order->customer_id)->delete();
    Schema::enableForeignKeyConstraints();

    expect(array_keys(issueErrors($order->refresh())))->toBe(['invoice'])
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceLine::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
});

// T-37
test('a product name with markup is stored raw and description and SKU keep the source lengths (T-37)', function () {
    $name = '<b>'.str_repeat('N', 252);
    $sku = str_repeat('S', 128);
    $order = Invoicing::order([[$name, $sku, 1, '10.00']], '21.000');

    $line = app(IssueInvoice::class)($order)->lines()->sole();

    expect($line->description)->toBe($name)
        ->and(mb_strlen($line->description))->toBe(255)
        ->and($line->sku)->toBe($sku);

    $markup = Invoicing::order([['<script>alert(1)</script>', 'X-1', 1, '10.00']], '21.000');

    expect(app(IssueInvoice::class)($markup)->lines()->sole()->description)->toBe('<script>alert(1)</script>');
});
