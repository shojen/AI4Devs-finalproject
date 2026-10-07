<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Story 0073: the backend layer of the two-layer write guard. Self-authorizing (and
 * refusal-logging) and self-validating wrapper over story 0070's deliberately unguarded
 * App\Actions\Translations\SetTranslation primitive, for one blog category name in one store
 * language.
 *
 * Constructor injection for the same reason as RenameBlogCategory: __invoke()'s three domain
 * arguments are this action's whole public signature. Authorizes BEFORE it validates (0058 D-13).
 */
class SetBlogCategoryTranslation
{
    use BlogCategoryValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateBlogCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Authorize, validate and persist one blog category's name in one store language.
     *
     * @throws AuthorizationException when the actor lacks blog.edit (logged first)
     * @throws ValidationException keyed "names.{$language->id}" -- blank, over-length, or
     *                             duplicate within that store language (incl. the 1062 race)
     * @throws LogicException when the primitive returns an unexpected model
     */
    public function __invoke(
        BlogCategory $blogCategory,
        StoreLanguage $language,
        string $name,
    ): BlogCategoryTranslation {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogCategory,
            targetType: 'blog_category',
            targetId: $blogCategory->id,
        );

        $name = $this->trimName($name);
        $errorKey = "names.{$language->id}";
        $attributeLabel = __('blog.categories.index.tabs.name_attribute');

        // Nested data with a dotted rule key: a flat ["names.{id}" => $name] data key is escaped
        // by Validator::parseData() and never reaches the rule.
        Validator::make(
            ['names' => [$language->id => $name]],
            [$errorKey => $this->nameRules($this->normalizeForSearch, $language->id, $blogCategory->id)],
            [],
            ['names.*' => $attributeLabel],
        )->validate();

        try {
            $translation = ($this->setTranslation)($blogCategory, $language, ['name' => $name]);
        } catch (QueryException $e) {
            throw ($this->translateNameUniqueViolation)($e, $errorKey, $attributeLabel);
        }

        if (! $translation instanceof BlogCategoryTranslation) {
            throw new LogicException('SetTranslation returned an unexpected model for a blog category.');
        }

        return $translation;
    }
}
