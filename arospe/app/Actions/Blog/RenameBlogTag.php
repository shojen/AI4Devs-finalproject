<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RenameBlogTag
{
    use BlogTagValidationRules;

    /**
     * Constructor injection for the same reason as CreateBlogTag: __invoke()'s two domain
     * arguments are this action's whole public signature.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
    ) {}

    /**
     * Rename an existing blog tag. A rename onto a name another tag holds is refused, never merged
     * (D-7): merging would mean reassigning `blog_post_tag` rows, a table this story does not own.
     *
     * Authorizes `update` on the target as its own first statement (D-12). The uniqueness rule
     * ignores the target's own id (R-1), which is what makes saving a tag under its own unchanged
     * name succeed.
     *
     * The passed instance is untrusted (docs/security/model-instance-trust.md): once authorized,
     * the row is re-read and every read and write below goes through that fresh copy. Otherwise a
     * stale instance turns a rename back to its old name into a silent no-op reported as success,
     * and whatever else the caller left dirty rides along in the save.
     */
    public function __invoke(BlogTag $blogTag, string $name): BlogTag
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogTag,
            targetType: 'blog_tag',
            targetId: $blogTag->id,
        );

        $name = $this->trimName($name);

        $current = BlogTag::query()->whereKey($blogTag->getKey())->firstOrFail();

        Validator::make(
            ['name' => $name],
            ['name' => $this->nameRules($this->normalizeForSearch, $current->id)],
        )->validate();

        try {
            $current->update(['name' => $name]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                // Last-word race guard behind the validation rule above -- see CreateBlogTag.
                throw ValidationException::withMessages([
                    'name' => trans('validation.unique', ['attribute' => 'name']),
                ]);
            }

            throw $e;
        }

        return $current;
    }
}
