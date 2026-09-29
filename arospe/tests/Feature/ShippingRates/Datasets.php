<?php

use Illuminate\Support\Str;

// Story 0036, Phase 4 RE-audit finding N-1.
//
// This is a Pest "Datasets.php" file -- Pest's own first-class convention for a dataset shared
// across more than one test file in a directory (Pest\Support\DatasetInfo::isADatasetsFile() /
// Pest\Bootstrappers\BootFiles::bootDatasets()). A file with exactly this basename is scanned and
// `include_once`'d UNCONDITIONALLY during Pest's bootstrap -- before test collection, and
// regardless of which specific test file(s) the current run targets (even a single-file
// `--filter` run) -- and every `dataset()` call inside it is scoped to its OWN directory
// (`tests/Feature/ShippingRates`), which is why every test file in this directory can resolve it
// by name via `->with('invalid_rate_attributes')`.
//
// This dataset used to live inline inside ShippingRateValidationTest.php, with a comment claiming
// "Pest's dataset registry is populated when the whole suite is collected, so a dataset() call in
// one file is addressable by name from any other file in the same run." That claim is FALSE, and
// reproduced as false by execution: Pest\Support\DatasetInfo::scope() gives a bare dataset() call
// (one that is NOT inside a Datasets.php file and NOT inside a directory literally named
// "Datasets") a scope equal to the EXACT FILE it was declared in --
// Pest\Repositories\DatasetsRepository::getScopedDataset() then only matches a `->with(...)` call
// whose OWN file path starts with that exact scope string. Two different files can never satisfy
// that condition for each other, so UpdateShippingRateTest.php's ->with('invalid_rate_attributes')
// silently failed to resolve -- surfacing as a top-level "PHPUnit ERROR" (a
// Pest\Exceptions\DatasetDoesNotExist), NOT a normal test failure, so it never appeared in a
// per-test pass/fail count. Confirmed reproducible in isolation, per-directory, AND across the
// full suite alike -- moving the SAME dataset definition here (Pest's own supported cross-file
// mechanism) is the fix, not a workaround.
//
// Ten named cases driving the PRD's `Scenario Outline: An invalid shipping rate is rejected`
// (the first seven) plus three additional hardening cases this story's own validation trait
// requires (D-7's decimal:N,M rules, the price ceiling). Each entry is [buildAttributes,
// expectedErrorKey] -- buildAttributes receives a real, existing carrier id and zone id so only
// ONE field under test is ever invalid at a time.
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
            'max_weight_kg',
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
            'min_weight_kg',
        ],
        'blank_name' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'name' => '',
            ]),
            'name',
        ],
        'blank_delivery_estimate' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'delivery_estimate' => '',
            ]),
            'delivery_estimate',
        ],
        'unknown_carrier' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'shipping_carrier_id' => (string) Str::uuid7(),
            ]),
            'shipping_carrier_id',
        ],
        'unknown_zone' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'shipping_zone_id' => (string) Str::uuid7(),
            ]),
            'shipping_zone_id',
        ],
        // D-7's maxWeightRules()/priceRules() use 'decimal:0,N', never a bare 'numeric' --
        // 'numeric' happily accepts scientific notation ('1e2' == 100), which decimal:0,2's
        // pattern has no e/E branch for and therefore rejects.
        'price_scientific_notation' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '1e2',
            ]),
            'price',
        ],
        // decimal:0,3 caps precision at 3 places, matching decimal(8,3) -- without it, 2.0001
        // reaches MySQL and is silently truncated or errors depending on strict mode.
        'weight_over_precision' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'max_weight_kg' => '2.0001',
            ]),
            'max_weight_kg',
        ],
        // Bounded so a forged payload cannot overflow decimal(10,2) into a raw SQLSTATE 22003
        // (a 500) instead of a field-level message.
        'price_over_ceiling' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '100000000.00',
            ]),
            'price',
        ],
    ];
});
