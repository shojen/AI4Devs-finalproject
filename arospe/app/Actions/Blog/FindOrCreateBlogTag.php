<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class FindOrCreateBlogTag
{
    use BlogTagValidationRules;

    /**
     * Constructor injection: __invoke()'s single domain argument is this action's whole public
     * signature, shared by every caller (the tag screen and story 0061's post save).
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
    ) {}

    /**
     * Resolve a tag by name, creating it only when nothing matches.
     *
     * A plain BlogTag comes back: `wasRecentlyCreated` already tells the caller which path ran.
     *
     * Story 0074 (D-7, D-8): the lookup is scoped to the store default language, since one
     * normalised name can legitimately identify two tags in two languages. The one exception is a
     * tag with no default-language name at all (see findNameHeldOnlyOutsideDefault()). A new tag
     * is authored in the default language.
     *
     * Authorization is conditional and both gates sit ABOVE the SetTranslation call, since
     * SetTranslation authorizes nothing (D-12): the caller must be able to read the tag catalog,
     * or to create in it. Only the insert branch asks `create`.
     *
     * Only the FORMAT is validated, never uniqueness: an existing name is a hit here. The name is
     * trimmed and validated BEFORE any lookup, so two different whitespace-only inputs cannot both
     * fold to '' and resolve to one shared empty-named row.
     *
     * A lost insert race is not a refusal, it is a success: a `23000` is caught and the winning
     * row is re-fetched. The parent row and its translation are written in one transaction (D-5,
     * overriding 0059's D-10), so losing the race rolls the parent back rather than leaving a
     * permanently translationless tag.
     */
    public function __invoke(string $name): BlogTag
    {
        $this->authorizeLookup();

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot resolve a blog tag: no default store language is configured.',
        );

        $name = $this->trimName($name);

        Validator::make(
            ['name' => $name],
            ['name' => $this->nameFormatRules($this->normalizeForSearch)],
        )->validate();

        $existing = $this->findByName($name, $defaultLanguage);

        if ($existing !== null) {
            return $existing;
        }

        $this->logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

        try {
            return DB::transaction(function () use ($name, $defaultLanguage): BlogTag {
                $blogTag = BlogTag::create();

                ($this->setTranslation)($blogTag, $defaultLanguage, ['name' => $name]);

                return $blogTag;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $winner = $this->findByName($name, $defaultLanguage, locking: true);

                if ($winner !== null) {
                    return $winner;
                }
            }

            throw $e;
        }
    }

    /**
     * The gate for the lookup branch: an actor who may create may certainly reuse, so `create`
     * short-circuits without logging a refusal that never happened; otherwise `viewAny` decides,
     * and its refusal is the one that is logged.
     */
    private function authorizeLookup(): void
    {
        if (Gate::allows('create', BlogTag::class)) {
            return;
        }

        $this->logRefusedPrivilegedAttempt->authorize('viewAny', BlogTag::class, targetType: 'blog_tag');
    }

    /**
     * The bounded fallback (0074 D-8 amendment): a tag that holds NO default-language name -- left
     * behind when the store default changed -- is still reachable by the name it does have, so a
     * post save reuses it instead of minting a duplicate and detaching the original. Tags that DO
     * have a default-language name are never matched through another language, so one normalised
     * name keeps a single answer; the oldest translation wins when several tags qualify.
     */
    private function findNameHeldOnlyOutsideDefault(string $name, StoreLanguage $language, bool $locking): ?BlogTagTranslation
    {
        return BlogTagTranslation::query()
            ->where('normalized_name', ($this->normalizeForSearch)($name))
            ->where('store_language_id', '!=', $language->id)
            ->whereNotIn('blog_tag_id', BlogTagTranslation::query()
                ->select('blog_tag_id')
                ->where('store_language_id', $language->id))
            ->when($locking, fn ($query) => $query->sharedLock())
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * A locking read is used to re-fetch a race winner: when the caller wraps this action in its own
     * transaction (story 0061's post save), the first lookup has already fixed InnoDB's REPEATABLE
     * READ snapshot, so a plain consistent read would not see a row another request has since
     * committed, although the duplicate-key error that sent us here did. A locking read is a current
     * read and does.
     */
    private function findByName(string $name, StoreLanguage $language, bool $locking = false): ?BlogTag
    {
        $translation = BlogTagTranslation::query()
            ->where('store_language_id', $language->id)
            ->where('normalized_name', ($this->normalizeForSearch)($name))
            ->when($locking, fn ($query) => $query->sharedLock())
            ->first();

        if ($translation === null) {
            $translation = $this->findNameHeldOnlyOutsideDefault($name, $language, $locking);
        }

        if ($translation === null) {
            return null;
        }

        $query = BlogTag::query()->whereKey($translation->blog_tag_id);

        if ($locking) {
            $query->sharedLock();
        }

        return $query->first();
    }
}
