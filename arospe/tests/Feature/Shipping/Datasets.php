<?php

use Illuminate\Support\Str;

// Story 0037. Pest scopes every `dataset()` call inside a "Datasets.php" file to the file's OWN
// directory (Pest\Support\DatasetInfo::scope() / Pest\Repositories\DatasetsRepository) -- see
// tests/Feature/ShippingRates/Datasets.php's own extensive comment for the full mechanism and the
// real incident that established it. That means the dataset defined THERE (scoped to
// tests/Feature/ShippingRates) is NOT addressable by name from a test file in THIS directory
// (tests/Feature/Shipping) -- the two are different scopes by design, not a gap to route around.
//
// This is therefore a deliberate, intentional DUPLICATE of that same dataset's content, not an
// oversight: RateEditorTest.php (this directory) needs to run the identical
// `Scenario Outline: An invalid shipping rate is rejected` cases THROUGH the component
// (App\Livewire\Shipping\Index::saveRate()) that tests/Feature/ShippingRates/*Test.php already
// runs directly against the actions. Keep the two definitions in sync by hand if
// ShippingRateValidationRules ever gains or changes a rule.
//
// ONE deliberate divergence from the sibling dataset (Phase 5 code-review finding F-1's fix):
// the expected error KEY in each row here is the CAMELCASE property name
// (App\Livewire\Shipping\Index::RATE_FIELD_MAP's values), never the snake_case action-array key
// tests/Feature/ShippingRates/Datasets.php uses -- saveRate() re-keys its ValidationException
// onto the component's own camelCase properties before it ever reaches the caller, so asserting
// the snake_case key here would fail against the real, fixed behaviour.
dataset('invalid_rate_attributes', function () {
    $baseline = fn (string $carrierId, string $zoneId): array => [
        'name' => 'Estándar',
        'shipping_carrier_id' => $carrierId,
        'shipping_zone_id' => $zoneId,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ];

    return [
        'min_greater_than_max' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'min_weight_kg' => '5',
                'max_weight_kg' => '2',
            ]),
            'maxWeightKg',
        ],
        'negative_price' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '-1.00',
            ]),
            'price',
        ],
        'negative_min_weight' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'min_weight_kg' => '-1',
            ]),
            'minWeightKg',
        ],
        'blank_name' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'name' => '',
            ]),
            'rateName',
        ],
        'blank_delivery_estimate' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'delivery_estimate' => '',
            ]),
            'deliveryEstimate',
        ],
        'unknown_carrier' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'shipping_carrier_id' => (string) Str::uuid7(),
            ]),
            'shippingCarrierId',
        ],
        'unknown_zone' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'shipping_zone_id' => (string) Str::uuid7(),
            ]),
            'shippingZoneId',
        ],
        'price_scientific_notation' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '1e2',
            ]),
            'price',
        ],
        'weight_over_precision' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'max_weight_kg' => '2.0001',
            ]),
            'maxWeightKg',
        ],
        'price_over_ceiling' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '100000000.00',
            ]),
            'price',
        ],
    ];
});
