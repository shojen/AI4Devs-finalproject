<?php

namespace Tests\Feature\InvoiceSettings;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Story 0103 -- shared helpers of the invoice settings screen tests: the frozen clock, the seeded
 * permissions, the actors and a complete valid form state keyed by Livewire property.
 */
final class ScreenFixtures
{
    /**
     * Freeze time and seed the real roles and permissions (call from beforeEach).
     */
    public static function boot(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        test()->seed(RolePermissionSeeder::class);
    }

    /**
     * Sign in a user holding exactly the given permissions.
     */
    public static function actingWith(string ...$permissions): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo($permissions);
        test()->actingAs($actor);

        return $actor;
    }

    public static function manager(): User
    {
        return self::actingWith('invoice-settings.view', 'invoice-settings.manage', 'media.view');
    }

    public static function viewer(): User
    {
        return self::actingWith('invoice-settings.view');
    }

    /**
     * A complete valid form state keyed by component property.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function form(array $overrides = []): array
    {
        return array_replace([
            'issuerLegalName' => 'Arospe Handmade Sociedad Limitada',
            'issuerTaxId' => 'ESB12345678',
            'issuerAddressLine1' => 'Calle Mayor 1',
            'issuerAddressLine2' => 'Piso 2',
            'issuerCity' => 'Madrid',
            'issuerPostalCode' => '28001',
            'issuerProvince' => 'Madrid',
            'issuerCountry' => 'ES',
            'primaryColor' => '#112233',
            'accentColor' => '#AABBCC',
            'template' => 'modern',
            'standardPrefix' => 'INV',
            'standardYearFormat' => 'YY',
            'standardSeparator' => '/',
            'standardPadding' => 6,
            'correctivePrefix' => 'COR',
            'correctiveYearFormat' => 'YYYY',
            'correctiveSeparator' => '.',
            'correctivePadding' => 4,
            'isNumberingResetYearly' => true,
            'isAutoIssueEnabled' => false,
        ], $overrides);
    }
}
