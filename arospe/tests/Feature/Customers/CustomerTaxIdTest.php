<?php

// Story 0100a (Phase 3, red step) -- customer tax ID through the real CreateCustomer /
// UpdateCustomer actions. The column, the rule and the factory state do not exist yet, so these
// tests are expected to fail until backend-expert / database-expert implement them.
//
// The normalisation and invalid-value datasets are the shared ones in tests/Datasets/TaxIds.php
// (also used by 0100's InvoiceSettingValidationTest), so the issuer and customer formats cannot drift.

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Concerns\TaxIdValidationRules;
use App\Models\Customer;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.create', 'customers.edit');
    $this->actingAs($actor);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function taxIdCreatePayload(array $overrides = []): array
{
    return array_merge(['name' => 'Cliente Fiscal', 'email' => 'fiscal@example.com'], $overrides);
}

/**
 * Runs UpdateCustomer with the customer's own name and email as defaults: both are required
 * fields, so an update always carries them. Only tax_id is partial (FD-3).
 *
 * @param  array<string, mixed>  $attributes
 */
function taxIdUpdate(Customer $customer, array $attributes): Customer
{
    return app(UpdateCustomer::class)($customer, array_merge(['name' => $customer->name, 'email' => $customer->email], $attributes));
}

/**
 * The refused keys of a ValidationException thrown by the callback, or [] when none is thrown.
 *
 * @return array<int, string>
 */
function taxIdRefusedKeys(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

// T-1 / T-2 / T-3 happy path -------------------------------------------------------------------

test('creating a customer with a tax ID stores the normalised value (T-1)', function () {
    $customer = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => ' b-12345678 ']));

    expect($customer->fresh()->tax_id)->toBe('B-12345678');
});

test('updating a customer sets the tax ID and leaves every other column unchanged (T-2)', function () {
    $customer = Customer::factory()->create();
    $before = Arr::except($customer->fresh()->getAttributes(), ['tax_id', 'updated_at']);

    taxIdUpdate($customer, ['tax_id' => 'B-87654321']);

    $after = $customer->fresh();

    expect($after->tax_id)->toBe('B-87654321')
        ->and(Arr::except($after->getAttributes(), ['tax_id', 'updated_at']))->toBe($before);
});

test('creating a customer without the tax ID key stores null (T-3)', function () {
    $customer = app(CreateCustomer::class)(taxIdCreatePayload());

    expect($customer->fresh()->tax_id)->toBeNull();
});

// T-4 / T-4b normalisation ---------------------------------------------------------------------

test('the shared normalisation dataset applies on create and on update (T-4)', function (?string $input, ?string $stored) {
    $created = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => $input]));
    $existing = Customer::factory()->create();
    taxIdUpdate($existing, ['tax_id' => $input]);

    expect($created->fresh()->tax_id)->toBe($stored)
        ->and($existing->fresh()->tax_id)->toBe($stored);
})->with('tax_id_normalisation');

test('normalising an already normalised tax ID changes nothing (T-4)', function (?string $input, ?string $stored) {
    $probe = new class
    {
        use TaxIdValidationRules;

        public function normalize(?string $value): ?string
        {
            return $this->normalizeTaxId($value);
        }
    };

    expect($probe->normalize($stored))->toBe($stored);
})->with('tax_id_normalisation');

test('the dot-dot-slash value is valid under the shared rule and stored as ../X (T-4b)', function () {
    $customer = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => '../x']));

    expect($customer->fresh()->tax_id)->toBe('../X');
});

// T-5 duplicates -------------------------------------------------------------------------------

test('the same tax ID can be saved on an active and on a soft-deleted customer (T-5)', function () {
    $deleted = Customer::factory()->create(['tax_id' => 'B-12345678']);
    $deleted->delete();

    $second = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => 'B-12345678']));
    $third = app(CreateCustomer::class)(taxIdCreatePayload(['email' => 'otro@example.com', 'tax_id' => 'B-12345678']));

    expect($second->fresh()->tax_id)->toBe('B-12345678')
        ->and($third->fresh()->tax_id)->toBe('B-12345678')
        ->and(Customer::withTrashed()->where('tax_id', 'B-12345678')->count())->toBe(3);
});

// T-6 factory ----------------------------------------------------------------------------------

test('the factory default tax ID is null and withTaxId() produces a value the rule accepts (T-6)', function () {
    $plain = Customer::factory()->create();
    $withDefault = Customer::factory()->withTaxId()->create();
    $explicit = Customer::factory()->withTaxId('B-12345678')->create();

    expect($plain->fresh()->tax_id)->toBeNull()
        ->and($explicit->fresh()->tax_id)->toBe('B-12345678')
        ->and($withDefault->fresh()->tax_id)->toBeString()->not->toBe('')
        ->and(taxIdRefusedKeys(fn () => taxIdUpdate($withDefault, ['tax_id' => $withDefault->tax_id])))->toBe([]);
});

