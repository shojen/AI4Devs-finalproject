<?php

// Story 0100 -- App\Actions\Invoices\SaveInvoiceSettings: the one writer (FD-5, FD-7).

use App\Actions\Invoices\SaveInvoiceSettings;
use App\Enums\InvoiceNumberYear;
use App\Enums\InvoiceTemplate;
use App\Models\InvoiceSetting;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\InvoiceSettings\InvoiceSettingsPayload;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('a full valid save with no row creates exactly one row with id 1 and every field reads back (T-1)', function () {
    InvoiceSettingsPayload::actingManager();
    $media = Media::factory()->create();
    $payload = InvoiceSettingsPayload::valid(['logo_media_id' => $media->id]);

    $saved = app(SaveInvoiceSettings::class)($payload);

    $row = InvoiceSetting::query()->findOrFail(1);

    expect(InvoiceSetting::query()->count())->toBe(1)
        ->and($saved->id)->toBe(InvoiceSetting::SINGLETON_ID)
        ->and($row->issuer_legal_name)->toBe('Arospe Handmade Sociedad Limitada')
        ->and($row->issuer_tax_id)->toBe('ESB12345678')
        ->and($row->issuer_address_line1)->toBe('Calle Mayor 1')
        ->and($row->issuer_address_line2)->toBe('Piso 2')
        ->and($row->issuer_city)->toBe('Madrid')
        ->and($row->issuer_postal_code)->toBe('28001')
        ->and($row->issuer_province)->toBe('Madrid')
        ->and($row->issuer_country)->toBe('ES')
        ->and($row->logo_media_id)->toBe($media->id)
        ->and($row->primary_color)->toBe('#112233')
        ->and($row->accent_color)->toBe('#AABBCC')
        ->and($row->template)->toBe(InvoiceTemplate::Modern)
        ->and($row->standard_prefix)->toBe('INV')
        ->and($row->corrective_prefix)->toBe('COR')
        ->and($row->standard_year_format)->toBe(InvoiceNumberYear::Short)
        ->and($row->corrective_year_format)->toBe(InvoiceNumberYear::Full)
        ->and($row->standard_separator)->toBe('/')
        ->and($row->corrective_separator)->toBe('.')
        ->and($row->standard_padding)->toBe(6)
        ->and($row->corrective_padding)->toBe(4)
        ->and($row->reset_numbering_yearly)->toBeTrue()
        ->and($row->auto_issue_on_order)->toBeFalse();
});

test('invoice-settings.manage alone can save (T-5)', function () {
    InvoiceSettingsPayload::actingManager('invoice-settings.manage');

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid());

    expect(InvoiceSetting::query()->count())->toBe(1);
});

test('a Super Admin can save (T-6)', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid());

    expect(InvoiceSetting::query()->count())->toBe(1);
});

test('empty issuer data with automatic invoicing off saves (T-9)', function () {
    InvoiceSettingsPayload::actingManager();

    $saved = app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid([
        'issuer_legal_name' => '', 'issuer_tax_id' => null, 'issuer_address_line1' => '  ',
        'issuer_address_line2' => null, 'issuer_city' => null, 'issuer_postal_code' => null,
        'issuer_province' => null, 'issuer_country' => '',
    ]));

    expect($saved->fresh()->hasCompleteIssuerData())->toBeFalse()
        ->and($saved->fresh()->issuer_legal_name)->toBeNull()
        ->and($saved->fresh()->issuer_country)->toBeNull();
});

test('two saves back to back leave one row and the last values win (T-10)', function () {
    InvoiceSettingsPayload::actingManager();
    $save = app(SaveInvoiceSettings::class);

    $save(InvoiceSettingsPayload::valid(['standard_prefix' => 'AAA']));
    $save(InvoiceSettingsPayload::valid(['standard_prefix' => 'BBB']));

    expect(InvoiceSetting::query()->count())->toBe(1)
        ->and(InvoiceSetting::current()->standard_prefix)->toBe('BBB');
});

test('a first save whose default-row insert finds the row already there keeps one row (T-10)', function () {
    InvoiceSettingsPayload::actingManager();
    InvoiceSetting::factory()->create(['standard_prefix' => 'OLD']);

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['standard_prefix' => 'NEW']));

    expect(InvoiceSetting::query()->count())->toBe(1)
        ->and(InvoiceSetting::current()->standard_prefix)->toBe('NEW');
});

