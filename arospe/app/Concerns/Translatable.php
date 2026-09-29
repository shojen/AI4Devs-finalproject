<?php

namespace App\Concerns;

use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Model;

/**
 * Story 0070: the minimal public shape App\Concerns\HasTranslations gives a consuming model,
 * named so a cross-cutting caller (App\Actions\Translations\SetTranslation) can type-hint
 * "any model that has translations" without widening to the bare Eloquent Model -- which has no
 * translation-relation method of its own. Every model that `use HasTranslations;` also
 * `implements Translatable`; the interface carries no logic of its own.
 *
 * Deliberately does NOT expose `translations(): HasMany` here (unlike the trait's own public
 * contract) -- Laravel's `HasMany` declares its `TDeclaringModel` template as invariant, so no
 * single generic shape this interface could declare stays compatible with every concrete
 * implementer's own `HasMany<Model, $this>` return. `firstTranslationOrNew()` is the one
 * relation-building step App\Actions\Translations\SetTranslation actually needs, and returning a
 * plain (non-generic) Model sidesteps that invariance conflict entirely.
 */
interface Translatable
{
    /**
     * The existing translation row for the given store language, or a new, in-memory one whose
     * foreign keys (including `store_language_id`, via `forceFill()` -- see
     * App\Models\ProductCategoryTranslation's docblock on why that column is deliberately absent
     * from `#[Fillable]`) are already set and ready for the caller to `fill()` and `save()`.
     */
    public function firstTranslationOrNew(StoreLanguage $language): Model;
}
