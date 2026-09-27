<?php

// Story 0068 -- authorization and refusal logging for App\Actions\Localization\
// SetDefaultUiLocale / SetDefaultNotificationLocale. Both actions authorize 'update' against
// App\Models\LocaleSetting::class (a class-level ability -- there is only ever one row) and carry
// the full LogRefusedPrivilegedAttempt treatment. D25's borrowed-permission binding is pinned
// here at the ACTION level (not only the Gate level tests/Feature/Policies/
// LocaleSettingPolicyTest.php already covers): a role granted exactly store-languages.edit can
// change both defaults through the real action, and a role granted store-languages.view alone
// cannot -- because D25's constant-aliasing is otherwise invisible at runtime.

use App\Actions\Localization\SetDefaultNotificationLocale;
use App\Actions\Localization\SetDefaultUiLocale;
use App\Actions\SalesRegions\SetDefaultSalesRegion;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use App\Models\SalesRegion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    config(['app.locale' => 'es']);
});

// =====================================================================
// D25 -- the borrowed-permission binding, pinned at the action level.
// =====================================================================

test('a role granted exactly store-languages.edit can change the default dashboard language', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.edit');
    $this->actingAs($actor);

    app(SetDefaultUiLocale::class)(UiLocale::English);

    expect(LocaleSetting::current()->default_ui_locale)->toBe('en');
});

test('a role granted exactly store-languages.edit can change the default notification language', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.edit');
    $this->actingAs($actor);

    app(SetDefaultNotificationLocale::class)(UiLocale::English);

    expect(LocaleSetting::current()->default_notification_locale)->toBe('en');
});

test('a role granted store-languages.view alone cannot change the default dashboard language', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.view');
    $this->actingAs($actor);

    expect(fn () => app(SetDefaultUiLocale::class)(UiLocale::English))
        ->toThrow(AuthorizationException::class);

    expect(LocaleSetting::count())->toBe(0);
});

test('a role granted store-languages.view alone cannot change the default notification language', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.view');
    $this->actingAs($actor);

    expect(fn () => app(SetDefaultNotificationLocale::class)(UiLocale::English))
        ->toThrow(AuthorizationException::class);

    expect(LocaleSetting::count())->toBe(0);
});

// =====================================================================
// Authorization -- no authenticated user.
// =====================================================================

test('SetDefaultUiLocale called with no authenticated user is refused', function () {
    expect(fn () => app(SetDefaultUiLocale::class)(UiLocale::English))
        ->toThrow(AuthorizationException::class);

    expect(LocaleSetting::count())->toBe(0);
});

test('SetDefaultNotificationLocale called with no authenticated user is refused', function () {
    expect(fn () => app(SetDefaultNotificationLocale::class)(UiLocale::English))
        ->toThrow(AuthorizationException::class);

    expect(LocaleSetting::count())->toBe(0);
});

// =====================================================================
// Refusal logging -- context array, never the rendered message; no secret-looking key.
// =====================================================================

test('SetDefaultUiLocale refusal writes exactly one Log::warning with the actor and update ability', function () {
    Log::spy();

    $actor = User::factory()->create(); // holds no permission at all
    $this->actingAs($actor);

    try {
        app(SetDefaultUiLocale::class)(UiLocale::English);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ! str_contains((string) json_encode($context), 'password'))
        ->once();
});

test('SetDefaultNotificationLocale refusal writes exactly one Log::warning with the actor and update ability', function () {
    Log::spy();

    $actor = User::factory()->create(); // holds no permission at all
    $this->actingAs($actor);

    try {
        app(SetDefaultNotificationLocale::class)(UiLocale::English);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ! str_contains((string) json_encode($context), 'password'))
        ->once();
});

// =====================================================================
// Permitted writes log their success line and never a warning.
// =====================================================================

test('a permitted SetDefaultUiLocale writes its single Log::info success line and no warning', function () {
    Log::spy();

    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.edit');
    $this->actingAs($actor);

    app(SetDefaultUiLocale::class)(UiLocale::English);

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')->once();
});

test('a permitted SetDefaultNotificationLocale writes its single Log::info success line and no warning', function () {
    Log::spy();

    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.edit');
    $this->actingAs($actor);

    app(SetDefaultNotificationLocale::class)(UiLocale::English);

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')->once();
});

// =====================================================================
// Equivalence -- this settings pair's refusal lines share exactly the same shape as an existing,
// already-proven screen's (Sales Regions'), per the task file's "an existing screen's" wording
// under "Locale settings -- authorization and logging".
// =====================================================================

test('the Locale Settings and Sales Regions refusal log lines share exactly the same shape', function () {
    Log::spy();

    // -- Locale Settings refusal. --
    $localeSettingActor = User::factory()->create(); // holds no permission at all
    $this->actingAs($localeSettingActor);

    try {
        app(SetDefaultUiLocale::class)(UiLocale::English);
    } catch (AuthorizationException) {
        //
    }

    // -- Sales Regions refusal (a second, distinct actor/target pair, same session). --
    $salesRegionTarget = SalesRegion::factory()->create();
    $salesRegionActor = User::factory()->create(); // holds no permission at all
    $this->actingAs($salesRegionActor);

    try {
        app(SetDefaultSalesRegion::class)($salesRegionTarget);
    } catch (AuthorizationException) {
        //
    }

    $recordedContexts = [];

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use (&$recordedContexts): bool {
            if ($message === 'Privileged action refused') {
                $recordedContexts[] = $context;
            }

            return true;
        })
        ->atLeast()->times(2);

    expect(count($recordedContexts))->toBeGreaterThanOrEqual(2);

    $keySets = array_map(
        fn (array $context): array => collect(array_keys($context))->sort()->values()->all(),
        $recordedContexts,
    );

    $serializedKeySets = array_map(fn (array $keys): string => implode(',', $keys), $keySets);
    expect(array_unique($serializedKeySets))->toHaveCount(1)
        ->and($keySets[0])->toBe(['ability', 'actor_id', 'target_id', 'target_type']);
});
