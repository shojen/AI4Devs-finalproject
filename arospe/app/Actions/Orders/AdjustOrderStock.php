<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Story 0088a -- applies signed stock deltas for the lines of one order, atomically.
 *
 * Transaction-bound and authorization-free by design (D7): it must be called inside a
 * `DB::transaction()` opened by an order action that has already authorized the operation, and it
 * is only ever used from `App\Actions\Orders\*`.
 *
 * Lock order (FD-5, FD-7): every `products` row is locked in ONE id-ordered `lockForUpdate()` read,
 * then every `product_variants` row in another, matching the catalog actions (`products` before
 * `product_variants`). The parent product row of a variant line is locked in that same products
 * read (locks only, stock is written to the actual target alone), because the `order_items` insert
 * shared-locks it through its FK. Deltas are summed per target and written as one relative
 * `increment('stock', $delta)` UPDATE per target, so a concurrent change is never overwritten.
 * Stock has no lower bound. A line whose catalog row no longer exists (null `stockTarget()`, or a
 * row deleted before the lock) is skipped and not reported as applied; a target whose deltas net
 * to zero is not written and its lines are not reported either.
 */
class AdjustOrderStock
{
    /**
     * @param  list<array{order_item: OrderItem, delta: int}>  $deltas
     * @return list<string> ids of the order items whose delta was applied
     *
     * @throws LogicException when called outside a transaction or with a line of another order
     */
    public function __invoke(Order $order, array $deltas): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('AdjustOrderStock must run inside an open database transaction.');
        }

        foreach ($deltas as $entry) {
            if ($entry['order_item']->order_id !== $order->id) {
                throw new LogicException('Every adjusted line must belong to the given order.');
            }
        }

        /** @var array<string, int> $netByTarget keyed "type:id" */
        $netByTarget = [];
        /** @var array<string, list<string>> $itemIdsByTarget */
        $itemIdsByTarget = [];
        /** @var array<string, array<string, string>> $idsByType */
        $idsByType = ['product' => [], 'variant' => []];

        foreach ($deltas as $entry) {
            $item = $entry['order_item'];
            $target = $item->stockTarget();

            if ($target === null) {
                continue;
            }

            $key = $target['type'].':'.$target['id'];
            $netByTarget[$key] = ($netByTarget[$key] ?? 0) + $entry['delta'];
            $itemIdsByTarget[$key][] = $item->id;
            $idsByType[$target['type']][$target['id']] = $target['id'];

            // A variant line's order_items insert also takes an FK shared lock on its parent
            // product row, so that row is locked (never written) in the same products read.
            if ($target['type'] === 'variant' && $item->product_id !== null) {
                $idsByType['product'][$item->product_id] = $item->product_id;
            }
        }

        /** @var array<string, true> $existing */
        $existing = [];

        if ($idsByType['product'] !== []) {
            $locked = Product::query()
                ->whereIn('id', array_values($idsByType['product']))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            foreach ($locked as $id) {
                $existing['product:'.$id] = true;
            }
        }

        if ($idsByType['variant'] !== []) {
            $locked = ProductVariant::query()
                ->whereIn('id', array_values($idsByType['variant']))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            foreach ($locked as $id) {
                $existing['variant:'.$id] = true;
            }
        }

        $applied = [];

        foreach ($netByTarget as $key => $net) {
            if (! isset($existing[$key]) || $net === 0) {
                continue;
            }

            [$type, $id] = explode(':', $key, 2);

            if ($type === 'product') {
                Product::query()->whereKey($id)->increment('stock', $net);
            } else {
                ProductVariant::query()->whereKey($id)->increment('stock', $net);
            }

            array_push($applied, ...$itemIdsByTarget[$key]);
        }

        return $applied;
    }
}
