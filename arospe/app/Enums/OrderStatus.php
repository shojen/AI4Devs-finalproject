<?php

namespace App\Enums;

/**
 * An order's fulfilment status (story 0045, PRD §3.2). TitleCase keys,
 * lowercase snake_case backing values, matching the UserStatus/
 * SalesRegionKind precedent (docs/conventions/naming.md#classes).
 *
 * Corrected 2026-09-15 (story 0047) -- this docblock used to read "Deliberately
 * no label() and no transition logic ... a one-caller label() is indirection
 * with no consumer until story 0055 renders it", per naming.md's "add label()
 * when a second consumer appears" rule. Story 0047's own order-history screen
 * is what actually renders this status first (a badge per order row), ahead of
 * 0055 -- so it is the story that earns label(), per the identical rule it was
 * deferred under. Still no transition logic here: which status may follow
 * which remains stories 0048-0052's job, not this one's.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Get the translated, human-readable label for the status.
     */
    public function label(): string
    {
        return __('orders.statuses.'.$this->value);
    }
}
