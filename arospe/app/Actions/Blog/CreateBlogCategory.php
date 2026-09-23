<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateBlogCategory
{
    use BlogCategoryValidationRules;

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
     * Create a new blog category.
     *
     * Authorizes `create` on `BlogCategory::class` as its own first statement (D-13), so a
     * non-dashboard caller inherits the refusal. `targetType` is passed explicitly since
     * LogRefusedPrivilegedAttempt only auto-resolves User and Role targets.
     *
     * The name is trimmed BEFORE validation, not after, so `max` and the uniqueness fold see the
     * value that will actually be stored (a name padded past 255 characters by surrounding
     * whitespace must not be refused). The trim is Unicode-aware -- see trimName().
     */
    public function __invoke(string $name): BlogCategory
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', BlogCategory::class, targetType: 'blog_category');

        $name = $this->trimName($name);

        Validator::make(
            ['name' => $name],
            $this->blogCategoryRules($this->normalizeForSearch),
        )->validate();

        try {
            return BlogCategory::create(['name' => $name]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                // The normalized_name unique index is the last-word RACE guard behind the
                // validation rule above -- the pre-flight check is not one (D-4).
                throw ValidationException::withMessages([
                    'name' => trans('validation.unique', ['attribute' => 'name']),
                ]);
            }

            throw $e;
        }
    }
}