// T-7 schema -----------------------------------------------------------------------------------

test('customers.tax_id is a nullable varchar(40) after billing_country with no index (T-7)', function () {
    $columns = collect(Schema::getColumns('customers'));
    $column = $columns->firstWhere('name', 'tax_id');
    $names = $columns->pluck('name')->values()->all();

    expect($column)->not->toBeNull()
        ->and($column['type'])->toBe('varchar(40)')
        ->and($column['nullable'])->toBeTrue()
        ->and($column['default'])->toBeNull()
        ->and($names[array_search('billing_country', $names, true) + 1])->toBe('tax_id')
        ->and(collect(Schema::getIndexes('customers'))->filter(fn (array $index): bool => in_array('tax_id', $index['columns'], true)))->toBeEmpty();
});

// MySQL DDL implicitly commits, so down() is pinned by a file-content check, not a round-trip.
test('the migration file defines a down() that drops the tax_id column (T-7)', function () {
    $files = glob(database_path('migrations/*_add_tax_id_to_customers_table.php'));

    expect($files)->toHaveCount(1);

    $source = (string) file_get_contents($files[0]);

    expect($source)->toContain('function down()')
        ->and($source)->toMatch('/dropColumn\(\s*[\'"]tax_id[\'"]\s*\)/')
        ->and($source)->toMatch('/after\(\s*[\'"]billing_country[\'"]\s*\)/');
});

// T-8 / T-9 update edge ------------------------------------------------------------------------

test('an update without the tax_id key leaves the stored value unchanged (T-8)', function () {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    taxIdUpdate($customer, ['name' => 'Nuevo Nombre']);

    expect($customer->fresh()->tax_id)->toBe('B-12345678')
        ->and($customer->fresh()->name)->toBe('Nuevo Nombre');
});

test('an explicit blank tax ID clears the stored value on update and stores null on create (T-9)', function (?string $blank) {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    taxIdUpdate($customer, ['tax_id' => $blank]);
    $created = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => $blank]));

    expect($customer->fresh()->tax_id)->toBeNull()
        ->and($created->fresh()->tax_id)->toBeNull();
})->with(['empty string' => [''], 'whitespace' => ['   '], 'null' => [null]]);

// T-10 length ----------------------------------------------------------------------------------

test('32 characters are accepted, 33 are refused and the refused value never reaches the column (T-10)', function () {
    $ok = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => str_repeat('A', 32)]));

    expect($ok->fresh()->tax_id)->toBe(str_repeat('A', 32))
        ->and(taxIdRefusedKeys(fn () => app(CreateCustomer::class)(taxIdCreatePayload(['email' => 'largo@example.com', 'tax_id' => str_repeat('A', 33)]))))->toBe(['tax_id'])
        ->and(Customer::query()->where('email', 'largo@example.com')->exists())->toBeFalse();
});

test('a 33-character input that is 32 after whitespace removal is accepted (T-10)', function () {
    $customer = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => str_repeat('A', 16).' '.str_repeat('A', 16).' ']));

    expect($customer->fresh()->tax_id)->toBe(str_repeat('A', 32));
});

test('a refused over-long update leaves the stored value untouched (T-10)', function () {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    expect(taxIdRefusedKeys(fn () => taxIdUpdate($customer, ['tax_id' => str_repeat('A', 100)])))->toBe(['tax_id'])
        ->and($customer->fresh()->tax_id)->toBe('B-12345678');
});

// T-11 non-ASCII -------------------------------------------------------------------------------

test('non-ASCII letters are refused rather than mangled by upper-casing (T-11)', function (string $input) {
    expect(taxIdRefusedKeys(fn () => app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => $input]))))->toBe(['tax_id'])
        ->and(Customer::query()->count())->toBe(0);
})->with(['ñ1', 'é']);

// T-12 soft delete -----------------------------------------------------------------------------

test('a soft-deleted customer keeps its tax ID (T-12)', function () {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);
    $customer->delete();

    expect(Customer::withTrashed()->find($customer->id)->tax_id)->toBe('B-12345678');
});

// T-13 race ------------------------------------------------------------------------------------

test('a duplicate-email refusal with a tax ID sent saves nothing of the new customer (T-13)', function () {
    Customer::factory()->create(['email' => 'ocupado@example.com']);

    expect(taxIdRefusedKeys(fn () => app(CreateCustomer::class)(taxIdCreatePayload(['email' => 'ocupado@example.com', 'tax_id' => 'B-12345678']))))->toBe(['email'])
        ->and(Customer::query()->where('tax_id', 'B-12345678')->exists())->toBeFalse();
});

