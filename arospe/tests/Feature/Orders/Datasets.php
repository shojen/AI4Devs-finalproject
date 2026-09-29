<?php

use App\Enums\OrderStatus;

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
