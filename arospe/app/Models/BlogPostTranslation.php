<?php

namespace App\Models;

use App\Actions\Products\SanitizeProductDescription;
use Database\Factories\BlogPostTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 0078: one row per (blog post, store language). `title` and `body` are form-supplied and so
 * fillable; `blog_post_id` and `store_language_id` are written only by
 * App\Actions\Translations\SetTranslation's explicit key list, and `slug` is derived, never fillable.
 *
 * No SoftDeletes (D-5): a trashed post's rows stay physically present, which is what keeps its slug
 * reserved in each language.
 *
 * @property string $id
 * @property string $blog_post_id
 * @property string $store_language_id
 * @property string $title
 * @property string $slug
 * @property string|null $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'body'])]
class BlogPostTranslation extends Model
{
    /** @use HasFactory<BlogPostTranslationFactory> */
    use HasFactory, HasUuids;

    /**
     * The name of the per-language slug unique index. Both actions match a 1062 error against it
     * (`str_contains`, so MySQL's `table.index` form and MariaDB's bare name both work).
     */
    public const LANGUAGE_SLUG_UNIQUE_INDEX = 'blog_post_translations_language_slug_unique';

    /**
     * The slug follows the title whenever it changes (D-4) and the body is sanitized whenever it
     * changes (D-8, second layer behind the actions). The hook only derives: it has no empty-slug
     * guard, which stays action-level.
     *
     * `booted()`, not `boot()`: this class extends Eloquent's Model directly.
     */
    protected static function booted(): void
    {
        static::saving(function (self $translation): void {
            if ($translation->isDirty('title')) {
                $translation->slug = Str::slug($translation->title);
            }

            if ($translation->isDirty('body')) {
                $translation->body = app(SanitizeProductDescription::class)($translation->body);
            }
        });
    }

    /**
     * @return BelongsTo<BlogPost, $this>
     */
    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class)->withTrashed();
    }

    /**
     * @return BelongsTo<StoreLanguage, $this>
     */
    public function storeLanguage(): BelongsTo
    {
        return $this->belongsTo(StoreLanguage::class);
    }
}
