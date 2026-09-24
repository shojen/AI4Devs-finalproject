<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateBlogTag
{
    use BlogTagValidationRules;

    /**
     * Constructor injection, not method injection: __invoke()'s single domain argument is this
     * action's whole public signature, matched verbatim by every direct-call test and the future
     * Livewire caller. See docs/conventions/code-style.md's constructor-injection exception.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
    ) {}

    /**
     * Create a new blog tag.
     *
     * Authorizes `create` on `BlogTag::class` as its own first statement (D-12), so a non-dashboard
     * caller inherits the refusal. `targetType` is passed explicitly since LogRefusedPrivilegedAttempt
     * only auto-resolves User and Role targets.
     *
     * The name is trimmed BEFORE validation, not after, so `max` and the uniqueness fold see the
     * value that will actually be stored, and a whitespace-only name is refused (R-2).
     */
    public function __invoke(string $name): BlogTag
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', BlogTag::class, targetType: 'blog_tag');

        $name = $this->trimName($name);

        Validator::make(
            ['name' => $name],
            ['name' => $this->nameRules($this->normalizeForSearch)],
        )->validate();

        try {
            return BlogTag::create(['name' => $name]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                // The normalized_name unique index is the last-word RACE guard behind the
                // validation rule above -- the pre-flight check is not one (D-3).
                throw ValidationException::withMessages([
                    'name' => trans('validation.unique', ['attribute' => 'name']),
                ]);
            }

            throw $e;
        }
    }
}
