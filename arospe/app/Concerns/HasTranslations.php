<?php

namespace App\Concerns;

use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 0070 (D-5, D-6, D-10, R-4): the shared read-side contract mixed into every translatable
 * model. One implementation, consumed -- never re-implemented -- by every sibling story that adds
 * its own `<entity>_translations` table (0072/0074/0076/0078).
 *
 * @mixin Model
 */
trait HasTranslations
{
    /**
     * Set by the consuming model; never inferred, since the trait has no way to guess the
     * translation model's class name from the parent's own.
     *
     * @return class-string<Model>
     */
    abstract protected function translationModel(): string;

    /**
     * @return HasMany<Model, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel());
    }

    /**
     * Story 0070 (App\Concerns\Translatable): the one relation-building step
     * App\Actions\Translations\SetTranslation needs, returning a plain Model rather than the
     * generically-typed HasMany relation itself -- see Translatable's own docblock for why.
     *
     * Deliberately queries the relation METHOD (a real, fresh query), never the already-loaded
     * `$this->translations` collection property `translated()` reads (R-4): this is the WRITE
     * path, called at most once per SetTranslation invocation, and the loaded collection may be
     * stale or entirely absent at write time -- reading it here would risk missing a translation
     * that genuinely exists and inserting a second, colliding row instead of updating the first.
     */
    public function firstTranslationOrNew(StoreLanguage $language): Model
    {
        $existing = $this->translations()->where('store_language_id', $language->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $new = $this->translations()->make();
        $new->forceFill(['store_language_id' => $language->id]);

        return $new;
    }

    /**
     * Resolve one translatable field for a store language, falling back to the store default
     * when the field is absent there. Returns null when neither supplies it, and also when no
     * default store language exists at all -- NEVER throws, because this runs on a
     * list-rendering path (D-6).
     *
     * Resolves per FIELD, not per row (D-5): invisible on a single-field entity, and the whole
     * point the moment a multi-field sibling (0076/0078) ships.
     */
    public function translated(string $field, ?string $storeLanguageId = null): ?string
    {
        $defaultId = StoreLanguage::defaultStoreLanguage()?->id;
        $requestedId = $storeLanguageId ?? $defaultId;

        // Reads the ALREADY-LOADED relation collection, never the relation method --
        // ->translations() would re-query per call and defeat eager loading (R-4).
        $value = $this->translations->firstWhere('store_language_id', $requestedId)?->{$field};

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->translations->firstWhere('store_language_id', $defaultId)?->{$field};
    }

    /**
     * Eager-load only the two languages a render actually needs, never every locale (R-4).
     *
     * @param  Builder<Model>  $query
     */
    public function scopeWithTranslationsFor(Builder $query, ?string $storeLanguageId = null): void
    {
        $ids = array_unique(array_filter([$storeLanguageId, StoreLanguage::defaultStoreLanguage()?->id]));

        $query->with(['translations' => fn ($q) => $q->whereIn('store_language_id', $ids)]);
    }
}
