<?php

namespace App\Actions\Orders;

use RuntimeException;

/**
 * Story 0048 -- a pure, dependency-free collaborator that narrows a plain
 * `string` (typically a `decimal:N`-cast Eloquent attribute, e.g.
 * `$item->unit_price`) to a `numeric-string`, which is what bcmath's own
 * stub signatures (`bcmul`/`bcadd`/`bccomp`) require and Larastan cannot
 * infer on its own.
 *
 * This is the exact mechanism `App\Actions\Orders\CreateOrder`'s own
 * private `toNumericString()` already established (its docblock: "F-4" --
 * a genuine runtime `is_numeric()` check, not a suppressed
 * static-analysis assertion, since a `decimal:N` cast that somehow
 * produced a non-numeric string would indicate corrupted catalog data
 * this action must refuse rather than silently coerce). It is
 * re-implemented here as a shared, injectable class -- rather than a
 * three-way duplicated private method across AddOrderItem /
 * RecalculateOrderTotals / UpdateOrderItemQuantity, or a move of
 * CreateOrder's own copy into this class -- because this story's own
 * scope fences forbid refactoring CreateOrder beyond the one named trait
 * extraction (App\Concerns\OrderValidationRules). A future story is free
 * to consolidate CreateOrder onto this same class; that is not this
 * story's call to make.
 *
 * Like `App\Actions\Products\HashVariantCombination`/`DeriveVariantSku`,
 * this is container-resolved and constructor-injected like any other
 * collaborator, never `new`-ed, even though it has no dependencies of its
 * own (docs/conventions/code-style.md).
 */
class ToNumericString
{
    /**
     * @return numeric-string
     */
    public function __invoke(string $value): string
    {
        if (! is_numeric($value)) {
            throw new RuntimeException("Expected a numeric decimal value, got: {$value}");
        }

        return $value;
    }
}
