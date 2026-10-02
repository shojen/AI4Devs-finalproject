<?php

// Story 0068 -- GET /settings/store-languages, gated by can:store-languages.view (not Spatie's
// permission: middleware -- same reason as sales-regions.index: Livewire 4's PersistentMiddleware
// allowlist carries Laravel's Authorize but not Spatie's PermissionMiddleware). The first four
// tests prove only that the route resolves and is gated, mirroring
// tests/Feature/SalesRegions/IndexTest.php's own HTTP-layer block. The literal URI is asserted
// directly (not via route()) because the task file's own contract names the exact path rather
// than a route name.
//
// Story 0069 EXTENDS this file (never a new IndexTest) with the component-level contract of the
// real screen: that each user gesture reaches the right 0068 action with the right argument, that
// every refusal lands on the property it concerns, and that a forged dashboard-default value is
// refused before UiLocale::from() ever sees it. Pure rendering assertions live in
// CatalogRenderingTest.php / LocaleSettingsRenderingTest.php.
//
// Action-reach tests wrap the REAL action in a Mockery partial (constructor dependency supplied,
// every method passed through) bound into the container, because Livewire container-resolves the
// action parameters: the call is observed AND still persists, so the same test can assert the
// resulting state.

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Localization\SetDefaultNotificationLocale;
use App\Actions\Localization\SetDefaultUiLocale;
use App\Actions\StoreLanguages\AddStoreLanguage;
use App\Actions\StoreLanguages\RemoveStoreLanguage;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Enums\UiLocale;
use App\Livewire\StoreLanguages\Index;
use App\Models\LocaleSetting;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('guests are redirected to the login page when visiting the store languages screen', function () {
    $this->get('/settings/store-languages')->assertRedirect(route('login'));
});

test('a signed-in user without store-languages.view is forbidden from the store languages screen, without naming the permission', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $this->get('/settings/store-languages')
        ->assertForbidden()
        ->assertDontSee('store-languages.view');
});

test('a user holding store-languages.view can reach the store languages screen', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.view');
    $this->actingAs($actor);

    $this->get('/settings/store-languages')->assertOk();
});

test('a Super Admin holding zero permission rows can reach the store languages screen', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get('/settings/store-languages')->assertOk();
});

/**
 * @param  array<int, string>  $permissions
 */
function storeLanguagesIndexActor(array $permissions = ['store-languages.view', 'store-languages.create', 'store-languages.edit', 'store-languages.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Wraps the real action in a Mockery partial and binds it into the container, so a Livewire
 * method-injected parameter receives it. The expectation is declared by the caller; an
 * expectation chained with ->passthru() still executes the real action.
 *
 * @param  class-string  $class
 */
function storeLanguagesWatch(string $class): MockInterface
{
    $watcher = Mockery::mock($class, [app(LogRefusedPrivilegedAttempt::class)])->makePartial();
    app()->instance($class, $watcher);

    return $watcher;
}

// =====================================================================
// Story 0069 -- the real screen, section A: content languages
// =====================================================================

test('a user holding store-languages.view receives a page carrying both the content languages and the dashboard defaults sections', function () {
    $this->actingAs(storeLanguagesIndexActor(['store-languages.view']));

    $this->get('/settings/store-languages')
        ->assertOk()
        ->assertSee('data-test="store-languages-section"', false)
        ->assertSee('data-test="locale-settings-section"', false);
});

test('mounting the component directly is forbidden without store-languages.view, even though route middleware never ran', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(storeLanguagesIndexActor([]));

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

test('picking a language from the bundled list calls AddStoreLanguage with exactly that code and the row renders active and not default', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesIndexActor());

    $watcher = storeLanguagesWatch(AddStoreLanguage::class);
    $watcher->shouldReceive('__invoke')->once()->with('fr')->passthru();

    $component = Livewire::test(Index::class)->call('addLanguage', 'fr');

    $french = StoreLanguage::query()->where('code', 'fr')->firstOrFail();

    expect($french->is_active)->toBeTrue()
        ->and($french->is_default)->toBeFalse();

    $html = $component->html();

    expect(substr_count($html, 'data-test="language-row-'.$french->id.'"'))->toBe(1)
        ->and($html)->not->toContain('data-test="default-badge-language-'.$french->id.'"');
});

test('setting a language as default calls SetDefaultStoreLanguage with that row and the default marker moves in the same render', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    $watcher = storeLanguagesWatch(SetDefaultStoreLanguage::class);
    $watcher->shouldReceive('__invoke')
        ->once()
        ->with(Mockery::on(fn ($model): bool => $model instanceof StoreLanguage && $model->is($french)))
        ->passthru();

    $html = Livewire::test(Index::class)->call('setDefaultLanguage', $french->id)->html();

    expect($html)->toContain('data-test="default-badge-language-'.$french->id.'"')
        ->and($html)->not->toContain('data-test="default-badge-language-'.$spanish->id.'"');
});

