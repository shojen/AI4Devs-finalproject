<?php

// Story 0064d -- App\Actions\Users\UpdateUser decides the sensitive-attribute/step-up gate off a
// caller-hydrated instance's getRawOriginal('status') and writes with no lockForUpdate() re-read.
// Two administrators racing to change the same user's status can therefore have the gate decision
// made on stale data, and the write can silently clobber a concurrent change with no detection.
//
// This file reproduces that race DETERMINISTICALLY with the stale-instance technique (0064c's D-6,
// technique 1 -- the only technique this story needs, per its own D-5: UpdateUser has exactly one
// caller and no nested-transaction shape). Never Event::fake() or a mocked Gate anywhere in this
// file (reading note (b)): the real Gate::authorize()/AuthorizationException path is the point.
// Never refresh()/fresh() the stale instance under test (reading note (d)): that instance staying
// stale is what makes the race real. Never a real two-connection lock-blocking test (reading note
// (e)): RefreshDatabase keeps every write uncommitted, so a second connection would hang out
// InnoDB's lock timeout rather than proving anything about this code.
//
// Read this before touching a mutation: G1 is expected RED against today's unmodified action (it
// never refreshes before the sensitive-attribute decision, so a resubmission matching the stale
// original is silently treated as a no-op); the Conflict path case is expected RED too (today's
// code has no re-read at all, so it writes straight through). R1, R3, G2 and G3 are all expected to
// already pass today -- G2 earns its place as a permanent regression net for the mechanism the fix
// introduces (a real ValidationException conflict), not by being red now; R1/R3/G3 earn theirs as
// the pre-existing behaviour the fix must not disturb.
use App\Actions\Users\RequestEmailChange;
use App\Actions\Users\UpdateUser;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\PasswordConfirmationRequiredException;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Build the D-6 "stale instance" fixture: an Administrator-tier target, then a SECOND load of that
 * same row -- the instance every test below hands to UpdateUser, standing in for whatever the
 * administrator's own form last displayed. Administrator-tier so G3 can exercise a genuine
 * roles.manage-administrators refusal against the identical fixture G1/G2 use (UserPolicy's
 * updateSensitiveAttributes() only consults that permission for an Administrator-holding target).
 */
function updateUserRaceFixture(UserStatus $initialStatus = UserStatus::Active): array
{
    $administratorRole = Role::where('name', RoleName::Administrator->value)->where('guard_name', 'web')->firstOrFail();

    $target = User::factory()->create(['status' => $initialStatus]);
    $target->assignRole($administratorRole);

    /** @var User $stale */
    $stale = User::query()->findOrFail($target->getKey());

    return [$stale, $administratorRole];
}

// Prefixed (not the bare markPasswordConfirmationStale()/Fresh() names) to match this codebase's
// own established convention for a per-file-local test helper of the same shape --
// UpdateUserStepUpAuthorizationTest.php already claims the bare names, and Pest loads every test
// file into one process, so a second same-named `function` is a fatal redeclaration error.
function markRacePasswordConfirmationStale(): void
{
    session(['auth.password_confirmed_at' => now()->subSeconds(config('auth.password_timeout') + 60)->unix()]);
}

function markRacePasswordConfirmationFresh(): void
{
    session(['auth.password_confirmed_at' => now()->unix()]);
}

// =====================================================================
// R1 -- a genuinely different submission, no race at all, is still correctly gated. The
// pre-existing behaviour this story must not disturb.
// =====================================================================

test('R1: a genuine status change with no race in play still requires and passes step-up', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);

    $updateUser = app(UpdateUser::class);
    $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, UserStatus::Suspended, app(RequestEmailChange::class));

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);
});

// =====================================================================
// R3 -- an unrelated third user is completely untouched by this file's race fixtures.
// =====================================================================

test('R3: an unrelated third user\'s status and updated_at are untouched by a concurrent race elsewhere', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    $bystander = User::factory()->create(['status' => UserStatus::Inactive]);
    $bystanderUpdatedAtBefore = $bystander->updated_at;

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);
    User::query()->whereKey($target->getKey())->update(['status' => UserStatus::Suspended->value]);

    $updateUser = app(UpdateUser::class);

    try {
        $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, UserStatus::Active, app(RequestEmailChange::class));
    } catch (Throwable) {
        // The conflict path is exercised elsewhere; this test only cares that the bystander
        // is never touched, whichever way this particular call resolves.
    }

    expect($bystander->fresh()->status)->toBe(UserStatus::Inactive)
        ->and($bystander->fresh()->updated_at->equalTo($bystanderUpdatedAtBefore))->toBeTrue();
});

// =====================================================================
// G1 -- a resubmission matching the stale original, when the row was concurrently changed to
// something else, still requires step-up. Predicted RED-FIRST: today's code compares the
// submission against the caller-hydrated (never refreshed) instance, so this reads as "no change"
// and skips the gate entirely.
// =====================================================================

test('G1: a resubmission matching the stale original still requires step-up once the row has moved', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationStale();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);

    // The second administrator's concurrent suspension, committed before this call begins.
    User::query()->whereKey($target->getKey())->update(['status' => UserStatus::Suspended->value]);

    $updateUser = app(UpdateUser::class);

    // $target's own in-memory status is still Active -- exactly what the first administrator's
    // form last showed them, submitted back unchanged.
    expect(fn () => $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, $target->status, app(RequestEmailChange::class)))
        ->toThrow(PasswordConfirmationRequiredException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);
});

