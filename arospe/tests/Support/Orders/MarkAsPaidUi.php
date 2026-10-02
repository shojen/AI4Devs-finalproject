<?php

namespace Tests\Support\Orders;

use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Story 0085 -- shared fixtures and DOM probes for the "Mark as paid" list and detail tests.
 *
 * Probes work on RENDERED html by `data-test` hook only (D-6): never by badge markup or class.
 * `mark-as-paid-{id}` is the list hook, `mark-as-paid` and `payment-info` the detail hooks.
 */
final class MarkAsPaidUi
{
    /**
     * A non-Super-Admin actor with exactly the given permissions and the given admin UI locale.
     * `OrdersUi::actor()` takes only permissions, so the locale is set afterwards.
     *
     * @param  array<int, string>  $permissions
     */
    public static function actor(array $permissions, string $locale = 'en'): User
    {
        $actor = OrdersUi::actor($permissions);
        $actor->forceFill(['ui_locale' => $locale])->save();

        return $actor;
    }

    /**
     * How many list-row "Mark as paid" buttons the html holds (the opening `data-test="mark-as-paid-`
     * prefix; the dialog's own hooks start with `confirm-dialog-` and never match).
     */
    public static function listButtons(string $html): int
    {
        return substr_count($html, 'data-test="mark-as-paid-');
    }

    /**
     * The html of one list row, from its row hook to the closing tag, or '' when absent.
     */
    public static function row(string $html, string $orderId): string
    {
        $start = strpos($html, 'data-test="order-row-'.$orderId.'"');

        if ($start === false) {
            return '';
        }

        return substr($html, $start, (int) strpos($html, '</tr>', $start) - $start);
    }

    /**
     * The confirmation dialog's inner content (heading, body, slot), or '' while it is closed.
     */
    public static function dialog(string $html, string $prefix = 'mark-as-paid'): string
    {
        $start = strpos($html, 'data-test="confirm-dialog-'.$prefix.'"');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, 'data-test="confirm-dialog-'.$prefix.'-confirm"', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    /**
     * The whitespace-normalised, tag-stripped text of the first element carrying the hook.
     */
    public static function text(string $html, string $hook): ?string
    {
        if (! preg_match('/data-test="'.preg_quote($hook, '/').'"[^>]*>(.*?)<\/(?:p|div|span)>/s', $html, $matches)) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($matches[1]), ENT_QUOTES)));
    }

    /**
     * The bank-transfer payment method, created when no order has needed it yet.
     */
    public static function bankTransfer(): PaymentMethod
    {
        return PaymentMethod::query()->where('code', 'bank_transfer')->first() ?? PaymentMethod::factory()->create();
    }
}
