<?php

namespace App\Actions\StoreLanguages;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\StoreLanguageValidationRules;
use App\Models\StoreLanguage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Add a store language by picking a code from the bundled ISO 639-1 list -- a find-or-create,
 * never a plain insert (D5, D15, D17): a fresh code inserts a new active/non-default row named
 * from the fixture, and a code matching an INACTIVE existing row reactivates it in place
 * (refreshing `name` from the fixture) rather than creating a duplicate. Both paths write with an
 * explicit literal key list -- forceFill()/forceCreate() -- mandatory here because
 * StoreLanguage's #[Fillable([])] means a plain create() would silently write nothing.
 */
class AddStoreLanguage
{
    use StoreLanguageValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Authorizes as its own first statement, outside any validation, so a refusal never runs a
     * query at all. $code is normalised to lowercase before both the membership/uniqueness check
     * and the write (R-6): 'fr' and 'FR' collide at the table's utf8mb4_unicode_ci unique index
     * but not under PHP `===`, so normalising here is what keeps the find-or-create lookup below
     * byte-exact and prevents a second row for the same language.
     *
     * Phase 4 finding L2 (Low, CWE-367): the "already active" validation check above and the
     * forceCreate() insert below have nothing locking between them, so two concurrent
     * AddStoreLanguage($code) calls for the SAME brand-new code can both pass validation (neither
     * sees the other's not-yet-committed row) and both attempt the insert -- the loser hits the
     * table's own unique index on `code` and would otherwise surface a raw
     * UniqueConstraintViolationException (a 500) instead of the clean, keyed ValidationException
     * the sequential-duplicate case already produces. The unique index is what actually enforces
     * the invariant here; the validation rule above is advisory for the common case. Caught and
     * rethrown as the identical refusal shape so a caller can never tell which of the two races it
     * lost.
     */
    public function __invoke(string $code): StoreLanguage
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', StoreLanguage::class, targetType: 'store_language');

        $normalizedCode = Str::lower($code);

        Validator::make(
            ['code' => $normalizedCode],
            ['code' => $this->codeRules()],
            messages: [
                'code.in' => __('store-languages.errors.code_not_in_fixture'),
                'code.unique' => __('store-languages.errors.code_already_active'),
            ],
            attributes: ['code' => __('store-languages.attributes.code')],
        )->validate();

        $name = StoreLanguage::availableLanguages()[$normalizedCode];

        $existing = StoreLanguage::query()->where('code', $normalizedCode)->first();

        if ($existing !== null) {
            $language = tap($existing->forceFill(['is_active' => true, 'name' => $name]))->save();
        } else {
            try {
                $language = StoreLanguage::forceCreate([
                    'code' => $normalizedCode,
                    'name' => $name,
                    'is_default' => false,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'code' => __('store-languages.errors.code_already_active'),
                ]);
            }
        }

        Log::info('Store language added', [
            'actor_id' => Auth::id(),
            'store_language_id' => $language->id,
        ]);

        return $language->refresh();
    }
}