test('the default row is inserted outside the transaction and the row is selected FOR UPDATE inside it (T-10, FD-7)', function () {
    InvoiceSettingsPayload::actingManager();
    $baseline = DB::transactionLevel();
    $seen = [];

    DB::listen(function ($query) use (&$seen): void {
        if (str_contains($query->sql, 'invoice_settings')) {
            $seen[] = [strtolower($query->sql), DB::transactionLevel()];
        }
    });

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid());

    $inserts = array_values(array_filter($seen, fn (array $q): bool => str_starts_with($q[0], 'insert')));
    $locks = array_values(array_filter($seen, fn (array $q): bool => str_contains($q[0], 'for update')));

    expect($inserts)->not->toBeEmpty()
        ->and($inserts[0][1])->toBe($baseline)
        ->and($locks)->not->toBeEmpty()
        ->and($locks[0][1])->toBe($baseline + 1);
});

test('a save writes only invoice_settings (T-21d)', function () {
    InvoiceSettingsPayload::actingManager();
    $tables = [];

    DB::listen(function ($query) use (&$tables): void {
        if (preg_match('/^\s*(insert(?: ignore)? into|update|delete from)\s+[`"]?(\w+)/i', $query->sql, $m)) {
            $tables[] = $m[2];
        }
    });

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid());

    expect(array_unique($tables))->toBe(['invoice_settings']);
});

// T-11 -- the row lock is real: a second connection holding the row FOR UPDATE makes the save fail
// with lock-wait timeout 1205, and the held row is untouched. The row is committed on the second
// connection (the test's own transaction would hide it) and removed in `finally`.
test('a row held FOR UPDATE by a second connection makes the save fail with a lock wait timeout (T-11)', function () {
    InvoiceSettingsPayload::actingManager();
    config(['database.connections.invoice_lock_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('invoice_lock_probe');

    $probe->table('invoice_settings')->insertOrIgnore(['id' => 1, 'standard_prefix' => 'HELD']);
    $probe->beginTransaction();

    try {
        $probe->table('invoice_settings')->where('id', 1)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $failure = null;
        try {
            app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['standard_prefix' => 'LOST']));
        } catch (QueryException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(QueryException::class)
            ->and((string) $failure->errorInfo[1])->toBe('1205');
    } finally {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $probe->rollBack();
        $probe->table('invoice_settings')->where('id', 1)->delete();
        DB::disconnect('invoice_lock_probe');
    }

    expect(InvoiceSetting::query()->count())->toBe(0);
});

test('extra keys id, created_at and foo are ignored (T-21a)', function () {
    InvoiceSettingsPayload::actingManager();
    InvoiceSetting::factory()->create(['created_at' => '2020-01-02 03:04:05']);

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid([
        'id' => 99, 'created_at' => '1999-01-01 00:00:00', 'foo' => 'bar',
    ]));

    $row = InvoiceSetting::query()->firstOrFail();

    expect(InvoiceSetting::query()->count())->toBe(1)
        ->and($row->id)->toBe(1)
        ->and($row->created_at->toDateTimeString())->toBe('2020-01-02 03:04:05');
});

test('text is trimmed and blank becomes null, colours and country are upper-cased (FD-6)', function () {
    InvoiceSettingsPayload::actingManager();

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid([
        'issuer_legal_name' => '  Acme  ', 'issuer_address_line2' => '   ', 'issuer_city' => '',
        'primary_color' => '#a1b2c3', 'accent_color' => '', 'issuer_country' => 'es',
    ]));

    $row = InvoiceSetting::current();

    expect($row->issuer_legal_name)->toBe('Acme')
        ->and($row->issuer_address_line2)->toBeNull()
        ->and($row->issuer_city)->toBeNull()
        ->and($row->primary_color)->toBe('#A1B2C3')
        ->and($row->accent_color)->toBeNull()
        ->and($row->issuer_country)->toBe('ES');
});

test('a save and a logo of null both work (T-20)', function () {
    InvoiceSettingsPayload::actingManager();
    $media = Media::factory()->create();

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['logo_media_id' => $media->id]));
    expect(InvoiceSetting::current()->logo_media_id)->toBe($media->id);

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['logo_media_id' => null]));
    expect(InvoiceSetting::current()->logo_media_id)->toBeNull();
});

test('a legal name of 255 characters round-trips whole (T-13)', function () {
    InvoiceSettingsPayload::actingManager();
    $name = str_repeat('é', 255);

    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['issuer_legal_name' => $name]));

    expect(InvoiceSetting::current()->issuer_legal_name)->toBe($name);
});

test('a failed validation leaves the previous row unchanged (FD-5)', function () {
    InvoiceSettingsPayload::actingManager();
    app(SaveInvoiceSettings::class)(InvoiceSettingsPayload::valid(['standard_prefix' => 'KEEP']));

    expect(InvoiceSettingsPayload::refusedKeys(InvoiceSettingsPayload::valid(['standard_prefix' => 'NEW', 'template' => 'nope'])))->toBe(['template'])
        ->and(InvoiceSetting::current()->standard_prefix)->toBe('KEEP');
});
