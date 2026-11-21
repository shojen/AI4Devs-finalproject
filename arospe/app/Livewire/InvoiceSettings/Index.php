<?php

namespace App\Livewire\InvoiceSettings;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Invoices\PeekNextInvoiceNumber;
use App\Actions\Invoices\ReadInvoiceSequenceState;
use App\Actions\Invoices\SaveInvoiceSettings;
use App\Actions\Invoices\SetInvoiceSequenceStart;
use App\Concerns\ChecksAbilitiesSafely;
use App\Concerns\InvoiceSettingValidationRules;
use App\Enums\InvoiceNumberYear;
use App\Enums\InvoiceTemplate;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice invoice settings screen (story 0103): one form that edits every invoice setting of
 * story 0100, shows the live next numbers (0101a) and links a sample PDF per template (0102).
 *
 * Every write method authorizes itself: the disabled fieldset of a read-only viewer is not security
 * (FD-9). Save errors are re-keyed from the action's snake_case fields to component properties (FD-8).
 */
#[Title('Invoice settings')]
class Index extends Component
{
    use ChecksAbilitiesSafely, InvoiceSettingValidationRules;

    /**
     * Save-error keys whose property is not the plain camel-case of the field.
     *
     * @var array<string, string>
     */
    private const IRREGULAR_PROPERTIES = [
        'reset_numbering_yearly' => 'isNumberingResetYearly',
        'auto_issue_on_order' => 'isAutoIssueEnabled',
    ];

    public string $issuerLegalName = '';

    public string $issuerTaxId = '';

    public string $issuerAddressLine1 = '';

    public string $issuerAddressLine2 = '';

    public string $issuerCity = '';

    public string $issuerPostalCode = '';

    public string $issuerProvince = '';

    public string $issuerCountry = '';

    public string $primaryColor = '';

    public string $accentColor = '';

    public string $template = 'classic';

    public string $standardPrefix = '';

    public string $standardYearFormat = '';

    public string $standardSeparator = '';

    public int $standardPadding = 5;

    public string $correctivePrefix = '';

    public string $correctiveYearFormat = '';

    public string $correctiveSeparator = '';

    public int $correctivePadding = 5;

    public bool $isNumberingResetYearly = true;

    public bool $isAutoIssueEnabled = false;

    public bool $showLogoGallery = false;

    public bool $showStartModal = false;

    /**
     * The chosen logo; only Save persists it (FD-3). Locked: the client never writes it.
     */
    #[Locked]
    public ?string $logoMediaId = null;

    /**
     * The series the start modal targets (an InvoiceType value).
     */
    #[Locked]
    public ?string $startSeries = null;

    public string $startAt = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', InvoiceSetting::class);

