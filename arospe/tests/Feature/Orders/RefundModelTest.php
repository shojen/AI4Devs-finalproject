<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

// Story 0051 -- pins the schema/model/factory layer for `refunds` and
// `orders.refunded_amount`, mirroring tests/Feature/Orders/OrderModelTest.php's own shape for the
// identical situation (0045's Order/OrderItem).

test('refunds exists with exactly the columns the migration defines', function () {
    expect(Schema::getColumnListing('refunds'))->toEqualCanonicalizing([
        'id', 'order_item_id', 'quantity', 'amount', 'refunded_by', 'reason', 'created_at', 'updated_at',
    ]);
});

// Verifies the "no explicit $table->index()" rule (docs/database/migrations.md) the same way
// tests/Feature/Database/DropRedundantUsersUuidIndexTest.php verifies it for `users`: the only
// indexes present are the primary key and the two constrained()-created FK indexes -- nothing
// hand-written, nothing redundant.
test('refunds carries only the indexes constrained() creates, plus its primary key', function () {
    $indexNames = collect(Schema::getIndexes('refunds'))->pluck('name')->sort()->values()->all();

    expect($indexNames)->toBe([
        'primary', 'refunds_order_item_id_foreign', 'refunds_refunded_by_foreign',
    ]);
});

test('refunds.order_item_id and refunds.refunded_by both restrict on delete (OQ-1, settled; D-10)', function () {
    $foreignKeys = collect(Schema::getForeignKeys('refunds'))->keyBy(fn (array $fk): string => $fk['columns'][0]);

    expect($foreignKeys['order_item_id']['on_delete'])->toBe('restrict')
        ->and($foreignKeys['order_item_id']['foreign_table'])->toBe('order_items')
        ->and($foreignKeys['refunded_by']['on_delete'])->toBe('restrict')
        ->and($foreignKeys['refunded_by']['foreign_table'])->toBe('users');
});

test('orders.refunded_amount exists and a newly created order reads 0.00', function () {
    expect(Schema::hasColumn('orders', 'refunded_amount'))->toBeTrue();

    $order = Order::factory()->create();

    // The factory's own definition() never sets refunded_amount (correctly omitted from
    // #[Fillable]), so the in-memory instance never learns the column's DB-side default --
    // re-fetch to read what MySQL actually wrote.
    // decimal(10,2) casts to a STRING, never a float (R-3) -- compared as a decimal string.
    expect((string) $order->fresh()->refunded_amount)->toBe('0.00');
});

test('Refund uses HasUuids, and a created refund has a 36-character UUID id', function () {
    $refund = Refund::factory()->create();

    expect($refund->id)->toBeString()->and(mb_strlen($refund->id))->toBe(36);
});

test('a refund round-trips through the factory with every column persisting and reloading byte-identically', function () {
    $refund = Refund::factory()->create(['reason' => null]);

    $fresh = $refund->fresh();

    expect($fresh->id)->toBe($refund->id)
        ->and($fresh->order_item_id)->toBe($refund->order_item_id)
        ->and($fresh->quantity)->toBe($refund->quantity)
        ->and((string) $fresh->amount)->toBe((string) $refund->amount)
        ->and($fresh->refunded_by)->toBe($refund->refunded_by)
        ->and($fresh->reason)->toBeNull();
});

// The sharpest structural test in the story: the omission-as-guard convention
// (docs/conventions/base-standards.md#model-conventions) is only real if something fails when it
// is undone. Mirrors OrderModelTest.php's own fill()-based shape for the identical situation --
// `amount` and `refunded_by` are NOT NULL with no database default, so a literal ::create() call
// would fail on the INSERT itself (a QueryException) rather than demonstrate anything about mass
// assignment; fill() proves the attribute never reaches the model at all.
test('the Refund fillable set excludes amount and refunded_by, and includes order_item_id, quantity and reason', function () {
    $fillable = (new Refund)->getFillable();

    expect($fillable)->not->toContain('amount')
        ->and($fillable)->not->toContain('refunded_by')
        ->and($fillable)->toContain('order_item_id')
        ->and($fillable)->toContain('quantity')
        ->and($fillable)->toContain('reason');
});

test('an amount and refunded_by supplied via a plain fill() payload never reach the model, because neither is fillable', function () {
    $otherUser = User::factory()->create();

    $refund = new Refund;
    $refund->fill([
        'order_item_id' => 'not-used-for-fill-test',
        'quantity' => 1,
        'amount' => '0.01',
        'refunded_by' => $otherUser->id,
    ]);

    expect($refund->getAttribute('amount'))->toBeNull()
        ->and($refund->getAttribute('refunded_by'))->toBeNull()
        ->and($refund->getAttribute('order_item_id'))->toBe('not-used-for-fill-test')
        ->and($refund->getAttribute('quantity'))->toBe(1);
});

test('a refunded_amount supplied via a plain fill() payload never reaches the Order model, because it is not fillable', function () {
    $order = new Order;
    $order->fill([
        'customer_id' => 'not-used-for-fill-test',
        'refunded_amount' => '999.99',
    ]);

    expect($order->getAttribute('refunded_amount'))->toBeNull();
});

test('OrderItem exposes a refunds relation, listing every refund recorded against it', function () {
    $item = OrderItem::factory()->create(['quantity' => 5]);
    $first = Refund::factory()->create(['order_item_id' => $item->id, 'quantity' => 1]);
    $item->forceFill(['refunded_quantity' => 1])->save();
    $second = Refund::factory()->create(['order_item_id' => $item->id, 'quantity' => 1]);

    $refundIds = $item->fresh()->refunds->pluck('id')->sort()->values()->all();

    expect($refundIds)->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

test('a refund belongs to its order item and to the user who performed it', function () {
    $refund = Refund::factory()->create();

    expect($refund->orderItem)->toBeInstanceOf(OrderItem::class)
        ->and($refund->orderItem->id)->toBe($refund->order_item_id)
        ->and($refund->refundedBy)->toBeInstanceOf(User::class)
        ->and($refund->refundedBy->id)->toBe($refund->refunded_by);
});
