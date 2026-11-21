<?php

namespace App\Actions\Invoices;

use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AutomaticInvoiceFailed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Story 0105 FD-8 -- notifies the holders of `invoices.create` or `invoices.send` that the
 * automatic invoicing of an order failed at one stage. A system collaborator of
 * IssueAutomaticInvoice, the only class that calls it; it authorizes nothing.
 *
 * Recipients are resolved live, active accounts only; Super Admin holds no permission rows and is
 * excluded exactly as in NotifyOrderCreated. At most one notification per stage per hour
 * (`Cache::add`). With no recipient it only logs. The payload never carries the exception text.
 */
class NotifyAutomaticInvoiceFailed
{
    private const DEDUPE_MINUTES = 60;

    public function __invoke(Order $order, string $stage, Throwable $cause): void
    {
        if (! Cache::add('invoices.automatic-failed.'.$stage, true, now()->addMinutes(self::DEDUPE_MINUTES))) {
            return;
        }

        $recipients = User::permission(['invoices.create', 'invoices.send'])
            ->where('status', UserStatus::Active)
            ->get();

        if ($recipients->isEmpty()) {
            Log::warning('Automatic invoicing failed and nobody holds invoices.create or invoices.send to be told.', [
                'order_id' => $order->id,
                'stage' => $stage,
            ]);

            return;
        }

        Notification::send($recipients, new AutomaticInvoiceFailed(
            $order->id,
            $order->order_number,
            $stage,
            $this->reasonKey($stage, $cause),
        ));
    }

    /**
     * A fixed key under `invoices.automatic.reasons`, derived from the kind of cause only.
     */
    private function reasonKey(string $stage, Throwable $cause): string
    {
        if ($cause instanceof ValidationException) {
            $errors = $cause->errors();

            return match (true) {
                array_key_exists('issuer', $errors) => 'issuer_incomplete',
                array_key_exists('number', $errors) => 'number_collision',
                array_key_exists('customer', $errors) => 'customer_deleted',
                default => $stage === 'send' ? 'send_failed' : 'unexpected',
            };
        }

        if ($cause instanceof QueryException && in_array($cause->errorInfo[1] ?? null, [1213, 1205], true)) {
            return 'lock_timeout';
        }

        return $stage === 'send' ? 'send_failed' : 'unexpected';
    }
}
