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
 * The single named writer of store_languages.is_default, anywhere in the app -- story 0068,
 * copying App\Actions\SalesRegions\SetDefaultSalesRegion's post-re-audit shape (D6): a single
 * primary-key-ordered lockForUpdate() query covering both the promotion target and every
 * currently-default row, inside one DB::transaction(attempts: 3), with every read and write
 * performed against the re-fetched rows and never against the caller's own instance
 * (docs/security/model-instance-trust.md).
 */
class SetDefaultStoreLanguage
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Authorizes as its own first statement, outside the transaction. An inactive target is
     * refused (D6's "the default must be active" half) inside the transaction, against the
     * freshly re-fetched row -- never the caller's in-memory attribute, which may be stale or
     * forged.
     */
    public function __invoke(StoreLanguage $newDefault): StoreLanguage
    {
        $this->logRefusedPrivilegedAttempt->authorize('update', $newDefault, targetType: 'store_language', targetId: $newDefault->id);

        $result = DB::transaction(function () use ($newDefault): StoreLanguage {
            // Every row this call could touch, locked together in ONE primary-key-ordered query:
            // the target itself, plus every row currently flagged as the default.
            $rows = StoreLanguage::query()
                ->where(fn ($query) => $query->where('is_default', true)->orWhere('id', $newDefault->getKey()))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $target = $rows->first(fn (StoreLanguage $row): bool => $row->is($newDefault))
                ?? throw (new ModelNotFoundException)->setModel(StoreLanguage::class, [$newDefault->getKey()]);

            if (! $target->is_active) {
                $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'default_must_be_active', 'store_language', $target->id);

                throw ValidationException::withMessages([
                    'languageId' => __('store-languages.errors.default_must_be_active'),
                ]);
            }

            // Clear every OTHER already-locked default row (self-healing: if the invariant were
            // ever violated by a data mishap, the next call converges instead of leaving a second
            // flag behind), excluding $target's own row.
            $rows->reject(fn (StoreLanguage $row): bool => $row->is($target))
                ->each(fn (StoreLanguage $current): bool => $current->forceFill(['is_default' => false])->save());

            return tap($target->forceFill(['is_default' => true]))->save();
        }, attempts: 3);

        Log::info('Store language default changed', [
            'actor_id' => Auth::id(),
            'store_language_id' => $result->id,
        ]);

        return $result;
    }
}
