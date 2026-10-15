<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
        private readonly SetTranslation $setTranslation,
        private readonly TranslateBlogTagNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Rename an existing blog tag in the store DEFAULT language (story 0074, D-7). A rename onto a
     * name another tag holds is refused, never merged (D-7 of 0059): merging would mean
     * reassigning `blog_post_tag` rows, a table this story does not own.
     *
     * Authorizes `update` on the target as its own first statement (D-12). The uniqueness rule
     * excludes the target's own translations by `blog_tag_id` (D-6), which is what makes saving a
     * tag under its own unchanged name succeed. No transaction: a rename is a single
     * `updateOrCreate` against one table.
     *
     * The passed instance is untrusted (docs/security/model-instance-trust.md): once authorized,
     * the row is re-read and every read and write below goes through that fresh copy.
     */
    public function __invoke(BlogTag $blogTag, string $name): BlogTag
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogTag,
            targetType: 'blog_tag',
            targetId: $blogTag->id,
        );

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot rename a blog tag: no default store language is configured.',
        );

        $name = $this->trimName($name);

        $current = BlogTag::query()->whereKey($blogTag->getKey())->firstOrFail();

        Validator::make(
            ['name' => $name],
            ['name' => $this->nameRules($this->normalizeForSearch, $defaultLanguage->id, $current->id)],
        )->validate();

        try {
            ($this->setTranslation)($current, $defaultLanguage, ['name' => $name]);
        } catch (QueryException $e) {
            // Last-word race guard behind the validation rule above -- see CreateBlogTag.
            throw ($this->translateNameUniqueViolation)($e);
        }

        return $current;
    }
}
