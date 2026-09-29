<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A fixed `TINYINT UNSIGNED` primary key, literal `1`, deliberately NOT UUIDv7 -- story 0068,
     * D19/D20, a further named exception to ADR 0001 Amendment 1's policy (see Amendment 5's
     * geography_entries for the other named exception, and the pending amendment backlog item 8
     * for this one). Neither locale column carries a database default (D21 -- they ARE the
     * fallback, so a DEFAULT would let a broken bootstrap silently succeed with a value nobody
     * chose) and neither is enum-cast on the model. No index beyond the primary key: the row is
     * only ever fetched by its fixed key.
     */
    public function up(): void
    {
        Schema::create('locale_settings', function (Blueprint $table): void {
            $table->tinyInteger('id')->unsigned()->primary();
            $table->string('default_ui_locale', 5);
            $table->string('default_notification_locale', 5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locale_settings');
    }
};
