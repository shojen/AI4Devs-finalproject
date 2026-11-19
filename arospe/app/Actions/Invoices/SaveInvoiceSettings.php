<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\InvoiceSettingValidationRules;
use App\Enums\InvoiceNumberYear;
use App\Models\InvoiceSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of `invoice_settings` (story 0100, FD-5, FD-7). A save replaces the whole
 * record: every settings key must be present, unknown keys are ignored. Order: logged
 * authorization, normalisation, field validation, cross-field checks (judged on the state the
 * save would produce), an autocommit insert-ignore of the row outside the transaction, then a
 * transaction that locks the row FOR UPDATE and writes it.
 */
class SaveInvoiceSettings
{
    use InvoiceSettingValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly AssertNumberingFormatIsFree $assertNumberingFormatIsFree,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function __invoke(array $attributes): InvoiceSetting
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            InvoiceSetting::class,
            targetType: 'invoice_setting',
            targetId: InvoiceSetting::SINGLETON_ID,
        );

        $validated = Validator::make(
            $this->normalizeInvoiceSettingAttributes($attributes),
            $this->invoiceSettingRules(),
            [],
            $this->attributeNames(),
        )->validate();

        $this->assertCrossFieldRules($validated);

        $now = now();

        InvoiceSetting::query()->insertOrIgnore([[
            'id' => InvoiceSetting::SINGLETON_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);

        $settings = DB::transaction(function () use ($validated, $now): InvoiceSetting {
            $row = InvoiceSetting::query()->lockForUpdate()->findOrFail(InvoiceSetting::SINGLETON_ID);

            ($this->assertNumberingFormatIsFree)($row->replicate()->forceFill($validated), $now);

            $row->forceFill($validated)->save();

            return $row;
        });

        Log::info('Invoice settings saved', [
            'actor_id' => Auth::id(),
            'changed_keys' => array_values(array_diff(array_keys($settings->getChanges()), ['updated_at'])),
        ]);

        return $settings;
    }

    /**
     * Refuse the combinations FD-4 forbids, each as an error on the named field only.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertCrossFieldRules(array $validated): void
    {
        $errors = [];

        if ($validated['reset_numbering_yearly']) {
            foreach (['standard', 'corrective'] as $series) {
                if ($validated["{$series}_year_format"] === InvoiceNumberYear::None->value) {
                    $errors["{$series}_year_format"] = [__('invoice-settings.validation.year_required_when_reset')];
                }
            }
        }

        if (strtolower((string) $validated['standard_prefix']) === strtolower((string) $validated['corrective_prefix'])) {
            $errors['corrective_prefix'] = [__('invoice-settings.validation.prefixes_must_differ')];
        }

        if ($validated['auto_issue_on_order']
            && (blank($validated['issuer_legal_name']) || blank($validated['issuer_tax_id']) || blank($validated['issuer_address_line1']))) {
            $errors['auto_issue_on_order'] = [__('invoice-settings.validation.auto_issue_requires_issuer')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
