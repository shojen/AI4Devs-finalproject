<?php

// Story 0068 -- App\Actions\StoreLanguages\AddStoreLanguage, a find-or-create over the bundled
// ISO 639-1 fixture (D5, D15, D17): a code not in App\Models\StoreLanguage::availableLanguages()
// can never enter the catalog, a fresh code inserts a new active/non-default row named from the
// fixture, and a code matching an INACTIVE existing row reactivates it in place (same id) rather
// than creating a duplicate.
//
// Direct action-level calls throughout (app(AddStoreLanguage::class)(...)), never `new
// AddStoreLanguage(...)` -- the task file's own contract requires container resolution -- and
// never through a Livewire component, since this story ships no interactive screen (story 0069's
// job).

use App\Actions\StoreLanguages\AddStoreLanguage;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function addStoreLanguageActor(array $permissions = ['store-languages.view', 'store-languages.create']): User
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

test('picking a fixture code persists a row that is active and not the default', function () {
    $this->actingAs(addStoreLanguageActor());

    $language = app(AddStoreLanguage::class)('fr');

    expect($language->code)->toBe('fr')
        ->and($language->is_active)->toBeTrue()
        ->and($language->is_default)->toBeFalse()
        ->and($language->fresh()->is_active)->toBeTrue()
        ->and($language->fresh()->is_default)->toBeFalse();
});

test('the persisted row\'s name equals the fixture\'s endonym for that code, never a caller-supplied value', function () {
    $this->actingAs(addStoreLanguageActor());

    $language = app(AddStoreLanguage::class)('fr');

    expect($language->name)->toBe(StoreLanguage::availableLanguages()['fr']);
});

// =====================================================================
// Reactivation -- D5: "remove" never deletes, so re-adding a removed language must reactivate
// its existing row rather than insert a duplicate. The id staying the same is what distinguishes
// a genuine reactivation from a delete-and-reinsert.
// =====================================================================

test('picking a code matching an inactive existing row reactivates it, keeping the same id and row count', function () {
    $inactive = StoreLanguage::factory()->inactive()->create(['code' => 'fr']);
    $countBefore = StoreLanguage::count();

    $this->actingAs(addStoreLanguageActor());

    $reactivated = app(AddStoreLanguage::class)('fr');

    expect($reactivated->id)->toBe($inactive->id)
        ->and($reactivated->is_active)->toBeTrue()
        ->and(StoreLanguage::count())->toBe($countBefore);
});

test('reactivating a removed row refreshes its name from the fixture, overwriting a stale stored value', function () {
    $inactive = StoreLanguage::factory()->inactive()->create([
        'code' => 'fr',
        'name' => 'Stale Name From Before A Fixture Correction',
    ]);

    $this->actingAs(addStoreLanguageActor());

    $reactivated = app(AddStoreLanguage::class)('fr');

    expect($reactivated->name)->toBe(StoreLanguage::availableLanguages()['fr'])
        ->and($reactivated->name)->not->toBe('Stale Name From Before A Fixture Correction')
        ->and($reactivated->id)->toBe($inactive->id);
});

// =====================================================================
// Domain invariant (negative) -- adding a code already ACTIVE is refused by validation, not a
// raw QueryException surfacing the unique index.
// =====================================================================

test('adding a code already held by an active row is refused by validation, not a raw QueryException', function () {
    StoreLanguage::factory()->create(['code' => 'fr']);
    $countBefore = StoreLanguage::count();

    $this->actingAs(addStoreLanguageActor());

    expect(fn () => app(AddStoreLanguage::class)('fr'))
        ->toThrow(ValidationException::class);

    expect(StoreLanguage::count())->toBe($countBefore);
});

test('the validation refusal for an already-active code is keyed on code, a name the calling component can declare', function () {
    StoreLanguage::factory()->create(['code' => 'fr']);

    $this->actingAs(addStoreLanguageActor());

    try {
        app(AddStoreLanguage::class)('fr');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('code');
    }
});

// =====================================================================
// Phase 4 finding L2 (Low, CWE-367): the "already active" validation check and the forceCreate()
// insert have nothing locking between them, so two concurrent AddStoreLanguage('fr') calls for a
// BRAND-NEW code can both pass validation (neither sees the other's not-yet-committed row) and
// both attempt the insert. A genuinely simultaneous two-connection race is unreachable under
// RefreshDatabase's single transaction, so the MECHANISM is simulated -- a competing row lands in
// the `creating` hook, after this call's own validation already passed and immediately before its
// own insert -- and the OUTCOME is asserted: a clean ValidationException, never a raw
// UniqueConstraintViolationException, and no second row for the code (mirrors
// tests/Feature/Blog/FindOrCreateBlogTagTest.php's own "a lost insert race" test).
// =====================================================================

