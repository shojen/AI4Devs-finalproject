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
        Schema::create('blog_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // 255 in lockstep with BlogCategory::NAME_MAX_LENGTH and normalized_name below (R-4).
            $table->string('name', 255);

            // The uniqueness rule lives HERE, not on `name` (D-4). Both this index and every
            // app-level lookup compare the output of the one shared App\Actions\NormalizeForSearch,
            // so there is no second definition of "the same category name" to drift from the first.
            // 255 is a hard ceiling, not headroom: the fold can be up to 5x longer than its input
            // (Str::ascii() transliterates), so BlogCategoryValidationRules refuses any name whose
            // folded form would not fit here, instead of letting the key truncate or 22001.
            $table->string('normalized_name', 255)->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_categories');
    }
};
