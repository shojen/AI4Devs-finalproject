<?php

namespace App\Actions\Invoices;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * The text stored in `invoice_deliveries.failure_reason` (story 0104 FD-10). The message of an
 * exception is never stored: it can carry the recipient address, a DSN, a token or a path, and no
 * pattern list can promise to catch them all. The reason is instead a fixed category chosen by the
 * exception class (plus a numeric SMTP code when the transport gave one), capped at 500 characters.
 */
class ClassifyDeliveryFailure
{
    public const MAX_LENGTH = 500;

    public function __invoke(Throwable $failure): string
    {
        return mb_substr($this->category($failure), 0, self::MAX_LENGTH);
    }

    private function category(Throwable $failure): string
    {
        if ($failure instanceof TransportExceptionInterface) {
            $code = $failure->getCode();

            return $code >= 200 && $code <= 599
                ? "Mail transport error (SMTP {$code})"
                : 'Mail transport error';
        }

        if ($failure instanceof MaxAttemptsExceededException || $failure instanceof TimeoutExceededException || $failure instanceof ProcessTimedOutException) {
            return 'Timed out';
        }

        if ($failure instanceof ProcessFailedException || str_starts_with($failure::class, 'Spatie\\Browsershot\\') || str_starts_with($failure::class, 'Spatie\\LaravelPdf\\')) {
            return 'PDF rendering failed';
        }

        return class_basename($failure);
    }
}