test('a concurrent insert of the same brand-new code is refused with a clean ValidationException, not a raw exception', function () {
    $this->actingAs(addStoreLanguageActor());

    StoreLanguage::creating(function (StoreLanguage $incoming): void {
        DB::table('store_languages')->insert([
            'id' => (string) Str::uuid7(),
            'code' => 'fr',
            'name' => 'Français',
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => app(AddStoreLanguage::class)('fr'))
        ->toThrow(ValidationException::class);

    expect(StoreLanguage::where('code', 'fr')->count())->toBe(1);
});

// =====================================================================
// Validation -- codes outside the bundled list are refused on every path. This is a
// tampered-request test (the picker itself only ever offers a bundled code), not a typo test.
// =====================================================================

test('a code outside the bundled fixture is refused with a validation error naming the code', function (string $badCode) {
    $this->actingAs(addStoreLanguageActor());

    $countBefore = StoreLanguage::count();

    try {
        app(AddStoreLanguage::class)($badCode);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('code');
    }

    expect(StoreLanguage::count())->toBe($countBefore);
})->with([
    'zz' => ['zz'],
    'a full English word' => ['english'],
    'valid BCP 47 but not in this bare-ISO-639-1 fixture (D16)' => ['pt-BR'],
    'empty string' => [''],
    'digits' => ['123'],
    'a 40-character string' => [str_repeat('x', 40)],
]);

// =====================================================================
// R-6 -- the utf8mb4_unicode_ci collation trap. 'fr' and 'FR' collide at the unique index but
// not under PHP ===, so the chosen behaviour (normalise before both the membership check and the
// write) must be pinned explicitly rather than left undiscovered, and must never allow a second
// row for the same language.
// =====================================================================

test('an uppercase code is normalised or refused, and can never produce a second row for the same language', function () {
    $this->actingAs(addStoreLanguageActor());

    try {
        $language = app(AddStoreLanguage::class)('FR');
        // Normalised path: accepted, but stored/looked-up lowercase.
        expect($language->code)->toBe('fr');
    } catch (ValidationException) {
        // Refused path: acceptable too, as long as nothing was written.
    }

    // Either way, adding the lowercase form afterwards must reach exactly one row for "fr".
    try {
        app(AddStoreLanguage::class)('fr');
    } catch (ValidationException) {
        // Already active from the first call -- also acceptable.
    }

    expect(StoreLanguage::where('code', 'fr')->count())->toBe(1);
});

// =====================================================================
// Authorization -- the action authorizes itself independently of any caller (a policy test
// cannot show this, and an HTTP test cannot either).
// =====================================================================

test('called directly, an actor lacking store-languages.create is refused and nothing is persisted', function () {
    $actor = User::factory()->create(); // holds no permission at all
    $this->actingAs($actor);

    $countBefore = StoreLanguage::count();

    expect(fn () => app(AddStoreLanguage::class)('fr'))
        ->toThrow(AuthorizationException::class);

    expect(StoreLanguage::count())->toBe($countBefore);
});

test('called with no authenticated user, the action is refused', function () {
    expect(fn () => app(AddStoreLanguage::class)('fr'))
        ->toThrow(AuthorizationException::class);

    expect(StoreLanguage::count())->toBe(0);
});

test('called directly, an actor holding store-languages.create succeeds', function () {
    $this->actingAs(addStoreLanguageActor());

    $language = app(AddStoreLanguage::class)('fr');

    expect($language->code)->toBe('fr');
});

// =====================================================================
// Refusal logging -- context array, never the rendered message (docs/architecture/
// authorization/step-up-and-refusal-logging.md).
// =====================================================================

// Phase 4 finding L3 (Low): the authorize() call against the bare StoreLanguage::class cannot
// derive a target type on its own (LogRefusedPrivilegedAttempt::resolveTarget() only recognises a
// User/Role instance), so this MUST be passed explicitly -- asserted here against the exact value,
// not merely that the key exists, since the prior key-presence-only assertion is exactly how a
// silently-null target_type passed unnoticed.
test('an authorization refusal writes exactly one Log::warning naming store_language as the target type', function () {
    Log::spy();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(AddStoreLanguage::class)('fr');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'create'
            && ($context['target_type'] ?? null) === 'store_language'
            && ! str_contains((string) json_encode($context), 'password'))
        ->once();
});

test('a permitted add writes its single Log::info success line and no warning', function () {
    Log::spy();

    $this->actingAs(addStoreLanguageActor());

    app(AddStoreLanguage::class)('fr');

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')->once();
});
