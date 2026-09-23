# Database Schema — Shipping — geography_entries

> Part of [Database Schema — Shipping](../schema-shipping.md). **Read this part when:** the task touches the shipping geography catalog (countries, communities, municipalities). The other parts are listed in the [hub](../schema-shipping.md#table-of-contents).

### `geography_entries`

Source: `database/migrations/2026_09_06_090000_create_geography_entries_table.php` (story 0032) — the read-only shipping geography catalog every ISO country, Spain's 17 comunidades autónomas, and every Spanish municipio at INE granularity ([PRD §2.4](../../PRD/sections/epic-2-products-taxes-shipping.md#24-shipping)). **Physically independent of [`sales_regions`](../schema-products/sales-regions-and-media.md#sales_regions)**: no shared table, no foreign key, per PRD assumption 4 — the two catalogs answer different questions (fiscal rate vs. shipping destination) and are seeded from two entirely separate `database/seeders/` classes.

Model: [`App\Models\GeographyEntry`](../../../app/Models/GeographyEntry.php). Columns in real physical order (verified with `php artisan db:table geography_entries`):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint` auto-increment PK | **the one deliberate exception to this project's UUIDv7 policy** — see [ADR 0001's Amendment 5](../../decisions/0001-uuid-primary-keys.md#amendment-5-2026-09-06--the-named-bigint-exception-is-real-geography_entries). A pure high-volume internal lookup table (~8,300 rows), no independent business identity, never URL-exposed |
| `level` | `VARCHAR(20)` | cast to [`App\Enums\GeographyLevel`](../../../app/Enums/GeographyLevel.php) (`country` / `community` / `municipality`); string + PHP enum over a native MySQL `enum`, the same precedent `users.status`/`sales_regions.kind` already establish. No default — every row is written explicitly by the seeder |
| `parent_id` | `bigint` FK → `geography_entries.id`, nullable | self-referencing, `restrictOnDelete()` — there is no admin CRUD on this table at all (this story or any planned one), so a cascade could only ever be a maintenance-script accident wiping an entire branch of the tree |
| `name` | `VARCHAR(255)` | display name, exactly as sourced (and, for the municipality fixture, exactly as rewritten to natural Spanish word order at build time — see [`database/data/README.md`](../../../database/data/README.md#es-municipalitiescsv)) |
| `normalized_name` | `VARCHAR(255)` | the search column, computed once at seed time by the project's centralized text-normalizer, [`App\Actions\NormalizeForSearch`](../../../app/Actions/NormalizeForSearch.php) — this table's seeder defines **no normalization rule of its own**, so seed-time and a future picker's search-time normalization can never drift apart (the same reasoning [`product_categories.name`](../schema-products/categories-and-products.md#product_categories) already establishes for its own normalized comparison) |
| `ine_code` | `VARCHAR(10)`, nullable, unique | comunidad-autónoma and municipio rows only; `NULL` for every country row. ⚠️ **The `UNIQUE` constraint is per-column, not per-level** — comunidad codes (`01`–`17`) and municipio codes (`01001`–`50…`) never collide today only because they differ in digit count, and the *province* codes already present in the raw fixture (`01`–`50`, dropped before writing since there is no `Province` level — see `province_name` below) are two-digit, exactly like comunidad codes. **A future `Province` level (OQ-5) could not reuse this column as-is**: province `15` (A Coruña) would collide with comunidad `15` (Comunidad Foral de Navarra). That story would need either a composite `UNIQUE(level, ine_code)` or a level-prefixed code, not a bare re-use of this column |
| `iso_alpha2` | `CHAR(2)`, nullable, unique | country rows only; `NULL` for every comunidad/municipio row |
| `province_name` | `VARCHAR(255)`, nullable | denormalized on municipio rows only — **a column, never a fourth catalog level** (this story's own OQ-5): the PRD explicitly rejects province-granularity zones, so the province is kept only as a free, queryable fact rather than a `Province` case on `GeographyLevel` |
| `created_at` / `updated_at` | timestamp, nullable | |

**No `SoftDeletes` and no seeded-vs-administrator-configurable column split, unlike [`sales_regions`](../schema-products/sales-regions-and-media.md#sales_regions).** Every column here is seeder-owned — there is no administrator-editable half at all, because this table has no admin CRUD surface of any kind (no route, no Livewire component, no policy, no permission). `GeographyCatalogSeeder`'s `upsert()` therefore refreshes every non-key column (`level`, `parent_id`, `name`, `normalized_name`, `province_name`) on every re-seed, which is what lets a corrected INE vintage reach an already-deployed install with no extra migration.

**`#[Fillable([])]` is deliberately empty** — the same omission-as-mass-assignment-guard convention `users.status`/`sales_regions.*` use, taken to its logical extreme: no form ever writes any column on this model at all, and the seeder inserts through the query builder (`DB::table('geography_entries')->upsert(...)`), never through the Eloquent model's own `fill()`/`create()` path.

#### The tree is exactly two hops deep, enforced by the seeder alone, not by the database

`level === Community` if and only if `parent_id` resolves to a `Country` row, and `level === Municipality` if and only if `parent_id` resolves to a `Community` row — the same shape [`sales_regions`](../schema-products/sales-regions-and-media.md#sales_regions)'s own two-tier country/fiscal-territory tree already establishes, one level deeper. Nothing in the schema enforces this depth: `parent_id` is a plain self-referencing FK with no `CHECK` constraint tying it to `level`. The invariant holds only because [`database/seeders/GeographyCatalogSeeder.php`](../../../database/seeders/GeographyCatalogSeeder.php) is the sole writer of every row (there is no admin CRUD to bypass it), and it writes the tree in exactly one order: every country row first, then the 17 comunidad rows (parented to the `ES` country row), then every municipio row (parented to its resolved comunidad row) — parent always before child, so the self-referencing FK never rejects a write.

#### Seeded state: three levels, one file each, no third fixture

Populated unconditionally by `GeographyCatalogSeeder`, called from both `DatabaseSeeder` and `ProductionSeeder` — required application data, the same "seeding is a required deployment step" precedent `sales_regions`/the permission tables already establish, not gated behind the `['local', 'testing']` fixture allow-list.

| Rows | `level` | Source |
| --- | --- | --- |
| 249 ISO countries | `country` | [`database/data/iso-3166-countries.json`](../../../database/data/iso-3166-countries.json) — **shared, read-only**, with [`sales_regions`](../schema-products/sales-regions-and-media.md#sales_regions)'s own `SalesRegionSeeder` (story 0016's file; see its ownership note in [`database/data/README.md`](../../../database/data/README.md)) |
| 17 comunidades autónomas | `community` | derived by de-duplicating `(community_ine_code, community_name)` while streaming the municipality fixture below — there is no separate comunidades file to keep in sync with it |
| 8,130 municipios | `municipality` | [`database/data/es-municipalities.csv`](../../../database/data/es-municipalities.csv) — story 0032's own file; see its provenance, and why the raw INE-adjacent source's 8,132 rows become 8,130 here (Ceuta/Melilla excluded — they are autonomous cities, not comunidades autónomas), in [`database/data/README.md`](../../../database/data/README.md#es-municipalitiescsv) |

≈8,396 rows total. **No test in this repo hardcodes the municipio count** — every count assertion resolves against the fixture's own parsed row count, since it changes between INE vintages as municipalities merge or split. The **17** comunidad-autónoma count is the one safe to hardcode: it is a fact about Spain's territorial organization, not about the bundled file.

#### The seeder is chunked and idempotent, keyed on the natural key per level

`GeographyCatalogSeeder` streams the municipality CSV with `SplFileObject`/`fgetcsv` (O(1) memory — never the whole file decoded into an array) and writes in batches of 500 via `DB::table('geography_entries')->upsert(...)`, keyed on `iso_alpha2` for country rows and `ine_code` for comunidad/municipio rows — `upsert()` chosen over truncate-and-reload specifically because of story 0033: once the future shipping-zone pivot carries FKs into this table, a `TRUNCATE` would either fail outright or orphan every zone assignment. The whole seed runs inside **one** `DB::transaction()`: a missing fixture, a malformed row (missing INE code), or a duplicate natural key aborts the entire run and leaves the table exactly as it was — never a half-populated catalog. This does not defeat the chunked-write memory goal; only the PHP-side row buffer needs to stay O(1), and the surrounding transaction costs nothing extra in memory.

#### Indexes — five, all present by requirement or for the picker

`php artisan db:table geography_entries` reports exactly: `primary` on `id`, `geography_entries_ine_code_unique`, `geography_entries_iso_alpha2_unique`, `geography_entries_level_normalized_name_index`, and `geography_entries_parent_id_foreign` (InnoDB's own, auto-created for the FK — **no** hand-written `$table->index('parent_id')`, per [migrations.md](../migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here)'s rule, confirmed live rather than read off the migration).

- **`ine_code` / `iso_alpha2` UNIQUE, both nullable** — the same pattern [`users.pending_email`](../schema-users-auth.md#users) already establishes: MySQL and SQLite both allow unlimited `NULL`s in a unique index, so each constraint binds only the rows of the level it actually identifies (a country row's `ine_code` is always `NULL`; a comunidad/municipio row's `iso_alpha2` is always `NULL`), and "no duplicate entries" becomes a database invariant rather than a seeder-only one.
- **`(level, normalized_name)` composite** exists for a consumer that does not exist yet — story 0034's future zone geography picker, which will run three bounded per-level searches (equality on `level`, prefix range-scan on `normalized_name`). This story adds the index and proves it exists (`Schema::getIndexes()`); it makes no performance claim about the picker itself, which belongs to 0034 at realistic volume.

#### The catalog ships with no way for an administrator to add to it

No route, no Livewire component, no Blade view, no policy, and no new permission — `shipping` is already one of `RolePermissionSeeder::MODULES`, and a read-only seeded catalog with no admin surface needs no `.view`/`.create`/`.edit`/`.delete` entry of its own. Story 0033 (shipping zones CRUD) is what a future permission gate belongs to, once there is an actual write surface — a `shipping_zones` table and its own pivot into this catalog — to gate.
