<?php

// Story 0069 (Phase 4 loop-back, findings F-1/F-2/F-3). Mirrors tests/Feature/SalesRegions/RefusalLoggingTest.php.
//
// F-1: a plain Gate::authorize() in a Livewire method throws BEFORE the action's own
// LogRefusedPrivilegedAttempt::authorize() runs, so a refusal over /livewire/update was never
// recorded (docs/security/livewire-authorization/entry-point-and-method-gates.md). Every gated
// method of App\Livewire\StoreLanguages\Index must log through the helper. mount() is the
// documented unlogged exception and is deliberately not covered.
//
// F-2: the removal modal body discloses a usage count and must neither survive closeRemoveModal()
// nor render for an actor lacking `delete`.
//
// F-3: a forged replacement id equal to the target's, differing only in letter case, must be
// refused as "same row".

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Livewire\StoreLanguages\Index;
use App\Models\LocaleSetting;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

/**
 * Mounts the screen as an actor holding every store-languages permission, then revokes one, so
 * mount() passes and only the method under test is refused (the SalesRegions recipe).
 *
 * @return array{0: User, 1: Testable}
 */
function storeLanguagesRefusalMount(string $revoked): array
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['store-languages.view', 'store-languages.create', 'store-languages.edit', 'store-languages.delete']);
    test()->actingAs($actor);

    $component = Livewire::test(Index::class);

    $actor->revokePermissionTo($revoked);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$actor, $component];
}

function storeLanguagesAssertRefusalLogged(User $actor, string $ability, string $targetType, int|string|null $targetId): void
{
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === $ability
            && ($context['target_type'] ?? null) === $targetType
            && ($context['target_id'] ?? null) === $targetId)
        ->once();
}

// =====================================================================
// F-1 -- refusals are logged and still refused
// =====================================================================

test('openAddLanguageModal() refusal is logged and still refused', function () {
    Log::spy();
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.create');

    $component->call('openAddLanguageModal')->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'create', 'store_language', null);
});

test('addLanguage() refusal is logged and still refused, and no language is added', function () {
    Log::spy();
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.create');
    $before = StoreLanguage::count();

    $component->call('addLanguage', 'fr')->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'create', 'store_language', null);
    expect(StoreLanguage::count())->toBe($before);
});

test('confirmRemoveLanguage() refusal is logged with the target id and still refused', function () {
    Log::spy();
    $french = StoreLanguage::factory()->create(['code' => 'fr']);
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.delete');

    $component->call('confirmRemoveLanguage', $french->id)->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'delete', 'store_language', $french->id);
});

test('removeLanguage() refusal is logged with the target id and still refused, and the language stays active', function () {
    Log::spy();
    $french = StoreLanguage::factory()->create(['code' => 'fr']);
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.view');

    // languageId is #[Locked]: the only way to hold a target is the (gated) confirmRemoveLanguage(),
    // so confirm while still permitted, then lose the ability.
    $component->call('confirmRemoveLanguage', $french->id);
    $actor->revokePermissionTo('store-languages.delete');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('removeLanguage')->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'delete', 'store_language', $french->id);
    expect($french->fresh()->is_active)->toBeTrue();
});

test('setDefaultLanguage() refusal is logged with the target id and still refused, and the default does not change', function () {
    Log::spy();
    $french = StoreLanguage::factory()->create(['code' => 'fr']);
    $defaultId = StoreLanguage::query()->where('is_default', true)->value('id');
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.edit');

    $component->call('setDefaultLanguage', $french->id)->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'update', 'store_language', $french->id);
    expect(StoreLanguage::query()->where('is_default', true)->value('id'))->toBe($defaultId);
});

test('saveDefaultUiLocale() refusal is logged against the locale_setting target and still refused, and nothing is persisted', function () {
    Log::spy();
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.edit');
    $before = LocaleSetting::defaultUiLocale()->value;

    $component->set('defaultUiLocale', $before === 'es' ? 'en' : 'es')->call('saveDefaultUiLocale')->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'update', 'locale_setting', LocaleSetting::SINGLETON_ID);
    expect(LocaleSetting::defaultUiLocale()->value)->toBe($before);
});

test('saveDefaultNotificationLocale() refusal is logged against the locale_setting target and still refused', function () {
    Log::spy();
    [$actor, $component] = storeLanguagesRefusalMount('store-languages.edit');
    $before = LocaleSetting::defaultNotificationLocale()->value;

    $component->set('defaultNotificationLocale', $before === 'es' ? 'en' : 'es')->call('saveDefaultNotificationLocale')->assertForbidden();

    storeLanguagesAssertRefusalLogged($actor, 'update', 'locale_setting', LocaleSetting::SINGLETON_ID);
    expect(LocaleSetting::defaultNotificationLocale()->value)->toBe($before);
});

// =====================================================================
// F-2 -- the removal modal is not a disclosure path
// =====================================================================

test('closeRemoveModal() clears the removal target', function () {
    $french = StoreLanguage::factory()->create(['code' => 'fr']);
    [, $component] = storeLanguagesRefusalMount('store-languages.view');

    $component->call('confirmRemoveLanguage', $french->id);
    expect($component->get('languageId'))->toBe($french->id);

    $component->call('closeRemoveModal');

    expect($component->get('languageId'))->toBe('');
});

test('an actor lacking delete does not see the removal modal body or usage count when showRemoveModal is forced true after a refused setDefaultLanguage()', function () {
    $french = StoreLanguage::factory()->inactive()->create(['code' => 'fr']);
    ProductCategoryTranslation::factory()->count(2)->forLanguage($french)->create();
    [, $component] = storeLanguagesRefusalMount('store-languages.delete');

    // An inactive target is refused by the action, which leaves languageId set to it.
    $component->call('setDefaultLanguage', $french->id)->assertHasErrors(['languageId']);
    expect($component->get('languageId'))->toBe($french->id);

    $html = $component->set('showRemoveModal', true)->html();

    expect($html)->not->toContain('data-test="remove-modal-usage-line"')
        ->and($html)->not->toContain('data-test="remove-language-modal"');
});

// =====================================================================
// F-3 -- case-only difference between replacement and target
// =====================================================================

test('a forged replacement id equal to the target id except for letter case is refused with a languageId error, without promoting anything', function () {
    $french = StoreLanguage::factory()->create(['code' => 'fr']);
    $target = StoreLanguage::query()->where('is_default', true)->firstOrFail();
    $actor = User::factory()->create();
    $actor->givePermissionTo(['store-languages.view', 'store-languages.create', 'store-languages.edit', 'store-languages.delete']);
    $this->actingAs($actor);

    $watcher = Mockery::mock(SetDefaultStoreLanguage::class, [app(LogRefusedPrivilegedAttempt::class)])->makePartial();
    $watcher->shouldNotReceive('__invoke');
    app()->instance(SetDefaultStoreLanguage::class, $watcher);

    Livewire::test(Index::class)
        ->call('confirmRemoveLanguage', $target->id)
        ->set('replacementLanguageId', strtoupper($target->id))
        ->call('removeLanguage')
        ->assertHasErrors(['languageId']);

    expect($target->fresh()->is_active)->toBeTrue()
        ->and($french->fresh()->is_default)->toBeFalse();
});
