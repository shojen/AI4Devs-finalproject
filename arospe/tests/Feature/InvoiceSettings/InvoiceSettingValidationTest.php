<?php

// Story 0100 -- field-level validation and normalisation of SaveInvoiceSettings (FD-2, FD-5, FD-6)
// and the shared InvoiceSettingValidationRules / TaxIdValidationRules traits (FD-13).

use App\Actions\Invoices\SaveInvoiceSettings;
use App\Concerns\InvoiceSettingValidationRules;
use App\Concerns\TaxIdValidationRules;
use App\Models\InvoiceSetting;
use App\Models\Media;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    InvoiceSettingsPayload::actingManager();
});

/**
 * Exposes the protected-or-public trait methods under test.
 */
function invoiceSettingRulesProbe(): object
{
    return new class
    {
        use InvoiceSettingValidationRules, TaxIdValidationRules;

        /** @return array<string, mixed> */
        public function normalize(array $attributes): array
        {
            return $this->normalizeInvoiceSettingAttributes($attributes);
        }

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return $this->invoiceSettingRules();
        }

        public function taxId(?string $value): ?string
        {
            return $this->normalizeTaxId($value);
        }
    };
}

// T-12 boundaries ------------------------------------------------------------------------------

test('boundary values of prefix, separator and padding are accepted or refused on their own field (T-12)', function (string $field, mixed $value, bool $accepted) {
    $keys = InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid([
        $field => $value,
        // keep the cross-field rules out of the way
        'standard_prefix' => $field === 'standard_prefix' ? $value : 'F',
        'corrective_prefix' => $field === 'corrective_prefix' ? $value : 'R',
    ]));

    expect($keys === [])->toBe($accepted);

    if (! $accepted) {
        expect($keys)->toBe([$field]);
    }
})->with([
    'prefix empty' => ['standard_prefix', '', true],
    'prefix 10' => ['standard_prefix', 'ABCDEFGHIJ', true],
    'prefix 11' => ['standard_prefix', 'ABCDEFGHIJK', false],
    'corrective prefix 11' => ['corrective_prefix', 'ABCDEFGHIJK', false],
    'separator empty' => ['standard_separator', '', true],
    'separator 3' => ['standard_separator', '-/.', true],
    'separator 4' => ['standard_separator', '-/.-', false],
    'corrective separator 4' => ['corrective_separator', '----', false],
    'padding 1' => ['standard_padding', 1, true],
    'padding 10' => ['standard_padding', 10, true],
    'padding 0' => ['standard_padding', 0, false],
    'padding 11' => ['standard_padding', 11, false],
    'corrective padding 11' => ['corrective_padding', 11, false],
]);

test('the legal name accepts 255 and refuses 256 characters, the tax ID accepts 32 and refuses 33 (T-13)', function () {
    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['issuer_legal_name' => str_repeat('a', 255)])))->toBe([])
        ->and(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['issuer_legal_name' => str_repeat('a', 256)])))->toBe(['issuer_legal_name'])
        ->and(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['issuer_tax_id' => str_repeat('A', 32)])))->toBe([])
        ->and(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['issuer_tax_id' => str_repeat('A', 33)])))->toBe(['issuer_tax_id']);
});

test('the other free-text issuer fields refuse values longer than their column', function (string $field, int $max) {
    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid([$field => str_repeat('a', $max)])))->toBe([])
        ->and(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid([$field => str_repeat('a', $max + 1)])))->toBe([$field]);
})->with([
    ['issuer_address_line1', 255], ['issuer_address_line2', 255], ['issuer_city', 100],
    ['issuer_postal_code', 20], ['issuer_province', 100],
]);

// T-14 tax ID normalisation ------------------------------------------------------------------

test('the tax ID is normalised and stored per FD-6 (T-14)', function (?string $input, ?string $stored) {
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['issuer_tax_id' => $input]));

    expect(InvoiceSetting::current()->issuer_tax_id)->toBe($stored)
        ->and(invoiceSettingRulesProbe()->taxId($input))->toBe($stored);
})->with([
    'trim, case, keeps punctuation' => [' es-b12.34/5 ', 'ES-B12.34/5'],
    'inner space removed' => ['ES B123', 'ESB123'],
    'tab and newline removed' => ["ES\tB1\n23", 'ESB123'],
    'empty becomes null' => ['', null],
    'whitespace becomes null' => ['   ', null],
    'null stays null' => [null, null],
]);

