<?php

namespace App\Actions\Translations;

use App\Concerns\Translatable;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Model;

/**
 * Story 0070 (D-8, D-9, D-12): the single cross-cutting write primitive every translatable
 * entity's actions reuse -- an update-or-create on the `(entity, language)` natural key, which is
 * what makes re-translating a category replace its row rather than duplicate it.
 *
 * NOT a bare `$translatable->translations()->updateOrCreate([...], [...])`: a translation
 * model's own `store_language_id` is deliberately omitted from its `#[Fillable]` list (see
 * App\Models\ProductCategoryTranslation's docblock) -- the guarded relation helper's `make()`
 * fills a new instance through that SAME mass-assignment guard, so `store_language_id` would be
 * silently dropped and the insert would fail on the column's NOT NULL constraint. This primitive
 * is precisely the "explicit key list" writer those docblocks promise: `store_language_id` is set
 * with `forceFill()`, and only the caller-supplied `$attributes` (`name`, and later sibling
 * fields) pass through the ordinary, still-guarded `fill()`.
 *
 * Deliberately does NOT authorize and does NOT validate (D-9). The correct ability is a
 * property of the CALLING operation, not of this primitive: App\Actions\ProductCategories\
 * CreateProductCategory authorizes `products.create`, while App\Actions\ProductCategories\
 * RenameProductCategory authorizes `products.edit` -- both call this same primitive, so a
 * self-authorizing `update` check here would wrongly lock create behind the edit permission.
 * Guarded, validating authoring of a non-default language is a caller this story does not ship
 * (story 0071's SetProductCategoryTranslation).
 */
class SetTranslation
{
    /**
     * @param  Model&Translatable  $translatable
     * @param  array<string, string|null>  $attributes
     */
    public function __invoke(Model $translatable, StoreLanguage $language, array $attributes): Model
    {
        $translation = $translatable->firstTranslationOrNew($language);

        $translation->fill($attributes);
        $translation->save();

        return $translation;
    }
}
