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
        'confirm_backward_heading' => 'Move this order backward?',
        'confirm_backward_body' => 'This moves the order to an earlier status than its current one. Confirm only if that is what you intend.',
        'confirm_backward_action' => 'Move it back',
        'stale_confirmation' => 'This order was changed by someone else while you were confirming. Review its status and try again.',
    ],

    // Story 0051 -- App\Actions\Orders\RecordRefund's three ValidationException
    // refusals, all raised on the `items` field (D-5: never an AuthorizationException,
    // since none of the three is about who is asking). No screen copy here -- story
    // 0055 extends this group with button/dialog text and does not rename these keys.
    'refunds' => [
        'invalid_payment_state' => 'This order cannot be refunded in its current payment state.',
        'item_not_owned' => 'One or more line items do not belong to this order.',
        'exceeds_outstanding_units' => 'One or more line items would be refunded more units than remain outstanding.',
        'action' => 'Record refund',
        'modal_title' => 'Record a refund',
        'units_to_refund' => 'Units to refund',
        'outstanding' => ':count unit outstanding|:count units outstanding',
        'dismiss' => 'Cancel',
        'confirm' => 'Refund',
        'nothing_selected' => 'Enter at least one unit to refund.',
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

    /*
    |--------------------------------------------------------------------------
    | Screen copy (story 0055)
    |--------------------------------------------------------------------------
    |
    | The orders list and detail screens. Appended to the groups the backend stories
    | 0045/0049/0050/0051/0054 shipped, which are consumed unchanged.
    |
    */

    'index' => [
        'summary' => ':count order|:count orders',
        'empty' => 'No orders have been recorded yet.',
        // "Needs attention", never "error"/"invalid": on a freshly seeded catalog every order
        // shipping outside Spain is flagged, and copy implying a fault would train administrators
        // to dismiss the one signal the tax chain has (D-15).
        'flagged' => 'Needs attention',
        'flagged_generic' => 'Needs attention: this order is flagged for manual review.',
        'view_order' => 'View order :number',
        'columns' => [
            'order' => 'Order',
            'customer' => 'Customer',
            'status' => 'Status',
            'payment' => 'Payment',
            'total' => 'Total',
            'placed' => 'Placed',
            'actions' => 'Actions',
        ],
    ],

    'detail' => [
        'back_to_list' => 'Back to orders',
        'customer_heading' => 'Customer',
        'shipping_address' => 'Shipping address (as at order time)',
        'no_address' => 'No shipping address recorded.',
        'line_items_heading' => 'Line items',
        'lifecycle_heading' => 'Status & lifecycle',
        'totals_heading' => 'Totals',
        'tax_heading' => 'Tax',
        'action_not_allowed' => 'You are not allowed to do this, or the order is not in a state that allows it.',
        'flag_callout_heading' => 'Needs attention',
        'placed_at' => 'Placed :date',
        'subtotal' => 'Subtotal',
        'tax_amount' => 'Tax',
        'shipping_amount' => 'Shipping',
        'total' => 'Total',
        'refunded_amount' => 'Refunded',
        'tax_region' => 'Sales region',
        'tax_rate' => 'Rate',
        'tax_provisional' => 'Provisional: this order is flagged for review, so this basis has not been confirmed.',
        // Distinct from a real 0.000% rate (D-16).
        'tax_unresolved' => 'Not yet resolved',
    ],

    'line_items' => [
        'product' => 'Product',
        'sku' => 'SKU',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit price',
        'line_total' => 'Line total',
        'refunded' => 'Refunded',
        'variant' => 'Variant',
        'add' => 'Add line item',
        'remove' => 'Remove',
        'save_quantity' => 'Save quantity',
        'select_product' => 'Select a product',
        'select_variant' => 'Select a variant',
        'catalog_empty' => 'There are no products available to add.',
        'catalog_truncated' => 'Showing the first :max products only.',
        'remove_refunded_hint' => 'A line with refunded units cannot be removed.',
        'product_unavailable' => 'That product is not available to add.',
        'variant_required' => 'Choose a variant for this product.',
    ],

    'lifecycle' => [
        'status' => 'Status',
        'apply' => 'Apply',
        'cancel' => 'Cancel order',
        'cancel_dialog_heading' => 'Cancel this order?',
        'cancel_dialog_body' => 'Cancelling is final: an order cannot be reopened once it is cancelled. Its payment state is not changed and nothing is refunded automatically.',
        'cancel_dialog_confirm' => 'Cancel order',
        'dialog_dismiss' => 'Keep as it is',
    ],

];
