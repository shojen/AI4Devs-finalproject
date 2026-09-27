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
use Illuminate\Support\Facades\Log;
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

test('an authorization refusal writes exactly one Log::warning with the actor, ability and target type', function () {
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
            && array_key_exists('target_type', $context)
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