        $this->fillFromSettings(InvoiceSetting::current());
    }

    /**
     * Replace the whole record from the form (FD-2); errors are rethrown keyed by property (FD-8).
     */
    public function save(SaveInvoiceSettings $saveInvoiceSettings, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $this->authorizeUpdate($logRefusedPrivilegedAttempt);

        try {
            $saveInvoiceSettings($this->formAttributes());
        } catch (ValidationException $exception) {
            $this->dispatch('focus-first-error');

            throw ValidationException::withMessages($this->rekeyErrors($exception->errors()));
        }

        $this->fillFromSettings(InvoiceSetting::current());
        $this->forgetComputed();

        Flux::toast(variant: 'success', text: __('invoice-settings.screen.saved'));
    }

    /**
     * The picker's chosen media. The preview is derived from the id, never from the payload.
     *
     * @param  array<int, mixed>  $media
     */
    #[On('invoice-logo-selected')]
    public function setLogo(array $media, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $this->authorizeUpdate($logRefusedPrivilegedAttempt);
        $logRefusedPrivilegedAttempt->authorize('viewAny', Media::class);

        $id = is_array($media[0] ?? null) ? ($media[0]['id'] ?? null) : null;

        if (! is_string($id)) {
            return;
        }

        $selected = Media::query()->find($id);

        if ($selected === null) {
            return;
        }

        $this->logoMediaId = $selected->id;
        unset($this->logo);
    }

    public function removeLogo(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $this->authorizeUpdate($logRefusedPrivilegedAttempt);

        $this->logoMediaId = null;
        unset($this->logo);
    }

    public function updatedPrimaryColor(): void
    {
        $this->primaryColor = $this->checkColour('primaryColor', 'primary_color', $this->primaryColor);
    }

    public function updatedAccentColor(): void
    {
        $this->accentColor = $this->checkColour('accentColor', 'accent_color', $this->accentColor);
    }

    public function openStartModal(string $series, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $this->authorizeUpdate($logRefusedPrivilegedAttempt);

        $type = InvoiceType::tryFrom($series);

        if ($type === null || $this->sequenceState()[$type->value]['hasIssued']) {
            return;
        }

        $this->startSeries = $type->value;
        $this->startAt = '';
        $this->resetErrorBag('startAt');
        $this->showStartModal = true;
    }

    public function setStart(SetInvoiceSequenceStart $setInvoiceSequenceStart, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $this->authorizeUpdate($logRefusedPrivilegedAttempt);

        $this->validate(['startAt' => ['required', 'integer']], [], ['startAt' => __('invoice-settings.screen.start.input')]);

        $series = $this->startSeries === null ? null : InvoiceType::tryFrom($this->startSeries);

        if ($series === null) {
            return;
        }

        try {
            $setInvoiceSequenceStart($series, (int) $this->startAt);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['startAt' => $exception->errors()['start_at'] ?? []]);
        }

        $this->showStartModal = false;
        $this->forgetComputed();

        Flux::toast(variant: 'success', text: __('invoice-settings.screen.start_saved'));
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->allowsSafely('update', InvoiceSetting::class);
    }

    #[Computed]
    public function canChooseLogo(): bool
    {
        return $this->canManage() && $this->allowsSafely('viewAny', Media::class);
    }

    /**
     * The chosen logo, always read from the database by the locked id.
     *
     * @return array{title: string, url: string}|null
     */
    #[Computed]
    public function logo(): ?array
    {
        if ($this->logoMediaId === null) {
            return null;
        }

        $media = Media::query()->find($this->logoMediaId);

        if ($media === null) {
            return null;
        }

        $disk = Storage::disk('public');

        return [
            'title' => (string) $media->title,
            'url' => $disk->url($media->webp_path),
        ];
    }

    /**
     * @return array<int, array{value: string, name: string, description: string}>
     */
    #[Computed]
    public function templateOptions(): array
    {
        return array_map(fn (InvoiceTemplate $case): array => [
            'value' => $case->value,
            'name' => __("invoice-settings.screen.templates.{$case->value}.name"),
            'description' => __("invoice-settings.screen.templates.{$case->value}.description"),
        ], InvoiceTemplate::cases());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function yearOptions(): array
    {
        return array_map(fn (InvoiceNumberYear $case): array => [
            'value' => $case->value,
            'label' => __("invoice-settings.screen.year_options.{$case->value}"),
        ], InvoiceNumberYear::cases());
    }

    /**
     * The only colour ever written into a style attribute (FD-7).
     */
    #[Computed]
    public function safePrimaryColor(): ?string
    {
        return $this->safeColour($this->primaryColor);
    }

    #[Computed]
    public function safeAccentColor(): ?string
    {
        return $this->safeColour($this->accentColor);
    }

    /**
     * The next number per series for the format being typed, or null when that format is invalid
     * (FD-5). Nothing goes into the error bag: field errors belong to Save.
     *
     * @return array{standard: string|null, corrective: string|null}
     */
    #[Computed]
    public function nextNumbers(): array
    {
        $peek = app(PeekNextInvoiceNumber::class);
        $candidate = [
            'standard_prefix' => $this->standardPrefix,
            'standard_year_format' => $this->standardYearFormat,
            'standard_separator' => $this->standardSeparator,
            'standard_padding' => $this->standardPadding,
            'corrective_prefix' => $this->correctivePrefix,
            'corrective_year_format' => $this->correctiveYearFormat,
            'corrective_separator' => $this->correctiveSeparator,
            'corrective_padding' => $this->correctivePadding,
            'reset_numbering_yearly' => $this->isNumberingResetYearly,
        ];

        $numbers = [];

        foreach (InvoiceType::cases() as $series) {
            try {
                $numbers[$series->value] = $peek($series, $candidate);
            } catch (ValidationException) {
                $numbers[$series->value] = null;
            }
        }

        return ['standard' => $numbers['standard'], 'corrective' => $numbers['corrective']];
    }

    /**
     * @return array{standard: array{hasIssued: bool, lastNumber: int, lastYear: int|null, lastIssuedAt: CarbonImmutable|null}, corrective: array{hasIssued: bool, lastNumber: int, lastYear: int|null, lastIssuedAt: CarbonImmutable|null}}
     */
    #[Computed]
    public function sequenceState(): array
    {
        return app(ReadInvoiceSequenceState::class)();
    }

    /**
     * The complete payload of the action: every key, always (FD-3).
     *
     * @return array<string, mixed>
     */
    protected function formAttributes(): array
    {
        return [
            'issuer_legal_name' => $this->issuerLegalName,
            'issuer_tax_id' => $this->issuerTaxId,
            'issuer_address_line1' => $this->issuerAddressLine1,
            'issuer_address_line2' => $this->issuerAddressLine2,
            'issuer_city' => $this->issuerCity,
            'issuer_postal_code' => $this->issuerPostalCode,
            'issuer_province' => $this->issuerProvince,
            'issuer_country' => $this->issuerCountry,
            'logo_media_id' => $this->logoMediaId,
            'primary_color' => $this->primaryColor,
            'accent_color' => $this->accentColor,
            'template' => $this->template,
            'standard_prefix' => $this->standardPrefix,
            'standard_year_format' => $this->standardYearFormat,
            'standard_separator' => $this->standardSeparator,
            'standard_padding' => $this->standardPadding,
            'corrective_prefix' => $this->correctivePrefix,
            'corrective_year_format' => $this->correctiveYearFormat,
            'corrective_separator' => $this->correctiveSeparator,
            'corrective_padding' => $this->correctivePadding,
            'reset_numbering_yearly' => $this->isNumberingResetYearly,
            'auto_issue_on_order' => $this->isAutoIssueEnabled,
        ];
    }

    private function fillFromSettings(InvoiceSetting $settings): void
    {
        $this->issuerLegalName = $settings->issuer_legal_name ?? '';
        $this->issuerTaxId = $settings->issuer_tax_id ?? '';
        $this->issuerAddressLine1 = $settings->issuer_address_line1 ?? '';
        $this->issuerAddressLine2 = $settings->issuer_address_line2 ?? '';
        $this->issuerCity = $settings->issuer_city ?? '';
        $this->issuerPostalCode = $settings->issuer_postal_code ?? '';
        $this->issuerProvince = $settings->issuer_province ?? '';
        $this->issuerCountry = $settings->issuer_country ?? '';
        $this->logoMediaId = $settings->logo_media_id;
        $this->primaryColor = $settings->primary_color ?? '';
        $this->accentColor = $settings->accent_color ?? '';
        $this->template = $settings->template->value;
        $this->standardPrefix = $settings->standard_prefix;
        $this->standardYearFormat = $settings->standard_year_format->value;
        $this->standardSeparator = $settings->standard_separator;
        $this->standardPadding = $settings->standard_padding;
        $this->correctivePrefix = $settings->corrective_prefix;
        $this->correctiveYearFormat = $settings->corrective_year_format->value;
        $this->correctiveSeparator = $settings->corrective_separator;
        $this->correctivePadding = $settings->corrective_padding;
        $this->isNumberingResetYearly = $settings->reset_numbering_yearly;
        $this->isAutoIssueEnabled = $settings->auto_issue_on_order;
    }

    private function authorizeUpdate(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $logRefusedPrivilegedAttempt->authorize(
            'update',
            InvoiceSetting::class,
            targetType: 'invoice_setting',
            targetId: InvoiceSetting::SINGLETON_ID,
        );
    }

    private function forgetComputed(): void
    {
        unset($this->nextNumbers, $this->sequenceState, $this->logo);
    }

    /**
     * Validate one colour on blur with the save's normaliser and rule (FD-10); returns the value to keep.
     */
    private function checkColour(string $property, string $field, string $value): string
    {
        $normalized = $this->normalizeInvoiceSettingAttributes([$field => $value]);

        $this->resetErrorBag($property);

        $validator = validator(
            $normalized,
            [$field => $this->invoiceSettingRules()[$field]],
            [],
            $this->attributeNames(),
        );

        if ($validator->fails()) {
            $this->addError($property, $validator->errors()->first($field));

            return $value;
        }

        return $normalized[$field] ?? '';
    }

    private function safeColour(string $value): ?string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private function rekeyErrors(array $errors): array
    {
        $rekeyed = [];

        foreach ($errors as $field => $messages) {
            $property = self::IRREGULAR_PROPERTIES[$field] ?? Str::camel($field);
            $rekeyed[$property] = [...($rekeyed[$property] ?? []), ...$messages];
        }

        return $rekeyed;
    }
}
