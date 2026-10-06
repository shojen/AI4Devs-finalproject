<?php

namespace App\Livewire\Dashboard;

use App\Actions\Dashboard\GetLowStockProducts;
use App\Concerns\ChecksAbilitiesSafely;
use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dashboard widget: the products closest to running out (story 0083). Read-only: no public property
 * and no mutating method. A variable product appears as its parent; its name links to the parent's
 * editor only when the actor may update products (ProductPolicy::update ignores its target, so one
 * evaluation against a fresh instance covers every row).
 */
class LowStockWidget extends Component
{
    use ChecksAbilitiesSafely;

    /**
     * @return array{rows: list<array{id: string, name: ?string, sku: string, effectiveStock: int, isOutOfStock: bool, hasVariants: bool, lowVariantCount: int}>, canEdit: bool}
     */
    #[Computed]
    public function products(): array
    {
        if (! $this->allowsSafely('viewAny', Product::class)) {
            return ['rows' => [], 'canEdit' => false];
        }

        return [
            'rows' => app(GetLowStockProducts::class)(),
            'canEdit' => $this->allowsSafely('update', new Product),
        ];
    }
}
