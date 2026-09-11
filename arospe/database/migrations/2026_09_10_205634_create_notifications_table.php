<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            // uuidMorphs(), NOT the stub's default morphs() -- users.id is a
            // CHAR(36) UUID (see docs/database/schema.md#users), so a plain
            // morphs() would emit an UNSIGNED BIGINT notifiable_id that can
            // never hold a real recipient. This migration is greenfield, so
            // the correction costs one line -- no rename, no backfill, no
            // multi-migration dance, unlike the historical users conversion.
            // See docs/database/migrations.md#uuid-primary-keys.
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
