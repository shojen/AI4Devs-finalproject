<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0054 -- the columns the virtual-product geo/fraud check reads and
     * writes. All nullable: `NULL` means "no IP was captured" / "not
     * flagged", never an empty capture. Deliberately no index on any of them:
     * nothing joins or filters on them (backlog item 2 owns a future filter).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('ip_address', 45)->after('flagged_for_review')->nullable();
            $table->string('ip_derived_country', 2)->after('ip_address')->nullable();
            $table->string('flag_reason', 64)->after('ip_derived_country')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['ip_address', 'ip_derived_country', 'flag_reason']);
        });
    }
};
