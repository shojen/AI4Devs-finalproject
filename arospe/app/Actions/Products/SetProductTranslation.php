<?php

namespace App\Actions\Products;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Translations\ResolveActiveStoreLanguage;
use App\Actions\Translations\SetTranslation;
use App\Concerns\ProductValidationRules;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Story 0076b: the backend layer of the two-layer write guard for one product's translatable
 * fields in one store language. Self-authorizing (and refusal-logging) and self-validating wrapper
 * over the deliberately unguarded SetTranslation primitive. Authorizes BEFORE it validates, always
 * on the Product itself (there is no translation policy).
 */
class SetProductTranslation
{
    use ProductValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly SanitizeProductDescription $sanitizeProductDescription,
        private readonly CanonicalizeProductSlug $canonicalizeProductSlug,
        private readonly ResolveActiveStoreLanguage $resolveActiveStoreLanguage,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateProductUniqueViolation $translateProductUniqueViolation,
    ) {}

    /**
     * Authorize, validate and persist one product's translation in one ACTIVE store language.
     *
     * The passed product and language are untrusted: both are re-read, the language through
     * ResolveActiveStoreLanguage. Error keys are derived from the language id, never accepted as input.
     *
     * @throws AuthorizationException when the actor lacks products.edit (logged first)
     * @throws ValidationException keyed "{names|slugs|descriptions|metaTitles|metaDescriptions}.{$language->id}"
     * @throws LogicException when the primitive returns an unexpected model
     */
    public function __invoke(
        Product $product,
        StoreLanguage $language,
        string $name,
        ?string $description = null,
        ?string $slug = null,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
    ): ProductTranslation {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $product,
            targetType: 'product',
            targetId: $product->id,
        );

        $product = Product::query()->whereKey($product->getKey())->firstOrFail();

        $nameKey = "names.{$language->id}";

        $activeLanguage = ($this->resolveActiveStoreLanguage)($language, $nameKey, __('products.editor.tabs.language_inactive'));

        $languageId = $activeLanguage->id;
        $name = trim($name);
        $description = $this->emptyToNull(($this->sanitizeProductDescription)($description));
        $slug = ($this->canonicalizeProductSlug)($slug);
        $metaTitle = $this->emptyToNull($metaTitle === null ? null : trim($metaTitle));
        $metaDescription = $this->emptyToNull($metaDescription === null ? null : trim($metaDescription));

        // Nested data with dotted rule keys: a flat "names.{id}" data key is escaped by
        // Validator::parseData() and never reaches the rule.
        Validator::make(
            [
                'names' => [$languageId => $name],
                'descriptions' => [$languageId => $description],
                'slugs' => [$languageId => $slug],
                'metaTitles' => [$languageId => $metaTitle],
                'metaDescriptions' => [$languageId => $metaDescription],
            ],
            [
                $nameKey => $this->productNameRules(),
                "descriptions.{$languageId}" => $this->productDescriptionRules(),
                "slugs.{$languageId}" => $this->productSlugRules($languageId, $product->id),
                "metaTitles.{$languageId}" => $this->productMetaTitleRules(),
                "metaDescriptions.{$languageId}" => $this->productMetaDescriptionRules(),
            ],
            [],
            [
                'names.*' => __('products.attributes.name'),
                'descriptions.*' => __('products.attributes.description'),
                'slugs.*' => __('products.attributes.slug'),
                'metaTitles.*' => __('products.attributes.meta_title'),
                'metaDescriptions.*' => __('products.attributes.meta_description'),
            ],
        )->validate();

        try {
            $translation = ($this->setTranslation)($product, $activeLanguage, [
                'name' => $name,
                'description' => $description,
                'slug' => $slug,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDescription,
            ]);
        } catch (QueryException $e) {
            throw ($this->translateProductUniqueViolation)($e, $languageId);
        }

        if (! $translation instanceof ProductTranslation) {
            throw new LogicException('SetTranslation returned an unexpected model for a product.');
        }

        return $translation;
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
