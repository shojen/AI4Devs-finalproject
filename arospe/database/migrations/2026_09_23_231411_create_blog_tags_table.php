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
        Schema::create('blog_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // 100 in lockstep with BlogTag::NAME_MAX_LENGTH and the validation `max:` (R-4).
            $table->string('name', 100);

            // The uniqueness rule lives HERE, not on `name` (D-3). Both this index and every
            // app-level lookup compare the output of the one shared App\Actions\NormalizeForSearch,
            // so there is no second definition of "the same tag name" to drift from the first.
            //
            // The value is STORED, so a change to that normaliser is a data migration for this
            // table -- every row's normalized_name would need recomputing (D-2), the same "re-seed
            // event" 0032's geography_entries.normalized_name already documents.
            //
            // 255 is a hard ceiling, not headroom: the fold can be up to 5x longer than its input
            // (Str::ascii() transliterates; measured over every code point, story 0059 Phase 2), so
            // BlogTagValidationRules refuses any name whose folded form would not fit here, instead
            // of letting the key truncate or raise a 22001. Deliberately no unique index on `name`.
            $table->string('normalized_name', 255)->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_tags');
    }
};
