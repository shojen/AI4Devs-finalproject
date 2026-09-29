<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Story 0043 -- a statement of fact about what happened (naming.md's
 * imperative-verb-phrase-free class naming for events), fired once per
 * eligible administrator when a customer record is created. See
 * App\Actions\Customers\NotifyCustomerCreated for recipient resolution.
 *
 * `database` channel only (D-2): the PRD's requirement is an in-panel bell,
 * not outbound email, and a mail channel here would be an unasked-for
 * per-customer-creation send with no opt-out or throttle. Not ShouldQueue
 * (D-4): the whole "delivery" is a single local INSERT, cheaper than the
 * `jobs` row queueing it would cost.
 *
 * `SerializesModels` is used even though this class is never queued today
 * (Phase 4 security-audit finding F-3): D-2/D-4 both explicitly invite
 * adding a `mail` channel plus `ShouldQueue` later, and without this trait
 * that reversal would serialize the WHOLE hydrated `$customer` --
 * email/phone/every address column -- into `jobs.payload` in plaintext,
 * directly undermining D-5's own data-minimisation reasoning below. With
 * it, a future queued form stores only the model's key.
 *
 * No `type` discriminator inside `data` -- Laravel's DatabaseChannel
 * already writes this class's FQCN into `notifications.type`, so a second
 * copy in the JSON payload would be redundant state that can drift on a
 * class rename. No lang/ file: `data` stores structural values for the
 * (not-yet-built) viewer to render, never rendered copy of its own -- the
 * same keys-not-copy reasoning base-standards.md applies to config/.
 */
class CustomerCreated extends Notification
{
    use SerializesModels;

    public function __construct(private readonly Customer $customer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Deliberately excludes the customer's email (D-5): `data` is an
     * immutable JSON snapshot with no update path, so duplicating a PII
     * value whose canonical home is the `customers` row would create a
     * stale second copy that survives a later email change or a soft
     * delete. `customer_id` is what a future bell links with; `customer_name`
     * is the only human-readable label a "New customer: ..." entry needs.
     *
     * `customer_name` is itself personal data, kept here for exactly the
     * reason `email` is excluded — and the same consequence applies to it
     * (Phase 4 security-audit finding F-2): this row has no erasure path
     * (no `model:prune`, R-3) and survives a soft-deleted or edited
     * `customers` row, so a future erasure/retention story must account for
     * `notifications.data` as a second location holding a customer's name,
     * not only the `customers` table itself.
     *
     * @return array{customer_id: string, customer_name: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
        ];
    }
}
