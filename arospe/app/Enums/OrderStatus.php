<?php

namespace App\Enums;

/**
 * An order's fulfilment status (story 0045, PRD §3.2). TitleCase keys,
 * lowercase snake_case backing values, matching the UserStatus/
 * SalesRegionKind precedent (docs/conventions/naming.md#classes).
 *
 * Deliberately no label() and no transition logic -- see the story's task
 * file (D-7 / N-4): a one-caller label() is indirection with no consumer
 * until story 0055 renders it, and which status may follow which is
 * stories 0048-0052's job, not this one's.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
