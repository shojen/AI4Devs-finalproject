<?php

// Story 0068 -- App\Actions\StoreLanguages\SetDefaultStoreLanguage. Mirrors
// tests/Feature/SalesRegions/SetDefaultSalesRegionTest.php's shape closely: a single
// primary-key-ordered lockForUpdate() query covering the promotion target and every currently-
// default row (D6), an inactive target refused by ValidationException (D6's "the default must be
// active" half), and the Phase-4-style caller-instance-trust regressions
// (docs/security/model-instance-trust.md) this action inherits by contract.

use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function setDefaultStoreLanguageActor(array $permissions = ['store-languages.view', 'store-languages.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

// =====================================================================
// Happy path
// =====================================================================

test('setting an active, non-default language as default flags it and clears the previous default', function () {
    $previousDefault = StoreLanguage::factory()->default()->create();
    $candidate = StoreLanguage::factory()->create();

    $this->actingAs(setDefaultStoreLanguageActor());

    app(SetDefaultStoreLanguage::class)($candidate);

    expect($candidate->fresh()->is_default)->toBeTrue()
        ->and($previousDefault->fresh()->is_default)->toBeFalse();
});

// =====================================================================
// Domain invariant (negative) -- D6's other half: an inactive language may never hold the
// default flag, enforced here so every call site inherits it.
// =====================================================================

test('setting an inactive language as default throws ValidationException keyed languageId, and the current default is unchanged', function () {
    $currentDefault = StoreLanguage::factory()->default()->create();
    $inactiveCandidate = StoreLanguage::factory()->inactive()->create();

    $this->actingAs(setDefaultStoreLanguageActor());

    try {
        app(SetDefaultStoreLanguage::class)($inactiveCandidate);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('languageId');
    }

    expect($inactiveCandidate->fresh()->is_default)->toBeFalse()
        ->and($currentDefault->fresh()->is_default)->toBeTrue();
});

// Bound for a Super Admin too -- the invariant is about the data, not the actor.
test('a Super Admin cannot set an inactive language as default either', function () {
    StoreLanguage::factory()->default()->create();
    $inactiveCandidate = StoreLanguage::factory()->inactive()->create();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect(fn () => app(SetDefaultStoreLanguage::class)($inactiveCandidate))
        ->toThrow(ValidationException::class);

    expect($inactiveCandidate->fresh()->is_default)->toBeFalse();
});

// =====================================================================
// A row deleted between hydration and invocation -- impossible under D5 through this app's own
// actions (nothing ever hard-deletes a StoreLanguage), but the action must still behave correctly
// against a row that vanished by any other means, raising ModelNotFoundException rather than
// silently succeeding against a stale in-memory copy.
// =====================================================================

test('a target deleted between hydration and invocation raises ModelNotFoundException, not a silent success', function () {
    $candidate = StoreLanguage::factory()->create();

    StoreLanguage::query()->whereKey($candidate->id)->delete();

    $this->actingAs(setDefaultStoreLanguageActor());

    expect(fn () => app(SetDefaultStoreLanguage::class)($candidate))
        ->toThrow(ModelNotFoundException::class);
});

// =====================================================================
// Invariant durability -- mirrors SetDefaultSalesRegionTest.php's "the row is re-read under
// lock" test: a concurrent deactivation landing between hydration and the call must be honoured,
// never the stale in-memory copy, so the catalog never ends up with zero or two defaults.
// =====================================================================

test('the row is re-read under lock: a concurrent deactivation between hydration and the call is honoured', function () {
    $currentDefault = StoreLanguage::factory()->default()->create();
    $candidate = StoreLanguage::factory()->create(['is_active' => true]);

    // Simulate a second administrator's already-committed deactivation landing between
    // hydration and this call -- the honest single-process simulation of another transaction.
    StoreLanguage::query()->whereKey($candidate->id)->update(['is_active' => false]);

    $this->actingAs(setDefaultStoreLanguageActor());

    expect(fn () => app(SetDefaultStoreLanguage::class)($candidate))
        ->toThrow(ValidationException::class);

    expect($currentDefault->fresh()->is_default)->toBeTrue()
        ->and(StoreLanguage::where('is_default', true)->count())->toBe(1);
});

// =====================================================================
// Phase-4-style finding (docs/security/model-instance-trust.md) -- save() writes the whole dirty
// set, not a fill() allow-list. A caller-dirtied attribute on the passed-in instance must not
// persist through this action, even though #[Fillable([])] makes it tempting to assume the model
// is unwritable.
// =====================================================================

test('a caller-dirtied attribute on the passed-in instance is not persisted -- only is_default moves', function () {
    StoreLanguage::factory()->default()->create();
    $candidate = StoreLanguage::factory()->create();
    $originalName = $candidate->name;

    $candidate->name = 'Hijacked via SetDefaultStoreLanguage';

    $this->actingAs(setDefaultStoreLanguageActor());

    app(SetDefaultStoreLanguage::class)($candidate);

    expect($candidate->fresh()->is_default)->toBeTrue()
        ->and($candidate->fresh()->name)->toBe($originalName);
});

// =====================================================================
// Authorization
// =====================================================================

test('called directly, an actor lacking store-languages.edit is refused and nothing changes', function () {
    $currentDefault = StoreLanguage::factory()->default()->create();
    $candidate = StoreLanguage::factory()->create();

    $actor = User::factory()->create(); // holds no permission at all
    $this->actingAs($actor);

    expect(fn () => app(SetDefaultStoreLanguage::class)($candidate))
        ->toThrow(AuthorizationException::class);

    expect($candidate->fresh()->is_default)->toBeFalse()
        ->and($currentDefault->fresh()->is_default)->toBeTrue();
});

test('called with no authenticated user, the action is refused', function () {
    $candidate = StoreLanguage::factory()->create();

    expect(fn () => app(SetDefaultStoreLanguage::class)($candidate))
        ->toThrow(AuthorizationException::class);

    expect($candidate->fresh()->is_default)->toBeFalse();
});

// =====================================================================
// Refusal logging
// =====================================================================

test('an authorization refusal writes exactly one Log::warning with the actor, ability and target', function () {
    Log::spy();

    $candidate = StoreLanguage::factory()->create();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(SetDefaultStoreLanguage::class)($candidate);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_id'] ?? null) === $candidate->id)
        ->once();
});

test('a permitted default change writes its single Log::info success line and no warning', function () {
    Log::spy();

    StoreLanguage::factory()->default()->create();
    $candidate = StoreLanguage::factory()->create();

    $this->actingAs(setDefaultStoreLanguageActor());

    app(SetDefaultStoreLanguage::class)($candidate);

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')->once();
});
