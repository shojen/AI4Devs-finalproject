<?php

namespace App\Actions\StoreLanguages;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Remove a store language -- meaning `is_active = false`, never a delete of any kind (D5): the
 * row, and therefore any content later keyed to it, stays physically intact. Two hard invariants
 * guard it, enforced under a row lock rather than as an authorization rule
 * (docs/architecture/authorization/domain-invariants.md): the current default cannot be removed
 * (D6), and the last active language cannot be removed (D7). Both bind a Super Admin identically
 * -- the invariant is about the data, not the actor.
 */
class RemoveStoreLanguage
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Authorizes as its own first statement, outside the transaction, so a refusal never opens
     * one. The row is re-fetched and locked inside the transaction rather than trusting the
     * caller-supplied $language instance (docs/security/model-instance-trust.md).
     *
     * Every row this call could touch -- the target itself, plus every currently-active row --
     * is locked together in ONE primary-key-ordered query, the same combined-lock shape
     * SetDefaultStoreLanguage / SetDefaultSalesRegion use and for the identical reason: two
     * SEPARATE lockForUpdate() queries (the target first, an unordered active-count scan second)
     * let two concurrent removals of two DIFFERENT active languages each lock their own target
     * first and then contend for the other's rows in the count scan with no guaranteed ordering
     * -- the confirmed-by-execution deadlock SetDefaultSalesRegion's own docblock documents,
     * reappearing here. A single ordered query removes the inconsistent ordering structurally:
     * any two overlapping acquisitions always request the same rows in the same order. Both the
     * is_default guard and the active-count guard are then derived from this ONE locked result
     * set, not from a stale pre-flight query, so a concurrent deactivation of another row is
     * honoured.
     *
     * When a row is BOTH the current default AND the last active language, only ONE refusal
     * reason is logged -- the more specific one, `cannot_remove_default` -- never both: the
     * is_default check runs first and returns before the active-count check is ever reached.
     */
    public function __invoke(StoreLanguage $language): StoreLanguage
    {
        $this->logRefusedPrivilegedAttempt->authorize('delete', $language, targetType: 'store_language', targetId: $language->id);

        $removed = DB::transaction(function () use ($language): StoreLanguage {
            // The target itself (whichever state it is currently in) plus every row currently
            // flagged active, locked together in ONE primary-key-ordered query.
            $rows = StoreLanguage::query()
                ->where(fn ($query) => $query->whereKey($language->getKey())->orWhere('is_active', true))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $target = $rows->first(fn (StoreLanguage $row): bool => $row->is($language))
                ?? throw (new ModelNotFoundException)->setModel(StoreLanguage::class, [$language->getKey()]);

            if ($target->is_default) {
                $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'cannot_remove_default', 'store_language', $target->id);

                throw ValidationException::withMessages([
                    'languageId' => __('store-languages.errors.cannot_remove_default'),
                ]);
            }

            $activeCount = $rows->filter(fn (StoreLanguage $row): bool => $row->is_active)->count();

            if ($activeCount <= 1) {
                $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'cannot_remove_last_active_language', 'store_language', $target->id);

                throw ValidationException::withMessages([
                    'languageId' => __('store-languages.errors.cannot_remove_last_active_language'),
                ]);
            }

            return tap($target->forceFill(['is_active' => false]))->save();
        });

        Log::info('Store language removed', [
            'actor_id' => Auth::id(),
            'store_language_id' => $removed->id,
        ]);

        return $removed;
    }
}
