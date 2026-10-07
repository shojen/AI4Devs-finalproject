<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogCategoryValidationRules;
use App\Models\BlogCategory;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
        private readonly SetTranslation $setTranslation,
        private readonly TranslateBlogCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Create a new blog category, writing its name into the store DEFAULT language only (story
     * 0072, D-7) -- the parent row and its translation in one transaction.
     *
     * Authorizes `create` on `BlogCategory::class` as its own first statement (D-13), so a
     * non-dashboard caller inherits the refusal. `targetType` is passed explicitly since
     * LogRefusedPrivilegedAttempt only auto-resolves User and Role targets.
     *
     * The default store language is resolved AFTER authorizing but BEFORE validating: writing a
     * "default-language" name with no default language configured is meaningless, so this refuses
     * legibly rather than validating against an id that does not exist.
     *
     * The name is trimmed BEFORE validation, not after, so `max` and the uniqueness fold see the
     * value that will actually be stored (a name padded past 255 characters by surrounding
     * whitespace must not be refused). The trim is Unicode-aware -- see trimName().
     */
    public function __invoke(string $name): BlogCategory
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', BlogCategory::class, targetType: 'blog_category');

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot create a blog category: no default store language is configured.',
        );

        $name = $this->trimName($name);

        Validator::make(
            ['name' => $name],
            $this->blogCategoryRules($this->normalizeForSearch, $defaultLanguage->id),
        )->validate();

        try {
            return DB::transaction(function () use ($name, $defaultLanguage): BlogCategory {
                $blogCategory = BlogCategory::create();

                ($this->setTranslation)($blogCategory, $defaultLanguage, ['name' => $name]);

                return $blogCategory;
            });
        } catch (QueryException $e) {
            // The per-language normalized_name unique index is the last-word RACE guard behind
            // the validation rule above -- the pre-flight check is not one (D-4).
            throw ($this->translateNameUniqueViolation)($e);
        }
    }
}
