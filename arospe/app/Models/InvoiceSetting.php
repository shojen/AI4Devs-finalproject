<?php

namespace App\Models;

use App\Enums\InvoiceNumberYear;
use App\Enums\InvoiceTemplate;
use Database\Factories\InvoiceSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The store-wide invoice settings (story 0100): issuer fiscal data, logo, colours, PDF template
 * and the numbering formats of both series. A singleton over a fixed literal primary key, like
 * LocaleSetting. Written only through App\Actions\Invoices\SaveInvoiceSettings.
 *
 * @property int $id
 * @property string|null $issuer_legal_name
 * @property string|null $issuer_tax_id
 * @property string|null $issuer_address_line1
 * @property string|null $issuer_address_line2
 * @property string|null $issuer_city
 * @property string|null $issuer_postal_code
 * @property string|null $issuer_province
 * @property string|null $issuer_country
 * @property string|null $logo_media_id
 * @property string|null $primary_color
 * @property string|null $accent_color
 * @property InvoiceTemplate $template
 * @property string $standard_prefix
 * @property string $corrective_prefix
 * @property InvoiceNumberYear $standard_year_format
 * @property InvoiceNumberYear $corrective_year_format
 * @property string $standard_separator
 * @property string $corrective_separator
 * @property int $standard_padding
 * @property int $corrective_padding
 * @property bool $reset_numbering_yearly
 * @property bool $auto_issue_on_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $logo
 */
#[Fillable([])]
class InvoiceSetting extends Model
{
    /** @use HasFactory<InvoiceSettingFactory> */
    use HasFactory;

    /** The row's one and only id. */
    public const SINGLETON_ID = 1;

    public const DEFAULT_TEMPLATE = InvoiceTemplate::Classic;

    public const DEFAULT_STANDARD_PREFIX = 'F';

    public const DEFAULT_CORRECTIVE_PREFIX = 'R';

    public const DEFAULT_YEAR_FORMAT = InvoiceNumberYear::Full;

    public const DEFAULT_SEPARATOR = '-';

    public const DEFAULT_PADDING = 5;

    public const DEFAULT_RESET_NUMBERING_YEARLY = true;

    public const DEFAULT_AUTO_ISSUE_ON_ORDER = false;

    /**
     * The primary key is a fixed literal, not a generated one.
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => InvoiceTemplate::class,
            'standard_year_format' => InvoiceNumberYear::class,
            'corrective_year_format' => InvoiceNumberYear::class,
            'standard_padding' => 'integer',
            'corrective_padding' => 'integer',
            'reset_numbering_yearly' => 'boolean',
            'auto_issue_on_order' => 'boolean',
        ];
    }

    /**
     * The saved row, or an unsaved instance carrying the documented defaults (FD-1). Never writes.
     */
    public static function current(): self
    {
        return self::query()->find(self::SINGLETON_ID) ?? self::defaults();
    }

    /**
     * An unsaved instance holding the documented defaults.
     */
    public static function defaults(): self
    {
        return (new self)->forceFill([
            'id' => self::SINGLETON_ID,
            'template' => self::DEFAULT_TEMPLATE,
            'standard_prefix' => self::DEFAULT_STANDARD_PREFIX,
            'corrective_prefix' => self::DEFAULT_CORRECTIVE_PREFIX,
            'standard_year_format' => self::DEFAULT_YEAR_FORMAT,
            'corrective_year_format' => self::DEFAULT_YEAR_FORMAT,
            'standard_separator' => self::DEFAULT_SEPARATOR,
            'corrective_separator' => self::DEFAULT_SEPARATOR,
            'standard_padding' => self::DEFAULT_PADDING,
            'corrective_padding' => self::DEFAULT_PADDING,
            'reset_numbering_yearly' => self::DEFAULT_RESET_NUMBERING_YEARLY,
            'auto_issue_on_order' => self::DEFAULT_AUTO_ISSUE_ON_ORDER,
        ]);
    }

    /**
     * Whether the issuer's legal name, tax ID and address line 1 are all filled in.
     */
    public function hasCompleteIssuerData(): bool
    {
        return filled($this->issuer_legal_name)
            && filled($this->issuer_tax_id)
            && filled($this->issuer_address_line1);
    }

    /**
     * The invoice logo.
     *
     * @return BelongsTo<Media, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }
}
