<?php

use App\Livewire\Dashboard\Overview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

// Story 0083 -- the dashboard is the real home page now, still ungated.

test('a user with no permissions gets the greeting and the nothing-to-show message', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create(['name' => 'Nora Quiroga']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();

    $html = $response->getContent();

    expect(DashboardUi::present($html, 'dashboard-greeting'))->toBeTrue()
        ->and(DashboardUi::text($html, 'dashboard-greeting'))->toContain('Nora')
        ->and(DashboardUi::present($html, 'dashboard-no-widgets'))->toBeTrue()
        ->and(DashboardUi::text($html, 'dashboard-no-widgets'))->toBe(__('dashboard.no_widgets'))
        ->and(DashboardUi::present($html, 'dashboard-counters'))->toBeFalse()
        ->and(DashboardUi::present($html, 'dashboard-widget-blog'))->toBeFalse()
        ->and(DashboardUi::present($html, 'dashboard-widget-stock'))->toBeFalse()
        ->and(DashboardUi::present($html, 'dashboard-widget-orders'))->toBeFalse();
});

test('the dashboard route is the full-page Overview component, named dashboard', function () {
    $route = app('router')->getRoutes()->getByName('dashboard');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('dashboard')
        // Route::livewire() registers every page under LivewirePageController and records the routed
        // component in the route action (HandleRouting), so that key is what identifies the page.
        ->and($route->getAction('livewire_component'))->toBe(Overview::class);
});
