<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Insert-only invoice lines (story 0101b): the database's SET NULL on
     * order_item_id is the only write a line ever receives.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 20);
            $table->string('description', 255);
            $table->string('sku', 128)->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('tax_rate', 6, 3)->nullable();
            $table->decimal('line_subtotal', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. Data loss: dropping the table permanently discards every invoice
     * line.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
