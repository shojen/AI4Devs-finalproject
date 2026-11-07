<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;

/**
 * Story 0076 (D-22, N-1): the identifiable `afterCreating` step of ProductFactory. A class rather
 * than a closure so named()/withoutTranslations() can recognise and swap just this callback,
 * leaving every other one (e.g. withGallery()'s) in place.
 */
class WritesDefaultProductTranslation
{
    public function __construct(
        private readonly ?string $name = null,
        private readonly ?string $description = null,
    ) {}

    public function __invoke(Product $product): void
    {
        $defaultLanguage = StoreLanguage::query()->where('is_default', true)->first()
            ?? StoreLanguage::factory()->default()->create();

        $attributes = [
            'product_id' => $product->id,
            'name' => $this->name ?? fake()->words(3, true),
        ];

        if ($this->name !== null) {
            $attributes['description'] = $this->description;
        }

        ProductTranslation::factory()->forLanguage($defaultLanguage)->create($attributes);
    }
}
