<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

// Story 0048 -- shared across AddOrderItemTest / RemoveOrderItemTest / UpdateOrderItemQuantityTest
// / HardBlockTest / OrderLineItemAuthorizationTest. Pest's own directory-scoped "Datasets.php"
// convention (see tests/Feature/ShippingRates/Datasets.php for the mechanism and why a bare
// dataset() call in an ordinary test file is NOT cross-file addressable the way this file's
// contents are): included unconditionally at bootstrap, and every dataset() call below is scoped
// to this directory (tests/Feature/Orders), so any test file here can resolve it by name via
// ->with('open_order_statuses') / ->with('blocked_order_statuses').
//
// R-3's mitigation: PRD §3.2's own Scenario Outline names TWO open statuses (Pendiente/Procesando)
// that an implementation must treat identically -- an implementation that hard-codes the hard
// block as "anything but Pending" would pass every Pending-only test and fail only on Processing
// (and would wrongly refuse Cancelled too, which the PRD never asks to block). This dataset is
// what makes the "or" in "Pendiente or Procesando" executable rather than merely asserted in prose.
dataset('open_order_statuses', [
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
]);

// The two hard-blocked statuses PRD §3.2 names, and only these two -- an implementation blocking
// on a deny-list of these two (rather than an allow-list of Pending/Processing) is what R-3 asks
// for, and this dataset is the shared source for both the "blocked" and "positive control" tests.
dataset('blocked_order_statuses', [
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
]);

// Story 0084 (D-1, D-5) -- the full payment-state x fulfilment-status grid (4 x 5 = 20 cells),
// written out literally on purpose: deriving the expected outcome from the same rule the action
// implements would make the dataset a mirror of the code instead of an independent statement of
// it. The third value is the expected outcome of marking the order as paid:
//   'marked'       -- the write happens (pending payment, any non-cancelled status);
//   'cancelled'    -- refused with orders.payment.cancelled_blocked (pending payment + cancelled);
//   'already_paid' -- refused with orders.payment.already_paid (every non-pending payment state,
//                     including cancelled + refunded: already-paid is checked BEFORE cancelled).
$markAsPaidStateGrid = [
    'pending payment / pending' => [PaymentStatus::PendingPayment, OrderStatus::Pending, 'marked'],
    'pending payment / processing' => [PaymentStatus::PendingPayment, OrderStatus::Processing, 'marked'],
    'pending payment / shipped' => [PaymentStatus::PendingPayment, OrderStatus::Shipped, 'marked'],
    'pending payment / delivered' => [PaymentStatus::PendingPayment, OrderStatus::Delivered, 'marked'],
    'pending payment / cancelled' => [PaymentStatus::PendingPayment, OrderStatus::Cancelled, 'cancelled'],
    'paid / pending' => [PaymentStatus::Paid, OrderStatus::Pending, 'already_paid'],
    'paid / processing' => [PaymentStatus::Paid, OrderStatus::Processing, 'already_paid'],
    'paid / shipped' => [PaymentStatus::Paid, OrderStatus::Shipped, 'already_paid'],
    'paid / delivered' => [PaymentStatus::Paid, OrderStatus::Delivered, 'already_paid'],
    'paid / cancelled' => [PaymentStatus::Paid, OrderStatus::Cancelled, 'already_paid'],
    'partially refunded / pending' => [PaymentStatus::PartiallyRefunded, OrderStatus::Pending, 'already_paid'],
    'partially refunded / processing' => [PaymentStatus::PartiallyRefunded, OrderStatus::Processing, 'already_paid'],
    'partially refunded / shipped' => [PaymentStatus::PartiallyRefunded, OrderStatus::Shipped, 'already_paid'],
    'partially refunded / delivered' => [PaymentStatus::PartiallyRefunded, OrderStatus::Delivered, 'already_paid'],
    'partially refunded / cancelled' => [PaymentStatus::PartiallyRefunded, OrderStatus::Cancelled, 'already_paid'],
    'refunded / pending' => [PaymentStatus::Refunded, OrderStatus::Pending, 'already_paid'],
    'refunded / processing' => [PaymentStatus::Refunded, OrderStatus::Processing, 'already_paid'],
    'refunded / shipped' => [PaymentStatus::Refunded, OrderStatus::Shipped, 'already_paid'],
    'refunded / delivered' => [PaymentStatus::Refunded, OrderStatus::Delivered, 'already_paid'],
    'refunded / cancelled' => [PaymentStatus::Refunded, OrderStatus::Cancelled, 'already_paid'],
];

dataset('mark_as_paid_state_grid', $markAsPaidStateGrid);

// The three readable slices of the grid, so each outcome gets its own test with its own name.
dataset('mark_as_paid_markable_cells', array_filter($markAsPaidStateGrid, fn (array $cell): bool => $cell[2] === 'marked'));
dataset('mark_as_paid_already_paid_cells', array_filter($markAsPaidStateGrid, fn (array $cell): bool => $cell[2] === 'already_paid'));
dataset('mark_as_paid_cancelled_cells', array_filter($markAsPaidStateGrid, fn (array $cell): bool => $cell[2] === 'cancelled'));

// Story 0085 (D-1) -- whether the "Mark as paid" button is VISIBLE to an `orders.view + orders.edit` actor,
// for the same 4 x 5 grid, written out literally (never derived from Order::isAwaitingPayment(), which
// would make the dataset a mirror of the rule it checks). Exactly 4 cells are visible: pending payment
// crossed with the four non-cancelled statuses. The third value is "the button is rendered".
dataset('mark_as_paid_button_visibility_grid', [
    'pending payment / pending' => [PaymentStatus::PendingPayment, OrderStatus::Pending, true],
    'pending payment / processing' => [PaymentStatus::PendingPayment, OrderStatus::Processing, true],
    'pending payment / shipped' => [PaymentStatus::PendingPayment, OrderStatus::Shipped, true],
    'pending payment / delivered' => [PaymentStatus::PendingPayment, OrderStatus::Delivered, true],
    'pending payment / cancelled' => [PaymentStatus::PendingPayment, OrderStatus::Cancelled, false],
    'paid / pending' => [PaymentStatus::Paid, OrderStatus::Pending, false],
    'paid / processing' => [PaymentStatus::Paid, OrderStatus::Processing, false],
    'paid / shipped' => [PaymentStatus::Paid, OrderStatus::Shipped, false],
    'paid / delivered' => [PaymentStatus::Paid, OrderStatus::Delivered, false],
    'paid / cancelled' => [PaymentStatus::Paid, OrderStatus::Cancelled, false],
    'partially refunded / pending' => [PaymentStatus::PartiallyRefunded, OrderStatus::Pending, false],
    'partially refunded / processing' => [PaymentStatus::PartiallyRefunded, OrderStatus::Processing, false],
    'partially refunded / shipped' => [PaymentStatus::PartiallyRefunded, OrderStatus::Shipped, false],
    'partially refunded / delivered' => [PaymentStatus::PartiallyRefunded, OrderStatus::Delivered, false],
    'partially refunded / cancelled' => [PaymentStatus::PartiallyRefunded, OrderStatus::Cancelled, false],
    'refunded / pending' => [PaymentStatus::Refunded, OrderStatus::Pending, false],
    'refunded / processing' => [PaymentStatus::Refunded, OrderStatus::Processing, false],
    'refunded / shipped' => [PaymentStatus::Refunded, OrderStatus::Shipped, false],
    'refunded / delivered' => [PaymentStatus::Refunded, OrderStatus::Delivered, false],
    'refunded / cancelled' => [PaymentStatus::Refunded, OrderStatus::Cancelled, false],
]);
