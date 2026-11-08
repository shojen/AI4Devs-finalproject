<?php

use App\Models\StoreLanguage;
use Database\Factories\StoreLanguageFactory;

test('a factory-made store language never gets a code that tests create by hand', function () {
    $codes = collect(range(1, 150))
        ->map(fn () => StoreLanguage::factory()->make()->code);

    fake()->unique(true);

    expect($codes->intersect(StoreLanguageFactory::RESERVED_CODES))->toBeEmpty();
});
