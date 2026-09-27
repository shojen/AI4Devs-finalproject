<?php

// Story 0068 -- cross-cutting refusal-logging assertions for the store_languages catalog that
// don't belong to any single action's own test file: the domain-invariant reason strings staying
// distinct from permission-shaped ability names, the no-secret-looking-key guarantee across all
// three actions, and the equivalence test proving this area's refusal lines share exactly the
// same shape as an existing, already-proven admin screen's -- mirrors
// tests/Feature/SalesRegions/RefusalLoggingTest.php's own "the Sales Regions and Roles screens
// refusal log lines share exactly the same shape" test, extended here to this area (asserting
// this area's shape in isolation is what lets two conventions drift into existence).

use App\Actions\SalesRegions\SetDefaultSalesRegion;
use App\Actions\StoreLanguages\RemoveStoreLanguage;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Models\SalesRegion;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<string, mixed>  $context
 */
function storeLanguagesRefusalLogContextHasNoSecretLookingKey(array $context): bool
{
    foreach (array_keys($context) as $key) {
        if (! is_string($key)) {
            continue;
        }

        if (str_contains($key, 'password') || str_contains($key, 'token') || str_contains($key, 'hash') || str_contains($key, 'session')) {
            return false;
        }
    }

    return true;
}

// =====================================================================
// Domain-invariant reasons are snake_case and distinct from any permission-shaped ability name
// (never 'update' or 'delete'), so an invariant refusal is never mistaken for an authorization
// decision when read back from the log.
// =====================================================================

test('the default_must_be_active reason is logged, distinguishable from the update authorization ability', function () {
    Log::spy();

    StoreLanguage::factory()->default()->create();
    $inactiveCandidate = StoreLanguage::factory()->inactive()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['store-languages.view', 'store-languages.edit']);
    $this->actingAs($actor);

    try {
        app(SetDefaultStoreLanguage::class)($inactiveCandidate);
    } catch (ValidationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['ability'] ?? null) === 'default_must_be_active'
            && $context['ability'] !== 'update'
            && storeLanguagesRefusalLogContextHasNoSecretLookingKey($context))
        ->once();
});

test('the cannot_remove_last_active_language reason is logged, distinguishable from the delete authorization ability', function () {
    Log::spy();

    $onlyActive = StoreLanguage::factory()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['store-languages.view', 'store-languages.delete']);
    $this->actingAs($actor);

    try {
        app(RemoveStoreLanguage::class)($onlyActive);
    } catch (ValidationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['ability'] ?? null) === 'cannot_remove_last_active_language'
            && $context['ability'] !== 'delete'
            && storeLanguagesRefusalLogContextHasNoSecretLookingKey($context))
        ->once();
});

test('the cannot_remove_default reason is logged, distinguishable from the delete authorization ability', function () {
    Log::spy();

    StoreLanguage::factory()->create();
    $default = StoreLanguage::factory()->default()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['store-languages.view', 'store-languages.delete']);
    $this->actingAs($actor);

    try {
        app(RemoveStoreLanguage::class)($default);
    } catch (ValidationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['ability'] ?? null) === 'cannot_remove_default'
            && $context['ability'] !== 'delete'
            && storeLanguagesRefusalLogContextHasNoSecretLookingKey($context))
        ->once();
});

// =====================================================================
// Equivalence -- this area's refusal lines share exactly the same shape as an existing, already-
// proven screen's (Sales Regions'), the "third admin screen" style proof this story's task file
// explicitly asks for.
// =====================================================================

test('the Store Languages and Sales Regions refusal log lines share exactly the same shape', function () {
    Log::spy();

    // -- Store Languages refusal (an authorization refusal, direct action call). --
    $storeLanguageTarget = StoreLanguage::factory()->create();
    $storeLanguageActor = User::factory()->create(); // holds no permission at all
    $this->actingAs($storeLanguageActor);

    try {
        app(RemoveStoreLanguage::class)($storeLanguageTarget);
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
