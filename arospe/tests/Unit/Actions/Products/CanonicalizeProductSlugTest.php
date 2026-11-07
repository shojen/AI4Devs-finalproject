<?php

use App\Actions\Products\CanonicalizeProductSlug;
use Tests\TestCase;

uses(TestCase::class);

// Story 0076a (D-6, D-30): the single slug canonicaliser shared by the actions (layer 1) and the
// ProductTranslation saving hook (layer 2). Pure string logic, no database.

test('a padded mixed-case slug is canonicalised to its stored form', function () {
    expect(app(CanonicalizeProductSlug::class)('  Chaussures De Course  '))->toBe('chaussures-de-course');
});

test('input with no usable character, an empty string, whitespace only, or null becomes null and never an empty string', function (?string $input) {
    expect(app(CanonicalizeProductSlug::class)($input))->toBeNull();
})->with([
    'punctuation only' => ['!!!'],
    'empty string' => [''],
    'whitespace only' => ['   '],
    'tabs and newlines' => ["\t \n"],
    'null' => [null],
]);

test('canonicalisation is idempotent on an already-canonical slug', function () {
    $canonicalize = app(CanonicalizeProductSlug::class);

    $once = $canonicalize('Zapatillas De Trail 2');

    expect($once)->toBe('zapatillas-de-trail-2')
        ->and($canonicalize($once))->toBe($once);
});