test('confirming removal of an active non-default language calls RemoveStoreLanguage and the row leaves the list', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    $watcher = storeLanguagesWatch(RemoveStoreLanguage::class);
    $watcher->shouldReceive('__invoke')
        ->once()
        ->with(Mockery::on(fn ($model): bool => $model instanceof StoreLanguage && $model->is($french)))
        ->passthru();

    $html = Livewire::test(Index::class)
        ->call('confirmRemoveLanguage', $french->id)
        ->call('removeLanguage')
        ->html();

    expect($french->fresh()->is_active)->toBeFalse()
        ->and($html)->not->toContain('data-test="language-row-'.$french->id.'"');
});

test('confirming removal of the current default with a replacement chosen promotes the replacement and then removes the old default, from one click', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    $setDefault = storeLanguagesWatch(SetDefaultStoreLanguage::class);
    $setDefault->shouldReceive('__invoke')
        ->once()
        ->globally()
        ->ordered()
        ->with(Mockery::on(fn ($model): bool => $model instanceof StoreLanguage && $model->is($french)))
        ->passthru();

    $remove = storeLanguagesWatch(RemoveStoreLanguage::class);
    $remove->shouldReceive('__invoke')
        ->once()
        ->globally()
        ->ordered()
        ->with(Mockery::on(fn ($model): bool => $model instanceof StoreLanguage && $model->is($spanish)))
        ->passthru();

    Livewire::test(Index::class)
        ->call('confirmRemoveLanguage', $spanish->id)
        ->set('replacementLanguageId', $french->id)
        ->call('removeLanguage');

    expect($french->fresh()->is_default)->toBeTrue()
        ->and($spanish->fresh()->is_active)->toBeFalse()
        ->and($spanish->fresh()->is_default)->toBeFalse();
});

test('reactivating a previously removed language renders as one row carrying its original id, not a duplicate', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->inactive()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    $html = Livewire::test(Index::class)->call('addLanguage', 'fr')->html();

    expect(StoreLanguage::query()->where('code', 'fr')->count())->toBe(1)
        ->and($french->fresh()->is_active)->toBeTrue()
        ->and(substr_count($html, 'data-test="language-row-'.$french->id.'"'))->toBe(1)
        ->and(preg_match_all('/data-test="language-row-/', $html))->toBe(2);
});

test('the picker offers the bundled list minus the codes already held by an active row, and offers a previously removed code again', function () {
    $this->seed(StoreLanguageSeeder::class);
    StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    StoreLanguage::factory()->inactive()->create(['code' => 'de', 'name' => 'Deutsch']);
    $this->actingAs(storeLanguagesIndexActor());

    $html = Livewire::test(Index::class)->call('openAddLanguageModal')->html();

    expect($html)->not->toContain('data-test="language-option-es"')
        ->and($html)->not->toContain('data-test="language-option-fr"')
        ->and($html)->toContain('data-test="language-option-de"')
        ->and($html)->toContain('data-test="language-option-it"')
        ->and(preg_match_all('/data-test="language-option-[a-z]{2}"/', $html))->toBe(count(StoreLanguage::availableLanguages()) - 2);
});

test('the add flow offers no free-text code entry on any path', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesIndexActor());

    $html = Livewire::test(Index::class)->call('openAddLanguageModal')->html();

    expect(preg_match('/wire:model[^=\s>]*\s*=\s*"code"/', $html))->toBe(0);
});

