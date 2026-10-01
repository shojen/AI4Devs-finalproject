<?php

use App\Actions\Dashboard\GetLatestBlogPosts;
use App\Actions\Dashboard\GetLatestOrders;
use App\Actions\Dashboard\GetLowStockProducts;
use App\Actions\Dashboard\GetOrdersSeries;
use App\Actions\Dashboard\GetSalesSeries;
use App\Enums\SalesGranularity;
use App\Models\BlogPost;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;
use Tests\Support\Orders\OrdersUi;

// Story 0082 (D-1), Phase 3 TDD red step: none of the Dashboard actions exist yet.
//
// The five gated actions authorize through LogRefusedPrivilegedAttempt with a class-level `viewAny`
// and an explicit target type, as their FIRST statement: a refusal is logged and thrown before any
// validation and before any domain-table query. GetDashboardCounters (the exception: it never
// throws) is covered by GetDashboardCountersTest. For the two series actions only the
// authorization rows live here; their functional behaviour is tested elsewhere.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // Something in every domain table the actions read, so a query that leaked past a refusal
    // would have data to read and would be counted.
    Order::factory()->create(['created_at' => Carbon::parse('2026-05-02 10:00:00', 'Europe/Madrid')]);
    BlogPost::factory()->published()->create();
    Product::factory()->active()->physical()->create(['stock' => 1]);
});

/**
 * Invoke the action with valid arguments (the series actions take a granularity and a range).
 */
function invokeGatedAction(string $class, bool $isSeries, array $overrides = []): mixed
{
    if ($isSeries) {
        $arguments = array_merge([
            'granularity' => SalesGranularity::Day,
            'from' => Carbon::parse('2026-05-01', 'Europe/Madrid'),
            'to' => Carbon::parse('2026-05-03', 'Europe/Madrid'),
        ], $overrides);

        return app($class)(...$arguments);
    }

    return app($class)();
}

/**
 * Every permission in the catalog except the one the action needs: other modules, and the other
 * abilities of its own module.
 *
 * @return list<string>
 */
function everyPermissionExcept(string $own): array
{
    return Permission::query()->where('name', '!=', $own)->pluck('name')->all();
}

dataset('gatedDashboardActions', [
    'GetLatestBlogPosts' => [GetLatestBlogPosts::class, false, 'blog.view', 'blog_post'],
    'GetLowStockProducts' => [GetLowStockProducts::class, false, 'products.view', 'product'],
    'GetLatestOrders' => [GetLatestOrders::class, false, 'orders.view', 'order'],
    'GetSalesSeries' => [GetSalesSeries::class, true, 'orders.view', 'order'],
    'GetOrdersSeries' => [GetOrdersSeries::class, true, 'orders.view', 'order'],
]);

it('refuses, logs and reads no domain table for an actor without any ability', function (string $class, bool $isSeries, string $own, string $targetType) {
    Log::spy();
    $actor = OrdersUi::actor([]);
    test()->actingAs($actor);

    $queries = DomainQueryLog::capture(function () use ($class, $isSeries): void {
        try {
            invokeGatedAction($class, $isSeries);
        } catch (AuthorizationException) {
            // asserted below through the log and the zero query count
        }
    });

    expect(fn () => invokeGatedAction($class, $isSeries))->toThrow(AuthorizationException::class)
        ->and(DomainQueryLog::total($queries))->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === $targetType
            && array_key_exists('target_id', $context) && $context['target_id'] === null)
        ->twice();
})->with('gatedDashboardActions');

it('refuses, logs and reads no domain table for an actor holding every other ability', function (string $class, bool $isSeries, string $own, string $targetType) {
    Log::spy();
    $actor = OrdersUi::actor(everyPermissionExcept($own));
    test()->actingAs($actor);

    $queries = DomainQueryLog::capture(function () use ($class, $isSeries): void {
        try {
            invokeGatedAction($class, $isSeries);
        } catch (AuthorizationException) {
            // asserted below
        }
    });

    expect(DomainQueryLog::total($queries))->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === $targetType)
        ->once();
})->with('gatedDashboardActions');

it('throws an AuthorizationException for an actor holding every other ability', function (string $class, bool $isSeries, string $own) {
    test()->actingAs(OrdersUi::actor(everyPermissionExcept($own)));

    expect(fn () => invokeGatedAction($class, $isSeries))->toThrow(AuthorizationException::class);
})->with('gatedDashboardActions');

it('serves an actor holding only its own ability and logs no refusal', function (string $class, bool $isSeries, string $own) {
    Log::spy();
    test()->actingAs(OrdersUi::actor([$own]));

    $result = invokeGatedAction($class, $isSeries);

    expect($result)->toBeArray();

    Log::shouldNotHaveReceived('warning');
})->with('gatedDashboardActions');

it('serves a Super Admin and logs no refusal', function (string $class, bool $isSeries) {
    Log::spy();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    expect(invokeGatedAction($class, $isSeries))->toBeArray();

    Log::shouldNotHaveReceived('warning');
})->with('gatedDashboardActions');

it('refuses a guest with an AuthorizationException and no domain-table query', function (string $class, bool $isSeries) {
    $queries = DomainQueryLog::capture(function () use ($class, $isSeries): void {
        try {
            invokeGatedAction($class, $isSeries);
        } catch (AuthorizationException) {
            // asserted below
        }
    });

    expect(fn () => invokeGatedAction($class, $isSeries))->toThrow(AuthorizationException::class)
        ->and(DomainQueryLog::total($queries))->toBe(0);
})->with('gatedDashboardActions');

// --- Order of operations: authorize -> validate -> query (series actions) ---

it('lets the refusal win over an invalid range', function (string $class) {
    Log::spy();
    $actor = OrdersUi::actor([]);
    test()->actingAs($actor);

    $queries = DomainQueryLog::capture(function () use ($class): void {
        expect(fn () => invokeGatedAction($class, true, [
            'from' => Carbon::parse('2026-05-05', 'Europe/Madrid'),
            'to' => Carbon::parse('2026-05-01', 'Europe/Madrid'),
        ]))->toThrow(AuthorizationException::class);
    });

    expect(DomainQueryLog::total($queries))->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === 'order')
        ->once();
})->with([GetSalesSeries::class, GetOrdersSeries::class]);

it('lets the refusal win over an empty status filter', function (string $class) {
    test()->actingAs(OrdersUi::actor([]));

    expect(fn () => invokeGatedAction($class, true, ['statuses' => []]))->toThrow(AuthorizationException::class);
})->with([GetSalesSeries::class, GetOrdersSeries::class]);

it('validates the range for an authorized actor, proving the refusal above is not a validation error', function (string $class) {
    test()->actingAs(OrdersUi::actor(['orders.view']));

    expect(fn () => invokeGatedAction($class, true, [
        'from' => Carbon::parse('2026-05-05', 'Europe/Madrid'),
        'to' => Carbon::parse('2026-05-01', 'Europe/Madrid'),
    ]))->toThrow(ValidationException::class);
})->with([GetSalesSeries::class, GetOrdersSeries::class]);
