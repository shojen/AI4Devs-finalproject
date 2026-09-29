<?php

// Story 0038 — a Pest "Datasets.php" file, Pest's own first-class convention for a dataset shared
// across more than one test file in a directory (see tests/Feature/ShippingRates/Datasets.php's
// own docblock for the full mechanism this relies on: every dataset() call inside a file with
// exactly this basename is scoped to its own DIRECTORY, not its file, so every test file in
// tests/Feature/PaymentMethods/ can resolve these by name via ->with(...)).
//
// Phase 5 code review finding N3: this file used to also carry `valid_ibans`/`invalid_ibans`
// dataset() calls duplicating tests/Unit/Concerns/PaymentMethodValidationRulesTest.php's own
// (different directory, so no name collision, but nothing in this directory ever consumed them --
// only `invalid_ibans_after_normalisation` below is used). A dead, drifting duplicate is worse
// than none: the Unit test's copy is the single authoritative valid/invalid IBAN set (it also
// carries two cases this file never had -- `null` and the F-2 trailing-newline regression) and
// this file holds only the one dataset genuinely specific to the component (post-normalisation)
// layer.

// A NARROWER dataset for a component-level (post-normalisation) rejection test. The component
// uppercases and strips spaces BEFORE validating (see App\Livewire\PaymentMethods\Index::save()),
// so the two case-only variants the Unit test's `invalid_ibans` set carries ('eS91...', 'es91...')
// are deliberately EXCLUDED here: after normalisation they become the structurally and
// arithmetically valid ES example and are correctly ACCEPTED, not rejected -- that is the separate
// "lowercase IBAN is accepted and stored uppercased" scenario, not a rejection case. Using the
// full invalid_ibans set against the component would fail the suite on cases that are working
// exactly as designed.
dataset('invalid_ibans_after_normalisation', [
    'punctuation embedded' => 'ES91-2100-0418-4502-0005-1332',
    'digits where the country code belongs' => '129121000418450200051332',
    'too short for ES (23 chars)' => 'ES912100041845020005133',
    'too long for ES (25 chars)' => 'ES91210004184502000513322',
    'structurally valid, wrong mod-97 check digit' => 'ES9021000418450200051332',
    'country code only' => 'ES',
    'empty string' => '',
]);
