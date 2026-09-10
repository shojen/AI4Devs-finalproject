<?php

use App\Concerns\PaymentMethodValidationRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// No RefreshDatabase -- this drives Validator::make() directly against the real ibanRules(),
// with no database involved at all, matching
// tests/Unit/Concerns/ProductCategoryValidationRulesTest.php's existing precedent.

uses(TestCase::class);

// Every value below was verified by executing the exact App\Rules\Iban implementation from this
// story (structure regex, then chunked mod-97), not taken from memory -- see the story's own task
// file for the real structOK/mod97 output each one produced.
dataset('valid_ibans', [
    'ES — 24 chars' => 'ES9121000418450200051332',
    'DE — 22 chars' => 'DE89370400440532013000',
    'GB — 22 chars' => 'GB29NWBK60161331926819',
    'FR — 27 chars, letter inside the BBAN' => 'FR1420041010050500013M02606',
]);

dataset('invalid_ibans', [
    // Each case isolates ONE failure mode against the valid ES example above.
    'country code not uppercase' => 'eS9121000418450200051332',
    'entirely lowercase' => 'es9121000418450200051332',
    'punctuation embedded' => 'ES91-2100-0418-4502-0005-1332',
    'digits where the country code belongs' => '129121000418450200051332',
    'too short for ES (23 chars)' => 'ES912100041845020005133',
    'too long for ES (25 chars)' => 'ES91210004184502000513322',
    'structurally valid, wrong mod-97 check digit' => 'ES9021000418450200051332',
    'country code only' => 'ES',
    'empty string' => '',
    'null' => null,
    // Phase 4 security audit finding F-2: a bare `$` anchor matches before a trailing newline
    // (PCRE), and the mod-97 checksum does not catch it either -- PHP's (int) cast tolerates
    // trailing whitespace inside a 7-digit chunk. App\Rules\Iban now anchors with `\z`.
    'trailing newline after an otherwise-valid IBAN' => "ES9121000418450200051332\n",
]);

function paymentMethodIbanRulesFixture(): object
{
    return new class
    {
        use PaymentMethodValidationRules;

        public function rules(): array
        {
            return $this->ibanRules();
        }
    };
}

test('every valid IBAN passes ibanRules()', function (string $iban) {
    $validator = Validator::make(['iban' => $iban], ['iban' => paymentMethodIbanRulesFixture()->rules()]);

    expect($validator->fails())->toBeFalse();
})->with('valid_ibans');

// This case -- 'structurally valid, wrong mod-97 check digit' -- is the single most important
// entry in this dataset and must not be moved, weakened, or dropped. A structure-only
// implementation passes every other entry and fails just this one; without it the entire mod-97
// requirement ships untested while the suite stays green.
test('every invalid IBAN fails ibanRules() with an error on the iban key', function (?string $iban) {
    $validator = Validator::make(['iban' => $iban], ['iban' => paymentMethodIbanRulesFixture()->rules()]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('iban'))->toBeTrue();
})->with('invalid_ibans');

test('a missing iban key fails required', function () {
    $validator = Validator::make([], ['iban' => paymentMethodIbanRulesFixture()->rules()]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('iban'))->toBeTrue();
});
