<?php

// Story 0068 -- Database\Seeders\StoreLanguageSeeder, the Spanish bootstrap (D2, D4). Unlike
// SalesRegionSeeder, this is a ONE-TIME bootstrap, not a repeatable resync: it only ever writes
// into an EMPTY catalog (`doesntExist()`-gated), so re-running it must never touch an
// administrator's subsequent choices. No forgetCachedPermissions() beforeEach() -- this seeder
// touches no permission cache, exactly like SalesRegionSeederTest.php's own reasoning.

use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function withCorruptedIso639FixtureDuring(?string $content, Closure $callback): mixed
{
    $path = base_path('database/data/iso-639-languages.json');
    $fileExisted = file_exists($path);
    $backupPath = $path.'.qa-backup';

    if ($fileExisted) {
        rename($path, $backupPath);
    }

    if ($content !== null) {
        file_put_contents($path, $content);
    }

    try {
        return $callback();
    } finally {
        if ($content !== null && file_exists($path)) {
            unlink($path);
        }

        if ($fileExisted) {
            rename($backupPath, $path);
        }
    }
}

// --- Fresh install ---

test('seeding an empty catalog creates exactly one row, Spanish, active and default', function () {
    $this->seed(StoreLanguageSeeder::class);

    expect(StoreLanguage::count())->toBe(1);

    $spain = StoreLanguage::where('code', 'es')->firstOrFail();

    expect($spain->is_active)->toBeTrue()
        ->and($spain->is_default)->toBeTrue();
});

// The seeded name must come from the fixture, not a hardcoded literal -- this test cannot pass
// against a seeder that hardcodes 'Español' by coincidence matching the current fixture value.
test('the seeded Spanish row\'s name equals the fixture\'s own endonym for es', function () {
    $this->seed(StoreLanguageSeeder::class);

    $spain = StoreLanguage::where('code', 'es')->firstOrFail();

    expect($spain->name)->toBe(StoreLanguage::availableLanguages()['es']);
});

// --- Idempotency ---

test('seeding twice creates no duplicate and no second default', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->seed(StoreLanguageSeeder::class);

    expect(StoreLanguage::count())->toBe(1)
        ->and(StoreLanguage::where('is_default', true)->count())->toBe(1);
});

// --- D2: the bootstrap only writes an EMPTY catalog, never a resync ---

test('re-seeding after an administrator has added and promoted French leaves French default and Spanish untouched', function () {
    $this->seed(StoreLanguageSeeder::class);

    $french = StoreLanguage::factory()->create(['code' => 'fr']);
    $french->forceFill(['is_default' => true])->save();

    $spain = StoreLanguage::where('code', 'es')->firstOrFail();
    $spain->forceFill(['is_default' => false])->save();

    $this->seed(StoreLanguageSeeder::class);

    expect($french->fresh()->is_default)->toBeTrue()
        ->and($spain->fresh()->is_default)->toBeFalse()
        ->and(StoreLanguage::where('is_default', true)->count())->toBe(1);
});

// --- Fail loudly ---

test('seeding with a missing fixture aborts the seed loudly, writing no row', function () {
    withCorruptedIso639FixtureDuring(null, function (): void {
        expect(fn () => (new StoreLanguageSeeder)->run())->toThrow(Exception::class);
    });

    expect(StoreLanguage::count())->toBe(0);
});

test('seeding with a malformed fixture aborts the seed loudly, writing no row', function () {
    withCorruptedIso639FixtureDuring('{not valid json', function (): void {
        expect(fn () => (new StoreLanguageSeeder)->run())->toThrow(Exception::class);
    });

    expect(StoreLanguage::count())->toBe(0);
});

// --- ProductionSeeder composition ---

test('ProductionSeeder reaches StoreLanguageSeeder', function () {
    app()->instance('env', 'production');
    config(['auth.super_admin.email' => null]);

    (new ProductionSeeder)();

    expect(StoreLanguage::where('code', 'es')->where('is_default', true)->exists())->toBeTrue();
});

// --- Isolation ---

test('running the Store Language seeder alone creates no users, roles or permissions', function () {
    $this->seed(StoreLanguageSeeder::class);

    expect(User::count())->toBe(0)
        ->and(Role::count())->toBe(0)
        ->and(Permission::count())->toBe(0);
});
