<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable, no default and deliberately no backfill (story 0066, D-3): NULL means "this
     * account never chose", and any non-null default would falsely record a choice nobody made.
     * No index (D-4): only ever read per-row through the primary key. VARCHAR(5), not a bare
     * string() (D-2), holding `en`/`es` with headroom for a future `en_US`-shaped value.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('ui_locale', 5)->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_locale');
        });
    }
};
