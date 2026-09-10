<?php

use App\Actions\Customers\CreateCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer, App\Actions\Customers\CreateCustomer,
// the customers migration and App\Concerns\CustomerValidationRules do not exist yet. Every test
// in this file is expected to fail (class/table not found) until backend-expert/database-expert
// implement them in the next step of the TDD cycle -- that is the correct, intended "red" outcome.
//
// Every assertion goes through the ACTION directly (`app(CreateCustomer::class)($attributes)`),
// never a Livewire component -- D-1 is explicit this story ships no screen at all (that is 0044's),
// so the action is the only thing there is to assert through, per
// docs/testing/README.md's "an authorization test at the action layer and an HTTP one are not
// substitutes" rule.
//
// CreateCustomer::__invoke(array $attributes): Customer authorizes `create` on Customer::class as
// its own first statement (D-12), so every test below actingAs() an actor holding
// customers.create, or the call throws AuthorizationException before validation ever runs -- see
// AuthorizationTest.php for the tests that exercise the refusal/success/Super-Admin-bypass shape
// of that gate itself.
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('customers.create');
    $this->actingAs($this->actor);
});

/**
 * The full, valid fifteen-column payload — every optional field populated so the "full payload
 * stores everything" happy-path test has something real to assert against.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function customerFullPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Cliente de Prueba',
        'email' => 'cliente@example.com',
        'phone' => '+34 600 111 222',
        'shipping_address_line1' => 'Calle Mayor 1',
        'shipping_address_line2' => 'Piso 2, Puerta B',
        'shipping_city' => 'Madrid',
        'shipping_postal_code' => '28013',
        'shipping_province' => 'Madrid',
        'shipping_country' => 'ES',
        'billing_address_line1' => 'Calle Mayor 1',
        'billing_address_line2' => 'Piso 2, Puerta B',
        'billing_city' => 'Madrid',
        'billing_postal_code' => '28013',
        'billing_province' => 'Madrid',
        'billing_country' => 'ES',
    ], $overrides);
}

/**
 * Builds a syntactically valid email of an exact total length. Laravel's default `'email'` rule
 * (Egulias\EmailValidator\Validation\RFCValidation alone) enforces RFC 5322 grammar but does NOT
 * fail on an over-length local part or domain — those are RFCValidation *warnings*, not errors —
 * so a local part built purely from letters is valid at any length, and only the app's own
 * `max:255` rule can be what refuses one that is too long. That is exactly the boundary this
 * helper exists to construct.
 */
function customerEmailOfLength(int $length): string
{
    $suffix = '@example.com';

    return str_repeat('a', $length - strlen($suffix)).$suffix;
}

function customerExpectRefusal(array $attributes, string $field): void
{
    $caught = null;

    try {
        app(CreateCustomer::class)($attributes);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey($field);

    expect(Customer::count())->toBe(0);
}

// =====================================================================
// Creation — happy paths
// =====================================================================

test('creating with a full payload stores all fifteen columns', function () {
    $attributes = customerFullPayload();

    $customer = app(CreateCustomer::class)($attributes);
    $fresh = $customer->fresh();

    foreach ($attributes as $key => $value) {
        expect($fresh->{$key})->toBe($value);
    }
});

test('creating with only a name and an email stores null in every optional column', function () {
    $customer = app(CreateCustomer::class)([
        'name' => 'Cliente Mínimo',
        'email' => 'minimo@example.com',
    ]);

    $fresh = $customer->fresh();

    expect($fresh->name)->toBe('Cliente Mínimo')
        ->and($fresh->email)->toBe('minimo@example.com')
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->shipping_address_line1)->toBeNull()
        ->and($fresh->shipping_address_line2)->toBeNull()
        ->and($fresh->shipping_city)->toBeNull()
        ->and($fresh->shipping_postal_code)->toBeNull()
        ->and($fresh->shipping_province)->toBeNull()
        ->and($fresh->shipping_country)->toBeNull()
        ->and($fresh->billing_address_line1)->toBeNull()
        ->and($fresh->billing_address_line2)->toBeNull()
        ->and($fresh->billing_city)->toBeNull()
        ->and($fresh->billing_postal_code)->toBeNull()
        ->and($fresh->billing_province)->toBeNull()
        ->and($fresh->billing_country)->toBeNull();
});

// D-5: the write and the uniqueness rule must see the SAME bytes, so lowercasing happens before
// validation, not as a database-column mutator.
test('an email supplied in mixed case is stored lowercase', function () {
    $customer = app(CreateCustomer::class)([
        'name' => 'Cliente',
        'email' => 'Cliente@Example.COM',
    ]);

    expect($customer->fresh()->email)->toBe('cliente@example.com');
});

// =====================================================================
// Creation — validation failures: required fields
// =====================================================================

test('creating without a name throws ValidationException on name and writes no row', function () {
    customerExpectRefusal(['email' => 'sinnombre@example.com'], 'name');
});

