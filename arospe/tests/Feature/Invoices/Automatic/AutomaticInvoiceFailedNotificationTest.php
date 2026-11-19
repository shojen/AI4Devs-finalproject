<?php

// Story 0105 T-20..T-23, T-27 and the en/es parity of invoices.automatic.* -- who is told when the
// automatic invoicing fails, with what payload, how often, and what happens when telling fails.

use App\Actions\Invoices\IssueInvoiceForOrder;
use App\Actions\Invoices\NotifyAutomaticInvoiceFailed;
use App\Enums\UserStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Tests\Support\Invoices\Automating;
use Tests\Support\Invoices\Invoicing;

beforeEach(function () {
    Cache::flush();
    Automating::prepare(fakeNotifications: false);
    $this->order = Invoicing::order();
});

function notifyFailure(Order $order, string $stage = 'issue', ?Throwable $cause = null): void
{
    app(NotifyAutomaticInvoiceFailed::class)($order, $stage, $cause ?? new RuntimeException('boom'));
}

// --- T-20 ---

test('five failures of one stage within an hour notify each recipient once, another stage notifies again and a new hour notifies again (T-20)', function () {
    $recipient = Automating::holder('invoices.create');

    foreach (range(1, 5) as $unused) {
        notifyFailure($this->order, 'issue');
    }

    expect(Automating::failures('issue'))->toHaveCount(1)
        ->and(Automating::failures('issue')->first()->notifiable_id)->toBe($recipient->id);

    notifyFailure($this->order, 'send');

    expect(Automating::failures('send'))->toHaveCount(1);

    $this->travel(61)->minutes();
    notifyFailure($this->order, 'issue');

    expect(Automating::failures('issue'))->toHaveCount(2);
});

// --- T-21 ---

test('the recipients are exactly the holders of invoices.create or invoices.send (T-21)', function () {
    $creator = Automating::holder('invoices.create');
    $sender = Automating::holder('invoices.send');
    $both = Automating::holder('invoices.create', 'invoices.send');
    $viewer = Automating::holder('invoices.view', 'orders.view');
    $inactive = Automating::holder('invoices.create');
    $inactive->forceFill(['status' => UserStatus::Inactive])->save();
    $deleted = Automating::holder('invoices.send');
    $deleted->delete();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    notifyFailure($this->order);

    $notified = Automating::failures()->pluck('notifiable_id')->sort()->values()->all();

    expect($notified)->toBe(collect([$creator->id, $sender->id, $both->id])->sort()->values()->all())
        ->and(Automating::failures()->pluck('notifiable_id')->contains($viewer->id))->toBeFalse()
        ->and(Automating::failures()->pluck('notifiable_id')->contains($inactive->id))->toBeFalse()
        ->and(Automating::failures()->pluck('notifiable_id')->contains($deleted->id))->toBeFalse()
        ->and(Automating::failures()->pluck('notifiable_id')->contains($superAdmin->id))->toBeFalse();
});

// --- T-22 ---

test('the stored payload has exactly the four keys and never carries the exception message (T-22)', function (string $secret) {
    Automating::holder('invoices.create');

    notifyFailure($this->order, 'send', new RuntimeException($secret));

    $data = Automating::failures()->sole()->data;

    expect(array_keys($data))->toEqualCanonicalizing(['order_id', 'order_number', 'stage', 'reason_key'])
        ->and($data['order_id'])->toBe($this->order->id)
        ->and($data['order_number'])->toBe($this->order->order_number)
        ->and($data['stage'])->toBe('send')
        ->and($data['reason_key'])->toBeString()->toMatch('/^[a-z0-9_.]+$/')
        ->and(json_encode($data))->not->toContain($secret);
})->with([
    'SQL' => ['SQLSTATE[23000]: insert into `invoices` (`issuer_tax_id`) values (ESB12345678)'],
    'a path' => ['file_put_contents(/var/www/arospe/storage/app/private/secret.pdf) failed'],
    'a tax ID' => ['Customer tax ID B-12345678 is invalid'],
    'an SMTP credential' => ['smtp://mailer:s3cr3t-p4ss@smtp.example.test:587 refused'],
]);

// --- T-23 ---

test('a failure notification that itself throws never reaches CreateOrder and the cause is logged (T-23)', function () {
    Automating::holder('invoices.create');
    Automating::orderCreator();
    $messages = [];

    Log::listen(function (MessageLogged $event) use (&$messages): void {
        $messages[] = $event->message;
    });

    $this->mock(NotifyAutomaticInvoiceFailed::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('notifier down'));
    $this->mock(IssueInvoiceForOrder::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('issue exploded 0105'));

    $order = Automating::createOrder();

    expect($order->exists)->toBeTrue()
        ->and(Invoice::query()->count())->toBe(0)
        ->and(collect($messages)->contains(fn (string $message): bool => str_contains($message, 'issue exploded 0105')))->toBeTrue();
});

// --- T-27 ---

test('with nobody holding the permissions the failure is only logged and nothing throws (T-27)', function () {
    $messages = [];

    Log::listen(function (MessageLogged $event) use (&$messages): void {
        $messages[] = $event->message;
    });

    notifyFailure($this->order);

    expect(Automating::failures())->toHaveCount(0)
        ->and($messages)->not->toBe([]);
});

// --- lang parity ---

test('the invoices.automatic keys exist in en and es with the same keys and no empty copy (FD-8)', function () {
    expect(Lang::has('invoices.automatic', 'en', false))->toBeTrue()
        ->and(Lang::has('invoices.automatic', 'es', false))->toBeTrue();

    $en = Arr::dot((array) trans('invoices.automatic', [], 'en'));
    $es = Arr::dot((array) trans('invoices.automatic', [], 'es'));

    expect($en)->not->toBeEmpty()
        ->and(array_keys($es))->toEqualCanonicalizing(array_keys($en))
        ->and(collect($en)->every(fn ($value): bool => is_string($value) && $value !== ''))->toBeTrue()
        ->and(collect($es)->every(fn ($value): bool => is_string($value) && $value !== ''))->toBeTrue();
});
