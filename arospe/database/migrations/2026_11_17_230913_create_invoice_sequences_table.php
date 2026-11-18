<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations. One counter row per invoice series; the two rows are created here and
     * never inserted at runtime (story 0101a, FD-1, FD-3).
     */
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('series', 20)->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->unsignedSmallInteger('last_year')->nullable();
            $table->dateTime('last_issued_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach (['standard', 'corrective'] as $series) {
            DB::table('invoice_sequences')->insert([
                'id' => (string) Str::uuid7(),
                'series' => $series,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
