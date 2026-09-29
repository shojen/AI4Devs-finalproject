<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

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
    ) {}

    /**
     * Resolve a tag by name, creating it only when nothing matches.
     *
     * A plain BlogTag comes back (D-10): `wasRecentlyCreated` already tells the caller which path
     * ran, so no return shape is invented for it.
     *
     * Authorization is conditional (D-11), and the first gate runs before anything is read or
     * written: the caller must be able to read the tag catalog, or to create in it. Reusing an
     * existing tag is a pure lookup. Only the insert branch asks `create`, the same ability
     * CreateBlogTag asks. The action asks nothing about posts -- its caller authorizes that itself.
     *
     * Only the FORMAT is validated, never uniqueness (D-9): an existing name is a hit here. The name
     * is trimmed and validated BEFORE any lookup, so two different whitespace-only inputs cannot
     * both fold to '' and resolve to one shared empty-named row (R-2).
     *
     * The lookup key is `normalized_name`, the column the unique index guards, so the check and the
     * constraint cannot disagree (D-3).
     *
     * A lost insert race is not a refusal, it is a success: someone else already created what the
     * caller asked for. So a `23000` is caught and the winning row is re-fetched -- the opposite of
     * CreateBlogTag's catch, which is correct for a create and would break this action's core case
     * (D-10). No DB::transaction(): the unique index plus this catch close the race, and a
     * transaction would neither prevent the collision nor change how it resolves.
     */
    public function __invoke(string $name): BlogTag
    {
        $this->authorizeLookup();

        $name = $this->trimName($name);

        Validator::make(
            ['name' => $name],
            ['name' => $this->nameFormatRules($this->normalizeForSearch)],
        )->validate();

        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $existing;
        }

        $this->logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

        try {
            return BlogTag::create(['name' => $name]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $winner = $this->findByName($name, locking: true);

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
     * A locking read is used to re-fetch a race winner: when the caller wraps this action in its own
     * transaction (story 0061's post save), the first lookup has already fixed InnoDB's REPEATABLE
     * READ snapshot, so a plain consistent read would not see a row another request has since
     * committed, although the duplicate-key error that sent us here did. A locking read is a current
     * read and does.
     */
    private function findByName(string $name, bool $locking = false): ?BlogTag
    {
        return BlogTag::query()
            ->where('normalized_name', ($this->normalizeForSearch)($name))
            ->when($locking, fn ($query) => $query->sharedLock())
            ->first();
    }
}
