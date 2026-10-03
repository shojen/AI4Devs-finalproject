<?php

// Story 0086 (D-1, Phase 2 finding 2) -- who sees the card and who may drive it.
//   * Render path: gated through allowsSafely('viewAny', Order::class); an actor without orders.view gets
//     no card, no orders-table query and NO refusal log (and a missing permission row is "not permitted",
//     never an exception).
//   * Update path: the series actions run unconditionally, so a never-authorized or revoked actor posting
//     a filter update is refused (AuthorizationException, i.e. a 403 over HTTP) and the refusal is logged.
// Actors are NON-Super-Admin (Gate::before would make every "hidden" assertion a false negative) except
// in their own positive cases.
//
// Red step: the component does not exist yet.

use App\Enums\OrderStatus;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Dashboard\SalesOverview;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DashboardUi as Ui;
use Tests\Support\Dashboard\DomainQueryLog;
use Tests\Support\Dashboard\SalesOverviewUi as Sales;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Sales::today();
});

dataset('actors without orders access', [
    'no permission at all' => [[]],
    'a user manager' => [['users.view', 'users.edit']],
    'every other module view' => [['users.view', 'products.view', 'media.view', 'blog.view']],
    'orders.edit without orders.view' => [['orders.edit']],
]);

// =====================================================================
// Render path
// =====================================================================

it('renders no card, runs no orders query and logs no refusal for an actor without orders.view', function (array $permissions) {
    Sales::order('2026-06-10 10:00:00', '987654.32');
    $this->actingAs(Ui::actor($permissions));
    Log::spy();

    $html = '';

    $counts = DomainQueryLog::capture(function () use (&$html): void {
        $html = Sales::component()->html();
    });

    expect($counts['orders'])->toBe(0)
        ->and(Ui::present($html, 'sales-overview'))->toBeFalse()
        ->and(Ui::hooksStartingWith($html, 'sales-'))->toBe([])
        ->and($html)->not->toContain('987654.32');

    Log::shouldNotHaveReceived('warning');
})->with('actors without orders access');

it('carries no sales figure in the snapshot of a non-orders actor', function () {
    Sales::order('2026-06-10 10:00:00', '987654.32');
    $this->actingAs(Ui::actor(['users.view']));

    $component = Sales::component();

    expect(json_encode(Ui::snapshotOf($component)))->not->toContain('987654');
});

it('treats a missing orders.view permission row as not permitted, not as an error', function () {
    Permission::query()->where('name', 'orders.view')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(Ui::actor([]));
    Log::spy();

    $html = Sales::component()->html();

    expect(Ui::present($html, 'sales-overview'))->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});

it('renders the card for an actor holding exactly orders.view, a Super Admin and the Administrator role', function (string $kind) {
    Sales::order('2026-06-10 10:00:00', '25.00');

    $actor = match ($kind) {
        'super' => Ui::superAdmin(),
        'role' => tap(Ui::actor([]), fn ($user) => $user->assignRole('Administrator')),
        default => Ui::actor(['orders.view']),
    };

    $this->actingAs($actor);
    Log::spy();

    $html = Sales::component()->html();

    expect(Ui::present($html, 'sales-overview'))->toBeTrue()
        ->and(Sales::kpi($html, 'sales'))->toBe('€ 25.00');

    Log::shouldNotHaveReceived('warning');
})->with(['orders.view only' => 'partial', 'Super Admin' => 'super', 'Administrator role' => 'role']);

// =====================================================================
// The dashboard page mounts the card only for orders actors
// =====================================================================

it('the dashboard home mounts the sales overview only for an actor who may see orders', function (array $permissions, bool $mounted) {
    $this->actingAs(Ui::actor($permissions));
    Log::spy();

    $html = Livewire::withoutLazyLoading()->test(Overview::class)->html();

    expect(Ui::present($html, 'sales-overview'))->toBe($mounted);

    Log::shouldNotHaveReceived('warning');
})->with([
    'orders.view' => [['orders.view'], true],
    'no permission' => [[], false],
    'users.view only' => [['users.view'], false],
]);

it('the dashboard page response holds a lazy placeholder only for an actor who may see orders', function (array $permissions, bool $lazy) {
    $this->actingAs(Ui::actor($permissions));

    $page = $this->get('/dashboard')->assertOk()->getContent();

    expect(str_contains($page, '__lazyLoad'))->toBe($lazy)
        ->and(str_contains($page, 'dashboard.sales-overview'))->toBe($lazy);
})->with([
    'orders.view' => [['orders.view'], true],
    'no permission' => [[], false],
]);

// =====================================================================
// Update path: refused AND logged, never silently ignored
// =====================================================================

it('refuses and logs every filter update from an actor who was never authorized', function (callable $update) {
    $actor = Ui::actor(['users.view']);
    $this->actingAs($actor);
    Log::spy();

    $component = Sales::component();

    $update($component)->assertForbidden();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === 'order')
        ->atLeast()->once();
})->with([
    'granularity' => [fn ($c) => $c->set('granularity', 'month')],
    'from' => [fn ($c) => $c->set('from', '2026-06-01')],
    'to' => [fn ($c) => $c->set('to', '2026-06-10')],
    'statuses' => [fn ($c) => $c->set('statuses', ['delivered'])],
    'a preset' => [fn ($c) => $c->call('applyPreset', 'last_7_days')],
    'a chip' => [fn ($c) => $c->call('toggleStatus', 'cancelled')],
    'the status reset' => [fn ($c) => $c->call('resetStatuses')],
    'a tampered path' => [fn ($c) => $c->set('from.x', '2026-06-01')],
]);

it('honours a permission revoked after the page loaded: the next filter change is refused and logged', function () {
    $actor = Ui::actor(['orders.view']);
    $this->actingAs($actor);
    Log::spy();

    $component = Sales::component();

    expect(Ui::present($component->html(), 'sales-overview'))->toBeTrue();

    $actor->revokePermissionTo('orders.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->set('granularity', 'month')->assertForbidden();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === 'order')
        ->atLeast()->once();
});

it('does not log a refusal for an authorized actor driving every filter', function () {
    $this->actingAs(Ui::actor(['orders.view']));
    Log::spy();

    Sales::component()
        ->set('granularity', 'month')
        ->call('applyPreset', 'last_7_days')
        ->call('toggleStatus', 'cancelled');

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// Hydrated state is client-writable scalars only
// =====================================================================

it('exposes only scalar public properties, never a model, collection, enum or date', function () {
    $properties = (new ReflectionClass(SalesOverview::class))->getProperties(ReflectionProperty::IS_PUBLIC);

    $names = array_map(fn (ReflectionProperty $property): string => $property->getName(), $properties);

    expect($names)->toEqualCanonicalizing(['granularity', 'from', 'to', 'statuses']);

    foreach ($properties as $property) {
        $type = (string) $property->getType();

        expect($type)->toBeIn(['string', 'array'], "{$property->getName()} must be a scalar or an array of scalars");
    }
});

it('holds only scalars in the rendered snapshot of an authorized actor', function () {
    Sales::order('2026-06-10 10:00:00', '25.00', OrderStatus::Delivered);
    $this->actingAs(Ui::actor(['orders.view']));

    $component = Sales::component();
    $data = Ui::snapshotOf($component)[0]['data'] ?? null;

    expect($data)->toBeArray();

    array_walk_recursive($data, function (mixed $value): void {
        expect(is_scalar($value) || $value === null)->toBeTrue('a snapshot value must be a scalar');
    });

    expect(json_encode($data))->not->toContain('Carbon')->not->toContain('App\\\\Models')->not->toContain('Illuminate');
});
