<?php

namespace App\Actions\PaymentMethods;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\PaymentMethodValidationRules;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Authorize, normalise (uppercase, whitespace stripped), validate and
 * persist $iban on $method.
 *
 * **Corrected 2026-09-10, Phase 4 security audit finding F-1 -- the story's
 * own task file previously said this class deliberately does NOT authorize
 * itself, reasoning that "every existing action in this repo (CreateUser,
 * UpdateUser, RequestEmailChange) is an unguarded domain operation, with
 * authorization at the Livewire boundary." That premise does not hold:
 * `CreateUser`/`UpdateUser` both self-authorize as their own first
 * statement (see app/Actions/Users/CreateUser.php), and `RequestEmailChange`
 * is ungated only because it is a self-service operation with no permission
 * to inherit in the first place -- not a precedent for a primary writer of
 * privileged, financially-sensitive data reachable from a permission-gated
 * screen. This is the shape task 0008a's "an authorization rule belongs to
 * the action, not to one of its callers" convention exists to enforce (see
 * docs/conventions/base-standards.md), and the exact gap story 0025 had to
 * retrofit into App\Actions\ProductCategories\* after shipping ungated on
 * the identical reasoning. Quoted here per this project's audit-authored-
 * page convention rather than silently rewritten.**
 *
 * App\Livewire\PaymentMethods\Index::save() authorizes AND validates too --
 * defence in depth, not duplication to remove; this is what a future
 * non-Livewire caller (an Artisan command, a queued job, Epic 3's order
 * flow) inherits. Validating here as well (Phase 5 code review finding N4)
 * closes the same class of gap F-1 closed, one field over: without it, a
 * direct caller that skips the component entirely could persist a
 * structurally invalid or checksum-failing IBAN with no error at all.
 *
 * Re-applies the normalisation the caller already performed, so a future
 * second call site that forgets to normalise first still stores the
 * canonical form. Strips ALL whitespace (`\s`), not only literal spaces --
 * a pasted bank statement can carry a non-breaking space or a tab.
 *
 * @throws ValidationException
 */
class UpdatePaymentMethodIban
{
    use PaymentMethodValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    public function __invoke(PaymentMethod $method, string $iban): void
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $method,
            targetType: 'payment_method',
            targetId: $method->id,
        );

        $normalisedIban = strtoupper((string) preg_replace('/\s+/u', '', $iban));

        $validated = Validator::make(
            ['iban' => $normalisedIban],
            ['iban' => $this->ibanRules()],
        )->validate();

        $method->update(['iban' => $validated['iban']]);
    }
}
