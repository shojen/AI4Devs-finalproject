<?php

// Story 0068 -- App\Models\StoreLanguage::availableLanguages(), the single named reader (D17) of
// the bundled database/data/iso-639-languages.json fixture, shared by StoreLanguageSeeder and
// StoreLanguageValidationRules::codeRules(). Exercised against the REAL bundled file, in the
// spirit of tests/Unit/Seeders/GeographyFixtureIntegrityTest.php's own fixture-shape assertions,
// but through the model method rather than a raw file parse, since availableLanguages() is the
// one place this story's contract says the JSON is ever read.
//
// uses(TestCase::class), no RefreshDatabase: availableLanguages() resolves a path via the app
// container (base_path()/database_path()), which a bare tests/Unit container does not provide --
// matches tests/Unit/Models/StoreLanguageTest.php's own opt-in.

use App\Models\StoreLanguage;
use Tests\TestCase;

uses(TestCase::class);

function realStoreLanguageFixturePath(): string
{
    return base_path('database/data/iso-639-languages.json');
}

/**
 * Temporarily replace the REAL bundled fixture's content with $content (or remove it
 * entirely when $content is null), run $callback, then restore the original file no matter
 * what -- there is no override hook on availableLanguages() to swap in a fixture path (unlike
 * TestableGeographyCatalogSeeder's static property), so this is the only way to exercise the
 * "missing or malformed" branch against the real reader without a database.
 */
function withCorruptedStoreLanguageFixture(?string $content, Closure $callback): mixed
{
    $path = realStoreLanguageFixturePath();
    $fileExisted = file_exists($path);
    $backupPath = $path.'.qa-backup';

    if ($fileExisted) {
        rename($path, $backupPath);
    }

    if ($content !== null) {
        file_put_contents($path, $content);
    }

    try {
        return $callback();
    } finally {
        if ($content !== null && file_exists($path)) {
            unlink($path);
        }

        if ($fileExisted) {
            rename($backupPath, $path);
        }
    }
}

test('availableLanguages returns a non-empty code => name map including es and fr', function () {
    $languages = StoreLanguage::availableLanguages();

    expect($languages)->toBeArray()
        ->not->toBeEmpty()
        ->and($languages)->toHaveKey('es')
        ->and($languages)->toHaveKey('fr')
        ->and($languages['es'])->toBeString()->not->toBe('')
        ->and($languages['fr'])->toBeString()->not->toBe('');
});

test('every fixture code matches the bare ISO 639-1 shape and is unique across the file', function () {
    $languages = StoreLanguage::availableLanguages();
    $codes = array_keys($languages);

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[a-z]{2}$/');
    }

    expect(array_unique($codes))->toHaveCount(count($codes));
});

test('every fixture entry has a non-empty endonym name', function () {
    $languages = StoreLanguage::availableLanguages();

    foreach ($languages as $code => $name) {
        expect($name)->toBeString()->not->toBe('');
    }
});

test('the raw fixture file carries code, name_endonym and name_en on every entry', function () {
    $path = realStoreLanguageFixturePath();
    expect(is_file($path))->toBeTrue();

    $decoded = json_decode(file_get_contents($path), associative: true);
    expect($decoded)->toBeArray()->not->toBeEmpty();

    foreach ($decoded as $entry) {
        expect($entry)->toHaveKeys(['code', 'name_endonym', 'name_en'])
            ->and($entry['name_endonym'])->not->toBe('');
    }
});

test('a missing fixture makes availableLanguages throw rather than returning an empty array', function () {
    withCorruptedStoreLanguageFixture(null, function (): void {
        expect(fn () => StoreLanguage::availableLanguages())->toThrow(Exception::class);
    });
});

test('a malformed (non-JSON) fixture makes availableLanguages throw rather than returning an empty array', function () {
    withCorruptedStoreLanguageFixture('{not valid json at all', function (): void {
        expect(fn () => StoreLanguage::availableLanguages())->toThrow(Exception::class);
    });
});

test('a fixture entry missing name_endonym makes availableLanguages throw', function () {
    withCorruptedStoreLanguageFixture(json_encode([['code' => 'zz', 'name_en' => 'Zeta']]), function (): void {
        expect(fn () => StoreLanguage::availableLanguages())->toThrow(Exception::class);
    });
});