test('a refused email on update does not store the tax ID sent with it (T-13)', function () {
    Customer::factory()->create(['email' => 'ocupado@example.com']);
    $customer = Customer::factory()->create(['tax_id' => 'B-11111111']);

    $keys = taxIdRefusedKeys(fn () => taxIdUpdate($customer, ['email' => 'ocupado@example.com', 'tax_id' => 'B-22222222']));

    expect($keys)->toBe(['email'])
        ->and($customer->fresh()->tax_id)->toBe('B-11111111');
});

// T-14 issuer tax ID ---------------------------------------------------------------------------

test('a customer tax ID equal to the issuer tax ID is accepted (T-14)', function () {
    InvoiceSetting::factory()->create(['issuer_tax_id' => 'ESB12345678']);

    $customer = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => 'esb12345678']));

    expect($customer->fresh()->tax_id)->toBe('ESB12345678')
        ->and(InvoiceSetting::current()->issuer_tax_id)->toBe('ESB12345678');
});

// T-15 orders unchanged ------------------------------------------------------------------------

test('changing the tax ID leaves every order row of the customer unchanged (T-15)', function () {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);
    Order::factory()->forCustomer($customer)->count(2)->create();
    $before = DB::table('orders')->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    taxIdUpdate($customer, ['tax_id' => 'B-87654321']);

    $after = DB::table('orders')->where('customer_id', $customer->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    expect($before)->toHaveCount(2)
        ->and($after)->toBe($before);
});

// T-16 invalid ---------------------------------------------------------------------------------

test('the shared invalid dataset is refused on tax_id on create and update, writing nothing (T-16)', function (string $input) {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);
    $count = Customer::query()->count();

    expect(taxIdRefusedKeys(fn () => app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => $input]))))->toBe(['tax_id'])
        ->and(taxIdRefusedKeys(fn () => taxIdUpdate($customer, ['name' => 'Cambiado', 'tax_id' => $input])))->toBe(['tax_id'])
        ->and(Customer::query()->count())->toBe($count)
        ->and($customer->fresh()->tax_id)->toBe('B-12345678')
        ->and($customer->fresh()->name)->not->toBe('Cambiado');
})->with('tax_id_invalid');

// T-17 non-strings -----------------------------------------------------------------------------

test('non-string tax IDs are refused on tax_id and never coerced and saved (T-17)', function (mixed $input) {
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    expect(taxIdRefusedKeys(fn () => app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => $input]))))->toBe(['tax_id'])
        ->and(Customer::query()->where('email', 'fiscal@example.com')->exists())->toBeFalse()
        ->and(taxIdRefusedKeys(fn () => taxIdUpdate($customer, ['tax_id' => $input])))->toBe(['tax_id'])
        ->and($customer->fresh()->tax_id)->toBe('B-12345678');
})->with([
    'array' => [['a']],
    'int' => [123],
    'bool' => [true],
    'nested array' => [[['a' => ['b']]]],
]);

// T-17b translated attribute name --------------------------------------------------------------

test('the tax ID validation message uses the translated attribute name in en and es, on create and update (T-17b)', function (string $locale) {
    app()->setLocale($locale);
    $name = __('customers.attributes.tax_id');
    $customer = Customer::factory()->create();

    $messages = [];

    foreach ([
        fn () => app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => 'ES_B1'])),
        fn () => taxIdUpdate($customer, ['tax_id' => 'ES_B1']),
    ] as $call) {
        try {
            $call();
        } catch (ValidationException $e) {
            $messages[] = $e->errors()['tax_id'][0];
        }
    }

    expect($name)->not->toBe('customers.attributes.tax_id')
        ->and($messages)->toHaveCount(2);

    foreach ($messages as $message) {
        expect($message)->toContain($name);
    }
})->with(['en', 'es']);

// T-21 mass assignment -------------------------------------------------------------------------

test('a payload carrying tax_id with id and deleted_at changes only fillable columns (T-21)', function () {
    $customer = Customer::factory()->create();
    $originalId = $customer->id;

    taxIdUpdate($customer, ['tax_id' => 'B-12345678', 'id' => '00000000-0000-7000-8000-000000000000', 'deleted_at' => now()->toDateTimeString()]);

    $fresh = Customer::withTrashed()->find($originalId);

    expect($fresh)->not->toBeNull()
        ->and($fresh->tax_id)->toBe('B-12345678')
        ->and($fresh->deleted_at)->toBeNull();

    $created = app(CreateCustomer::class)(taxIdCreatePayload(['tax_id' => 'B-1', 'id' => '00000000-0000-7000-8000-000000000001', 'deleted_at' => now()->toDateTimeString()]));

    expect($created->fresh()->id)->not->toBe('00000000-0000-7000-8000-000000000001')
        ->and($created->fresh()->deleted_at)->toBeNull();
});