// =====================================================================
// G2 -- the same fixture, with a fresh confirmation: the persisted status is never silently
// reverted to what the stale screen showed. Predicted NOT red -- Eloquent's own dirty-tracking
// already protects today's unmodified code by coincidence (the in-memory attribute still matches
// the submitted value, so no UPDATE ever touches the column); the fix makes this an explicit,
// detected conflict instead of an accident. If this comes back red, STOP: it means today's code
// can silently clobber a concurrent administrator's change.
// =====================================================================

test('G2: the persisted status is never silently reverted to what the stale screen showed', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);

    User::query()->whereKey($target->getKey())->update(['status' => UserStatus::Suspended->value]);

    $updateUser = app(UpdateUser::class);

    try {
        $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, $target->status, app(RequestEmailChange::class));
    } catch (ValidationException) {
        // The fixed code refuses this as a conflict -- an acceptable, even expected, resolution;
        // this test only asserts the row was never reverted, regardless of the mechanism.
    }

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);
});

// =====================================================================
// G3 -- same fixture as G1, actor lacks the sensitive-attribute permission tier: the refusal must
// be AuthorizationException, distinguishing "the gate ran and refused" from "the gate never ran".
// =====================================================================

test('G3: an actor lacking the Administrator-tier permission is refused by the Gate, not silently waved through', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('users.edit'); // deliberately NOT roles.manage-administrators
    $this->actingAs($actor);
    markRacePasswordConfirmationStale();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);
    User::query()->whereKey($target->getKey())->update(['status' => UserStatus::Suspended->value]);

    $updateUser = app(UpdateUser::class);

    $caught = null;

    try {
        $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, $target->status, app(RequestEmailChange::class));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class)
        ->and($caught)->not->toBeInstanceOf(PasswordConfirmationRequiredException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);
});

// =====================================================================
// Conflict path -- an administrator submits a status change chosen from an outdated profile: the
// row has since genuinely moved to a THIRD value. Red-first: no such check exists today, so the
// row is silently overwritten with whatever was submitted.
// =====================================================================

test('conflict path: a status change decided from an outdated profile is refused, and the concurrent write stands', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);

    // The second administrator's concurrent change to a THIRD value -- neither the stale original
    // (Active) nor what the first administrator is about to submit (Suspended).
    User::query()->whereKey($target->getKey())->update(['status' => UserStatus::Inactive->value]);

    $updateUser = app(UpdateUser::class);

    $caught = null;

    try {
        // Chosen from the outdated (Active) profile: a genuine Active -> Suspended decision.
        $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, UserStatus::Suspended, app(RequestEmailChange::class));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    /** @var ValidationException $caught */
    expect($caught->errors())->toHaveKey('status');

    // The concurrent administrator's write is left standing -- neither the stale original nor the
    // losing submission ever reaches the row.
    expect($target->fresh()->status)->toBe(UserStatus::Inactive);
});

test('the conflict-exception path never discloses the concurrent value to the losing request', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);
    User::query()->whereKey($target->getKey())->update(['status' => UserStatus::Inactive->value]);

    $updateUser = app(UpdateUser::class);

    try {
        $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, UserStatus::Suspended, app(RequestEmailChange::class));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        $message = $e->errors()['status'][0];

        expect($message)->not->toContain(UserStatus::Inactive->value)
            ->and($message)->not->toContain('inactive')
            ->and($message)->not->toContain(UserStatus::Suspended->value);
    }
});

// =====================================================================
// A target soft- or hard-deleted between form load and submission (D-4) is refused as a conflict
// through the SAME path -- not a dedicated test per the task's own OQ-2(a), but exercised once
// here as a structural pin that the null-row branch is reachable and produces the same exception
// shape, not a fatal TypeError.
// =====================================================================

test('a target deleted between form load and submission is refused as a conflict, not written through', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);

    $other = User::query()->findOrFail($target->getKey());
    $other->delete();

    $updateUser = app(UpdateUser::class);

    $caught = null;

    try {
        $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, UserStatus::Suspended, app(RequestEmailChange::class));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect(User::withTrashed()->findOrFail($target->getKey())->trashed())->toBeTrue();
});

// =====================================================================
// Locked read is structural, not assumed -- one DB::listen-pinned query matching a lockForUpdate
// against `users`, scoped by primary key, actually runs during a status-changing call.
// =====================================================================

test('a status-changing call issues a locked, primary-key-scoped re-read of the users row', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['users.edit', 'roles.manage-administrators']);
    $this->actingAs($actor);
    markRacePasswordConfirmationFresh();

    [$target, $administratorRole] = updateUserRaceFixture(UserStatus::Active);

    /** @var list<object> $lockedSelects */
    $lockedSelects = [];
    DB::listen(function ($query) use (&$lockedSelects, $target): void {
        if (preg_match('/^select \* from `users` where `users`\.`id` = \? and `users`\.`deleted_at` is null limit 1 for update$/i', $query->sql) === 1
            && in_array($target->getKey(), $query->bindings, true)) {
            $lockedSelects[] = $query;
        }
    });

    $updateUser = app(UpdateUser::class);
    $updateUser($target, $target->name, $target->email, (string) $administratorRole->id, UserStatus::Suspended, app(RequestEmailChange::class));

    expect($lockedSelects)->toHaveCount(1);
});
