<?php

namespace App\Actions\Orders;

use Illuminate\Validation\ValidationException;

/**
 * Phase 4 security audit finding F-1 on story 0048 -- a pure, dependency-free
 * collaborator extracted so the decimal(10,2) column-overflow guard
 * `App\Actions\Orders\CreateOrder::assertWithinColumnCeiling()` already
 * applies to `order_items.line_total` / `orders.subtotal` / `orders.total`
 * at CREATE time also applies on the EDIT path (`AddOrderItem`,
 * `UpdateOrderItemQuantity`, `RecalculateOrderTotals`), which previously had
 * no such guard at all -- an edit could compute a value that silently
 * overflows the column on write, surfacing as an uncaught `QueryException`
 * (a 500) instead of a clean `ValidationException`.
 *
 * This is the SAME logic and the SAME `MAX_DECIMAL_COLUMN_VALUE` ceiling
 * `CreateOrder`'s own private `assertWithinColumnCeiling()` method already
 * has, deliberately re-implemented here as a shared, injectable class
 * rather than moving `CreateOrder`'s copy or duplicating it three more
 * times -- this story's own scope fences forbid refactoring `CreateOrder`
 * beyond its one already-made trait extraction
 * (`App\Concerns\OrderValidationRules`), matching the identical reasoning
 * `App\Actions\Orders\ToNumericString`'s own docblock already states for
 * the same constraint. A future story is free to consolidate `CreateOrder`
 * onto this class; that is not this fix's call to make.
 *
 * Like `ToNumericString` and `App\Actions\Products\HashVariantCombination`/
 * `DeriveVariantSku`, this is container-resolved and constructor-injected
 * like any other collaborator, never `new`-ed, even though it has no
 * dependencies of its own (docs/conventions/code-style.md).
 */
class AssertWithinColumnCeiling
{
    /**
     * `order_items.unit_price`/`line_total` and `orders.subtotal`/
     * `tax_amount`/`shipping_amount`/`total` are all `decimal(10,2)` -- the
     * largest value the column can hold. Identical to
     * `CreateOrder::MAX_DECIMAL_COLUMN_VALUE`.
     */
    private const MAX_DECIMAL_COLUMN_VALUE = '99999999.99';

    /**
     * Compared with bcmath (`bccomp`), never a float cast, for the same
     * reason every other money computation in this codebase does.
     *
     * @param  numeric-string  $value
     */
    public function __invoke(string $value, string $field): void
    {
        if (bccomp($value, self::MAX_DECIMAL_COLUMN_VALUE, 2) > 0) {
            throw ValidationException::withMessages([
                $field => __('orders.errors.total_exceeds_maximum'),
            ]);
        }
    }
}