test('a tax ID with a character outside letters, digits and - . / is refused (T-14)', function (string $input) {
    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['issuer_tax_id' => $input])))->toBe(['issuer_tax_id'])
        ->and(InvoiceSetting::query()->count())->toBe(0);
})->with(['ES_B1', '<b>', 'ES;B1', 'ES$B1', "ES'B1"]);

test('taxIdRules(required: true) refuses an empty value and the default allows null', function () {
    $probe = new class
    {
        use TaxIdValidationRules;

        /** @return array<int, mixed> */
        public function rules(bool $required): array
        {
            return $this->taxIdRules($required);
        }
    };

    expect(Validator::make(['t' => null], ['t' => $probe->rules(false)])->passes())->toBeTrue()
        ->and(Validator::make(['t' => null], ['t' => $probe->rules(true)])->passes())->toBeFalse()
        ->and(Validator::make(['t' => 'ESB1'], ['t' => $probe->rules(true)])->passes())->toBeTrue();
});

// T-21 colours ------------------------------------------------------------------------------

test('valid colours are accepted and stored upper-cased (T-21)', function (?string $input, ?string $stored) {
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['primary_color' => $input, 'accent_color' => $input]));

    expect(InvoiceSetting::current()->primary_color)->toBe($stored)
        ->and(InvoiceSetting::current()->accent_color)->toBe($stored);
})->with([
    'lower case' => ['#a1b2c3', '#A1B2C3'],
    'black' => ['#000000', '#000000'],
    'null' => [null, null],
    'empty becomes null' => ['', null],
]);

test('malformed colours are refused on their own field (T-21)', function (string $input) {
    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['primary_color' => $input])))->toBe(['primary_color'])
        ->and(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['accent_color' => $input])))->toBe(['accent_color']);
})->with(['#FFF', 'A1B2C3', '#GGGGGG', '#12345678', 'red', '#fff;}</style><script>']);

// T-21b omitted keys --------------------------------------------------------------------------

test('each omitted key is refused on that key and the row stays unchanged (T-21b, FD-5)', function (string $omitted) {
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['standard_prefix' => 'KEEP', 'issuer_city' => 'Kept']));
    $before = InvoiceSetting::query()->firstOrFail()->getAttributes();

    $payload = InvoiceSettingsPayload::valid(['standard_prefix' => 'NEWX']);
    unset($payload[$omitted]);

    expect(InvoiceSettingsPayload::refusedKeys($payload))->toBe([$omitted])
        ->and(InvoiceSetting::query()->firstOrFail()->getAttributes())->toBe($before);
})->with(InvoiceSettingsPayload::KEYS);

test('an omitted key with no row writes nothing (T-21b)', function () {
    $payload = InvoiceSettingsPayload::valid();
    unset($payload['accent_color']);

    expect(InvoiceSettingsPayload::refusedKeys($payload))->toBe(['accent_color'])
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

// T-21c country -----------------------------------------------------------------------------

test('the country is upper-cased, two letters A-Z, with no ISO membership check (T-21c)', function (string $input, ?string $stored) {
    $keys = InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['issuer_country' => $input]));

    if ($stored === null) {
        expect($keys)->toBe(['issuer_country']);
    } else {
        expect($keys)->toBe([])
            ->and(InvoiceSetting::current()->issuer_country)->toBe($stored);
    }
})->with([
    'lower case' => ['es', 'ES'],
    'unassigned code accepted' => ['XX', 'XX'],
    'one letter' => ['E', null],
    'three letters' => ['ESP', null],
    'digit' => ['E1', null],
]);

// T-30 / T-31 ---------------------------------------------------------------------------------

test('out-of-set and malformed values are refused on their own field (T-30)', function (string $field, mixed $value) {
    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid([$field => $value])))->toBe([$field]);
})->with([
    'template classic2' => ['template', 'classic2'],
    'standard year yyyy' => ['standard_year_format', 'yyyy'],
    'corrective year Y' => ['corrective_year_format', 'Y'],
    'unknown logo id' => ['logo_media_id', '00000000-0000-7000-8000-000000000000'],
    'non uuid logo id' => ['logo_media_id', 'not-a-uuid'],
    'padding injection' => ['standard_padding', '5; DROP'],
    'reset flag maybe' => ['reset_numbering_yearly', 'maybe'],
    'auto issue flag maybe' => ['auto_issue_on_order', 'maybe'],
]);

