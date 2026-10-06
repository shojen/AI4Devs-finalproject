<?php

// Story 0069 -- Pest 4 browser tests for the Store Languages settings screen (route
// store-languages.index, GET /settings/store-languages). Mirrored folder per D-17: the two flat
// browser files (UsersIndexTest, SalesRegionsIndexTest) are recorded debt, not precedent.
//
// What only a real browser proves, and Livewire::test() cannot:
//   - that the picker's search field and its options are wired to each other and that picking a
//     match submits the fixture's canonical lowercase CODE rather than the raw text typed
//     (Livewire::test()->call('addLanguage', 'fr') bypasses the picker entirely);
//   - that the compiled wire:click on "Set default" and on the removal confirmation really fires;
//   - that a refusal's inline error does not survive a Cancel and a reopen against a different row
//     (the resetValidation() regression task 0018 shipped as a blocking bug).
//
// Language rows are reached only through their D-14 data-test hooks -- never a page-global
// assertSee('Français') or assertSee('es'): story 0067's switcher renders English/Español in the
// chrome of this very page (R-3).
//
// ->waitForEvent('networkidle') is banned in this repo (it never settles here); a short bounded
// ->wait(1) after a Livewire round trip is the accepted mitigation, and each one below
// compensates for exactly that: the DOM has not yet re-rendered from the server response.
//
// Hooks assumed beyond D-14's table (the removal dialog's two buttons have none there) --
// adjust here first if the real markup names them differently:
//   confirm-remove-language, cancel-remove-language

use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

function storeLanguagesBrowserActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['store-languages.view', 'store-languages.create', 'store-languages.edit', 'store-languages.delete']);

    return $actor;
}

test('typing in the picker narrows the list and picking the match submits the canonical lowercase code, not the typed text', function () {
    $this->actingAs(storeLanguagesBrowserActor());

    visit('/settings/store-languages')
        ->assertNoJavaScriptErrors()
        ->click('@add-language-button')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-option-de')
        ->fill('@language-picker-search', 'fran')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-option-fr')
        ->assertMissing('@language-option-de')
        ->click('@language-option-fr')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $french = StoreLanguage::query()->where('code', 'fr')->first();

    expect($french)->not->toBeNull()
        ->and($french->is_active)->toBeTrue()
        ->and($french->is_default)->toBeFalse()
        ->and(StoreLanguage::query()->where('code', 'fran')->exists())->toBeFalse();

    visit('/settings/store-languages')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@language-row-'.$french->id);
});

test('a real click on Set default moves the default marker to that row', function () {
    $this->actingAs(storeLanguagesBrowserActor());
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);

    visit('/settings/store-languages')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@default-badge-language-'.$spanish->id)
        ->assertMissing('@default-badge-language-'.$french->id)
        ->click('@set-default-language-'.$french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@default-badge-language-'.$french->id)
        ->assertMissing('@default-badge-language-'.$spanish->id);

    expect($french->fresh()->is_default)->toBeTrue()
        ->and($spanish->fresh()->is_default)->toBeFalse();
});

test('a real click on Remove, then on the confirmation, removes a non-default language row', function () {
    $this->actingAs(storeLanguagesBrowserActor());
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);

    visit('/settings/store-languages')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-row-'.$french->id)
        ->click('@remove-language-'.$french->id)
        ->assertNoJavaScriptErrors()
        ->click('@confirm-remove-language')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertMissing('@language-row-'.$french->id);

    expect($french->fresh()->is_active)->toBeFalse();
});

test('a refused removal reports its reason, and that error does not survive a Cancel and a reopen against a different row', function () {
    $this->actingAs(storeLanguagesBrowserActor());
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $german = StoreLanguage::factory()->create(['code' => 'de', 'name' => 'Deutsch']);

    $page = visit('/settings/store-languages')->assertNoJavaScriptErrors();

    // Another administrator promotes French while this page is open, so the page's own copy of the
    // list is stale and offers Remove on a row that has since become the default.
    $spanish->forceFill(['is_default' => false])->save();
    $french->forceFill(['is_default' => true])->save();

    $refusal = __('store-languages.errors.cannot_remove_default');

    $page->click('@remove-language-'.$french->id)
        ->assertNoJavaScriptErrors()
        ->click('@confirm-remove-language')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSee($refusal)
        ->click('@cancel-remove-language')
        ->wait(1)
        ->click('@remove-language-'.$german->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertDontSee($refusal);

    expect($french->fresh()->is_active)->toBeTrue()
        ->and($german->fresh()->is_active)->toBeTrue();
});
