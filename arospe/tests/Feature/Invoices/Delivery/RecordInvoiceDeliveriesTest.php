<?php

// Story 0104 FD-15 -- RecordInvoiceDeliveries: the transaction-bound, ungated collaborator that the
// two send actions and the automatic invoicing of 0105 (as the system) call.

use App\Actions\Invoices\RecordInvoiceDeliveries;
use App\Enums\InvoiceDeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\Order;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    Delivering::prepare();
});

/**
 * Run the collaborator the way an action does: inside a transaction, on the order read under its lock.
 *
 * @param  list<Invoice>  $invoices
 * @return list<InvoiceDelivery>
 */
function recordDeliveries(Order $order, array $invoices, ?User $sentBy): array
{
    return DB::transaction(function () use ($order, $invoices, $sentBy): array {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

        return app(RecordInvoiceDeliveries::class)($locked, $invoices, $sentBy);
    });
}

test('called as the system with no signed-in user and no permission, it records queued rows with a null sender and queues one mail (FD-15, T-31)', function () {
    $invoice = Delivering::invoice();
    $other = Delivering::invoice($invoice->order);
    auth()->logout();

    $deliveries = recordDeliveries($invoice->order, [$invoice, $other], null);

    expect($deliveries)->toHaveCount(2)
        ->and(collect($deliveries)->every(fn (InvoiceDelivery $delivery): bool => $delivery->sent_by === null && $delivery->status === InvoiceDeliveryStatus::Queued && $delivery->is_copy === false))->toBeTrue()
        ->and(collect($deliveries)->pluck('invoice_id')->all())->toEqualCanonicalizing([$invoice->id, $other->id]);

    Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
    Notification::assertSentOnDemand(InvoiceSent::class, function (InvoiceSent $notification, array $channels, $notifiable) use ($deliveries, $invoice): bool {
        $ids = Delivering::idsOf($notification);
        sort($ids);

        return $ids === collect($deliveries)->pluck('id')->sort()->values()->all()
            && $notifiable->routes['mail'] === Delivering::addressOf($invoice);
    });
});

test('it takes the actor it is given as the sender (FD-15)', function () {
    $actor = User::factory()->create();
    $invoice = Delivering::invoice();

    expect(recordDeliveries($invoice->order, [$invoice], $actor)[0]->sent_by)->toBe($actor->id);
});

test('it decides original or copy per invoice from the deliveries it finds under the lock (FD-1)', function () {
    $order = Delivering::invoice()->order;
    $never = Delivering::invoice($order);
    $sent = Delivering::invoice($order);
    Delivering::deliver($sent, 'sent');
    $failedOnly = Delivering::invoice($order);
    Delivering::deliver($failedOnly, 'failed');

    $byInvoice = collect(recordDeliveries($order, [$never, $sent, $failedOnly], null))->keyBy('invoice_id');

    expect($byInvoice[$never->id]->is_copy)->toBeFalse()
        ->and($byInvoice[$sent->id]->is_copy)->toBeTrue()
        ->and($byInvoice[$failedOnly->id]->is_copy)->toBeFalse();
});

test('it refuses to run outside a transaction and writes nothing (FD-15)', function () {
    $invoice = Delivering::invoice();
    $order = Order::query()->findOrFail($invoice->order_id);

    Numbering::withoutTransaction(function () use ($order, $invoice): void {
        expect(fn () => app(RecordInvoiceDeliveries::class)($order, [$invoice], null))->toThrow(LogicException::class);
    });

    expect(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