test('creating without an email throws ValidationException on email and writes no row', function () {
    customerExpectRefusal(['name' => 'Cliente'], 'email');
});

test('creating with a blank name is refused', function () {
    customerExpectRefusal(['name' => '', 'email' => 'blank-name@example.com'], 'name');
});

test('creating with a blank email is refused', function () {
    customerExpectRefusal(['name' => 'Cliente', 'email' => ''], 'email');
});

// =====================================================================
// Creation — validation failures: malformed email
// =====================================================================

test('a malformed email is rejected', function (string $email) {
    customerExpectRefusal(['name' => 'Cliente', 'email' => $email], 'email');
})->with([
    'no @ at all' => ['not-an-email'],
    'nothing after the @' => ['a@'],
    'nothing before the @' => ['@example.com'],
    'an unescaped space in the local part' => ['a b@example.com'],
    // Also exceeds max:255 once the domain is appended -- refused either way, and it stays a
    // clean ValidationException rather than a truncated/500'd write regardless of which rule
    // catches it first.
    'a 300-character local part' => [str_repeat('a', 300).'@example.com'],
]);

// =====================================================================
// Creation — validation failures: boundary lengths, asserted from BOTH sides so the test can
// tell max:N from max:N+1 (R-3: the migration length and the validation max: must stay in
// lockstep).
// =====================================================================

test('a name of exactly the maximum length (150) is accepted', function () {
    $name = str_repeat('a', 150);

    $customer = app(CreateCustomer::class)(['name' => $name, 'email' => 'name-max@example.com']);

    expect($customer->fresh()->name)->toBe($name);
});

test('a name one character over the maximum length (151) is refused', function () {
    customerExpectRefusal(['name' => str_repeat('a', 151), 'email' => 'name-over@example.com'], 'name');
});

test('an email of exactly the maximum length (255) is accepted', function () {
    $email = customerEmailOfLength(255);

    $customer = app(CreateCustomer::class)(['name' => 'Cliente', 'email' => $email]);

    expect($customer->fresh()->email)->toBe($email);
});

test('an email one character over the maximum length (256) is refused', function () {
    customerExpectRefusal(['name' => 'Cliente', 'email' => customerEmailOfLength(256)], 'email');
});

test('a phone number of exactly the maximum length (30) is accepted', function () {
    $phone = str_repeat('1', 30);

    $customer = app(CreateCustomer::class)([
        'name' => 'Cliente', 'email' => 'phone-max@example.com', 'phone' => $phone,
    ]);

    expect($customer->fresh()->phone)->toBe($phone);
});

test('a phone number one character over the maximum length (31) is refused', function () {
    customerExpectRefusal([
        'name' => 'Cliente', 'email' => 'phone-over@example.com', 'phone' => str_repeat('1', 31),
    ], 'phone');
});

test('a shipping address line of exactly the maximum length (255) is accepted', function () {
    $line = str_repeat('a', 255);

    $customer = app(CreateCustomer::class)([
        'name' => 'Cliente', 'email' => 'address-max@example.com', 'shipping_address_line1' => $line,
    ]);

    expect($customer->fresh()->shipping_address_line1)->toBe($line);
});

test('a shipping address line one character over the maximum length (256) is refused', function () {
    customerExpectRefusal([
        'name' => 'Cliente', 'email' => 'address-over@example.com', 'shipping_address_line1' => str_repeat('a', 256),
    ], 'shipping_address_line1');
});

test('a shipping city of exactly the maximum length (100) is accepted', function () {
    $city = str_repeat('a', 100);

    $customer = app(CreateCustomer::class)([
        'name' => 'Cliente', 'email' => 'city-max@example.com', 'shipping_city' => $city,
    ]);

    expect($customer->fresh()->shipping_city)->toBe($city);
});

test('a shipping city one character over the maximum length (101) is refused', function () {
    customerExpectRefusal([
        'name' => 'Cliente', 'email' => 'city-over@example.com', 'shipping_city' => str_repeat('a', 101),
    ], 'shipping_city');
});

test('a billing postal code of exactly the maximum length (20) is accepted', function () {
    $postalCode = str_repeat('1', 20);

    $customer = app(CreateCustomer::class)([
        'name' => 'Cliente', 'email' => 'postal-max@example.com', 'billing_postal_code' => $postalCode,
    ]);

    expect($customer->fresh()->billing_postal_code)->toBe($postalCode);
});

test('a billing postal code one character over the maximum length (21) is refused', function () {
    customerExpectRefusal([
        'name' => 'Cliente', 'email' => 'postal-over@example.com', 'billing_postal_code' => str_repeat('1', 21),
    ], 'billing_postal_code');
});

// =====================================================================
// Creation — validation failures: country shape (D-9 — ISO 3166-1 alpha-2 shape only, never
// membership in the seeded sales_regions catalog)
// =====================================================================

