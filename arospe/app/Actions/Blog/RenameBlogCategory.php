<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RenameBlogCategory
{
    use BlogCategoryValidationRules;

    /**
     * Constructor injection for the same reason as CreateBlogCategory: __invoke()'s two domain
     * arguments are this action's whole public signature.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
    ) {}

    /**
     * Rename an existing blog category.
     *
     * Authorizes `update` on the target as its own first statement (D-13). The uniqueness rule
     * ignores the target's own id (R-1), which is what makes saving a category under its own
     * unchanged name succeed.
     *
     * The passed instance is untrusted (docs/security/model-instance-trust.md): once authorized,
     * the row is re-read and every read and write below goes through that fresh copy. Otherwise a
     * stale instance turns a rename back to its old name into a silent no-op reported as success,
     * and whatever else the caller left dirty rides along in the save.
     */
    public function __invoke(BlogCategory $blogCategory, string $name): BlogCategory
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogCategory,
            targetType: 'blog_category',
            targetId: $blogCategory->id,
        );

        $name = $this->trimName($name);

        $current = BlogCategory::query()->whereKey($blogCategory->getKey())->firstOrFail();

        Validator::make(
            ['name' => $name],
            $this->blogCategoryRules($this->normalizeForSearch, $current->id),
        )->validate();

        try {
            $current->update(['name' => $name]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                // Last-word race guard behind the validation rule above -- see CreateBlogCategory.
                throw ValidationException::withMessages([
                    'name' => trans('validation.unique', ['attribute' => 'name']),
                ]);
            }

            throw $e;
        }

        return $current;
    }
}