test('a user holding only store-languages.view is refused on every catalog write and nothing persists', function (string $method) {
    $this->withoutExceptionHandling();
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor(['store-languages.view']));

    $arguments = match ($method) {
        'openAddLanguageModal' => [],
        'addLanguage' => ['de'],
        default => [$french->id],
    };

    expect(fn () => Livewire::test(Index::class)->call($method, ...$arguments))
        ->toThrow(AuthorizationException::class);

    expect(StoreLanguage::query()->where('code', 'de')->exists())->toBeFalse()
        ->and($french->fresh()->is_default)->toBeFalse()
        ->and($french->fresh()->is_active)->toBeTrue();
})->with(['openAddLanguageModal', 'addLanguage', 'setDefaultLanguage', 'confirmRemoveLanguage']);

test('a forced removeLanguage against a language that has since become the default reports the refusal against languageId', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    $component = Livewire::test(Index::class)->call('confirmRemoveLanguage', $french->id);

    // Another administrator promotes French while this dialog is open.
    $spanish->forceFill(['is_default' => false])->save();
    $french->forceFill(['is_default' => true])->save();

    $component->call('removeLanguage')
        ->assertHasErrors(['languageId'])
        ->assertSee(__('store-languages.errors.cannot_remove_default'));

    expect($french->fresh()->is_active)->toBeTrue();
});

test('a forced setDefaultLanguage against an inactive language surfaces its own distinct refusal', function () {
    $this->seed(StoreLanguageSeeder::class);
    $inactive = StoreLanguage::factory()->inactive()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    Livewire::test(Index::class)
        ->call('setDefaultLanguage', $inactive->id)
        ->assertHasErrors(['languageId'])
        ->assertSee(__('store-languages.errors.default_must_be_active'))
        ->assertDontSee(__('store-languages.errors.cannot_remove_default'));

    expect($inactive->fresh()->is_default)->toBeFalse();
});

test('a target that vanishes between render and click raises ModelNotFoundException from every target-resolving method, never a TypeError', function (string $method) {
    $this->withoutExceptionHandling();
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesIndexActor());

    $component = Livewire::test(Index::class);
    $staleId = $french->id;
    $french->delete();

    expect(fn () => $component->call($method, $staleId))->toThrow(ModelNotFoundException::class);
})->with(['setDefaultLanguage', 'confirmRemoveLanguage']);

test('a forged id that is not a row at all is a ModelNotFoundException, not a TypeError from a null policy argument', function (string $method) {
    $this->withoutExceptionHandling();
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesIndexActor());

    expect(fn () => Livewire::test(Index::class)->call($method, (string) Str::uuid()))
        ->toThrow(ModelNotFoundException::class);
})->with(['setDefaultLanguage', 'confirmRemoveLanguage']);

test('a Super Admin holding zero store-languages permission rows can use every catalog write', function () {
    $this->seed(StoreLanguageSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Livewire::test(Index::class)
        ->call('addLanguage', 'fr')
        ->assertHasNoErrors();

    $french = StoreLanguage::query()->where('code', 'fr')->firstOrFail();

    Livewire::test(Index::class)
        ->call('setDefaultLanguage', $french->id)
        ->assertHasNoErrors();

    expect($french->fresh()->is_default)->toBeTrue();
});

test('the removal confirmation reports a real usage count only when a translation row references the language', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $german = StoreLanguage::factory()->create(['code' => 'de', 'name' => 'Deutsch']);
    ProductCategoryTranslation::factory()->count(2)->forLanguage($french)->create();
    $this->actingAs(storeLanguagesIndexActor());

    $unused = Livewire::test(Index::class)->call('confirmRemoveLanguage', $german->id)->html();
    $used = Livewire::test(Index::class)->call('confirmRemoveLanguage', $french->id)->html();

    expect($unused)->not->toContain('data-test="remove-modal-usage-line"')
        ->and($used)->toContain('data-test="remove-modal-usage-line"')
        ->and(preg_match('/data-test="remove-modal-usage-line"[^>]*>[^<]*\b2\b/', $used))->toBe(1);
});

