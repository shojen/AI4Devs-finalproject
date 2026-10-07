<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
        private readonly SetTranslation $setTranslation,
        private readonly TranslateBlogCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Rename an existing blog category in the store DEFAULT language (story 0072, D-7).
     *
     * Authorizes `update` on the target as its own first statement (D-13). The uniqueness rule
     * ignores the target's own translations by `blog_category_id` (R-1, D-4), which is what makes
     * saving a category under its own unchanged name succeed.
     *
     * The passed instance is untrusted (docs/security/model-instance-trust.md): once authorized,
     * the row is re-read and every read and write below goes through that fresh copy. Otherwise a
     * stale instance, or a stale loaded `translations` relation, would let the write act on
     * whatever the caller left behind.
     */
    public function __invoke(BlogCategory $blogCategory, string $name): BlogCategory
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogCategory,
            targetType: 'blog_category',
            targetId: $blogCategory->id,
        );

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot rename a blog category: no default store language is configured.',
        );

        $name = $this->trimName($name);

        $current = BlogCategory::query()->whereKey($blogCategory->getKey())->firstOrFail();

        Validator::make(
            ['name' => $name],
            $this->blogCategoryRules($this->normalizeForSearch, $defaultLanguage->id, $current->id),
        )->validate();

        try {
            ($this->setTranslation)($current, $defaultLanguage, ['name' => $name]);
        } catch (QueryException $e) {
            // Last-word race guard behind the validation rule above -- see CreateBlogCategory.
            throw ($this->translateNameUniqueViolation)($e);
        }

        return $current;
    }
}
