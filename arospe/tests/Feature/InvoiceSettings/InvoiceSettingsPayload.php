<?php

namespace Tests\Feature\InvoiceSettings;

use App\Actions\Invoices\SaveInvoiceSettings;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Shared helpers of the story 0100 test files: one complete, valid settings payload (FD-5: a save
 * replaces the whole record, so every key is always present), a permitted actor and a helper that
 * returns the validation error keys of a refused save.
 */
final class InvoiceSettingsPayload
{
    /**
     * Every settings key a save must carry (FD-5).
     *
     * @var array<int, string>
     */
    public const KEYS = [
        'issuer_legal_name', 'issuer_tax_id', 'issuer_address_line1', 'issuer_address_line2',
        'issuer_city', 'issuer_postal_code', 'issuer_province', 'issuer_country',
        'logo_media_id', 'primary_color', 'accent_color', 'template',
        'standard_prefix', 'corrective_prefix', 'standard_year_format', 'corrective_year_format',
        'standard_separator', 'corrective_separator', 'standard_padding', 'corrective_padding',
        'reset_numbering_yearly', 'auto_issue_on_order',
    ];

    /**
     * A complete valid payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function valid(array $overrides = []): array
    {
        return array_replace([
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada',
            'issuer_tax_id' => 'ESB12345678',
            'issuer_address_line1' => 'Calle Mayor 1',
            'issuer_address_line2' => 'Piso 2',
            'issuer_city' => 'Madrid',
            'issuer_postal_code' => '28001',
            'issuer_province' => 'Madrid',
            'issuer_country' => 'ES',
            'logo_media_id' => null,
            'primary_color' => '#112233',
            'accent_color' => '#AABBCC',
            'template' => 'modern',
            'standard_prefix' => 'INV',
            'corrective_prefix' => 'COR',
            'standard_year_format' => 'YY',
            'corrective_year_format' => 'YYYY',
            'standard_separator' => '/',
            'corrective_separator' => '.',
            'standard_padding' => 6,
            'corrective_padding' => 4,
            'reset_numbering_yearly' => true,
            'auto_issue_on_order' => false,
        ], $overrides);
    }

    /**
     * Sign in a user holding exactly the given permissions and return it.
     */
    public static function actingManager(string ...$permissions): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo($permissions === [] ? ['invoice-settings.manage'] : $permissions);
        test()->actingAs($actor);

        return $actor;
    }

    /**
     * Save the payload and return the validation error keys, or [] when it saved.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function refusedKeys(array $payload): array
    {
        try {
            app(SaveInvoiceSettings::class)($payload);
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    }
}
