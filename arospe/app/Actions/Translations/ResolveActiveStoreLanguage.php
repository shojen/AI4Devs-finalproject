<?php

namespace App\Actions\Translations;

use App\Models\StoreLanguage;
use Illuminate\Validation\ValidationException;

/**
 * Story 0087: the one place a store language is re-read as ACTIVE before a Set*Translation action
 * writes into it. It authorizes nothing: it is a collaborator of already-authorized actions.
 *
 * Deliberately NOT final: a test binds a subclass double of it in the container.
 */
class ResolveActiveStoreLanguage
{
    /**
     * The passed language is untrusted (docs/security/model-instance-trust.md): callers must use the
     * returned fresh instance afterwards.
     *
     * @throws ValidationException keyed $errorKey with $message when the language is not active
     */
    public function __invoke(StoreLanguage $language, string $errorKey, string $message): StoreLanguage
    {
        $activeLanguage = StoreLanguage::query()->active()->find($language->id);

        if ($activeLanguage === null) {
            throw ValidationException::withMessages([$errorKey => $message]);
        }

        return $activeLanguage;
    }
}
