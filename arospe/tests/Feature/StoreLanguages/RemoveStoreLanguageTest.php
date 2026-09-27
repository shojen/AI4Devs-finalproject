<?php

// Story 0068 -- App\Actions\StoreLanguages\RemoveStoreLanguage. "Remove" means is_active = false,
// never a delete of any kind (D5) -- the row, and therefore any content later keyed to it, stays
// physically intact. Two hard invariants guard it, enforced in the action under a row lock, never
// by an authorization rule (docs/architecture/authorization/domain-invariants.md): the current
// default cannot be removed (D6), and the last active language cannot be removed (D7). Both bind
// a Super Admin identically, because the invariant is about the data, not the actor.

use App\Actions\StoreLanguages\RemoveStoreLanguage;
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

function removeStoreLanguageActor(array $permissions = ['store-languages.view', 'store-languages.delete']): User
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

test('removing an active, non-default language sets is_active = false and leaves the row present', function () {
    StoreLanguage::factory()->create(); // some other active language, so removal is not the last one
    $target = StoreLanguage::factory()->create();

    $this->actingAs(removeStoreLanguageActor());

    app(RemoveStoreLanguage::class)($target);

    expect($target->fresh())->not->toBeNull()
        ->and($target->fresh()->is_active)->toBeFalse();
});

test('removing a language leaves every other row\'s is_active and is_default untouched', function () {
    $default = StoreLanguage::factory()->default()->create();
    $bystander = StoreLanguage::factory()->create();
    $target = StoreLanguage::factory()->create();

    $this->actingAs(removeStoreLanguageActor());

    app(RemoveStoreLanguage::class)($target);

    expect($default->fresh()->is_default)->toBeTrue()
        ->and($default->fresh()->is_active)->toBeTrue()
        ->and($bystander->fresh()->is_active)->toBeTrue()
        ->and($bystander->fresh()->is_default)->toBeFalse();
});

// =====================================================================
// Domain invariants (negative)
// =====================================================================

test('removing the current default throws ValidationException keyed languageId, and the row stays active', function () {
    StoreLanguage::factory()->create(); // a second active language so "last active" is not also true
    $default = StoreLanguage::factory()->default()->create();

    $this->actingAs(removeStoreLanguageActor());

    try {
        app(RemoveStoreLanguage::class)($default);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('languageId');
    }

    expect($default->fresh()->is_active)->toBeTrue()
        ->and($default->fresh()->is_default)->toBeTrue();
});

test('removing the last remaining active language throws ValidationException keyed languageId, and the row stays active', function () {
    $onlyActive = StoreLanguage::factory()->create();

    $this->actingAs(removeStoreLanguageActor());

    try {
        app(RemoveStoreLanguage::class)($onlyActive);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('languageId');
    }

    expect($onlyActive->fresh()->is_active)->toBeTrue();
});

// A row that is BOTH the default AND the last active language must log exactly one refusal
// reason -- the more specific one -- never two. Per the task file's own Gherkin ordering ("The
// current default language cannot be removed" is listed before "The last remaining active
// language cannot be removed"), the default-must-be-reassigned-first reason is treated here as
// the more specific one.
test('a row that is both the default and the last active language logs exactly one, more specific refusal reason', function () {
    Log::spy();

    $onlyLanguage = StoreLanguage::factory()->default()->create();

    $this->actingAs(removeStoreLanguageActor());

    try {
        app(RemoveStoreLanguage::class)($onlyLanguage);
    } catch (ValidationException) {
        //
    }

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['ability'] ?? null) === 'cannot_remove_default')
        ->once();

    expect($onlyLanguage->fresh()->is_active)->toBeTrue()
        ->and($onlyLanguage->fresh()->is_default)->toBeTrue();
});

// Both hard refusals bind a Super Admin exactly like anyone else -- the invariant is about the
// data, not the actor.
test('a Super Admin cannot remove the current default either', function () {
    StoreLanguage::factory()->create();
    $default = StoreLanguage::factory()->default()->create();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect(fn () => app(RemoveStoreLanguage::class)($default))
        ->toThrow(ValidationException::class);

    expect($default->fresh()->is_active)->toBeTrue();
});

test('a Super Admin cannot remove the last active language either', function () {
    $onlyActive = StoreLanguage::factory()->create();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect(fn () => app(RemoveStoreLanguage::class)($onlyActive))
        ->toThrow(ValidationException::class);

    expect($onlyActive->fresh()->is_active)->toBeTrue();
});

// =====================================================================
// Invariant durability -- the active-count check must read rows INSIDE its own locked
// transaction, not as a pre-flight query taken before the row is locked (asserted the same way
// tests/Feature/StoreLanguages/SetDefaultStoreLanguageTest.php pins its own lock-freshness
// property): a concurrent deactivation of the OTHER active language, landing after $target is
// hydrated but before RemoveStoreLanguage runs, must still be honoured -- $target really is the
// last active language by the time the guard checks, and the refusal must reflect that live
// state rather than a stale count taken earlier.
// =====================================================================

test('the last-active guard is re-read under lock: a concurrent deactivation of the other active language is honoured', function () {
    $target = StoreLanguage::factory()->create();
    $otherActive = StoreLanguage::factory()->create();

    // Simulate a second administrator's already-committed removal of the OTHER active
    // language, landing between $target's hydration and this call.
    StoreLanguage::query()->whereKey($otherActive->id)->update(['is_active' => false]);

    $this->actingAs(removeStoreLanguageActor());

    expect(fn () => app(RemoveStoreLanguage::class)($target))
        ->toThrow(ValidationException::class);

    expect($target->fresh()->is_active)->toBeTrue();
});

// =====================================================================
// Authorization
// =====================================================================

test('called directly, an actor lacking store-languages.delete is refused and nothing changes', function () {
    StoreLanguage::factory()->create();
    $target = StoreLanguage::factory()->create();

    $actor = User::factory()->create(); // holds no permission at all
    $this->actingAs($actor);

    expect(fn () => app(RemoveStoreLanguage::class)($target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->is_active)->toBeTrue();
});

test('called with no authenticated user, the action is refused', function () {
    StoreLanguage::factory()->create();
    $target = StoreLanguage::factory()->create();

    expect(fn () => app(RemoveStoreLanguage::class)($target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->is_active)->toBeTrue();
});

// =====================================================================
// Refusal logging
// =====================================================================

test('an authorization refusal writes exactly one Log::warning with the actor, ability and target', function () {
    Log::spy();

    StoreLanguage::factory()->create();
    $target = StoreLanguage::factory()->create();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(RemoveStoreLanguage::class)($target);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'delete'
            && ($context['target_id'] ?? null) === $target->id)
        ->once();
});

test('a permitted remove writes its single Log::info success line and no warning', function () {
    Log::spy();

    StoreLanguage::factory()->create();
    $target = StoreLanguage::factory()->create();

    $this->actingAs(removeStoreLanguageActor());

    app(RemoveStoreLanguage::class)($target);

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')->once();
});
