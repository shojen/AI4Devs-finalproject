<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One row per invoice e-mail send attempt (story 0104); rows are never
     * deleted.
     */
    public function up(): void
    {
        Schema::create('invoice_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->string('recipient_email', 255);
            $table->boolean('is_copy')->default(false);
            $table->string('status', 20)->default('queued');
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. Data loss: dropping the table permanently discards the delivery
     * history of every invoice.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_deliveries');
    }
};
