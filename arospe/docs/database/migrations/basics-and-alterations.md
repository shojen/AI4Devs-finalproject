# Migration Conventions — File naming, structure and alterations

> Part of [Migration Conventions](../migrations.md). **Read this part when:** you write any migration: naming, `down()` symmetry, adding a column, backfilling a wrong default, dropping a unique index. The other parts are listed in the [hub](../migrations.md#table-of-contents).

## File naming

Standard Laravel timestamp-prefixed naming, verified against every file in `database/migrations/`:

```
YYYY_MM_DD_HHMMSS_verb_noun.php
```

Real examples from this repo:

- `2024_01_01_000000_create_passkeys_table.php`
- `2025_08_14_170933_add_two_factor_columns_to_users_table.php`
- `2026_07_12_181045_create_permission_tables.php`

Table-creation migrations are named `create_<table>_table`; alterations are named `<verb>_<what>_to_<table>_table` (e.g. `add_two_factor_columns_to_users_table`).

## Structure

Every migration in this repo is an anonymous class returned from the file, with `up()` and a symmetric `down()` that reverses it exactly:

```php
// database/migrations/2024_01_01_000000_create_passkeys_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
```

✅ Good — matches this pattern:
- `foreignId(...)->constrained()->cascadeOnDelete()` for FKs that should disappear with their parent (passkeys belong to a user; no orphaned passkeys).
- `down()` always exists and is the exact inverse of `up()`.

⚠️ **The explicit `$table->index('user_id')` on the last line is not the shape to copy for a *new* table — but it is not a duplicate index either, and the reason matters.** **Corrected 2026-09-07 (story 0033) — this line previously read *"it duplicates the index `constrained()` already causes InnoDB to create"*, and that claim is false; quoted in full rather than silently rewritten, per this project's audit-authored-page convention.** Story 0033 measured it directly (`php artisan db:table passkeys` against a live, migrated MySQL instance) rather than assuming: InnoDB auto-creates a supporting index for a foreign key **only when no suitable index already exists** on that column — the same rule stated correctly two paragraphs into [An FK column does not also get an explicit index here](uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here) below, which this line simply failed to agree with. `user_id`'s hand-written `$table->index('user_id')` runs **before** InnoDB would otherwise auto-create one for `constrained()`'s FK, so it satisfies the requirement itself — there is exactly **one** index on `user_id`, not two, and the only visible effect of writing it by hand is that the resulting index is named `passkeys_user_id_index` rather than the auto-generated `passkeys_user_id_foreign`. The underlying design guidance is unaffected by this correction: don't hand-write an FK index in a *new* table unless you have a specific reason to, since `constrained()` alone already leaves the column indexed and a redundant *second* index (the real `users_uuid_unique` mistake in [errors-log.md](../../errors-log.md)) is a genuine, different hazard from this one. See [An FK column does not also get an explicit index here](uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here) for the rule and its now-tenth confirming instance.

❌ Bad — do not do this in this codebase:
```php
// Anti-pattern — do not commit a migration like this
public function down(): void
{
    // TODO
}
```
An empty or missing `down()` breaks `php artisan migrate:rollback` and CI resets. Every migration in `database/migrations/` has a real `down()` — keep that invariant.

## Real examples

### Creating a table with a unique index

```php
// database/migrations/0001_01_01_000000_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();
});
```

### Non-incrementing primary key table

```php
// database/migrations/0001_01_01_000000_create_users_table.php
Schema::create('password_reset_tokens', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});
```

## Adding a column to an existing table

Real example — a dedicated migration adds columns to `users` rather than editing the original `create_users_table` migration:

```php
// database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->text('two_factor_secret')->after('password')->nullable();
        $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
        $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn([
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ]);
    });
}
```

✅ Good — `after(...)` keeps column order deliberate and readable; `down()` drops exactly the columns `up()` added, nothing more.

### When the new column's default is wrong for existing rows, backfill in the same `up()`

`ALTER TABLE ... DEFAULT` also writes that default into every pre-existing row, so a default chosen for *new* rows can silently mis-state the *old* ones. When it does, the fix is a second statement in the same `up()`, not a different default:

```php
// database/migrations/2026_08_11_175426_add_status_to_users_table.php
use App\Enums\UserStatus;   // importing an app class from a migration follows 2026_07_22_100004_*

public function up(): void
{
    Schema::table('users', function (Blueprint $table): void {
        $table->string('status', 20)->after('email_verified_at')->default(UserStatus::Inactive->value);
    });

    DB::table('users')
        ->whereNotNull('email_verified_at')
        ->update(['status' => UserStatus::Active->value]);
}
```

✅ Good — three things this example establishes:

- **`string(20)`, not bare `string()`.** A bare `string()` is `VARCHAR(255)` for a 10-character token, and would make any future index a 1020-byte utf8mb4 key.
- **`string` + a PHP enum over a native MySQL `enum`** — a native `enum` needs DDL for each new value and orders by ordinal rather than alphabetically; `Rule::enum(UserStatus::class)` is the validation boundary, so the database need not re-enforce the value set. The enum class is imported straight into the migration, matching `2026_07_22_100004_*`.
- **The conditional backfill is why `up()` is two statements.** Applying the `inactive` default blindly would have flipped every already-verified account — the Super Admin included — to `inactive`.

**`2026_09_17_120001_add_refunded_amount_to_orders_table.php` (story 0051) is the confirming *negative* case — a one-statement `up()`, and a docblock stating why rather than leaving the omission to look like an oversight.** `orders.refunded_amount` defaults to `0.00`, and no refund mechanism existed anywhere in the app before this story, so `0.00` is the true value for every pre-existing row — there is nothing for a second statement to correct. The rule this page states ("backfill when the new default mis-states old rows") cuts both ways: it requires a backfill when the check fails, and it forbids inventing one when the check passes.

### Drop a unique index explicitly before its column

```php
// database/migrations/2026_08_11_175427_add_pending_email_to_users_table.php
public function down(): void
{
    Schema::table('users', function (Blueprint $table): void {
        $table->dropUnique(['pending_email']);
        $table->dropColumn('pending_email');
    });
}
```

✅ Good — dropping the column first leaves the engine to infer the index drop, which is version-dependent; being explicit keeps `migrate:rollback` deterministic. This is the same "be explicit about indexes" instinct as the manual `$table->index('user_id')` in `create_passkeys_table` above.
