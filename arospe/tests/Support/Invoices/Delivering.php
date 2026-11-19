<?php

namespace Tests\Support\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Spatie\LaravelPdf\FakePdfBuilder;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Story 0104 -- fixtures of the invoice e-mail delivery tests: the fakes every test of the folder
 * needs, invoices with lines on an order, deliveries in a given status, and the readers that turn the
 * faked notification and the faked PDF engine into plain values.
 */
final class Delivering
{
    /**
     * Seed roles and the store language, create complete issuer settings and fake the PDF engine
     * (and, by default, notifications).
     */
    public static function prepare(bool $issuer = true, bool $fakeNotifications = true): FakePdfBuilder
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        test()->seed(RolePermissionSeeder::class);
        test()->seed(StoreLanguageSeeder::class);

        if ($issuer) {
            Invoicing::settings();
        }

        if ($fakeNotifications) {
            Notification::fake();
        }

        return FakesPdf::fake();
    }

    /**
     * Sign in a user holding exactly the given permissions.
     */
    public static function sender(string ...$permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        test()->actingAs($user);

        return $user;
    }

    /**
     * A saved standard invoice with its lines, on the given order (a fresh one by default).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function invoice(?Order $order = null, array $overrides = []): Invoice
    {
        $order ??= Invoicing::order();

        $invoice = Invoice::factory()->forOrder($order)->create(PdfFixtures::attributes($overrides + ['number' => self::number('F')]));

        foreach (PdfFixtures::defaultLines() as $line) {
            InvoiceLine::factory()->create(['invoice_id' => $invoice->id] + $line);
        }

        return $invoice->fresh();
    }

    /**
     * A saved corrective of the invoice.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function corrective(Invoice $of, array $overrides = []): Invoice
    {
        return PdfFixtures::savedCorrective($of, $overrides + ['number' => self::number('R')]);
    }

    /**
     * A delivery row of the invoice in the given status (`queued`, `sent` or `failed`).
     */
    public static function deliver(Invoice $invoice, string $status = 'queued', bool $isCopy = false): InvoiceDelivery
    {
        $factory = InvoiceDelivery::factory();
        $factory = match ($status) {
            'sent' => $factory->sent(),
            'failed' => $factory->failed(),
            default => $factory->queued(),
        };

        return $factory->create([
            'invoice_id' => $invoice->id,
            'is_copy' => $isCopy,
            'recipient_email' => self::addressOf($invoice),
        ]);
    }

    /**
     * The e-mail address of the invoice's customer (soft-deleted customers included).
     */
    public static function addressOf(Invoice $invoice): string
    {
        return (string) $invoice->order->customer()->withTrashed()->value('email');
    }

    /**
     * Every InvoiceSent the faked notification channel recorded, oldest first.
     *
     * @return list<InvoiceSent>
     */
    public static function sentNotifications(): array
    {
        return array_values(Notification::sent(new AnonymousNotifiable, InvoiceSent::class)->all());
    }

    /**
     * The delivery ids carried by the notification, whatever the property's visibility.
     *
     * @return list<string>
     */
    public static function idsOf(InvoiceSent $notification): array
    {
        /** @var list<string> $ids */
        $ids = (new ReflectionProperty($notification, 'deliveryIds'))->getValue($notification);

        return $ids;
    }

    /**
     * Build the mail the way a worker does: inside the notification's own locale.
     */
    public static function mailOf(InvoiceSent $notification, string $to = 'customer@example.test'): MailMessage
    {
        $notifiable = (new AnonymousNotifiable)->route('mail', $to);

        $previous = app()->getLocale();
        app()->setLocale($notification->locale ?? $previous);

        try {
            return $notification->toMail($notifiable);
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * The PDFs the faked engine generated (attached), oldest first.
     *
     * @return list<PdfBuilder>
     */
    public static function generated(FakePdfBuilder $fake): array
    {
        return array_values((new ReflectionProperty(FakePdfBuilder::class, 'generatedPdfs'))->getValue($fake));
    }

    /**
     * The HTML a generated PDF was rendered from.
     */
    public static function htmlOf(PdfBuilder $pdf): string
    {
        return view($pdf->viewName, $pdf->viewData)->render();
    }

    /**
     * Run the closure and return the validation errors it raised.
     *
     * @param  Closure(): mixed  $callback
     * @return array<string, list<string>>
     */
    public static function refusal(Closure $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }

    private static function number(string $prefix): string
    {
        return $prefix.'-'.fake()->unique()->numerify('########');
    }
}
