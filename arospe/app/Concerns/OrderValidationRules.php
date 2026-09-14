<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Story 0045 -- mirrors UserValidationRules/CustomerValidationRules exactly:
 * <Noun>ValidationRules trait, <noun>Rules() methods returning rule arrays,
 * flat and single-concern
 * (docs/conventions/naming-validation-traits.md#traits-and-their-methods).
 *
 * `Rule::exists('<table>', 'id')` (a bare table name, never a model class)
 * matches the shape already established by ShippingRateValidationRules and
 * ProductValidationRules -- and it is deliberately the soft-delete-unaware
 * form: Laravel's Rule::exists() queries the table directly rather than
 * through Eloquent, so it never applies a SoftDeletingScope regardless of
 * which form is used. For `customer_id` this is D-12's explicit decision
 * (a soft-deleted customer's id must still pass, so their order history is
 * never orphaned) rather than an oversight.
 */
trait OrderValidationRules
{
    /**
     * Ceiling on the number of line items a single order may carry. This is
     * an administrator-entered backoffice order, not a bulk import -- and,
     * per docs/security/array-validation-bounds.md, a `max:` on the `items`
     * array bounds what is accepted, never what the request COSTS: every
     * `.*` rule (including this trait's `Rule::exists()` checks) is expanded
     * and run against whatever the client actually submitted, regardless of
     * whether the array-level `max:` already failed. `CreateOrder::__invoke()`
     * therefore validates `items`' own shape in an EARLY, separate
     * `Validator::make()` call, before composing the full per-item rule set
     * -- so an oversized array throws before a single `.*` query runs.
     */
    public const MAX_ITEMS = 100;

    /**
     * Ceiling on one line item's `quantity`. `shipping_rates`-style bracket
     * columns aside, `order_items.unit_price`/`line_total` are
     * `decimal(10,2)` (max 99,999,999.99) -- this bound alone does not
     * prevent every overflow (a max-priced product at this quantity can
     * still overflow), only the obvious runaway-quantity case; see
     * CreateOrder::assertWithinColumnCeiling() for the second half of the
     * guard.
     */
    public const MAX_ITEM_QUANTITY = 10000;

    /**
     * The whole payload's rules -- customer, payment method, and the items
     * array (D-5's `min:1`, plus each element's own shape). `orderItemRules()`
     * returns the per-item field rules keyed by their bare (unprefixed)
     * field name; this method fans them out under `items.*.<field>` so a
     * single per-item rule set is never duplicated.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function orderRules(): array
    {
        $itemRules = [];

        foreach ($this->orderItemRules() as $field => $rules) {
            $itemRules["items.*.{$field}"] = $rules;
        }

        return [
            'customer_id' => $this->orderCustomerRules(),
            'payment_method_id' => $this->orderPaymentMethodRules(),
            'items' => $this->orderItemsRules(),
            ...$itemRules,
        ];
    }

    /**
     * @return array<int, ValidationRule|string>
     */
    protected function orderCustomerRules(): array
    {
        return ['required', 'uuid', Rule::exists('customers', 'id')];
    }

    /**
     * @return array<int, ValidationRule|string>
     */
    protected function orderPaymentMethodRules(): array
    {
        return ['required', 'uuid', Rule::exists('payment_methods', 'id')];
    }

    /**
     * D-5: the entire zero-line-item rejection is this one rule -- no
     * engine expresses "a parent must have at least one child" without a
     * trigger, so it is enforced here rather than as a database constraint.
     * `max:` (F-2) bounds the array's own shape; it does NOT bound the cost
     * of the `.*` rules below when both are validated in the same call --
     * see this trait's `MAX_ITEMS` docblock and
     * docs/security/array-validation-bounds.md.
     *
     * @return array<int, string>
     */
    protected function orderItemsRules(): array
    {
        return ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS];
    }

    /**
     * Per-item field rules, keyed by the item's own (unprefixed) field
     * name -- orderRules() above fans these out under `items.*.<field>`.
     *
     * `quantity`: `min:1` rejects both `0` and a negative value, `integer`
     * rejects a fractional or non-numeric value -- three of the story's
     * negative scenarios resolve to this one rule -- and `max:` (F-3) caps
     * a single line item's quantity so an absurd value cannot alone drive
     * `line_total`/`subtotal` past the `decimal(10,2)` column ceiling
     * (CreateOrder::assertWithinColumnCeiling() closes the residual case a
     * quantity cap alone cannot: a near-maximum price at this quantity).
     * `product_variant_id` is `nullable`: a line item for the plain
     * product (no variant) omits it entirely. Note `Rule::exists('product_variants', 'id')`
     * here only proves the id exists SOMEWHERE in the table -- it does not
     * prove the variant belongs to this item's own `product_id` (F-1).
     * That cross-field invariant cannot be expressed as a bare validation
     * rule (it needs the sibling field's value), so CreateOrder resolves
     * the variant THROUGH its parent product instead of trusting this rule
     * alone -- see the action's own docblock.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function orderItemRules(): array
    {
        return [
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')],
            'product_variant_id' => ['nullable', 'uuid', Rule::exists('product_variants', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ITEM_QUANTITY],
        ];
    }
}
