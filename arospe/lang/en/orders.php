<?php

// Story 0045 -- two key groups (statuses, payment_statuses), one leaf per case
// of App\Enums\OrderStatus and App\Enums\PaymentStatus, keyed by the enum's own
// backing value. No screen copy, no button labels -- those belong to story
// 0055, which extends this file rather than creating it. Neither enum declares
// label() yet (naming.md's "add label() when a second consumer appears" rule;
// story 0055 is that first consumer) -- this file ships now because this story
// is what fixes the two enums' backing values, and a translation file is only
// ever correct relative to the value set it covers.
//
// A third group, `errors`, was added at Phase 4 re-audit (finding N-1): every
// ValidationException::withMessages() call site in app/Actions/ routes its
// message through a translation key, and CreateOrder's column-ceiling guard
// (assertWithinColumnCeiling()) is no exception -- a hardcoded English literal
// there would have been the actual violation of this project's naming.md
// convention. This is the one deliberate exception to "no validation-message
// overrides" above; it exists because the story's own action needs it, not
// because a screen does.

return [

    'statuses' => [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ],

    'payment_statuses' => [
        'pending_payment' => 'Pending payment',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
    ],

    // Phase 4 re-audit finding N-1: CreateOrder's column-ceiling guard
    // (assertWithinColumnCeiling()) must route through a translation key
    // like every other ValidationException::withMessages() call site in
    // app/Actions/, rather than a hardcoded English literal.
    // Story 0048: order_not_editable is OrderNotEditableException's constant
    // message (D-5) -- never interpolated with the order's id, number or
    // status. last_line_item_cannot_be_removed is D-1's ValidationException
    // message for RemoveOrderItem's own guard. too_many_line_items is Phase 4
    // security audit finding F-2's AddOrderItem ceiling guard message,
    // added against App\Concerns\OrderValidationRules::MAX_ITEMS.
    'errors' => [
        'total_exceeds_maximum' => 'One or more line items would produce a total that exceeds the maximum representable value.',
        'order_not_editable' => 'This order can no longer be edited.',
        'last_line_item_cannot_be_removed' => 'An order must keep at least one line item. Cancel the order instead of removing its last item.',
        'refunded_line_item_cannot_be_removed' => 'The line item cannot be removed because some of its units have already been refunded.',
        'quantity_below_refunded' => 'The quantity cannot be lower than the :refunded units already refunded.',
        'too_many_line_items' => 'An order cannot hold more than :max line items.',
    ],

    // Story 0049 -- App\Actions\Orders\TransitionOrderStatus's three refusals. No screen copy here
    // (story 0055 extends this group with button/dialog text, and does not rename these keys).
    'transitions' => [
        'requires_confirmation' => 'Moving this order backward requires explicit confirmation.',
        'same_status' => 'This order already has that status.',
        'cancellation_unsupported' => 'Cancelling or reopening an order is not available here.',
    ],

    // Story 0051 -- App\Actions\Orders\RecordRefund's three ValidationException
    // refusals, all raised on the `items` field (D-5: never an AuthorizationException,
    // since none of the three is about who is asking). No screen copy here -- story
    // 0055 extends this group with button/dialog text and does not rename these keys.
    'refunds' => [
        'invalid_payment_state' => 'This order cannot be refunded in its current payment state.',
        'item_not_owned' => 'One or more line items do not belong to this order.',
        'exceeds_outstanding_units' => 'One or more line items would be refunded more units than remain outstanding.',
    ],

    // Story 0050 -- App\Actions\Orders\CancelOrder's two refusals. No screen copy here (story 0055
    // extends this group with button/dialog text and does not rename these keys).
    'cancellation' => [
        'blocked' => 'This order can no longer be cancelled.',
        'already_cancelled' => 'This order is already cancelled.',
    ],

    // Story 0054 -- tokens written to `orders.flag_reason` by
    // App\Actions\Orders\ResolveVirtualOrderSalesRegion, keyed by its REASON_* constants.
    'flag_reasons' => [
        'billing_ip_country_mismatch' => 'The billing country does not match the country derived from the purchaser\'s IP address.',
        'ip_country_missing' => 'No IP-derived country was captured for this order, so the billing address could not be validated.',
        'mixed_basket' => 'This order mixes physical and virtual products, so its tax region cannot be resolved automatically.',
    ],

];
