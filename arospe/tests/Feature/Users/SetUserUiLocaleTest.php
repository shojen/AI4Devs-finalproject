<?php

// Story 0066 -- App\Actions\Users\SetUserUiLocale, the users.ui_locale column's single writer
// (D7). The self-only rule (D11) is DERIVED from Auth::user() inside the action, never accepted as
// a parameter: an HTTP caller (an authenticated actor present) may only ever write their own row;
// a console/queue caller (no authenticated actor) may target any user -- both shapes are tested as
// deliberately as each other below. No policy and no permission is introduced (D11): setting one's
// own UI language is self-service, identical for every account.
//
// The invalid-value cases test App\Concerns\UserValidationRules::uiLocaleRules() directly via a
// harness (mirroring tests/Unit/Concerns/BlogPostValidationRulesTest.php's pattern), NOT the action
// itself: the action's own signature is `__invoke(UiLocale $locale, ?User $user = null)`, a typed
// enum parameter, so an invalid raw string never reaches it in the first place -- validation is
// this trait's job, consulted by whichever caller (story 0067's Livewire form) builds the UiLocale
// enum from user input. This is filed here rather than under tests/Unit/Concerns/ because the
// "leaves the stored value unchanged" half of the assertion needs a real database row.

use App\Actions\Users\SetUserUiLocale;
use App\Concerns\UserValidationRules;
use App\Enums\UiLocale;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

test('the action persists the chosen locale for the acting user, asserted against the database row', function () {
    $user = User::factory()->create(['ui_locale' => 'en']);
    $this->actingAs($user);

    $returned = app(SetUserUiLocale::class)(UiLocale::Spanish, $user);

    expect($returned->ui_locale)->toBe('es');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'ui_locale' => 'es']);
});

test('called with no explicit target, it writes only the authenticated users own row', function () {
    $actor = User::factory()->create(['ui_locale' => 'en']);
    $bystander = User::factory()->create(['ui_locale' => 'en']);
    $this->actingAs($actor);

    $returned = app(SetUserUiLocale::class)(UiLocale::Spanish);

    expect($returned->id)->toBe($actor->id);
    $this->assertDatabaseHas('users', ['id' => $actor->id, 'ui_locale' => 'es']);
    $this->assertDatabaseHas('users', ['id' => $bystander->id, 'ui_locale' => 'en']);
});

test('an authenticated actor targeting another user is refused with AuthorizationException, and the targets row is unchanged', function () {
    // Risk if missing (task file): a later refactor adding an admin-on-behalf-of path silently
    // opens a cross-user write nothing else in the story defends.
    $actor = User::factory()->create(['ui_locale' => 'en']);
    $target = User::factory()->create(['ui_locale' => 'en']);
    $this->actingAs($actor);

    expect(fn () => app(SetUserUiLocale::class)(UiLocale::Spanish, $target))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('users', ['id' => $target->id, 'ui_locale' => 'en']);
});

test('called with an explicit target and no authenticated actor, the console/queue shape, it succeeds', function () {
    // The exemption tested as deliberately as the refusal above -- an over-block here would ship
    // unnoticed otherwise.
    $target = User::factory()->create(['ui_locale' => 'en']);

    expect(Auth::user())->toBeNull();

    $returned = app(SetUserUiLocale::class)(UiLocale::Spanish, $target);

    expect($returned->ui_locale)->toBe('es');
    $this->assertDatabaseHas('users', ['id' => $target->id, 'ui_locale' => 'es']);
});

test('a soft-deleted target reached with no authenticated actor still has its row written', function () {
    // Pinned, not load-bearing (task file): a trashed user cannot obtain a session, so this shape
    // is only reachable from the console/queue path above. Recorded once so a later change to the
    // soft-delete global scope does not silently change this action's behaviour too. The action's
    // own pseudocode carries no trashed check, so a direct forceFill()->save() on an already-
    // hydrated instance is expected to succeed regardless -- Eloquent's SoftDeletes scope applies
    // to QUERIES, not to save() on a model already in hand.
    $target = User::factory()->create(['ui_locale' => 'en']);
    $target->delete();
    expect($target->trashed())->toBeTrue();

    $returned = app(SetUserUiLocale::class)(UiLocale::Spanish, $target);

    expect($returned->ui_locale)->toBe('es');
    $this->assertDatabaseHas('users', ['id' => $target->id, 'ui_locale' => 'es']);
});

test('called with no authenticated actor and no explicit target, it throws a clean exception instead of a bare Error', function () {
    // appsec-auditor F-3 (Low, story 0066 Phase 4): the console/queue shape with nobody signed
    // in AND no $user argument leaves $target null, so `$target->forceFill(...)` currently
    // fatals with a bare Error ("Call to a member function forceFill() on null") -- a raw 500 /
    // ugly job failure, not an intentional guard. This pins that misuse throws SOME ordinary
    // \Exception-family failure instead, without pinning backend-expert's exact class choice.
    expect(Auth::user())->toBeNull();

    $thrown = null;

    try {
        app(SetUserUiLocale::class)(UiLocale::Spanish);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown)->not->toBeInstanceOf(Error::class)
        ->and($thrown)->toBeInstanceOf(Exception::class);
});

test('uiLocaleRules() refuses every value outside en/es, leaving the stored value unchanged', function (mixed $invalid) {
    $user = User::factory()->create(['ui_locale' => 'en']);

    $rules = (new class
    {
        use UserValidationRules;

        /** @return array<int, mixed> */
        public function uiLocale(): array
        {
            return $this->uiLocaleRules();
        }
    })->uiLocale();

    $validator = Validator::make(['ui_locale' => $invalid], ['ui_locale' => $rules]);

    expect($validator->fails())->toBeTrue();

    // No caller ever invokes the action once validation has failed -- asserting the database
    // row (not just the validator's ->fails() return) is what proves the row genuinely never
    // moved, per the task file's own callout.
    $this->assertDatabaseHas('users', ['id' => $user->id, 'ui_locale' => 'en']);
})->with([
    'an unsupported language code' => ['fr'],
    'a differently-cased code' => ['EN'],
    'a region-qualified code' => ['en-US'],
    'an empty value' => [''],
    'an over-long value' => [str_repeat('a', 200)],
]);
