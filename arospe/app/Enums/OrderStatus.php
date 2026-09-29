<?php

namespace App\Enums;

/**
 * An order's fulfilment status (story 0045, PRD §3.2). TitleCase keys,
 * lowercase snake_case backing values, matching the UserStatus/
 * SalesRegionKind precedent (docs/conventions/naming/classes.md#classes).
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

    /**
     * The status's position on the linear order ladder.
     *
     * Deliberately covers ONLY the four linear statuses. `Cancelled` has no
     * rank because it has no position: it is a terminal state reachable from
     * several places, not a step on the ladder. The `match` therefore has no
     * Cancelled arm, so calling this on it raises \UnhandledMatchError
     * rather than inventing a number -- a fail-loud guarantee PHP gives for
     * free.
     *
     * Every caller must refuse a Cancelled status BEFORE reaching this
     * method (see App\Actions\Orders\TransitionOrderStatus's ordering rule
     * and story 0049's D-3).
     */
    public function rank(): int
    {
        // @phpstan-ignore match.unhandled (deliberate: Cancelled has no rank, see the docblock above)
        return match ($this) {
            self::Pending => 0,
            self::Processing => 1,
            self::Shipped => 2,
            self::Delivered => 3,
        };
    }

    /**
     * Is this status behind the one given -- i.e. would moving to it be a
     * regression?
     *
     * Non-throwing predicate. App\Actions\Orders\TransitionOrderStatus's
     * guard is a wrapper around exactly this call, so the rule has one
     * implementation and a later UI hint cannot drift from the rule that
     * refuses. Same shape as
     * App\Actions\Auth\EnsureRecentPasswordConfirmation's predicate/wrapper
     * split -- see docs/security/step-up-authentication.md.
     */
    public function isBackwardFrom(self $current): bool
    {
        return $this->rank() < $current->rank();
    }
}
