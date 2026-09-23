<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An order: a customer plus one or more priced line items, with the price
 * frozen at the moment of ordering (story 0045, PRD §3.2).
 *
 * `#[Fillable]` lists the administrator-writable columns only.
 * Deliberately omitted, written via `forceFill()` from
 * App\Actions\Orders\CreateOrder alone: `order_number` (derived, D-1),
 * `status`, `payment_status`, `subtotal`, `tax_amount`, `shipping_amount`,
 * `total`, `tax_rate`, `flagged_for_review`, `sales_region_id`,
 * `shipping_rate_id`, and (story 0054) `ip_address`, `ip_derived_country`,
 * `flag_reason` -- a caller able to set its own `ip_derived_country` could
 * mirror its billing country and disable the geo check from outside. Every one of these is either derived arithmetic, a
 * status a later story owns, or a tax input -- none may ever arrive from a
 * form. `customer_id` and `payment_method_id` are the operator-supplied
 * inputs a new order genuinely needs; the twelve address columns are
 * fillable too -- they are a frozen COPY of the resolved Customer's
 * addresses at creation time (D-4), not a derived/computed value the way
 * the omitted columns are, and a legitimate future caller (an order-address
 * edit screen) could reasonably need to write them directly.
 *
 * `refunded_amount` (story 0051) joins the omitted list too -- a running
 * total written only via `forceFill()` by App\Actions\Orders\RecordRefund,
 * kept consistent with the SUM of this order's line items' `refunds` rows'
 * `amount`. It is a MERCHANDISE total only (quantity x unit_price): it
 * excludes tax and shipping, which are both `0.00` on every order today, so
 * the distinction is unobservable until 0053/0054 populate them (R-2).
 *
 * No `SoftDeletes`: orders are never deleted this phase; `Cancelled` is a
 * `status` value, not a soft delete.
 *
 * @property string $id
 * @property string $order_number
 * @property string $customer_id
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property string|null $sales_region_id
 * @property string|null $shipping_rate_id
 * @property string $payment_method_id
 * @property string|null $tax_rate 'decimal:3' casts to a STRING, not a float
 * @property string $subtotal 'decimal:2' casts to a STRING, not a float
 * @property string $tax_amount 'decimal:2' casts to a STRING, not a float
 * @property string $shipping_amount 'decimal:2' casts to a STRING, not a float
 * @property string $total 'decimal:2' casts to a STRING, not a float
 * @property string $refunded_amount 'decimal:2' casts to a STRING, not a float
 * @property bool $flagged_for_review
 * @property string|null $ip_address
 * @property string|null $ip_derived_country
 * @property string|null $flag_reason
 * @property string|null $shipping_address_line1
 * @property string|null $shipping_address_line2
 * @property string|null $shipping_city
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_province
 * @property string|null $shipping_country
 * @property string|null $billing_address_line1
 * @property string|null $billing_address_line2
 * @property string|null $billing_city
 * @property string|null $billing_postal_code
 * @property string|null $billing_province
 * @property string|null $billing_country
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read PaymentMethod $paymentMethod
 * @property-read SalesRegion|null $salesRegion
 * @property-read ShippingRate|null $shippingRate
 * @property-read Collection<int, OrderItem> $items
 */
#[Fillable([
    'customer_id', 'payment_method_id',
    'shipping_address_line1', 'shipping_address_line2', 'shipping_city',
    'shipping_postal_code', 'shipping_province', 'shipping_country',
    'billing_address_line1', 'billing_address_line2', 'billing_city',
    'billing_postal_code', 'billing_province', 'billing_country',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            // decimal:N casts return a STRING, never a float -- the
            // @property string annotations above document this
            // deliberately rather than as an oversight.
            'tax_rate' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'flagged_for_review' => 'boolean',
        ];
    }

    /**
     * The customer this order belongs to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The configured payment method this order references.
     *
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * The sales region used to resolve this order's tax -- null until a
     * sibling story resolves it (D-9).
     *
     * @return BelongsTo<SalesRegion, $this>
     */
    public function salesRegion(): BelongsTo
    {
        return $this->belongsTo(SalesRegion::class);
    }

    /**
     * The shipping rate selected for this order's delivery -- null until a
     * sibling story resolves it (D-9).
     *
     * @return BelongsTo<ShippingRate, $this>
     */
    public function shippingRate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class);
    }

    /**
     * The line items belonging to this order.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * May this order's line items be added, removed or re-quantified right now?
     *
     * Non-throwing predicate (story 0055, D-4 shape (b)): PRD §3.2 hard-blocks line-item
     * edits on Shipped/Delivered orders with no confirmation path around it. Every write
     * action in App\Actions\Orders that guards that block reads this ONE method and throws
     * OrderNotEditableException itself, and the order-detail screen's edit controls read it
     * too -- so a UI hint cannot drift from the rule that refuses. Deliberately says nothing
     * about the ACTOR (a policy concern) and reproduces the shipped guard exactly: Cancelled
     * is not blocked, since the PRD names only these two statuses.
     */
    public function isLineItemEditable(): bool
    {
        return ! in_array($this->status, [OrderStatus::Shipped, OrderStatus::Delivered], true);
    }

    /**
     * May this order be cancelled by an administrator right now?
     *
     * Non-throwing predicate over BOTH status dimensions (story 0050, PRD
     * §3.2): permitted only from Pending/Processing, and never while the
     * payment state is PartiallyRefunded. App\Actions\Orders\CancelOrder's
     * guard and OrderPolicy::cancel()'s state clause are both wrappers
     * around exactly this call, so the rule has ONE implementation and a
     * later UI hint cannot drift from the rule that refuses -- the same
     * predicate/wrapper shape as OrderStatus::isBackwardFrom() and
     * App\Actions\Auth\EnsureRecentPasswordConfirmation.
     *
     * Deliberately says nothing about the ACTOR, and nothing about the
     * already-Cancelled case, which CancelOrder rejects earlier and
     * differently (as a ValidationException, not via this predicate).
     * Reads `Cancelled` as simply not being in the permitted set --
     * `in_array(..., strict: true)` rather than a `match`, since this
     * method must answer for every OrderStatus case including Cancelled,
     * unlike OrderStatus::rank().
     *
     * The 100%-refund auto-cancel (a future story) does NOT consult this
     * predicate: it is a system side effect that cancels regardless of
     * state, by design (PRD §3.2).
     */
    public function isManuallyCancellable(): bool
    {
        return in_array($this->status, [OrderStatus::Pending, OrderStatus::Processing], true)
            && $this->payment_status !== PaymentStatus::PartiallyRefunded;
    }
}