test('closing the add and the removal dialogs clears the refusal they were showing', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $this->actingAs(storeLanguagesIndexActor());

    Livewire::test(Index::class)
        ->call('addLanguage', 'es')
        ->assertHasErrors(['code'])
        ->call('closeAddLanguageModal')
        ->assertHasNoErrors(['code']);

    Livewire::test(Index::class)
        ->call('confirmRemoveLanguage', $spanish->id)
        ->call('removeLanguage')
        ->assertHasErrors(['languageId'])
        ->call('closeRemoveModal')
        ->assertHasNoErrors(['languageId']);
});

// =====================================================================
// Story 0069 -- the real screen, section B: dashboard defaults
// =====================================================================

test('saving the dashboard default calls SetDefaultUiLocale alone, with its own argument', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesIndexActor());

    $ui = storeLanguagesWatch(SetDefaultUiLocale::class);
    $ui->shouldReceive('__invoke')->once()->with(UiLocale::English)->passthru();
    $notification = storeLanguagesWatch(SetDefaultNotificationLocale::class);
    $notification->shouldReceive('__invoke')->never();

    Livewire::test(Index::class)
        ->set('defaultUiLocale', 'en')
        ->call('saveDefaultUiLocale')
        ->assertHasNoErrors();

    expect(LocaleSetting::defaultUiLocale())->toBe(UiLocale::English);
});

test('saving the notification default calls SetDefaultNotificationLocale alone, with its own argument', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesIndexActor());

    $ui = storeLanguagesWatch(SetDefaultUiLocale::class);
    $ui->shouldReceive('__invoke')->never();
    $notification = storeLanguagesWatch(SetDefaultNotificationLocale::class);
    $notification->shouldReceive('__invoke')->once()->with(UiLocale::Spanish)->passthru();

    Livewire::test(Index::class)
        ->set('defaultNotificationLocale', 'es')
        ->call('saveDefaultNotificationLocale')
        ->assertHasNoErrors();

    expect(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::Spanish);
});

test('a forged locale value on either property is refused with an error keyed on that property, calls no action and leaves the stored row unchanged', function (array $target, string $forgedValue) {
    [$property, $saveMethod, $actionClass] = $target;

    $this->seed(StoreLanguageSeeder::class);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'es',
    ]);
    $this->actingAs(storeLanguagesIndexActor());

    $this->mock($actionClass)->shouldNotReceive('__invoke');

    Livewire::test(Index::class)
        ->set($property, $forgedValue)
        ->call($saveMethod)
        ->assertHasErrors([$property]);

    $row = LocaleSetting::query()->findOrFail(LocaleSetting::SINGLETON_ID);

    expect($row->default_ui_locale)->toBe('es')
        ->and($row->default_notification_locale)->toBe('es');
})->with([
    'dashboard default' => [['defaultUiLocale', 'saveDefaultUiLocale', SetDefaultUiLocale::class]],
    'notification default' => [['defaultNotificationLocale', 'saveDefaultNotificationLocale', SetDefaultNotificationLocale::class]],
])->with([
    'another language' => 'fr',
    'wrong case' => 'EN',
    'region subtag' => 'en-GB',
    'empty' => '',
    'forty characters' => str_repeat('x', 40),
]);

test('a user holding only store-languages.view is refused on both dashboard default saves and nothing persists', function (string $property, string $saveMethod) {
    $this->withoutExceptionHandling();
    $this->seed(StoreLanguageSeeder::class);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'es',
    ]);
    $this->actingAs(storeLanguagesIndexActor(['store-languages.view']));

    expect(fn () => Livewire::test(Index::class)->set($property, 'en')->call($saveMethod))
        ->toThrow(AuthorizationException::class);

    $row = LocaleSetting::query()->findOrFail(LocaleSetting::SINGLETON_ID);

    expect($row->default_ui_locale)->toBe('es')
        ->and($row->default_notification_locale)->toBe('es');
})->with([
    'dashboard default' => ['defaultUiLocale', 'saveDefaultUiLocale'],
    'notification default' => ['defaultNotificationLocale', 'saveDefaultNotificationLocale'],
]);
