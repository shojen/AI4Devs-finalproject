<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Story 0046 -- a statement of fact about what happened (naming.md's
 * imperative-verb-phrase-free class naming for events), fired once per
 * eligible administrator when an order record is created. See
 * App\Actions\Orders\NotifyOrderCreated for recipient resolution.
 *
 * A shape copy of App\Notifications\CustomerCreated (story 0043), not an
 * inheritance -- same reasoning, applied to a different model.
 *
 * `database` channel only: the PRD's requirement is an in-panel bell, not
 * outbound email, and a mail channel here would be an unasked-for
 * per-order-creation send with no opt-out or throttle. Not ShouldQueue: the
 * whole "delivery" is a single local INSERT, cheaper than the `jobs` row
 * queueing it would cost.
 *
 * `SerializesModels` is used even though this class is never queued today,
 * matching `CustomerCreated`'s own reasoning: without it, a future `mail`
 * channel + `ShouldQueue` reversal would serialize the WHOLE hydrated
 * `$order` -- every address column, every total -- into `jobs.payload` in
 * plaintext. With it, a future queued form stores only the model's key.
 *
 * No `type` discriminator inside `data` -- Laravel's DatabaseChannel already
 * writes this class's FQCN into `notifications.type`, so a second copy in
 * the JSON payload would be redundant state that can drift on a class
 * rename. No lang/ file: `data` stores structural values for the (not-yet-
 * built) viewer to render, never rendered copy of its own.
 */
class OrderCreated extends Notification
{
    use SerializesModels;

    public function __construct(private readonly Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * D-5: exactly three keys -- order_id, order_number, customer_name.
     * Deliberately excludes the order's total, its line items and every
     * customer field beyond the name: `data` is an immutable JSON snapshot
     * with no update path, so duplicating values whose canonical home is
     * the `orders`/`customers` rows would create a stale second copy.
     * `order_id` is what a future bell links with; `order_number` and
     * `customer_name` are the only human-readable labels a "New order: ..."
     * entry needs.
     *
     * `$this->order->customer->name` assumes the caller
     * (App\Actions\Orders\NotifyOrderCreated) has already resolved the
     * `customer` relation -- including against a soft-deleted customer,
     * per D-12 -- before constructing this notification, so no query runs
     * here and no query runs per recipient.
     *
     * @return array{order_id: string, order_number: string, customer_name: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_name' => $this->order->customer->name,
        ];
    }
}
