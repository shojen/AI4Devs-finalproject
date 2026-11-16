<?php

// Story 0100 -- authorization comes first and a refusal is logged (T-25..T-29), mirroring
// tests/Feature/Localization/LocaleSettingRefusalLoggingTest.php.

use App\Actions\Invoices\SaveInvoiceSettings;
use App\Models\InvoiceSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('an actor with no permission is refused for a valid and for an invalid payload, with no validation error leaking (T-25)', function (array $payload) {
    $this->actingAs(User::factory()->create());

    expect(fn () => app(SaveInvoiceSettings::class)($payload))->toThrow(AuthorizationException::class)
        ->and(InvoiceSetting::query()->count())->toBe(0);
})->with([
    'valid' => fn () => [InvoiceSettingsPayload::valid()],
    'invalid' => fn () => [InvoiceSettingsPayload::valid(['template' => 'nope', 'standard_padding' => 99])],
    'partial' => fn () => [['template' => 'classic']],
    'empty' => fn () => [[]],
]);

test('a refusal writes exactly one Log::warning naming invoice_setting and carries no payload value (T-26)', function () {
    Log::spy();
    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid());
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'invoice_setting'
            && ($context['target_id'] ?? null) === InvoiceSetting::SINGLETON_ID
            && ! str_contains((string) json_encode($context), 'ESB12345678')
            && ! str_contains((string) json_encode($context), 'Arospe')
            && collect(array_keys($context))->sort()->values()->all() === ['ability', 'actor_id', 'target_id', 'target_type'])
        ->once();
    Log::shouldNotHaveReceived('info');
});

test('an unauthenticated save is refused and logged without an actor id (T-27)', function () {
    Log::spy();

    expect(fn () => app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid()))
        ->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null
            && ($context['target_type'] ?? null) === 'invoice_setting')
        ->once();
    expect(InvoiceSetting::query()->count())->toBe(0);
});

test('a holder of view only cannot save and neither permission fails viewAny (T-28)', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('invoice-settings.view');
    $this->actingAs($viewer);
    InvoiceSetting::factory()->create(['standard_prefix' => 'KEEP']);

    expect(fn () => app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid()))
        ->toThrow(AuthorizationException::class)
        ->and(InvoiceSetting::current()->standard_prefix)->toBe('KEEP')
        ->and(Gate::forUser(User::factory()->create())->allows('viewAny', InvoiceSetting::class))->toBeFalse();
});

test('a permitted save writes one Log::info with the actor and the changed keys only, never a fiscal value (T-29)', function () {
    Log::spy();
    $actor = InvoiceSettingsPayload::actingManager();

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid());

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($actor): bool {
            $serialized = (string) json_encode($context);

            return ($context['actor_id'] ?? null) === $actor->id
                && str_contains($serialized, 'issuer_legal_name')
                && str_contains($serialized, 'standard_prefix')
                && ! str_contains($serialized, 'Arospe')
                && ! str_contains($serialized, 'ESB12345678')
                && ! str_contains($serialized, 'Calle Mayor')
                && ! str_contains($serialized, '28001');
        })
        ->once();
});
