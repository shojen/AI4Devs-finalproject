<?php

// Story 0106 (T-12, T-23, T-24) -- invoices.collect alone allows collect and undo; every other caller
// is refused before any state is read, with the same refusal whatever the invoice state, and the
// refusal is logged once with the ability name and no amounts.

use App\Actions\Invoices\CollectInvoice;
use App\Actions\Invoices\UndoInvoiceCollection;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Collecting;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An invoice in the given state: collectable (uncollected) for `collect`, collected for `uncollect`.
 */
function collectionInvoiceFor(string $ability, string $state): Invoice
{
    $collected = $ability === 'uncollect';
    $order = Collecting::order('1000.00', $state === 'cancelled order' ? ['status' => OrderStatus::Cancelled] : []);

    return Collecting::invoice($order, '600.00', $collected, $state === 'voided' ? ['voided_at' => now()] : []);
}

/**
 * Run the ability's action.
 */
function runCollectionAbility(string $ability, Invoice $invoice, ?PaymentMethod $method = null): void
{
    if ($ability === 'collect') {
        app(CollectInvoice::class)($invoice, $method ?? Collecting::method(), OrderPaymentType::Transfer);

        return;
    }

    app(UndoInvoiceCollection::class)($invoice);
}

// T-12
test('the policy constant and the seeded catalog carry invoices.collect, 49 permissions in all (T-12)', function () {
    expect(InvoicePolicy::COLLECT_PERMISSION)->toBe('invoices.collect')
        ->and(Permission::count())->toBe(49)
        ->and(Permission::query()->where('name', 'invoices.collect')->exists())->toBeTrue();
});

test('invoices.collect alone allows both collect and uncollect, and nothing else about invoices (T-12)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $collector = User::factory()->create();
    $collector->givePermissionTo('invoices.collect');

    expect(Gate::forUser($collector)->allows('collect', $invoice))->toBeTrue()
        ->and(Gate::forUser($collector)->allows('uncollect', $invoice))->toBeTrue()
        ->and(Gate::forUser($collector)->allows('view', $invoice))->toBeFalse()
        ->and(Gate::forUser($collector)->allows('create', Invoice::class))->toBeFalse();
});

test('the other invoice permissions do not allow collect or uncollect (T-12)', function (string $permission) {
    $invoice = Invoice::factory()->standard()->create();
    $holder = User::factory()->create();
    $holder->givePermissionTo($permission);

    expect(Gate::forUser($holder)->allows('collect', $invoice))->toBeFalse()
        ->and(Gate::forUser($holder)->allows('uncollect', $invoice))->toBeFalse();
})->with(['invoices.view', 'invoices.send', 'invoices.create', 'orders.edit']);

test('Super Admin is allowed and Administrator holds the permission (T-12)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect(Gate::forUser($superAdmin)->allows('collect', $invoice))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('uncollect', $invoice))->toBeTrue()
        ->and(Role::findByName('Administrator')->hasPermissionTo('invoices.collect'))->toBeTrue();
});

// T-23
test('a caller without invoices.collect is refused before any state is read, whatever the invoice state, and nothing is written (T-23)', function (array $permissions, string $ability, string $state) {
    $invoice = collectionInvoiceFor($ability, $state);
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    $this->actingAs($actor);
    $order = $invoice->order;
    $method = Collecting::method();
    $before = Collecting::snapshot($order);
    $touched = [];

    DB::listen(function ($query) use (&$touched): void {
        if (preg_match('/\b(invoices|orders|order_payments|payment_methods|customers)\b/i', $query->sql) === 1) {
            $touched[] = $query->sql;
        }
    });

    expect(fn () => runCollectionAbility($ability, $invoice, $method))->toThrow(AuthorizationException::class)
        ->and($touched)->toBe([])
        ->and(Collecting::snapshot($order))->toBe($before);
})->with([
    'no permission' => [[]],
    'invoices.view' => [['invoices.view']],
    'invoices.send' => [['invoices.send']],
    'invoices.create' => [['invoices.create']],
])->with(['collect', 'uncollect'])->with(['ordinary', 'voided', 'cancelled order']);

test('every refused caller gets the same refusal whatever the invoice state, so the invoice cannot be probed (T-23)', function (string $ability) {
    $this->actingAs(User::factory()->create());
    $messages = [];

    foreach (['ordinary', 'voided', 'cancelled order'] as $state) {
        try {
            runCollectionAbility($ability, collectionInvoiceFor($ability, $state));
        } catch (AuthorizationException $exception) {
            $messages[] = $exception->getMessage();
        }
    }

    expect($messages)->toHaveCount(3)->and(array_unique($messages))->toHaveCount(1);
})->with(['collect', 'uncollect']);

// T-24
test('a refusal writes exactly one warning with the ability, target type invoice and no amounts (T-24)', function (string $ability) {
    $invoice = collectionInvoiceFor($ability, 'ordinary');
    $actor = User::factory()->create();
    $actor->givePermissionTo('invoices.view');
    $this->actingAs($actor);
    Log::spy();

    expect(fn () => runCollectionAbility($ability, $invoice))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === $ability
            && ($context['target_type'] ?? null) === 'invoice'
            && ! str_contains((string) json_encode($context), '600.00')
            && ! str_contains((string) json_encode($context), '1000.00'))
        ->once();
})->with(['collect', 'uncollect']);

test('an unauthenticated caller is refused and logged without an actor id (T-24)', function (string $ability) {
    $invoice = collectionInvoiceFor($ability, 'ordinary');
    Log::spy();

    expect(fn () => runCollectionAbility($ability, $invoice))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null
            && ($context['ability'] ?? null) === $ability
            && ($context['target_type'] ?? null) === 'invoice')
        ->once();
})->with(['collect', 'uncollect']);

test('a permitted call logs no refusal (T-24)', function (string $ability) {
    $invoice = collectionInvoiceFor($ability, 'ordinary');
    Collecting::actor();
    Log::spy();

    runCollectionAbility($ability, $invoice);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (mixed ...$arguments): bool => ($arguments[0] ?? null) === 'Privileged action refused')
        ->never();
})->with(['collect', 'uncollect']);
