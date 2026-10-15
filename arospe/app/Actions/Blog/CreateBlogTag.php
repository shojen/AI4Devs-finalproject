<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
        private readonly SetTranslation $setTranslation,
        private readonly TranslateBlogTagNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Create a new blog tag, writing its name into the store DEFAULT language only (story 0074,
     * D-7) -- the parent row and its translation in ONE transaction (D-5), so a lost unique race
     * on the translation never leaves a translationless parent behind.
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

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot create a blog tag: no default store language is configured.',
        );

        $name = $this->trimName($name);

        Validator::make(
            ['name' => $name],
            ['name' => $this->nameRules($this->normalizeForSearch, $defaultLanguage->id)],
        )->validate();

        try {
            return DB::transaction(function () use ($name, $defaultLanguage): BlogTag {
                $blogTag = BlogTag::create();

                ($this->setTranslation)($blogTag, $defaultLanguage, ['name' => $name]);

                return $blogTag;
            });
        } catch (QueryException $e) {
            // The per-language normalized_name unique index is the last-word RACE guard behind
            // the validation rule above -- the pre-flight check is not one (D-3).
            throw ($this->translateNameUniqueViolation)($e);
        }
    }
}