// D-9 amended at Phase 4 audit (F-1): a BLANK country is no longer part of this "rejected in
// shape" dataset -- it is normalised to null before validation ever runs (see the dedicated
// blank-to-null normalisation test below) and is therefore ACCEPTED, not refused. A country that
// is present but malformed (wrong length, non-letters) is still refused exactly as before -- only
// the blank case's outcome changed.
test('the shipping/billing country accepts a two-letter code and rejects anything else in shape', function (string $country, bool $accepted) {
    $attributes = [
        'name' => 'Cliente',
        'email' => 'country-'.Str::lower($country).'-'.Str::random(6).'@example.com',
        'shipping_country' => $country,
        'billing_country' => $country,
    ];

    if (! $accepted) {
        customerExpectRefusal($attributes, 'shipping_country');

        return;
    }

    $customer = app(CreateCustomer::class)($attributes);

    // Stored uppercase for a canonical form, per D-9 — 'es' round-trips as 'ES'.
    expect($customer->fresh()->shipping_country)->toBe(Str::upper($country))
        ->and($customer->fresh()->billing_country)->toBe(Str::upper($country));
})->with([
    'a full country name is rejected' => ['España', false],
    'a three-letter ISO code is rejected' => ['ESP', false],
    'a single letter is rejected' => ['E', false],
    'a letter plus a digit is rejected' => ['E1', false],
    'the upper-case alpha-2 code is accepted' => ['ES', true],
    'the lower-case alpha-2 code is accepted, stored canonically upper-case' => ['es', true],
]);

// =====================================================================
// Creation — blank-to-null normalisation (D-9, Phase 4 audit F-1/F-2): every one of the thirteen
// optional columns, submitted as an explicit '' (the shape a real form submits for an untouched
// optional field), must persist as a real database null rather than a literal empty string. One
// dataset entry per App\Concerns\CustomerValidationRules::OPTIONAL_FIELDS member, so a future
// column added to that list without a matching case here is caught by an under-count rather than
// silently skipped.
// =====================================================================

test('an optional column submitted as a blank string persists as null on create', function (string $field) {
    $attributes = customerFullPayload([
        'email' => 'blank-'.Str::lower(str_replace('_', '-', $field)).'-'.Str::random(6).'@example.com',
        $field => '',
    ]);

    $customer = app(CreateCustomer::class)($attributes);

    expect($customer->fresh()->{$field})->toBeNull();
})->with(CreateCustomer::OPTIONAL_FIELDS);

// =====================================================================
// Duplicate email
// =====================================================================

test('a second customer with the identical email is rejected and exactly one row exists afterwards', function () {
    app(CreateCustomer::class)(['name' => 'Primero', 'email' => 'duplicado@example.com']);

    customerExpectDuplicateRefusal('duplicado@example.com');

    expect(Customer::where('email', 'duplicado@example.com')->count())->toBe(1);
});

// R-2/D-5: the PHP-side normalisation is what makes this a clean ValidationException rather than
// a raw QueryException — MySQL's utf8mb4_unicode_ci collation would ALSO refuse this pair via the
// index alone, so a test only asserting "the second row was not created" could pass even with the
// app-level lower-casing deleted entirely. The exception CLASS is the whole point.
test('a duplicate email differing only in capitalisation is rejected as a ValidationException, not a QueryException', function () {
    app(CreateCustomer::class)(['name' => 'Primero', 'email' => 'CaseSensitive@Example.com']);

    $caught = null;

    try {
        app(CreateCustomer::class)(['name' => 'Segundo', 'email' => 'casesensitive@EXAMPLE.COM']);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('email');

    expect(Customer::count())->toBe(1);
});

function customerExpectDuplicateRefusal(string $email): void
{
    $caught = null;

    try {
        app(CreateCustomer::class)(['name' => 'Otro cliente', 'email' => $email]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('email');
}

// D-6: uniqueness is scoped to the customers table alone — a customer and a dashboard user are
// different domains and may legitimately share an address (e.g. the store owner testing their
// own shop as a customer).
test('a customer may hold the same email address as an existing dashboard user', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $customer = app(CreateCustomer::class)(['name' => 'Cliente Admin', 'email' => 'admin@example.com']);

    expect($customer->fresh()->email)->toBe('admin@example.com');
});

// D-5/D-10: proves the database's own UNIQUE index exists and has the last word, by inserting a
// colliding row directly through the query builder — bypassing CreateCustomer, and therefore its
// PHP-side normalised-uniqueness check, entirely.
test('the database unique index refuses a duplicate email inserted directly, bypassing the action', function () {
    app(CreateCustomer::class)(['name' => 'Primero', 'email' => 'indice@example.com']);

    $caught = null;

    try {
        DB::table('customers')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Bypass de la acción',
            'email' => 'indice@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->getCode())->toBe('23000');

    expect(Customer::where('email', 'indice@example.com')->count())->toBe(1);
});
