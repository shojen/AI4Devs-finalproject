<?php

// Story 0068 (B1 — moved here from story 0066, unchanged shape). App\Enums\UiLocale is a plain
// two-case backed enum with no label() and no default() method (D-6 / D18-D21) -- no translator,
// no container, no database needed, so this stays a pure tests/Unit/ test with no uses(TestCase::class)
// at all, unlike tests/Unit/Enums/UserStatusTest.php's label()-driven case.

use App\Enums\UiLocale;

test('the backing values are exactly en and es', function () {
    expect(UiLocale::English->value)->toBe('en')
        ->and(UiLocale::Spanish->value)->toBe('es');
});

test('cases() has exactly two entries', function () {
    expect(UiLocale::cases())->toHaveCount(2);
});

// Risk if missing (task file): a third case added later silently widens what validation
// accepts, what both locale-settings defaults may hold and what story 0067's switcher offers,
// contradicting the PRD's "only Spanish and English" scenario with no test objecting.
test('a third case cannot be added without this test naming it', function () {
    $values = array_map(fn (UiLocale $case): string => $case->value, UiLocale::cases());

    expect($values)->toBe(['en', 'es']);
});

// Pins the property every resolution boundary in this story (and 0066's) depends on: the
// config-tier and the persisted-tier reads in LocaleSetting's accessors both use tryFrom(),
// never from(), specifically so an unmapped value returns null instead of throwing \ValueError.
test('tryFrom returns null for an unmapped value rather than throwing', function () {
    expect(UiLocale::tryFrom('fr'))->toBeNull()
        ->and(UiLocale::tryFrom(''))->toBeNull();
});

test('from throws ValueError for an unmapped value', function () {
    expect(fn () => UiLocale::from('fr'))->toThrow(ValueError::class);
});

// Story 0067 (D-9) added label() as the second consumer arrived: endonyms, not translated.
test('label returns each language in its own language', function () {
    expect(UiLocale::English->label())->toBe('English')
        ->and(UiLocale::Spanish->label())->toBe('Español');
});

// D-6 / D19-D21: no default() method exists on this enum -- the default lives solely at
// LocaleSetting's one call site, not forked onto the enum itself.
test('the enum still declares no default method', function () {
    expect((new ReflectionEnum(UiLocale::class))->hasMethod('default'))->toBeFalse();
});
