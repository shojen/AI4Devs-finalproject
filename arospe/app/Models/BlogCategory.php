<?php

namespace App\Models;

use App\Actions\NormalizeForSearch;
use Database\Factories\BlogCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A blog category -- deliberately a standalone taxonomy, sharing no table, model or namespace with
 * App\Models\ProductCategory (story 0058, D-11).
 *
 * @property string $id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class BlogCategory extends Model
{
    /** @use HasFactory<BlogCategoryFactory> */
    use HasFactory, HasUuids;

    /**
     * Length of `name` and of `normalized_name` in the migration, and the `max:` its validation
     * rule enforces (R-4). Kept in one place so the three cannot drift apart.
     */
    public const NAME_MAX_LENGTH = 255;

    /**
     * `normalized_name` is the folded key the UNIQUE index guards and every lookup compares
     * against. It is derived from the mutable `name`, so it is locked at the model layer rather
     * than left to every writer to remember (D-12): omitted from #[Fillable], and rewritten here
     * whenever a save touches either column.
     *
     * `booted()`, not `boot()`: App\Models\Role's `boot()` is a vendor-hook-ordering workaround
     * that does not apply to a model extending Eloquent's Model directly.
     *
     * Also re-derived when `normalized_name` itself was assigned, not only when `name` was --
     * otherwise `$category->normalized_name = 'x'` followed by a save that leaves `name` clean
     * would persist a key that no longer matches the name. An unrelated save (neither column
     * dirty) still leaves the column alone.
     */
    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if ($category->isDirty('name') || $category->isDirty('normalized_name')) {
                $category->normalized_name = app(NormalizeForSearch::class)($category->name);
            }
        });
    }
}
