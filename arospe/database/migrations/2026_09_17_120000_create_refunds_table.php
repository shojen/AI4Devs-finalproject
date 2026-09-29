<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0051 -- a plain UUIDv7 greenfield table, the refund event log
     * (D-2): one row per refund event against one line item, never a scalar
     * `refunded_at` column. `order_items.refunded_quantity` (0045's D-3) is
     * the fast running total this table is the source of truth for; a test
     * pins that the former always equals the SUM of the latter.
     *
     * `order_item_id` -- OQ-1, SETTLED (this story's own implementer
     * decision, overriding the migration snippet the task file originally
     * shipped as "(b) as contributed"): restrictOnDelete(), NOT
     * cascadeOnDelete(). A cascade would let a future line-item delete (0049
     * lets an administrator remove a line item from a Pending/Processing
     * order, which can legitimately already be Paid/PartiallyRefunded)
     * silently destroy financial records and leave `orders.refunded_amount`
     * overstating a sum with no rows behind it. Restrict makes "you cannot
     * remove a line you have already refunded" a database invariant rather
     * than a rule a future story must remember to enforce -- matching
     * `sales_regions.parent_id`'s precedent (restrict where a cascade would
     * destroy configured/financial data).
     *
     * `refunded_by` -- restrictOnDelete() and NOT NULL (D-10): a refund must
     * always name a real actor. The restrict is effectively insurance --
     * `users` is soft-deleted, so User::delete() never fires this FK.
     *
     * No `deleted_at` (OQ-4): a refund is a recorded, immutable fact.
     *
     * No hand-written $table->index() anywhere -- constrained() already
     * leaves both FK columns indexed
     * (docs/database/migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here).
     * No index on `created_at` either, matching 0045's identical
     * cardinality argument for `orders.status`.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_item_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('amount', 10, 2); // quantity x order_items.unit_price, snapshot at refund time (D-8)

            $table->foreignUuid('refunded_by')->constrained('users')->restrictOnDelete();

            $table->text('reason')->nullable(); // column now, behaviour later (D-11)

            $table->timestamps(); // created_at IS the refund event timestamp (D-2)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
