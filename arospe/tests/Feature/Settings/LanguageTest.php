<?php

// Story 0067 -- the Settings > Language tab: route gate and the settings navigation.
//
// Plain HTTP (`$this->get()`), NOT Livewire::test(): the real `web` middleware stack runs, so
// SetUiLocale applies the user's stored language to the render.
//
// Deliberately NO `verified`-refusal test: App\Models\User does not implement MustVerifyEmail, so
// `verified` refuses nobody and such a test could never go red (docs/errors-log.md, 2026-08-20).
//
// Every navlist assertion is scoped to data-test="settings-navlist": unscoped, the labels collide
// with the untranslated <title>, the topbar heading and the co-rendered chrome switcher heading.
// Note: lang/es.json already translates Profile/Security/Appearance, so before the implementation
// the Spanish case is red ONLY on "Idioma" -- the other three labels' green does not prove the
// switch to the topbar.settings.* keys (a key typo still renders the raw key and goes red).

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * The settings navigation's links as [label, href] pairs, extracted from the element carrying
 * data-test="settings-navlist" (empty when that hook is absent).
 *
 * @return array<int, array{0: string, 1: string}>
 */
function settingsNavlistLinks(TestResponse $response): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $links = [];
    foreach ((new DOMXPath($document))->query('//*[@data-test="settings-navlist"]//a') as $anchor) {
        $links[] = [trim(preg_replace('/\s+/', ' ', $anchor->textContent)), $anchor->getAttribute('href')];
    }

    return $links;
}

// Scenario: A visitor cannot reach the Settings Language tab
test('a guest is redirected to the sign-in page from the Settings Language tab', function () {
    $this->get(route('language.edit'))->assertRedirect(route('login'));
});

// Scenario: The Settings Language tab is available to an administrator holding no module permissions
test('a signed-in user holding no module permissions is served the Settings Language tab', function () {
    $user = User::factory()->create();
    expect($user->getAllPermissions())->toBeEmpty();

    $this->actingAs($user)->get(route('language.edit'))->assertOk();
});

// Scenario: The Settings area offers a Language tab
test('the settings navigation lists a Language tab linking to the Language screen', function () {
    $response = $this->actingAs(User::factory()->uiLocale('en')->create())->get(route('profile.edit'));

    $response->assertOk();
    $links = settingsNavlistLinks($response);

    expect($links)->not->toBeEmpty()
        ->and($links[3] ?? null)->toBe(['Language', route('language.edit')]);
});

// Scenario: The Settings navigation is shown in the administrator's interface language
test('the settings navigation labels follow the administrators interface language', function (string $locale, array $expectedLabels, array $absentLabels) {
    $response = $this->actingAs(User::factory()->uiLocale($locale)->create())->get(route('profile.edit'));

    $response->assertOk();
    $labels = array_column(settingsNavlistLinks($response), 0);

    expect($labels)->toBe($expectedLabels)
        ->and(array_intersect($labels, $absentLabels))->toBeEmpty();
})->with([
    'Spanish' => ['es', ['Perfil', 'Seguridad', 'Apariencia', 'Idioma'], ['Profile', 'Security', 'Appearance', 'Language']],
    'English' => ['en', ['Profile', 'Security', 'Appearance', 'Language'], ['Perfil', 'Seguridad', 'Apariencia', 'Idioma']],
]);
