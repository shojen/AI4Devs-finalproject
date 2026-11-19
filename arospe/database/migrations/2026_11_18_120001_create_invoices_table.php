<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The complete invoices table (story 0101b, FD-1): frozen snapshot columns
     * plus the lifecycle and collection columns later stories write. The printed number is the only
     * unique key on numbering (FD-2); every foreign key restricts deletion (FD-4, FD-18).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 30);
            $table->string('type', 20);
            $table->string('reason', 20)->nullable();
            $table->string('series', 20);
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedInteger('sequence_number');
            $table->string('number', 50)->unique();
            $table->timestamp('issued_at')->index();
            $table->foreignUuid('corrects_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('template', 30);
            $table->string('primary_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->foreignUuid('logo_media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('issuer_legal_name', 255);
            $table->string('issuer_tax_id', 40);
            $table->string('issuer_address_line1', 255)->nullable();
            $table->string('issuer_address_line2', 255)->nullable();
            $table->string('issuer_city', 100)->nullable();
            $table->string('issuer_postal_code', 20)->nullable();
            $table->string('issuer_province', 100)->nullable();
            $table->string('issuer_country', 2)->nullable();
            $table->string('recipient_name', 150);
            $table->string('recipient_tax_id', 40)->nullable();
            $table->string('recipient_address_line1', 255)->nullable();
            $table->string('recipient_address_line2', 255)->nullable();
            $table->string('recipient_city', 100)->nullable();
            $table->string('recipient_postal_code', 20)->nullable();
            $table->string('recipient_province', 100)->nullable();
            $table->string('recipient_country', 2)->nullable();
            $table->decimal('tax_rate', 6, 3)->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_amount', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('total', 10, 2);
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->foreignUuid('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('collection_payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('collection_type', 20)->nullable();
            $table->boolean('collection_carried_over')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. Data loss: dropping the table permanently discards every issued
     * invoice.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
