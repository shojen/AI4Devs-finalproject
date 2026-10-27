<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\BlogTagValidationRules;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Story 0075: the backend layer of the two-layer write guard for one blog tag name in one store
 * language. Self-authorizing (and refusal-logging) and self-validating wrapper over story 0070's
 * deliberately unguarded App\Actions\Translations\SetTranslation primitive -- the ONLY class that
 * reaches it for a tag translation, so no caller (component, importer, Artisan command, queued job)
 * can write one without inheriting the whole rule.
 *
 * Constructor injection for the same reason as RenameBlogTag: __invoke()'s three domain arguments
 * are this action's whole public signature. Authorizes BEFORE it validates (0058 D-13), always on
 * the BlogTag itself -- translated content adds no ability and there is no translation policy.
 */
class SetBlogTagTranslation
{
    use BlogTagValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateBlogTagNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Authorize, validate and persist one blog tag's name in one ACTIVE store language.
     *
     * The passed language is untrusted (docs/security/model-instance-trust.md): it is re-read
     * through the `active()` scope, so a removed language cannot be written to even by a forged
     * payload that bypassed the tab strip (D-5). The error key is derived from the language, never
     * accepted as a parameter, so a caller cannot point a refusal at the wrong tab (D-12).
     *
     * @throws AuthorizationException when the actor lacks blog.edit (logged first)
     * @throws ValidationException keyed "names.{$language->id}" -- inactive language, blank,
     *                             over-length, or duplicate within that store language (incl. the
     *                             1062 race)
     * @throws LogicException when the primitive returns an unexpected model
     */
    public function __invoke(
        BlogTag $blogTag,
        StoreLanguage $language,
        string $name,
    ): BlogTagTranslation {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $blogTag,
            targetType: 'blog_tag',
            targetId: $blogTag->id,
        );

        // The passed tag is untrusted too: once authorized it is re-read, and everything below
        // goes through the fresh copy (a tag deleted meanwhile is a clean 404, not an FK error).
        $blogTag = BlogTag::query()->whereKey($blogTag->getKey())->firstOrFail();

        $errorKey = "names.{$language->id}";
        $attributeLabel = __('blog-tags.index.tabs.name_attribute');

        $activeLanguage = StoreLanguage::query()->active()->find($language->id);

        if ($activeLanguage === null) {
            throw ValidationException::withMessages([
                $errorKey => __('blog-tags.index.tabs.language_inactive'),
            ]);
        }

        $name = $this->trimName($name);

        // Nested data with a dotted rule key: a flat ["names.{id}" => $name] data key is escaped
        // by Validator::parseData() and never reaches the rule.
        Validator::make(
            ['names' => [$activeLanguage->id => $name]],
            [$errorKey => $this->nameRules($this->normalizeForSearch, $activeLanguage->id, $blogTag->id)],
            [],
            ['names.*' => $attributeLabel],
        )->validate();

        try {
            $translation = ($this->setTranslation)($blogTag, $activeLanguage, ['name' => $name]);
        } catch (QueryException $e) {
            throw ($this->translateNameUniqueViolation)($e, $errorKey, $attributeLabel);
        }

        if (! $translation instanceof BlogTagTranslation) {
            throw new LogicException('SetTranslation returned an unexpected model for a blog tag.');
        }

        return $translation;
    }
}