test('prefixes and separators outside the whitelist are refused (T-31)', function (string $field, string $value) {
    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid([$field => $value])))->toBe([$field]);
})->with([
    'prefix with space' => ['standard_prefix', 'F A'],
    'prefix with dot' => ['standard_prefix', 'F.'],
    'prefix with hash' => ['standard_prefix', 'F#'],
    'prefix with angle bracket' => ['standard_prefix', '<'],
    'prefix with trailing newline' => ['standard_prefix', "F\n"],
    'prefix path traversal' => ['standard_prefix', '../'],
    'prefix accent' => ['standard_prefix', 'Fé'],
    'corrective prefix with dot' => ['corrective_prefix', 'R.'],
    'separator letters' => ['standard_separator', 'ab'],
    'separator repeated' => ['corrective_separator', '-/-/'],
    'standard prefix one space' => ['standard_prefix', ' '],
    'standard prefix two spaces' => ['standard_prefix', '  '],
    'standard prefix newline' => ['standard_prefix', "\n"],
    'corrective prefix one space' => ['corrective_prefix', ' '],
    'corrective prefix two spaces' => ['corrective_prefix', '  '],
    'corrective prefix newline' => ['corrective_prefix', "\n"],
    'corrective prefix tab newline' => ['corrective_prefix', "\t\n"],
    'standard separator one space' => ['standard_separator', ' '],
    'standard separator two spaces' => ['standard_separator', '  '],
    'standard separator newline' => ['standard_separator', "\n"],
    'corrective separator one space' => ['corrective_separator', ' '],
    'corrective separator two spaces' => ['corrective_separator', '  '],
    'corrective separator newline' => ['corrective_separator', "\n"],
]);

test('a real media id is accepted as the logo', function () {
    $media = Media::factory()->create();

    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['logo_media_id' => $media->id])))->toBe([]);
});

// T-35 ----------------------------------------------------------------------------------------

test('normalizeInvoiceSettingAttributes applied alone yields exactly what a save stores (T-35)', function (string $input) {
    $normalized = invoiceSettingRulesProbe()->normalize(InvoiceSettingsPayload::valid([
        'primary_color' => $input, 'accent_color' => $input, 'issuer_country' => 'es', 'issuer_tax_id' => ' es b1 ',
    ]));

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid([
        'primary_color' => $input, 'accent_color' => $input, 'issuer_country' => 'es', 'issuer_tax_id' => ' es b1 ',
    ]));

    $stored = InvoiceSetting::current();

    expect($normalized['primary_color'])->toBe($stored->primary_color)
        ->and($normalized['accent_color'])->toBe($stored->accent_color)
        ->and($normalized['issuer_country'])->toBe($stored->issuer_country)
        ->and($normalized['issuer_tax_id'])->toBe($stored->issuer_tax_id);
})->with(['#a1b2c3', '#000000', '#AbCdEf']);

test('normalizeInvoiceSettingAttributes turns blanks into null and trims the rest (T-35)', function () {
    $normalized = invoiceSettingRulesProbe()->normalize(['issuer_city' => '  ', 'primary_color' => '', 'issuer_province' => ' Sevilla ']);

    expect($normalized['issuer_city'])->toBeNull()
        ->and($normalized['primary_color'])->toBeNull()
        ->and($normalized['issuer_province'])->toBe('Sevilla');
});

test('invoiceSettingRules is keyed by field and a single key validates that key alone (T-35)', function () {
    $rules = invoiceSettingRulesProbe()->rules();

    expect(array_keys($rules))->toContain(...InvoiceSettingsPayload::KEYS);

    $colourOnly = Validator::make(['primary_color' => '#12'], ['primary_color' => $rules['primary_color']]);
    $colourOk = Validator::make(['primary_color' => '#112233'], ['primary_color' => $rules['primary_color']]);

    expect($colourOnly->fails())->toBeTrue()
        ->and($colourOnly->errors()->keys())->toBe(['primary_color'])
        ->and($colourOk->passes())->toBeTrue();
});
